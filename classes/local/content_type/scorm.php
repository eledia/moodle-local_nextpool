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

/**
 * Content type 'scorm' as a core SCORM package (mod_scorm), updated in place (task25a).
 *
 * The version snapshot (.zip) becomes the package of the activity. An update
 * replaces the package and parses it again; core keeps every SCO whose
 * identifier is still in the manifest, together with its tracks, and deletes
 * SCOs that are gone - with all their tracks. check_update() therefore refuses
 * when a SCO with learner data would disappear, when the package standard
 * changes, and when attempts with saved progress are unfinished (q20).
 * Findings and core references: docs/13-spike-inplace.md §1.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm extends base {
    /** @var string[] Extensions of AICC files, as in mod/scorm/datamodels/aicclib.php. */
    private const AICC_EXTENSIONS = ['crs', 'des', 'au', 'cst', 'ort', 'pre', 'cmp'];

    #[\Override]
    public function supports_preview(): bool {
        $hook = new \local_nextpool\hook\collect_preview_providers();
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return isset($hook->get_providers()['scorm']);
    }

    #[\Override]
    public function get_modname(): string {
        return 'scorm';
    }

    #[\Override]
    public function get_file_extensions(): array {
        return ['zip'];
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_scorm', 'local_nextpool');
    }

    /**
     * Only a package core can read becomes a version: the rule of read_manifest() (bug205).
     *
     * @param string $path Local path of the file.
     * @throws \moodle_exception If the file is no SCORM or AICC package.
     */
    #[\Override]
    public function validate_package(string $path): void {
        if (!self::read_manifest_of(self::open_zip($path))['valid']) {
            $this->refuse_file();
        }
    }

    #[\Override]
    public function read_upload_metadata(string $path, string $filename): array {
        $manifest = self::read_manifest_of(self::open_zip($path));
        return ['title' => $manifest['title'] ?? pathinfo($filename, PATHINFO_FILENAME), 'standard' => $manifest['version']];
    }

    #[\Override]
    public function get_course_content_hash(\cm_info $cm): ?string {
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            'mod_scorm',
            'package',
            0,
            'id ASC',
            false
        );
        return $files ? reset($files)->get_contenthash() : null;
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $draftitemid = file_get_unused_draft_itemid();
        $snapshot = $this->get_snapshot($version);
        $attribution = $this->get_file_attribution($item);
        $attribution['source'] = serialize((object) ['source' => $attribution['source']]);
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $snapshot->get_filename(),
        ] + $attribution, $snapshot);

        // Site defaults of mod_scorm, with core's own defaults for settings a site never saved (bug63, Q-27).
        $config = (object) ((array) get_config('scorm') + [
            'framewidth' => 100, 'frameheight' => 500, 'skipview' => 0, 'hidebrowse' => 0,
            'displaycoursestructure' => 0, 'hidetoc' => 0, 'nav' => SCORM_NAV_UNDER_CONTENT, 'navpositionleft' => -100,
            'navpositiontop' => -100, 'displayattemptstatus' => 1, 'maxgrade' => 100, 'maxattempt' => 0,
            'whatgrade' => HIGHESTATTEMPT, 'forcenewattempt' => 0, 'lastattemptlock' => 0, 'forcecompleted' => 0,
            'masteryoverride' => 1, 'auto' => 0,
        ]);
        $moduleinfo = create_module((object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'cmidnumber' => '',
            'scormtype' => SCORM_TYPE_LOCAL,
            'packagefile' => $draftitemid,
            'packageurl' => '',
            // Updates come from the catalogue, never from the package itself.
            'updatefreq' => SCORM_UPDATE_NEVER,
            'popup' => 0,
            'width' => $config->framewidth,
            'height' => $config->frameheight,
            'skipview' => $config->skipview,
            'hidebrowse' => $config->hidebrowse,
            'displaycoursestructure' => $config->displaycoursestructure,
            'hidetoc' => $config->hidetoc,
            'nav' => $config->nav,
            'navpositionleft' => $config->navpositionleft,
            'navpositiontop' => $config->navpositiontop,
            'displayattemptstatus' => $config->displayattemptstatus,
            'timeopen' => 0,
            'timeclose' => 0,
            'grademethod' => GRADESCOES,
            'maxgrade' => $config->maxgrade,
            'maxattempt' => $config->maxattempt,
            'whatgrade' => $config->whatgrade,
            'forcenewattempt' => $config->forcenewattempt,
            'lastattemptlock' => $config->lastattemptlock,
            'forcecompleted' => $config->forcecompleted,
            'masteryoverride' => $config->masteryoverride,
            'auto' => $config->auto,
        ]);
        return (int) $moduleinfo->coursemodule;
    }

    #[\Override]
    public function check_update(\cm_info $cm, \stdClass $item, \stdClass $version): ?string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], 'id, scormtype, version', MUST_EXIST);
        if ($scorm->scormtype !== SCORM_TYPE_LOCAL) {
            return get_string('noinplace_scormtype', 'local_nextpool');
        }
        $manifest = self::read_manifest($this->get_snapshot($version));
        if (!$manifest['valid']) {
            // Core would replace the package and fail to parse it, leaving a broken activity (bug58).
            return get_string('noinplace_scorminvalid', 'local_nextpool');
        }
        if ($scorm->version !== '' && $manifest['version'] !== $scorm->version) {
            return get_string('noinplace_scormversion', 'local_nextpool', (object) [
                'from' => s($scorm->version),
                'to' => s($manifest['version']),
            ]);
        }

        // Core deletes the tracks of every SCO that is no longer in the manifest.
        $tracked = $DB->get_fieldset_sql(
            'SELECT DISTINCT s.identifier
               FROM {scorm_scoes} s
               JOIN {scorm_scoes_value} v ON v.scoid = s.id
              WHERE s.scorm = :scormid',
            ['scormid' => $scorm->id]
        );
        $lost = array_diff($tracked, $manifest['identifiers']);
        if ($lost) {
            return get_string('noinplace_scormlost', 'local_nextpool', s(implode(', ', $lost)));
        }

        if (self::has_unfinished_progress((int) $scorm->id)) {
            return get_string('noinplace_scormprogress', 'local_nextpool');
        }
        return null;
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);
        $snapshot = $this->get_snapshot($version);
        $fs = get_file_storage();

        $transaction = $DB->start_delegated_transaction();
        $fs->delete_area_files($context->id, 'mod_scorm', 'package', 0);
        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'mod_scorm',
            'filearea' => 'package',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $snapshot->get_filename(),
        ] + $this->get_file_attribution($item), $snapshot);
        $DB->update_record('scorm', (object) [
            'id' => $cm->instance,
            'reference' => $snapshot->get_filename(),
            'timemodified' => time(),
        ]);
        $transaction->allow_commit();

        // Unpacking is large and not transactional; the update engine holds the placement lock meanwhile.
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $scorm->cmid = $cm->id;
        scorm_parse($scorm, true);
        $scorm = $DB->get_record('scorm', ['id' => $cm->instance], '*', MUST_EXIST);
        $scorm->cmidnumber = $cm->idnumber;
        scorm_update_grades($scorm);

        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
    }

    /**
     * Standard and SCO identifiers of a package, as core will read them.
     *
     * A package is valid when it is a ZIP with a readable manifest that names at least one organization or
     * item, or, without a manifest, when it has an AICC file (same file types as core's AICC parser).
     *
     * @param \stored_file $package Package (.zip).
     * @return array Keys valid (bool), version (SCORM_1.2, SCORM_1.3 or AICC) and identifiers (organizations and
     *     items in the manifest; empty for AICC).
     */
    public static function read_manifest(\stored_file $package): array {
        return self::read_manifest_of(self::open_package($package));
    }

    /**
     * Manifest of an open package, see read_manifest().
     *
     * @param \ZipArchive|null $zip Open package (closed here), null if the file is not a ZIP.
     * @return array As read_manifest().
     */
    private static function read_manifest_of(?\ZipArchive $zip): array {
        if ($zip === null) {
            return ['valid' => false, 'version' => '', 'identifiers' => []];
        }
        try {
            $xml = self::read_zip_entry($zip, 'imsmanifest.xml', 1048576);
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }
        $aicc = false;
        for ($i = 0; $xml === false && !$aicc && $i < $zip->numFiles; $i++) {
            $extension = strtolower(pathinfo((string) $zip->getNameIndex($i), PATHINFO_EXTENSION));
            $aicc = in_array($extension, self::AICC_EXTENSIONS, true);
        }
        $zip->close();
        if ($xml === false) {
            return ['valid' => $aicc, 'version' => 'AICC', 'identifiers' => []];
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return ['valid' => false, 'version' => 'SCORM_1.2', 'identifiers' => []];
        }
        $identifiers = [];
        foreach (['organization', 'item'] as $tag) {
            foreach ($dom->getElementsByTagNameNS('*', $tag) as $node) {
                if ($node->hasAttribute('identifier')) {
                    $identifiers[] = $node->getAttribute('identifier');
                }
            }
        }
        // Same rule as core (mod/scorm/datamodels/scormlib.php): 1.2 unless the schema version names 2004/1.3.
        $schema = '';
        foreach ($dom->getElementsByTagNameNS('*', 'schemaversion') as $node) {
            $schema = trim($node->textContent);
            break;
        }
        $is2004 = $schema !== '' && (stripos($schema, '2004') !== false || stripos($schema, '1.3') !== false);
        return [
            'valid' => !empty($identifiers),
            'version' => $is2004 ? 'SCORM_1.3' : 'SCORM_1.2',
            'identifiers' => array_values(array_unique($identifiers)),
            'title' => trim($dom->getElementsByTagNameNS('*', 'title')->item(0)?->textContent ?? ''),
        ];
    }

    /**
     * Whether a SCORM activity has attempts with saved progress that are not completed (q20).
     *
     * @param int $scormid SCORM instance id.
     * @return bool
     */
    public static function has_unfinished_progress(int $scormid): bool {
        global $DB;
        $value = $DB->sql_compare_text('v.value');
        $status = $DB->sql_compare_text('d.value', 20);
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {scorm_scoes_value} v
               JOIN {scorm_element} e ON e.id = v.elementid
               JOIN {scorm_attempt} a ON a.id = v.attemptid
              WHERE a.scormid = :scormid
                AND e.element = :suspend
                AND $value <> :empty
                AND NOT EXISTS (
                    SELECT 1
                      FROM {scorm_scoes_value} d
                      JOIN {scorm_element} de ON de.id = d.elementid
                     WHERE d.attemptid = v.attemptid
                       AND d.scoid = v.scoid
                       AND de.element IN (:status12, :status2004)
                       AND $status IN (:completed, :passed)
                )",
            [
                'scormid' => $scormid,
                'suspend' => 'cmi.suspend_data',
                'empty' => '',
                'status12' => 'cmi.core.lesson_status',
                'status2004' => 'cmi.completion_status',
                'completed' => 'completed',
                'passed' => 'passed',
            ]
        );
    }
}
