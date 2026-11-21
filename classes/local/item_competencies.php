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
 * Competencies of catalogue items (F14).
 *
 * Each item carries a list of competencies, stored by the idnumber of the
 * core_competency competency together with the Skills level the item leads to,
 * its didactic function and learning objectives. The format matches nugget
 * format 2 of ContentFlow, so variants of eLeDia nuggets (F13) keep the entries.
 *
 * The idnumber is resolved only when it is needed: an entry whose competency
 * does not exist (yet) on this site is kept, shown to the editors and skipped
 * when the item is placed.
 *
 * Domain layer: capabilities are checked by the pages; linking to a course
 * module goes through core_competency\api, which checks its own capability.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_competencies {
    /** @var string Competency table. */
    public const TABLE = 'local_nextpool_competency';

    /** @var int Lowest Skills level. */
    public const LEVEL_MIN = 1;
    /** @var int Highest Skills level. */
    public const LEVEL_MAX = 5;

    /** @var string[] Didactic functions (Skills q27: apply replaces case). */
    public const FUNCTIONS = ['explain', 'example', 'practice', 'check', 'apply'];

    /** @var int Most competencies one search of the item form returns (bug52). */
    public const SEARCH_LIMIT = 50;

    /** @var string[] Bloom levels of learning objectives. */
    public const BLOOM = ['remember', 'understand', 'apply', 'analyse', 'evaluate', 'create'];

    /** @var string Resolution: exactly one competency has the idnumber. */
    public const RESOLVED = 'resolved';
    /** @var string Resolution: no competency has the idnumber. */
    public const UNKNOWN = 'unknown';
    /** @var string Resolution: several frameworks have a competency with the idnumber. */
    public const AMBIGUOUS = 'ambiguous';

    /** @var string Link result: the course module carries the competency. */
    public const LINKED = 'linked';
    /** @var string Link result: the competency is in a hidden framework. */
    public const HIDDEN = 'hidden';
    /** @var string Link result: the user may not manage course competencies. */
    public const NOPERMISSION = 'nopermission';
    /** @var string Link result: competencies are disabled on the site. */
    public const DISABLED = 'disabled';
    /** @var string Link result: core refused for another reason. */
    public const FAILED = 'failed';

    /**
     * Competencies of an item, in display order, with decoded objectives.
     *
     * @param int $itemid Item id.
     * @return \stdClass[] Records; objectives is a list of ['id' => ?, 'bloom' => ?, 'text' => ?].
     */
    public static function get(int $itemid): array {
        global $DB;
        $records = array_values($DB->get_records(self::TABLE, ['itemid' => $itemid], 'sortorder, id'));
        foreach ($records as $record) {
            $record->objectives = self::decode_objectives($record->objectives);
        }
        return $records;
    }

    /**
     * Replace the competency list of an item.
     *
     * Entries use the nugget format: 'competency' (idnumber), 'level' (1-5),
     * 'function' and optional 'objectives'. Entries without idnumber are ignored.
     *
     * @param int $itemid Item id.
     * @param array $entries Competency entries (arrays or objects).
     * @param int $userid Acting user, 0 for the current user.
     * @return \stdClass[] The stored competencies.
     * @throws \moodle_exception On the first invalid entry.
     */
    public static function set(int $itemid, array $entries, int $userid = 0): array {
        global $DB, $USER;
        item_manager::get($itemid);
        $clean = self::validate($entries);
        $userid = $userid ?: (int) $USER->id;
        $now = time();
        $created = $DB->get_records_menu(self::TABLE, ['itemid' => $itemid], '', 'competency, timecreated');

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(self::TABLE, ['itemid' => $itemid]);
        foreach ($clean as $sortorder => $entry) {
            $DB->insert_record(self::TABLE, (object) [
                'itemid' => $itemid,
                'competency' => $entry['competency'],
                'skilllevel' => $entry['level'],
                'didacticfunction' => $entry['function'],
                'objectives' => $entry['objectives'] ? json_encode($entry['objectives']) : null,
                'sortorder' => $sortorder,
                'usermodified' => $userid,
                'timecreated' => (int) ($created[$entry['competency']] ?? $now),
                'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
        return self::get($itemid);
    }

    /**
     * Remove all competencies of an item.
     *
     * @param int $itemid Item id.
     */
    public static function delete_for_item(int $itemid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['itemid' => $itemid]);
    }

    /**
     * Validate and normalise competency entries.
     *
     * @param array $entries Raw entries.
     * @return array<int,array{competency:string,level:int,function:string,objectives:array}>
     * @throws \moodle_exception On the first invalid entry.
     */
    public static function validate(array $entries): array {
        $clean = [];
        $seen = [];
        foreach ($entries as $entry) {
            $entry = (array) $entry;
            $idnumber = trim((string) ($entry['competency'] ?? ''));
            if ($idnumber === '') {
                continue;
            }
            if (\core_text::strlen($idnumber) > 100) {
                throw new \moodle_exception('error_competencyidnumber', 'local_nextpool', '', s($idnumber));
            }
            // Case-insensitive like the unique index on databases with case-insensitive collation (bug168).
            $key = \core_text::strtolower($idnumber);
            if (isset($seen[$key])) {
                throw new \moodle_exception('error_competencyduplicate', 'local_nextpool', '', s($idnumber));
            }
            $seen[$key] = true;
            $level = (int) ($entry['level'] ?? 0);
            if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
                throw new \moodle_exception('error_competencylevel', 'local_nextpool', '', s($idnumber));
            }
            $function = (string) ($entry['function'] ?? '');
            if (!in_array($function, self::FUNCTIONS, true)) {
                throw new \moodle_exception('error_competencyfunction', 'local_nextpool', '', s($idnumber));
            }
            $clean[$idnumber] = [
                'competency' => $idnumber,
                'level' => $level,
                'function' => $function,
                'objectives' => self::validate_objectives((array) ($entry['objectives'] ?? []), $idnumber),
            ];
        }
        return array_values($clean);
    }

    /**
     * Validate and normalise learning objectives.
     *
     * @param array $objectives Raw objectives with optional id, bloom and text.
     * @param string $idnumber Competency, for error messages.
     * @return array<int,array{id:?string,bloom:?string,text:?string}>
     * @throws \moodle_exception If a Bloom level is unknown.
     */
    private static function validate_objectives(array $objectives, string $idnumber): array {
        $clean = [];
        foreach ($objectives as $objective) {
            $objective = (array) $objective;
            $id = trim((string) ($objective['id'] ?? ''));
            $bloom = trim((string) ($objective['bloom'] ?? ''));
            $text = trim((string) ($objective['text'] ?? ''));
            if ($id === '' && $text === '') {
                continue;
            }
            if ($bloom !== '' && !in_array($bloom, self::BLOOM, true)) {
                throw new \moodle_exception('error_competencybloom', 'local_nextpool', '', (object) [
                    'competency' => s($idnumber),
                    'bloom' => s($bloom),
                ]);
            }
            $clean[] = [
                'id' => $id === '' ? null : $id,
                'bloom' => $bloom === '' ? null : $bloom,
                'text' => $text === '' ? null : $text,
            ];
        }
        return $clean;
    }

    /**
     * Decode stored objectives.
     *
     * @param string|null $json Stored JSON.
     * @return array
     */
    private static function decode_objectives(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Find the core_competency competency of an idnumber.
     *
     * An idnumber is unique within a framework only, so it resolves only if
     * exactly one framework has it.
     *
     * @param string $idnumber Competency idnumber.
     * @return array{0:string,1:?\stdClass} Resolution constant and the competency record if resolved.
     */
    public static function resolve(string $idnumber): array {
        return self::resolve_many([$idnumber])[$idnumber];
    }

    /**
     * Find the competencies of several idnumbers with one query (bug56).
     *
     * @param string[] $idnumbers Competency idnumbers.
     * @return array<string,array{0:string,1:?\stdClass}> Keyed by idnumber, as resolve() returns them.
     */
    public static function resolve_many(array $idnumbers): array {
        global $DB;
        $idnumbers = array_values(array_unique(array_map('strval', $idnumbers)));
        if (!$idnumbers) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED);
        $found = [];
        $fields = 'id, shortname, idnumber, competencyframeworkid';
        $records = $DB->get_records_select('competency', "idnumber $insql", $params, 'id', $fields);
        foreach ($records as $record) {
            $found[(string) $record->idnumber][] = $record;
        }
        $resolved = [];
        foreach ($idnumbers as $idnumber) {
            $matches = $found[$idnumber] ?? [];
            if (!$matches) {
                $resolved[$idnumber] = [self::UNKNOWN, null];
            } else if (count($matches) > 1) {
                $resolved[$idnumber] = [self::AMBIGUOUS, null];
            } else {
                $resolved[$idnumber] = [self::RESOLVED, $matches[0]];
            }
        }
        return $resolved;
    }

    /**
     * Competencies of visible frameworks whose idnumber or name contains the query, for the item form (bug52).
     *
     * The item form loads its choices through this search instead of putting every competency of the
     * site into each row. Placing refuses competencies of hidden frameworks, so they are not offered.
     * An idnumber that several frameworks share is offered once, as the menu did before.
     *
     * @param string $query Text the editor typed; empty returns the first competencies.
     * @param int $limit Most results.
     * @return array<int,array{idnumber:string,label:string}> Matches with an exact idnumber first.
     */
    public static function search(string $query, int $limit = self::SEARCH_LIMIT): array {
        global $DB;
        $query = trim($query);
        $where = "c.idnumber <> '' AND f.visible = 1";
        $params = [];
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $where .= ' AND (' . $DB->sql_like('c.idnumber', ':q1', false) . ' OR '
                . $DB->sql_like('c.shortname', ':q2', false) . ')';
            $params = ['q1' => $like, 'q2' => $like];
        }
        // Exact idnumber first, so a long list never hides the competency the editor typed in full.
        $exact = $query === '' ? '1' : 'CASE WHEN ' . $DB->sql_equal('c.idnumber', ':q3', false) . ' THEN 0 ELSE 1 END';
        if ($query !== '') {
            $params['q3'] = $query;
        }
        $records = $DB->get_records_sql(
            "SELECT c.id, c.idnumber, c.shortname, f.shortname AS frameworkname, $exact AS exactorder
               FROM {competency} c
               JOIN {competency_framework} f ON f.id = c.competencyframeworkid
              WHERE $where
           ORDER BY exactorder, f.shortname, c.sortorder, c.id",
            $params,
            0,
            // Some idnumbers repeat across frameworks; read a few more to fill the limit after merging them.
            $limit * 2
        );
        $results = [];
        foreach ($records as $record) {
            if (count($results) >= $limit) {
                break;
            }
            if (!isset($results[$record->idnumber])) {
                $results[$record->idnumber] = [
                    'idnumber' => (string) $record->idnumber,
                    'label' => self::get_option_label($record),
                ];
            }
        }
        return array_values($results);
    }

    /**
     * Label of a chosen idnumber in the item form (bug52).
     *
     * Also for competencies of hidden frameworks, which an item may already have.
     *
     * @param string $idnumber Competency idnumber.
     * @return string|null Label, or null if no single competency has the idnumber.
     */
    public static function get_label(string $idnumber): ?string {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT c.id, c.idnumber, c.shortname, f.shortname AS frameworkname
               FROM {competency} c
               JOIN {competency_framework} f ON f.id = c.competencyframeworkid
              WHERE c.idnumber = :idnumber",
            ['idnumber' => $idnumber],
            0,
            2
        );
        return count($records) === 1 ? self::get_option_label(reset($records)) : null;
    }

    /**
     * Name, idnumber and framework of a competency for a menu (HTML).
     *
     * @param \stdClass $record Record with shortname, idnumber and frameworkname.
     * @return string
     */
    private static function get_option_label(\stdClass $record): string {
        return get_string('competencyoption', 'local_nextpool', (object) [
            'name' => format_string($record->shortname),
            'idnumber' => s($record->idnumber),
            'framework' => format_string($record->frameworkname),
        ]);
    }

    /**
     * Competencies of an item whose idnumber does not resolve, for the editors' notice.
     *
     * @param int $itemid Item id.
     * @return string[] Idnumbers.
     */
    public static function get_unresolved(int $itemid): array {
        $unresolved = [];
        $records = self::get($itemid);
        $resolved = self::resolve_many(array_column($records, 'competency'));
        foreach ($records as $record) {
            [$resolution] = $resolved[$record->competency];
            if ($resolution !== self::RESOLVED) {
                $unresolved[] = $record->competency;
            }
        }
        return $unresolved;
    }

    /**
     * Link every resolvable competency of an item with a course module (F05, F14).
     *
     * Missing course competencies are added first, as core requires. A competency
     * that cannot be linked is skipped and reported; placing never fails because of it.
     *
     * @param int $itemid Item id.
     * @param int $cmid Course module id.
     * @return array[] One result per entry: competency, level, function, competencyid (0 if not linked), result.
     */
    public static function link_to_cm(int $itemid, int $cmid): array {
        $results = [];
        $enabled = \core_competency\api::is_enabled();
        $records = self::get($itemid);
        $resolved = self::resolve_many(array_column($records, 'competency'));
        foreach ($records as $record) {
            [$resolution, $competency] = $resolved[$record->competency];
            $result = $resolution;
            if ($resolution === self::RESOLVED) {
                $result = $enabled ? self::link_one($cmid, $competency) : self::DISABLED;
            }
            $results[] = [
                'competency' => $record->competency,
                'level' => (int) $record->skilllevel,
                'function' => $record->didacticfunction,
                'competencyid' => $result === self::LINKED ? (int) $competency->id : 0,
                'result' => $result,
            ];
        }
        return $results;
    }

    /**
     * Link one competency with a course module through core.
     *
     * @param int $cmid Course module id.
     * @param \stdClass $competency Competency record.
     * @return string Link result constant.
     */
    private static function link_one(int $cmid, \stdClass $competency): string {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        if (!has_capability('moodle/competency:coursecompetencymanage', \context_module::instance($cmid))) {
            return self::NOPERMISSION;
        }
        $framework = new \core_competency\competency_framework((int) $competency->competencyframeworkid);
        if (!$framework->get('visible')) {
            return self::HIDDEN;
        }
        try {
            \core_competency\api::add_competency_to_course((int) $cm->course, (int) $competency->id);
            \core_competency\api::add_competency_to_course_module($cm, (int) $competency->id);
        } catch (\required_capability_exception $e) {
            return self::NOPERMISSION;
        } catch (\Exception $e) {
            debugging('Linking competency ' . $competency->id . ' to cm ' . $cmid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return self::FAILED;
        }
        return self::LINKED;
    }

    /**
     * Competency data for the placement events, without linking anything.
     *
     * competencyid is the competency the course module carries for this entry, or 0 if it carries none or
     * several with this idnumber (from different frameworks); competencyids lists all of them (bug57).
     *
     * @param int $itemid Item id.
     * @param int $cmid Course module id.
     * @return array[] competency, level, function, competencyid, competencyids.
     */
    public static function get_event_data(int $itemid, int $cmid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            'SELECT mc.id, c.idnumber, c.id AS competencyid
               FROM {competency_modulecomp} mc
               JOIN {competency} c ON c.id = mc.competencyid
              WHERE mc.cmid = :cmid
           ORDER BY c.id',
            ['cmid' => $cmid]
        );
        $linked = [];
        foreach ($rows as $row) {
            $linked[(string) $row->idnumber][] = (int) $row->competencyid;
        }
        $data = [];
        foreach (self::get($itemid) as $record) {
            $ids = $linked[$record->competency] ?? [];
            $data[] = [
                'competency' => $record->competency,
                'level' => (int) $record->skilllevel,
                'function' => $record->didacticfunction,
                'competencyid' => count($ids) === 1 ? $ids[0] : 0,
                'competencyids' => $ids,
            ];
        }
        return $data;
    }
}
