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
 * Content type 'file' (video, PDF, document) as a core File resource (mod_resource).
 *
 * The version snapshot becomes the main file of the resource. An update
 * replaces that file and raises the resource revision, so browsers fetch the
 * new content; the course module, its completion and logs stay.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file extends base {
    #[\Override]
    public function get_modname(): string {
        return 'resource';
    }

    #[\Override]
    public function get_expected_file(): string {
        return get_string('expectedfile_file', 'local_nextpool');
    }

    #[\Override]
    public function get_course_content_hash(\cm_info $cm): ?string {
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id ASC',
            false
        );
        return $files ? reset($files)->get_contenthash() : null;
    }

    #[\Override]
    public function supports_preview(): bool {
        return true;
    }

    #[\Override]
    public function get_preview(\stdClass $item, \stdClass $version, int $courseid): \local_nextpool\local\preview_result {
        $file = $this->get_snapshot($version);
        $mime = $file->get_mimetype();
        if (!in_array($mime, \local_nextpool\local\preview_assets::MIME_TYPES, true)) {
            return new \local_nextpool\local\preview_result(message: get_string('previewformatunavailable', 'local_nextpool'));
        }
        return new \local_nextpool\local\preview_result(
            $mime === 'application/pdf' ? 'frame' : 'media',
            \local_nextpool\local\preview_assets::url((int) $version->id, $courseid, $file->get_filename())->out(false),
            mimetype: $mime
        );
    }

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        // Hand the snapshot to mod_resource through a draft area, as its own form would.
        $draftitemid = file_get_unused_draft_itemid();
        $snapshot = $this->get_snapshot($version);
        $attribution = $this->get_file_attribution($item);
        // Draft files carry their source serialised; saving the draft area restores the plain value.
        $attribution['source'] = serialize((object) ['source' => $attribution['source']]);
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $snapshot->get_filename(),
        ] + $attribution, $snapshot);

        $moduleinfo = (object) [
            'modulename' => $this->get_modname(),
            'course' => $course->id,
            'section' => $section,
            'visible' => 1,
            'name' => $item->title,
            'introeditor' => $this->get_intro_editor($item),
            'showdescription' => 0,
            'files' => $draftitemid,
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'printintro' => 1,
            'showsize' => 1,
            'showtype' => 1,
        ];
        $moduleinfo = create_module($moduleinfo);
        return (int) $moduleinfo->coursemodule;
    }

    #[\Override]
    public function update_in_place(\cm_info $cm, \stdClass $item, \stdClass $version): void {
        global $DB;
        if ($cm->modname !== $this->get_modname()) {
            throw new \moodle_exception('error_wrongmodule', 'local_nextpool');
        }
        $context = \context_module::instance($cm->id);
        $snapshot = $this->get_snapshot($version);
        $fs = get_file_storage();

        $transaction = $DB->start_delegated_transaction();
        $fs->delete_area_files($context->id, 'mod_resource', 'content', 0);
        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $snapshot->get_filename(),
            'sortorder' => 1,
        ] + $this->get_file_attribution($item), $snapshot);
        // The revision is part of the file URL; raising it stops browsers from showing the old file.
        $DB->execute('UPDATE {resource} SET revision = revision + 1, timemodified = :now WHERE id = :id', [
            'now' => time(),
            'id' => $cm->instance,
        ]);
        $transaction->allow_commit();

        rebuild_course_cache($cm->course, true);
        \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
    }
}
