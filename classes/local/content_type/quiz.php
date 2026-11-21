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

namespace local_nextpool\local\content_type;

use local_nextpool\local\placement_manager;
use local_nextpool\local\quiz_package;
use mod_quiz\question\display_options;

/**
 * Content type 'quiz' as a Moodle quiz, updated in place (F04-quiz, q21, q28-q31).
 *
 * The questions of a placed quiz live in the quiz's own question bank (q21). The catalogue idnumber of a
 * question is the idnumber of its question bank entry, and every question the catalogue writes carries the hash
 * of its catalogue XML in question.stamp ("nextpool:<sha1>:<random>"). From that the handler knows
 * - whether a question changed in the catalogue (other hash): it gets a new version of the same entry; slots
 *   use the latest version, so new attempts get it while running and finished attempts keep theirs;
 * - whether a teacher changed a question in the course (latest version without our stamp, or a slot with a
 *   question the catalogue does not know): the course version is never overwritten, the update waits (q21).
 *
 * Without attempts a new version is applied completely, also with other questions, order, pages or marks. With
 * attempts only corrections of existing questions of the same type are applied; a change of the structure waits
 * for the teacher, who can then create the new version as a new quiz alongside, hiding the old one (q29).
 * Only questions, order, marks and pages come from the catalogue (q31); quiz settings are set in the course.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz extends base {
    /** @var string Prefix of question.stamp for questions written by the catalogue. */
    public const STAMP_PREFIX = 'nextpool:';

    /** @var string[] Quiz settings a teacher sets; place_alongside() takes them from the old quiz (q31). */
    private const TEACHER_SETTINGS = ['timeopen', 'timeclose', 'timelimit', 'overduehandling', 'graceperiod',
        'preferredbehaviour', 'canredoquestions', 'attempts', 'attemptonlast', 'grademethod', 'decimalpoints',
        'questiondecimalpoints', 'shuffleanswers', 'grade', 'subnet', 'browsersecurity', 'delay1', 'delay2',
        'showuserpicture', 'showblocks', 'completionattemptsexhausted', 'completionminattempts', 'allowofflineattempts',
        'navmethod', 'gradepass'];

    /** @var string[] Completion settings of the course module; place_alongside() takes them from the old quiz (bug91). */
    private const COMPLETION_SETTINGS = ['completion', 'completionview', 'completionexpected', 'completionpassgrade'];

    /** @var string[] Review options of a quiz, as columns review<field> (mod/quiz/lib.php quiz_process_options()). */
    private const REVIEW_FIELDS = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
        'rightanswer', 'overallfeedback'];

    #[\Override]
    public function get_modname(): string {
        return 'quiz';
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/bank.php');
        $package = \local_nextpool\local\quiz_package::read_stored($this->get_snapshot($version));
        $html = '';
        $marks = 0;
        $fs = get_file_storage();
        foreach ($package['slots'] as $index => $slot) {
            $question = $package['questions'][$slot['question']];
            $text = $question['questiontext'];
            foreach ($question['questionfiles'] as $path => $content) {
                if (!in_array(mimeinfo('type', $path), \local_nextpool\local\preview_assets::MIME_TYPES, true)) {
                    continue;
                }
                $assetpath = 'question-' . $index . '/' . $path;
                $filepath = '/' . dirname($assetpath) . '/';
                $contextid = \context_system::instance()->id;
                $exists = $fs->file_exists(
                    $contextid,
                    'local_nextpool',
                    'previewassets',
                    $version->id,
                    $filepath,
                    basename($assetpath)
                );
                if (!$exists) {
                    $fs->create_file_from_string([
                        'contextid' => $contextid, 'component' => 'local_nextpool', 'filearea' => 'previewassets',
                        'itemid' => $version->id, 'filepath' => $filepath, 'filename' => basename($assetpath),
                        'userid' => \local_nextpool\local\h5p_guard::get_owner(),
                    ], $content);
                }
                $text = str_replace(
                    '@@PLUGINFILE@@/' . implode('/', array_map('rawurlencode', explode('/', $path))),
                    \local_nextpool\local\preview_assets::url((int) $version->id, $courseid, $assetpath)->out(false),
                    $text
                );
            }
            $heading = get_string('previewquestion', 'local_nextpool', (object) [
                'number' => $index + 1, 'page' => $slot['page'], 'marks' => $slot['maxmark'],
                'type' => \question_bank::get_qtype_name($question['qtype']),
            ]);
            $html .= \html_writer::tag('h5', s($heading)) . purify_html(format_text(
                $text,
                $question['questionformat'],
                ['context' => \local_nextpool\local\access::get_context($item), 'filter' => false]
            ));
            $marks += $slot['maxmark'];
        }
        $html .= \html_writer::tag('p', s(get_string('previewquiztotal', 'local_nextpool', (object) [
            'count' => count($package['slots']), 'marks' => $marks,
        ])));
        return new \local_nextpool\local\preview_result('html', html: $html);
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $package = quiz_package::read_stored($this->get_snapshot($version));

        // Site defaults of mod_quiz for everything that is not part of the catalogue version (q31).
        $config = (object) ((array) get_config('quiz') + [
            'timelimit' => 0, 'overduehandling' => 'autosubmit', 'graceperiod' => 86400,
            'preferredbehaviour' => 'deferredfeedback', 'canredoquestions' => 0, 'attempts' => 0, 'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST, 'decimalpoints' => 2, 'questiondecimalpoints' => -1,
            'shuffleanswers' => 1, 'maximumgrade' => 10, 'browsersecurity' => '-', 'delay1' => 0, 'delay2' => 0,
            'showuserpicture' => 0, 'showblocks' => 0, 'navmethod' => QUIZ_NAVMETHOD_FREE,
        ]);
        $settings = [
            'timeopen' => 0, 'timeclose' => 0, 'timelimit' => $config->timelimit,
            'overduehandling' => $config->overduehandling, 'graceperiod' => $config->graceperiod,
            'preferredbehaviour' => $config->preferredbehaviour, 'canredoquestions' => $config->canredoquestions,
            'attempts' => $config->attempts, 'attemptonlast' => $config->attemptonlast,
            'grademethod' => $config->grademethod, 'decimalpoints' => $config->decimalpoints,
            'questiondecimalpoints' => $config->questiondecimalpoints, 'shuffleanswers' => $config->shuffleanswers,
            'grade' => $config->maximumgrade, 'subnet' => '', 'browsersecurity' => $config->browsersecurity,
            'delay1' => $config->delay1, 'delay2' => $config->delay2, 'showuserpicture' => $config->showuserpicture,
            'showblocks' => $config->showblocks, 'navmethod' => $config->navmethod,
        ];
        $allon = display_options::DURING | display_options::IMMEDIATELY_AFTER | display_options::LATER_WHILE_OPEN
            | display_options::AFTER_CLOSE;
        $review = [];
        foreach (self::REVIEW_FIELDS as $field) {
            $review[$field] = (int) ($config->{'review' . $field} ?? $allon);
        }
        return $this->create_quiz($item, $package, $course, $section, $item->title, $settings, $review);
    }

    #[\Override]
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        try {
            $package = quiz_package::read_stored($this->get_snapshot($version));
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }
        $context = \context_module::instance($cm->id);
        $slots = self::get_slots((int) $cm->instance, $context);
        $entries = self::get_entries($context);

        // A teacher's change in the course is never overwritten (q21, F04Q.AC05).
        $local = [];
        foreach ($slots as $slot) {
            $entry = $slot->idnumber !== null ? ($entries[$slot->idnumber] ?? null) : null;
            if ($entry === null || (int) $entry->entryid !== (int) $slot->entryid || self::get_stamp_hash($entry->stamp) === null) {
                $local[] = $slot->name;
            }
        }
        if ($local) {
            return get_string('noinplace_quizlocal', 'local_nextpool', s(implode(', ', $local)));
        }

        if (!quiz_has_attempts((int) $cm->instance)) {
            return null;
        }
        // Core locks the structure of a quiz with attempts; changing marks or types would change grades (F04Q.AC04).
        if (self::get_structure($slots) !== self::package_structure($package)) {
            return get_string('noinplace_quizstructure', 'local_nextpool');
        }
        $changedtypes = [];
        foreach ($package['questions'] as $idnumber => $question) {
            if (isset($entries[$idnumber]) && $entries[$idnumber]->qtype !== $question['qtype']) {
                $changedtypes[] = $question['name'];
            }
        }
        if ($changedtypes) {
            return get_string('noinplace_quizqtype', 'local_nextpool', s(implode(', ', $changedtypes)));
        }
        return null;
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $package = quiz_package::read_stored($this->get_snapshot($version));
        $context = \context_module::instance($cm->id);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;

        $transaction = $DB->start_delegated_transaction();
        $questionids = $this->write_questions($context, $package);
        $slots = self::get_slots((int) $quiz->id, $context);
        if (self::get_structure($slots) !== self::package_structure($package)) {
            // Only reached without attempts; check_update() refuses structural changes otherwise.
            self::remove_slots($quiz, $context);
            $this->add_slots($quiz, $package, $questionids);
        }
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $transaction->allow_commit();

        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
    }

    #[\Override]
    public function can_place_alongside(): bool {
        return true;
    }

    #[\Override]
    public function place_alongside(\cm_info $cm, \stdClass $item, \stdClass $version): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $package = quiz_package::read_stored($this->get_snapshot($version));
        $old = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        // The teacher's settings stay as they were (q31); only questions, order, marks and pages are new.
        $settings = [];
        foreach (self::TEACHER_SETTINGS as $field) {
            if (property_exists($old, $field)) {
                $settings[$field] = $old->$field;
            }
        }
        $review = [];
        foreach (self::REVIEW_FIELDS as $field) {
            $review[$field] = (int) ($old->{'review' . $field} ?? 0);
        }
        // Completion conditions, overall feedback and grade items stay as well (bug91).
        foreach (self::COMPLETION_SETTINGS as $field) {
            $settings[$field] = $cm->$field;
        }
        if ($cm->completiongradeitemnumber !== null) {
            $settings['completionusegrade'] = 1;
            $settings['completiongradeitemnumber'] = $cm->completiongradeitemnumber;
        }
        // The grade to pass lives in the gradebook; core sets it from the module info (completionpassgrade).
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $old->id,
            'itemnumber' => 0, 'courseid' => $cm->course]);
        if ($gradeitem) {
            $settings['gradepass'] = $gradeitem->gradepass;
        }
        $oldslots = self::get_slots((int) $old->id, \context_module::instance($cm->id));
        $course = get_course($cm->course);
        $cmid = $this->create_quiz(
            $item,
            $package,
            $course,
            (int) $cm->sectionnum,
            $cm->name,
            $settings,
            $review,
            static function (\stdClass $quiz, \context_module $context) use ($cm, $old, $oldslots, $package): void {
                self::copy_feedback((int) $old->id, \context_module::instance($cm->id), (int) $quiz->id, $context);
                self::copy_grade_items((int) $old->id, $oldslots, (int) $quiz->id, $package);
            }
        );

        // The new quiz goes right after the old one; the old one is hidden, never deleted (q29, adr07).
        $modinfo = get_fast_modinfo($course);
        $sequence = $modinfo->get_sections()[(int) $cm->sectionnum] ?? [];
        $position = array_search((int) $cm->id, $sequence, true);
        $before = $position !== false ? ($sequence[$position + 1] ?? null) : null;
        $actions = \core_courseformat\formatactions::cm($course);
        if ($before !== null && $before !== $cmid) {
            $actions->move_before($cmid, $before);
        }
        $actions->set_visibility((int) $cm->id, 0);
        return $cmid;
    }

    /**
     * Create a quiz with the questions of a package.
     *
     * @param \stdClass $item Item record.
     * @param array $package Package from quiz_package::read().
     * @param \stdClass $course Course.
     * @param int $section Section number.
     * @param string $name Name of the quiz.
     * @param array $settings Quiz settings.
     * @param array $review Review options by field, as bit masks of display_options.
     * @param callable|null $complete Called with the quiz record and context after the slots are added.
     * @return int The new course module id.
     */
    private function create_quiz(
        \stdClass $item,
        array $package,
        \stdClass $course,
        int $section,
        string $name,
        array $settings,
        array $review,
        ?callable $complete = null
    ): int {
        $moduleinfo = (object) ([
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $name,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'cmidnumber' => '',
            'quizpassword' => '',
            // Pages come from the catalogue version, not from a fixed number of questions per page.
            'questionsperpage' => 0,
            'sumgrades' => 0,
        ] + $settings);
        $times = [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];
        foreach ($review as $field => $mask) {
            foreach ($times as $whenname => $when) {
                $moduleinfo->{$field . $whenname} = ($mask & $when) ? 1 : 0;
            }
        }

        return $this->create_module_cleanly($moduleinfo, function (\cm_info $cm) use ($package, $complete): void {
            global $DB;
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $quiz->cmid = $cm->id;
            $context = \context_module::instance($cm->id);
            $questionids = $this->write_questions($context, $package);
            $this->add_slots($quiz, $package, $questionids);
            if ($complete !== null) {
                $complete($quiz, $context);
            }
            \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        });
    }

    /**
     * Bring the questions of a package into the quiz's question bank.
     *
     * Unchanged questions stay; changed ones get a new version of their entry; new ones get a new entry. A
     * question whose type changed gets a new entry too, the old entry loses the idnumber (core cannot change
     * the type of an entry).
     *
     * @param \context_module $context Quiz context.
     * @param array $package Package from quiz_package::read().
     * @return array<string,int> Latest question id by catalogue idnumber.
     */
    private function write_questions(\context_module $context, array $package): array {
        global $DB;
        $entries = self::get_entries($context);
        $result = [];
        $toimport = [];
        foreach ($package['questions'] as $idnumber => $question) {
            $entry = $entries[$idnumber] ?? null;
            if ($entry !== null && self::get_stamp_hash($entry->stamp) === $question['hash']) {
                $result[$idnumber] = (int) $entry->questionid;
            } else {
                $toimport[] = $idnumber;
            }
        }
        if (!$toimport) {
            return $result;
        }

        $category = question_get_default_category($context->id, true);
        $imported = $this->import_questions($category, self::filter_xml($package['xml'], $toimport));
        foreach ($toimport as $i => $idnumber) {
            $questionid = $imported[$i];
            $entry = $entries[$idnumber] ?? null;
            $newentryid = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
            if ($entry !== null && $entry->qtype === $package['questions'][$idnumber]['qtype']) {
                // Make the imported question the next version of the existing entry.
                $next = max((int) $entry->nextversion, (int) $DB->get_field_sql(
                    'SELECT MAX(version) FROM {question_versions} WHERE questionbankentryid = ?',
                    [$entry->entryid]
                ) + 1);
                $DB->update_record('question_versions', (object) [
                    'id' => $DB->get_field('question_versions', 'id', ['questionid' => $questionid], MUST_EXIST),
                    'questionbankentryid' => $entry->entryid,
                    'version' => $next,
                ]);
                $DB->set_field('question_bank_entries', 'nextversion', $next + 1, ['id' => $entry->entryid]);
                $DB->delete_records('question_bank_entries', ['id' => $newentryid]);
            } else {
                if ($entry !== null) {
                    $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $entry->entryid]);
                }
                $DB->set_field('question_bank_entries', 'idnumber', $idnumber, ['id' => $newentryid]);
            }
            $DB->set_field('question', 'stamp', self::STAMP_PREFIX . $package['questions'][$idnumber]['hash'] . ':' .
                random_string(10), ['id' => $questionid]);
            // Core caches question data with its entry and version.
            \question_bank::notify_question_edited($questionid);
            $result[$idnumber] = $questionid;
        }
        return $result;
    }

    /**
     * Import Moodle XML into a category.
     *
     * @param \stdClass $category Question category.
     * @param string $xml Moodle XML.
     * @return int[] Ids of the new questions, in the order of the XML.
     * @throws \moodle_exception If core cannot import the questions.
     */
    private function import_questions(\stdClass $category, string $xml): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $file = make_request_directory() . '/questions.xml';
        file_put_contents($file, $xml);
        $format = new class extends \qformat_xml {
            #[\Override]
            public function import_text_with_files($data, $path, $defaultvalue = '', $defaultformat = 'html') {
                $field = parent::import_text_with_files($data, $path, $defaultvalue, $defaultformat);
                if ($field['format'] === FORMAT_HTML) {
                    // Catalogue authors do not gain permission to store active HTML in a shared question bank (bug138).
                    $field['text'] = purify_html($field['text']);
                }
                return $field;
            }
        };
        $format->setCategory($category);
        $format->setContexts([\context::instance_by_id($category->contextid)]);
        $format->setCourse(get_course(\context::instance_by_id($category->contextid)->get_course_context()->instanceid));
        $format->setFilename($file);
        $format->setRealfilename('questions.xml');
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        $format->set_display_progress(false);
        ob_start();
        try {
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
        } finally {
            $output = ob_get_clean();
        }
        if (!$ok) {
            throw new \moodle_exception('error_quizimport', 'local_nextpool', '', trim(strip_tags((string) $output)));
        }
        return array_map('intval', $format->questionids);
    }

    /**
     * Add the slots of a package to an empty quiz.
     *
     * @param \stdClass $quiz Quiz record with cmid.
     * @param array $package Package from quiz_package::read().
     * @param array $questionids Question id by idnumber (string => int).
     */
    private function add_slots(\stdClass $quiz, array $package, array $questionids): void {
        foreach ($package['slots'] as $slot) {
            quiz_add_quiz_question($questionids[$slot['question']], $quiz, $slot['page'], $slot['maxmark']);
        }
    }

    /**
     * Copy the overall feedback of a quiz with its files to another quiz (bug91).
     *
     * Core only saves overall feedback from the settings form (draft areas of the current user); the records
     * and files are copied as they are. The boundaries are absolute grades, the new quiz has the same grade.
     *
     * @param int $oldquizid Quiz to copy from.
     * @param \context_module $oldcontext Its context.
     * @param int $quizid Quiz to copy to.
     * @param \context_module $context Its context.
     */
    private static function copy_feedback(
        int $oldquizid,
        \context_module $oldcontext,
        int $quizid,
        \context_module $context
    ): void {
        global $DB;
        $fs = get_file_storage();
        $DB->delete_records('quiz_feedback', ['quizid' => $quizid]);
        foreach ($DB->get_records('quiz_feedback', ['quizid' => $oldquizid], 'mingrade DESC, id') as $feedback) {
            $oldid = (int) $feedback->id;
            unset($feedback->id);
            $feedback->quizid = $quizid;
            $feedbackid = $DB->insert_record('quiz_feedback', $feedback);
            foreach ($fs->get_area_files($oldcontext->id, 'mod_quiz', 'feedback', $oldid, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['contextid' => $context->id, 'itemid' => $feedbackid], $file);
            }
        }
    }

    /**
     * Copy the grade items of a quiz to another quiz with the core structure API (bug91).
     *
     * A slot of the new quiz gets the grade item that the slot with the same catalogue question had in the old
     * quiz; questions new in this version get none, as when a teacher adds a question.
     *
     * @param int $oldquizid Quiz to copy from.
     * @param \stdClass[] $oldslots Its slots, from get_slots().
     * @param int $quizid Quiz to copy to, with the slots of the package.
     * @param array $package Package from quiz_package::read().
     */
    private static function copy_grade_items(int $oldquizid, array $oldslots, int $quizid, array $package): void {
        global $DB;
        $olditems = $DB->get_records('quiz_grade_items', ['quizid' => $oldquizid], 'sortorder, id');
        if (!$olditems) {
            return;
        }
        $structure = \mod_quiz\quiz_settings::create($quizid)->get_structure();
        $newids = [];
        foreach ($olditems as $olditem) {
            $gradeitem = (object) ['quizid' => $quizid, 'name' => $olditem->name];
            $structure->create_grade_item($gradeitem);
            $newids[(int) $olditem->id] = (int) $gradeitem->id;
        }
        $byidnumber = [];
        foreach ($oldslots as $oldslot) {
            if ($oldslot->idnumber !== null && $oldslot->quizgradeitemid !== null) {
                $byidnumber[$oldslot->idnumber] = $newids[(int) $oldslot->quizgradeitemid] ?? null;
            }
        }
        foreach ($package['slots'] as $i => $slot) {
            $gradeitemid = $byidnumber[$slot['question']] ?? null;
            if ($gradeitemid !== null) {
                $structure->update_slot_grade_item($structure->get_slot_by_number($i + 1), $gradeitemid);
            }
        }
    }

    /**
     * Remove all slots of a quiz without attempts, before its slots are added again.
     *
     * Like structure::remove_slot() for every slot, in one go. The first section with its settings stays; the
     * questions stay in the quiz's question bank.
     *
     * @param \stdClass $quiz Quiz record.
     * @param \context_module $context Quiz context.
     */
    private static function remove_slots(\stdClass $quiz, \context_module $context): void {
        global $DB;
        $slotids = $DB->get_fieldset('quiz_slots', 'id', ['quizid' => $quiz->id]);
        if ($slotids) {
            [$insql, $params] = $DB->get_in_or_equal($slotids, SQL_PARAMS_NAMED);
            $params += ['contextid' => $context->id, 'component' => 'mod_quiz', 'area' => 'slot'];
            $where = "usingcontextid = :contextid AND component = :component AND questionarea = :area AND itemid $insql";
            $DB->delete_records_select('question_references', $where, $params);
            $DB->delete_records_select('question_set_references', $where, $params);
            $DB->delete_records('quiz_slots', ['quizid' => $quiz->id]);
        }
        $DB->delete_records_select('quiz_sections', 'quizid = :quizid AND firstslot > 1', ['quizid' => $quiz->id]);
    }

    /**
     * Moodle XML with only some questions, in the given order.
     *
     * @param string $xml Moodle XML of the package.
     * @param string[] $idnumbers Questions to keep.
     * @return string
     */
    private static function filter_xml(string $xml, array $idnumbers): string {
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);
        $nodes = [];
        foreach (iterator_to_array($dom->documentElement->childNodes) as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === 'question' && $node->getAttribute('type') !== 'category') {
                $idnumber = '';
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->nodeName === 'idnumber') {
                        $idnumber = trim($child->textContent);
                    }
                }
                $nodes[$idnumber] = $node;
            }
            $dom->documentElement->removeChild($node);
        }
        foreach ($idnumbers as $idnumber) {
            $dom->documentElement->appendChild($nodes[$idnumber]);
        }
        return $dom->saveXML();
    }

    /**
     * Question bank entries of the quiz with a catalogue idnumber, with their latest version.
     *
     * @param \context_module $context Quiz context.
     * @return array<string,\stdClass> By idnumber: entryid, nextversion, questionid, qtype, stamp.
     */
    private static function get_entries(\context_module $context): array {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT qbe.idnumber, qbe.id AS entryid, qbe.nextversion, q.id AS questionid, q.qtype, q.stamp
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qc.contextid = :contextid
                AND qbe.idnumber IS NOT NULL
                AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)",
            ['contextid' => $context->id]
        );
        return $records;
    }

    /**
     * Slots of the quiz with the entry each one uses.
     *
     * @param int $quizid Quiz id.
     * @param \context_module $context Quiz context.
     * @return \stdClass[] In slot order: slot, page, maxmark, entryid, idnumber, name (of the latest version).
     */
    private static function get_slots(int $quizid, \context_module $context): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT s.slot, s.page, s.maxmark, s.quizgradeitemid, qbe.id AS entryid, qbe.idnumber, q.name
               FROM {quiz_slots} s
          LEFT JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot' AND qr.usingcontextid = :contextid
          LEFT JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
          LEFT JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)
          LEFT JOIN {question} q ON q.id = qv.questionid
              WHERE s.quizid = :quizid
           ORDER BY s.slot",
            ['contextid' => $context->id, 'quizid' => $quizid]
        ));
    }

    /**
     * Comparable structure of slots: idnumber, page and mark per slot.
     *
     * @param \stdClass[] $slots From get_slots().
     * @return string[]
     */
    private static function get_structure(array $slots): array {
        return array_map(
            static fn($s) => $s->idnumber . '|' . (int) $s->page . '|' . sprintf('%.7F', (float) $s->maxmark),
            $slots
        );
    }

    /**
     * Comparable structure of a package, as get_structure().
     *
     * @param array $package Package from quiz_package::read().
     * @return string[]
     */
    private static function package_structure(array $package): array {
        return array_map(
            static fn($s) => $s['question'] . '|' . $s['page'] . '|' . sprintf('%.7F', $s['maxmark']),
            $package['slots']
        );
    }

    /**
     * Catalogue hash of a question written by the catalogue.
     *
     * @param string $stamp question.stamp.
     * @return string|null Null if the question was not written by the catalogue (for example edited in the course).
     */
    public static function get_stamp_hash(string $stamp): ?string {
        if (!str_starts_with($stamp, self::STAMP_PREFIX)) {
            return null;
        }
        return explode(':', substr($stamp, strlen(self::STAMP_PREFIX)))[0];
    }

    #[\Override]
    public function supports_extract(): bool {
        return true;
    }

    /**
     * Write the questions of a quiz as a quiz package (questions.xml and quiz.json, task83).
     *
     * Every slot gives the question version the quiz uses: a fixed version, else the latest ready one. Questions
     * keep the idnumber of their question bank entry; questions without one, e.g. added in the course, get
     * "q<entry id>" ((t83-d)). Pages are numbered from 1 without gaps. Random questions and question types the
     * catalogue does not take (q30) stop the take-over with their names.
     *
     * @param \cm_info $cm Course module of the quiz.
     * @param string $targetfile Absolute path of the ZIP to write.
     * @return string File name.
     * @throws \moodle_exception If the quiz has no questions or questions the catalogue does not take.
     */
    #[\Override]
    public function extract(\cm_info $cm, string $targetfile): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);
        $slots = $DB->get_records_sql(
            "SELECT s.id, s.slot, s.page, s.maxmark, qr.questionbankentryid AS entryid, qr.version, qbe.idnumber
               FROM {quiz_slots} s
          LEFT JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot' AND qr.usingcontextid = :contextid
          LEFT JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
              WHERE s.quizid = :quizid
           ORDER BY s.slot",
            ['contextid' => $context->id, 'quizid' => $cm->instance]
        );
        if (!$slots) {
            throw new \moodle_exception('error_extractquizempty', 'local_nextpool');
        }

        $questions = [];
        $description = [];
        $refused = [];
        $idnumbers = [];
        $page = 0;
        $lastpage = null;
        foreach ($slots as $slot) {
            if ($slot->entryid === null) {
                $refused[] = get_string('extract_randomquestion', 'local_nextpool', (int) $slot->slot);
                continue;
            }
            $question = self::get_used_question((int) $slot->entryid, $slot->version === null ? null : (int) $slot->version);
            if (!in_array($question->qtype, quiz_package::ALLOWED_QTYPES, true)) {
                $refused[] = get_string('extract_questionqtype', 'local_nextpool', (object) [
                    'name' => $question->name,
                    'qtype' => \question_bank::get_qtype_name($question->qtype),
                ]);
                continue;
            }
            $idnumber = trim((string) $slot->idnumber) !== '' ? trim((string) $slot->idnumber) : 'q' . $slot->entryid;
            if (isset($idnumbers[$idnumber])) {
                $idnumber .= '-' . $slot->entryid;
            }
            $idnumbers[$idnumber] = true;
            if ((int) $slot->page !== $lastpage) {
                $page++;
                $lastpage = (int) $slot->page;
            }
            \question_bank::get_qtype($question->qtype)->get_question_options($question);
            $question->export_process = true;
            // Hidden versions are exported as well: the quiz uses them.
            $question->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
            $questions[] = $question;
            $description[] = ['question' => $idnumber, 'page' => $page, 'maxmark' => (float) $slot->maxmark];
        }
        if ($refused) {
            throw new \moodle_exception('error_extractquizqtype', 'local_nextpool', '', s(implode(', ', $refused)));
        }

        $format = new \qformat_xml();
        $format->setQuestions($questions);
        $format->setCattofile(false);
        $format->setContexttofile(false);
        $xml = self::set_idnumbers($format->exportprocess(false), array_column($description, 'question'));

        $zip = new \ZipArchive();
        if ($zip->open($targetfile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('error_extractfailed', 'local_nextpool');
        }
        $zip->addFromString(quiz_package::QUESTIONS_FILE, $xml);
        $zip->addFromString(quiz_package::DESCRIPTION_FILE, json_encode(
            ['format' => quiz_package::FORMAT, 'version' => 1, 'slots' => $description],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        $zip->close();
        return self::get_extract_filename($cm, 'zip');
    }

    /**
     * The question version a slot uses: the fixed version, else the latest ready one, else the latest.
     *
     * @param int $entryid Question bank entry id.
     * @param int|null $version Fixed version of the slot, null for "always latest".
     * @return \stdClass Question record with status and category, as the question export reads it.
     */
    private static function get_used_question(int $entryid, ?int $version): \stdClass {
        global $DB;
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entryid], 'version DESC');
        $chosen = null;
        foreach ($versions as $candidate) {
            if (
                $version !== null ? (int) $candidate->version === $version
                    : $candidate->status === \core_question\local\bank\question_version_status::QUESTION_STATUS_READY
            ) {
                $chosen = $candidate;
                break;
            }
        }
        $chosen = $chosen ?? reset($versions);
        return $DB->get_record_sql(
            'SELECT q.*, qv.status, qbe.questioncategoryid AS category
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.id = :id',
            ['id' => $chosen->questionid],
            MUST_EXIST
        );
    }

    /**
     * Moodle XML with the given idnumber for each question, in order.
     *
     * @param string $xml Moodle XML from the question export.
     * @param string[] $idnumbers One idnumber per question.
     * @return string
     */
    private static function set_idnumbers(string $xml, array $idnumbers): string {
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);
        $i = 0;
        foreach ($dom->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->nodeName !== 'question' || $node->getAttribute('type') === 'category') {
                continue;
            }
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->nodeName === 'idnumber') {
                    $child->textContent = $idnumbers[$i];
                }
            }
            $i++;
        }
        return (string) $dom->saveXML();
    }

    #[\Override]
    public function validate_package(string $path): void {
        quiz_package::read($path);
    }

    #[\Override]
    public function get_file_extensions(): array {
        return ['zip'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_quiz', 'local_nextpool');
    }
}
