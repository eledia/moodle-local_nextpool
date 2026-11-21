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
 * AI proposals for the competencies of a catalogue item (F14).
 *
 * Asks the core_ai subsystem (generate_text) which competencies of the site the
 * item builds, with level, didactic function and learning objectives. The answer
 * is restricted to competencies of visible frameworks; anything else is dropped.
 * Proposals are never stored by themselves: the editor picks the ones to keep,
 * and only then are they added to the item's list.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class competency_suggester {
    /** @var int Most competencies sent to the AI provider, to keep the prompt small. */
    public const VOCABULARY_LIMIT = 400;

    /** @var int Most proposals kept from one answer. */
    public const SUGGESTION_LIMIT = 10;

    /**
     * Whether an AI provider can generate text on this site.
     *
     * @return bool
     */
    public static function is_available(): bool {
        if (!class_exists(\core_ai\manager::class) || !class_exists(\core_ai\aiactions\generate_text::class)) {
            return false;
        }
        return \core\di::get(\core_ai\manager::class)->is_action_available(\core_ai\aiactions\generate_text::class);
    }

    /**
     * Competencies the AI may choose from: those with an idnumber in visible frameworks.
     *
     * An idnumber that exists more than once on the site, in any framework, is left out, since placing could not
     * link it (same rule as item_competencies::resolve()). The limit applies after that, so ambiguous idnumbers
     * do not take places (bug51); count_vocabulary() tells whether competencies were cut off.
     *
     * @param int $limit Most competencies to return.
     * @return array<string,array{name:string,description:string,framework:string}> Keyed by idnumber.
     */
    public static function vocabulary(int $limit = self::VOCABULARY_LIMIT): array {
        global $DB;
        [$from, $params] = self::vocabulary_from();
        $records = $DB->get_records_sql(
            "SELECT c.id, c.idnumber, c.shortname, c.description, f.shortname AS framework
               $from
           ORDER BY f.shortname, c.sortorder, c.id",
            $params,
            0,
            $limit
        );
        $vocabulary = [];
        foreach ($records as $record) {
            $vocabulary[(string) $record->idnumber] = [
                'name' => (string) $record->shortname,
                'description' => \core_text::substr(trim(strip_tags((string) $record->description)), 0, 200),
                'framework' => (string) $record->framework,
            ];
        }
        return $vocabulary;
    }

    /**
     * Number of competencies the AI could choose from without the limit.
     *
     * @return int More than VOCABULARY_LIMIT means vocabulary() leaves some out.
     */
    public static function count_vocabulary(): int {
        global $DB;
        [$from, $params] = self::vocabulary_from();
        return $DB->count_records_sql("SELECT COUNT(1) $from", $params);
    }

    /**
     * FROM and WHERE of the vocabulary.
     *
     * @return array SQL and parameters.
     */
    private static function vocabulary_from(): array {
        return [
            "FROM {competency} c
              JOIN {competency_framework} f ON f.id = c.competencyframeworkid
             WHERE f.visible = 1 AND c.idnumber <> :empty
               AND NOT EXISTS (SELECT 1 FROM {competency} d WHERE d.idnumber = c.idnumber AND d.id <> c.id)",
            ['empty' => ''],
        ];
    }

    /**
     * Ask the AI provider for competencies of an item.
     *
     * @param int $itemid Item id.
     * @param int $userid Acting user, 0 for the current user.
     * @return array|null Validated entries in the nugget format, or null if no provider is available or it failed.
     */
    public static function suggest(int $itemid, int $userid = 0): ?array {
        global $USER;
        $item = item_manager::get($itemid);
        $vocabulary = self::vocabulary();
        if (!$vocabulary || !self::is_available()) {
            return null;
        }
        $describe = static fn(string $idnumber, array $entry) => ['id' => $idnumber] + $entry;
        $content = [
            'title' => (string) $item->title,
            'description' => \core_text::substr(trim(strip_tags((string) $item->description)), 0, 2000),
            'type' => (string) $item->type,
            'language' => (string) $item->language,
            'audience' => (string) ($item->audience ?? ''),
            'tags' => item_manager::get_tags($itemid),
        ];
        $prompt = "You tag learning content with the competencies it builds.\n"
            . 'Use only the competency ids given. Choose at most ' . self::SUGGESTION_LIMIT . " competencies that the content "
            . "clearly builds; choose none when nothing fits. For each give the level the content leads to (1 basic "
            . "understanding, 2 guided application, 3 independent application, 4 advanced, 5 expertise), the didactic "
            . 'function (one of ' . implode(', ', item_competencies::FUNCTIONS) . ') and up to three learning objectives '
            . 'with a Bloom level (one of ' . implode(', ', item_competencies::BLOOM) . ") in the language of the content.\n"
            . 'Answer only with JSON: {"competencies":[{"competency":"…","level":2,"function":"explain",'
            . "\"objectives\":[{\"bloom\":\"understand\",\"text\":\"…\"}]}]}.\n"
            . 'CONTENT: ' . json_encode($content, JSON_UNESCAPED_UNICODE) . "\n"
            . 'COMPETENCIES: ' . json_encode(
                array_values(array_map($describe, array_keys($vocabulary), $vocabulary)),
                JSON_UNESCAPED_UNICODE
            );
        try {
            $context = access::get_context($item);
            $action = new \core_ai\aiactions\generate_text($context->id, $userid ?: (int) $USER->id, $prompt);
            $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        } catch (\Throwable $e) {
            debugging('local_nextpool: AI competency proposal failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        if (!$response->get_success()) {
            return null;
        }
        return self::parse((string) ($response->get_response_data()['generatedcontent'] ?? ''), $vocabulary);
    }

    /**
     * Turn an AI answer (or a submitted proposal list) into valid entries.
     *
     * Entries with an idnumber outside the vocabulary, an invalid level or function, or a
     * repeated idnumber are dropped; unknown Bloom levels are removed from the objective.
     *
     * @param string $text JSON, possibly surrounded by other text.
     * @param array $vocabulary Allowed idnumbers as keys (see vocabulary()).
     * @return array Entries in the nugget format, at most SUGGESTION_LIMIT.
     */
    public static function parse(string $text, array $vocabulary): array {
        if (preg_match('/\{.*\}/s', $text, $match)) {
            $text = $match[0];
        }
        $data = json_decode($text, true);
        if (!is_array($data) || !is_array($data['competencies'] ?? null)) {
            return [];
        }
        $entries = [];
        foreach ($data['competencies'] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $idnumber = trim((string) ($raw['competency'] ?? ''));
            $level = (int) ($raw['level'] ?? 0);
            $function = (string) ($raw['function'] ?? '');
            $valid = isset($vocabulary[$idnumber]) && !isset($entries[$idnumber])
                && $level >= item_competencies::LEVEL_MIN && $level <= item_competencies::LEVEL_MAX
                && in_array($function, item_competencies::FUNCTIONS, true);
            if (!$valid) {
                continue;
            }
            $objectives = [];
            foreach (array_slice((array) ($raw['objectives'] ?? []), 0, 3) as $objective) {
                $objective = (array) $objective;
                $objtext = trim(clean_param((string) ($objective['text'] ?? ''), PARAM_TEXT));
                if ($objtext === '') {
                    continue;
                }
                $bloom = (string) ($objective['bloom'] ?? '');
                $objectives[] = [
                    'id' => null,
                    'bloom' => in_array($bloom, item_competencies::BLOOM, true) ? $bloom : null,
                    'text' => \core_text::substr($objtext, 0, 500),
                ];
            }
            $entries[$idnumber] = [
                'competency' => $idnumber,
                'level' => $level,
                'function' => $function,
                'objectives' => $objectives,
            ];
            if (count($entries) >= self::SUGGESTION_LIMIT) {
                break;
            }
        }
        return array_values($entries);
    }

    /**
     * Add picked proposals to an item, keeping its existing entries.
     *
     * A proposal for a competency the item already has does not change that entry.
     *
     * @param int $itemid Item id.
     * @param array $entries Entries in the nugget format.
     * @param int $userid Acting user, 0 for the current user.
     * @return string[] Idnumbers that were added.
     */
    public static function apply(int $itemid, array $entries, int $userid = 0): array {
        $current = [];
        foreach (item_competencies::get($itemid) as $record) {
            $current[$record->competency] = [
                'competency' => $record->competency,
                'level' => (int) $record->skilllevel,
                'function' => $record->didacticfunction,
                'objectives' => $record->objectives,
            ];
        }
        $added = [];
        foreach ($entries as $entry) {
            $idnumber = (string) $entry['competency'];
            if (!isset($current[$idnumber])) {
                $current[$idnumber] = $entry;
                $added[] = $idnumber;
            }
        }
        if ($added) {
            item_competencies::set($itemid, array_values($current), $userid);
        }
        return $added;
    }
}
