<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_nextpool\local;

/**
 * Exchange format of a catalogue quiz in the storage location (F04-quiz, q28, q30).
 *
 * A quiz version is a ZIP with two files:
 * - questions.xml: the questions in Moodle XML. Every question needs an <idnumber>; it identifies the question
 *   across versions, so a corrected question becomes a new version of the same question in the course.
 * - quiz.json: order, marks and pages, for example
 *   {"format": "nextpool-quiz", "version": 1,
 *    "slots": [{"question": "q1", "page": 1, "maxmark": 2}, {"question": "q2", "page": 2}]}
 *   A slot without maxmark gets the question's default mark. Pages start at 1 and never go down.
 *
 * Nothing else comes from the catalogue (q31): times, attempts, grading method and feedback are set in the
 * course. Only the question types of ALLOWED_QTYPES are accepted (q30); category entries of the XML are ignored.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_package {
    /** @var string File with the questions in Moodle XML. */
    public const QUESTIONS_FILE = 'questions.xml';
    /** @var string File with order, marks and pages. */
    public const DESCRIPTION_FILE = 'quiz.json';
    /** @var string Value of "format" in the description file. */
    public const FORMAT = 'nextpool-quiz';

    /**
     * Question types allowed in R1 (q30, PO 2026-11-09: proposal accepted).
     *
     * Multiple choice, true/false, short answer, numerical, matching, essay, select missing words and drag and drop
     * into text. Not: random questions, embedded answers (Cloze), calculated questions, drag and drop onto image.
     *
     * @var string[]
     */
    public const ALLOWED_QTYPES = ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'gapselect',
        'ddwtos'];

    /**
     * Read and check a quiz package.
     *
     * @param string $path Local path of the ZIP.
     * @return array{slots: array<int, array{question: string, page: int, maxmark: float}>,
     *     questions: array<string, array{qtype: string, name: string, hash: string, defaultmark: float}>, xml: string}
     *     Slots in order; questions by idnumber, hash of the canonical XML of each question; xml is questions.xml.
     * @throws \moodle_exception error_quizpackage with the reason if the package is not a valid quiz.
     */
    public static function read(string $path): array {
        $zip = new \ZipArchive();
        if (!is_readable($path) || $zip->open($path, \ZipArchive::RDONLY) !== true) {
            self::fail('quizpackage_notzip');
        }
        try {
            $xml = content_type\base::read_zip_entry($zip, self::QUESTIONS_FILE);
            $json = content_type\base::read_zip_entry($zip, self::DESCRIPTION_FILE);
        } finally {
            $zip->close();
        }
        if ($xml === false || $json === false) {
            $names = ['questions' => self::QUESTIONS_FILE, 'description' => self::DESCRIPTION_FILE];
            self::fail('quizpackage_files', (object) $names);
        }

        $questions = self::read_questions($xml);
        $slots = self::read_slots($json, $questions);
        return ['slots' => $slots, 'questions' => $questions, 'xml' => $xml];
    }

    /**
     * Read a quiz package from a stored file, in place in the file pool.
     *
     * @param \stored_file $package Package.
     * @return array See read().
     * @throws \moodle_exception If the package is not a valid quiz.
     */
    public static function read_stored(\stored_file $package): array {
        $path = get_file_storage()->get_file_system()->get_local_path_from_storedfile($package, true);
        return self::read((string) $path);
    }

    /**
     * Questions of the XML by idnumber.
     *
     * @param string $xml Moodle XML.
     * @return array<string, array{qtype: string, name: string, hash: string, defaultmark: float}>
     * @throws \moodle_exception If the XML cannot be read, a question has no or a duplicate idnumber or a type
     *     that is not allowed.
     */
    private static function read_questions(string $xml): array {
        global $CFG;
        // The global question bank is not autoloaded by a normal Moodle request (bug239).
        require_once($CFG->dirroot . '/question/engine/bank.php');

        $dom = new \DOMDocument();
        // Indentation is no change of a question.
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET) || $dom->documentElement->nodeName !== 'quiz') {
            self::fail('quizpackage_xml');
        }
        $questions = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->nodeName !== 'question') {
                continue;
            }
            $qtype = $node->getAttribute('type');
            if ($qtype === 'category') {
                continue;
            }
            $name = trim((string) self::child_text($node, 'name'));
            if (!in_array($qtype, self::ALLOWED_QTYPES, true) || !\question_bank::is_qtype_installed($qtype)) {
                self::fail('quizpackage_qtype', (object) ['qtype' => $qtype, 'name' => $name]);
            }
            $idnumber = trim((string) self::child_text($node, 'idnumber'));
            if ($idnumber === '') {
                self::fail('quizpackage_noidnumber', $name);
            }
            if (isset($questions[$idnumber])) {
                self::fail('quizpackage_duplicate', $idnumber);
            }
            $defaultmark = self::child_text($node, 'defaultgrade');
            $questions[$idnumber] = [
                'qtype' => $qtype,
                'name' => $name,
                // Canonical XML: the same question gives the same hash, whatever the formatting of the file.
                'hash' => sha1((string) $node->C14N()),
                'defaultmark' => is_numeric($defaultmark) ? (float) $defaultmark : 1.0,
                // Preview uses only the question text, never answer/feedback elements (F16 task208).
                'questiontext' => self::child_text($node, 'questiontext') ?? '',
                'questionformat' => self::question_format($node),
                'questionfiles' => self::question_files($node),
            ];
        }
        if (!$questions) {
            self::fail('quizpackage_noquestions');
        }
        return $questions;
    }

    /**
     * Format declared on questiontext, defaulting to HTML like Moodle XML imports.
     *
     * @param \DOMElement $node Question.
     * @return int
     */
    private static function question_format(\DOMElement $node): int {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'questiontext') {
                return match ($child->getAttribute('format')) {
                    'plain_text' => FORMAT_PLAIN, 'markdown' => FORMAT_MARKDOWN,
                    'moodle_auto_format' => FORMAT_MOODLE, default => FORMAT_HTML,
                };
            }
        }
        return FORMAT_HTML;
    }

    /**
     * Embedded files of question text only. Answers and feedback are never traversed.
     *
     * @param \DOMElement $node Question.
     * @return array
     */
    private static function question_files(\DOMElement $node): array {
        $files = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof \DOMElement) || $child->nodeName !== 'questiontext') {
                continue;
            }
            foreach ($child->getElementsByTagName('file') as $file) {
                $path = ltrim($file->getAttribute('path') . '/' . $file->getAttribute('name'), '/');
                if (
                    $file->getAttribute('encoding') === 'base64'
                        && content_type\page::normalise_path($path) === $path
                        && strlen($file->textContent) <= 4 * preview_assets::MAX_ENTRY / 3 + 4
                ) {
                    $content = base64_decode($file->textContent, true);
                    if ($content !== false) {
                        $files[$path] = $content;
                    }
                }
            }
        }
        return $files;
    }

    /**
     * Slots of the description file.
     *
     * @param string $json Description file.
     * @param array $questions Questions by idnumber, from read_questions().
     * @return array<int, array{question: string, page: int, maxmark: float}>
     * @throws \moodle_exception If the description is invalid or does not match the questions.
     */
    private static function read_slots(string $json, array $questions): array {
        $data = json_decode($json, true);
        if (
            !is_array($data) || ($data['format'] ?? '') !== self::FORMAT || (int) ($data['version'] ?? 0) !== 1
                || !is_array($data['slots'] ?? null) || !$data['slots']
        ) {
            self::fail('quizpackage_description', self::DESCRIPTION_FILE);
        }
        $slots = [];
        $used = [];
        $lastpage = 1;
        foreach (array_values($data['slots']) as $i => $slot) {
            $idnumber = is_array($slot) ? trim((string) ($slot['question'] ?? '')) : '';
            if (!isset($questions[$idnumber])) {
                self::fail('quizpackage_unknownquestion', $idnumber);
            }
            if (isset($used[$idnumber])) {
                self::fail('quizpackage_duplicate', $idnumber);
            }
            $page = $slot['page'] ?? $lastpage;
            if (!is_int($page) || $page < $lastpage || $page > $lastpage + 1 || ($i === 0 && $page !== 1)) {
                self::fail('quizpackage_page', $idnumber);
            }
            $maxmark = $slot['maxmark'] ?? $questions[$idnumber]['defaultmark'];
            if ((!is_int($maxmark) && !is_float($maxmark)) || $maxmark < 0) {
                self::fail('quizpackage_maxmark', $idnumber);
            }
            $used[$idnumber] = true;
            $lastpage = $page;
            $slots[] = ['question' => $idnumber, 'page' => $page, 'maxmark' => round((float) $maxmark, 7)];
        }
        $unused = array_diff(array_keys($questions), array_keys($used));
        if ($unused) {
            self::fail('quizpackage_unused', implode(', ', $unused));
        }
        return $slots;
    }

    /**
     * Text of a child element, or of its <text> child, as Moodle XML writes it.
     *
     * @param \DOMElement $node Question.
     * @param string $name Child element.
     * @return string|null Null if the child is missing.
     */
    private static function child_text(\DOMElement $node, string $name): ?string {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === $name) {
                foreach ($child->childNodes as $text) {
                    if ($text instanceof \DOMElement && $text->nodeName === 'text') {
                        return $text->textContent;
                    }
                }
                return $child->textContent;
            }
        }
        return null;
    }

    /**
     * Stop with a reason the editorial team can act on.
     *
     * @param string $reason String identifier of the reason.
     * @param mixed $a Placeholder of the reason.
     * @throws \moodle_exception Always.
     */
    private static function fail(string $reason, $a = null): never {
        // Names come from the package and end up in HTML.
        if (is_object($a)) {
            $a = (object) array_map('s', (array) $a);
        } else if ($a !== null) {
            $a = s($a);
        }
        throw new \moodle_exception('error_quizpackage', 'local_nextpool', '', get_string($reason, 'local_nextpool', $a));
    }
}
