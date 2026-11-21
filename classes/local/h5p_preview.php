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

use local_nextpool\local\content_type\h5p;
use local_nextpool\local\content_type\page;

/**
 * Anonymous H5P preview cache and public Core rendering adapter (F16).
 *
 * No persisted file has the deployed H5P's synthetic original hash. Core's native media/export routes
 * therefore cannot bypass the catalogue check, even with a known content id or as the guest account.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_preview {
    /** @var string Private, content-only operational package cache. */
    public const FILEAREA = 'previewh5p';
    /** @var string Permission-checked virtual content directory. */
    public const MEDIA_AREA = 'previewh5pmedia';

    /**
     * Old Core file callbacks serve unpacked content publicly. Never deploy a preview on those versions.
     *
     * @return bool Whether the required native original-file access guards are available.
     */
    public static function supported(): bool {
        return method_exists(\core_h5p\api::class, 'can_access_content')
            && method_exists(\core_h5p\api::class, 'can_access_export');
    }

    /**
     * The single anonymous context, shared across courses and viewers.
     *
     * @return \context_user Guest context.
     */
    public static function context(): \context_user {
        return \context_user::instance(h5p_guard::get_owner());
    }

    /**
     * Server-only original identity, deliberately absent from the files table.
     *
     * @param int $versionid Version id.
     * @return string HMAC pathname hash.
     */
    public static function key(int $versionid): string {
        $secret = get_config('local_nextpool', 'h5ppreviewsecret');
        if (!$secret) {
            $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('h5ppreviewsecret', 10);
            if (!$lock) {
                throw new \moodle_exception('previewunavailable', 'local_nextpool');
            }
            try {
                $secret = get_config('local_nextpool', 'h5ppreviewsecret');
                if (!$secret) {
                    $secret = bin2hex(random_bytes(32));
                    set_config('h5ppreviewsecret', $secret, 'local_nextpool');
                }
            } finally {
                $lock->release();
            }
        }
        return hash_hmac('sha1', 'local_nextpool/h5p-preview/' . $versionid, $secret);
    }

    /**
     * Build or reuse the content-only package. The real stored file always keeps the guest guard.
     *
     * @param \stdClass $version Published version.
     * @return \stored_file Anonymous cache package.
     */
    public static function package(\stdClass $version): \stored_file {
        if (!static::supported()) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $snapshot = version_manager::get_file((int) $version->id);
        if (!$snapshot) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('previewh5p_' . $version->id, 10);
        if (!$lock) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        try {
            $fs = get_file_storage();
            $source = 'nextpool-preview:' . $snapshot->get_contenthash();
            $files = $fs->get_area_files(self::context()->id, 'local_nextpool', self::FILEAREA, $version->id, 'id', false);
            foreach ($files as $file) {
                if ($file->get_source() === $source && (int) $file->get_userid() === h5p_guard::get_owner()) {
                    return $file;
                }
            }
            self::delete((int) $version->id);
            $path = h5p::create_content_only_package($snapshot);
            if ($path === null) {
                throw new \moodle_exception('previewunavailable', 'local_nextpool');
            }
            return $fs->create_file_from_pathname([
                'contextid' => self::context()->id, 'component' => 'local_nextpool', 'filearea' => self::FILEAREA,
                'itemid' => $version->id, 'filepath' => '/', 'filename' => bin2hex(random_bytes(16)) . '.h5p',
                'userid' => h5p_guard::get_owner(), 'source' => $source,
            ], $path);
        } finally {
            $lock->release();
        }
    }

    /**
     * A non-persisted input for Core: id zero prevents a Core mutator from changing the protected real file.
     *
     * @param \stored_file $file Actual immutable input.
     * @param string $pathnamehash Synthetic original hash.
     * @param int|null $userid NULL only for our content-only cache; publisher id for library-only import.
     * @param int|null $contextid Optional capability context of the publisher.
     * @return \stored_file In-memory projection, never inserted into files.
     */
    private static function project(\stored_file $file, string $pathnamehash, ?int $userid, ?int $contextid = null): \stored_file {
        global $DB;
        $record = $DB->get_record('files', ['id' => $file->get_id()], '*', MUST_EXIST);
        $record->id = 0;
        $record->pathnamehash = $pathnamehash;
        $record->userid = $userid;
        $record->contextid = $contextid ?? $record->contextid;
        return new \stored_file(get_file_storage(), $record);
    }

    /**
     * Install missing dependencies only on the still-authorised publisher's explicit publication decision.
     *
     * @param \stdClass $version Published version.
     */
    private static function ensure_libraries(\stdClass $version): void {
        global $DB;
        $snapshot = version_manager::get_file((int) $version->id);
        $json = $snapshot ? h5p::read_h5p_json($snapshot) : null;
        if ($json === null) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $required = $json;
        $required['preloadedDependencies'] = array_merge($json['preloadedDependencies'] ?? [], $json['dynamicDependencies'] ?? []);
        if (!h5p::get_missing_libraries($required)) {
            return;
        }
        $publisherid = (int) ($version->publishedby ?: $version->createdby);
        $publisher = $DB->get_record('user', ['id' => $publisherid, 'deleted' => 0, 'suspended' => 0]);
        $system = \context_system::instance();
        if (
            !h5p::allows_library_updates($version) || !$publisher || isguestuser($publisher)
                || !has_capability('moodle/h5p:updatelibraries', $system, $publisher)
        ) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $input = self::project($snapshot, hash('sha1', self::key((int) $version->id) . '/libraries'), $publisherid, $system->id);
        // Explicitly authorised library-only import. Never deploy the original snapshot as content.
        \core_h5p\helper::save_h5p(new \core_h5p\factory(), $input, new \stdClass(), false, true);
        if (h5p::get_missing_libraries($required)) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
    }

    /**
     * Resolve the exact current published version and check the viewer's course rights on every request.
     *
     * @param int $versionid Version.
     * @param int $courseid Course.
     * @return \stdClass|null Authorised version.
     */
    private static function authorised_version(int $versionid, int $courseid): ?\stdClass {
        global $DB;
        $version = $DB->get_record(version_manager::TABLE, ['id' => $versionid, 'status' => status::PUBLISHED]);
        $item = $version ? $DB->get_record(item_manager::TABLE, ['id' => $version->itemid, 'type' => 'h5p']) : null;
        return $item && (int) $item->currentversionid === $versionid && access::can_preview($item, $courseid) ? $version : null;
    }

    /**
     * Deploy anonymous operational content and prepare sanitised settings before serialisation.
     *
     * @param int $versionid Current published version.
     * @param int $courseid Course access context.
     * @return array Core id, embed type and safe integration settings.
     */
    public static function prepare(int $versionid, int $courseid): array {
        if (!static::supported() || !($version = self::authorised_version($versionid, $courseid))) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_nextpool')->get_lock('previewh5p_deploy_' . $versionid, 10);
        if (!$lock) {
            throw new \moodle_exception('previewunavailable', 'local_nextpool');
        }
        try {
            self::ensure_libraries($version);
            $file = self::package($version);
            $factory = new \core_h5p\factory();
            $h5p = \core_h5p\api::get_content_from_pathnamehash(self::key($versionid));
            if ($h5p && $h5p->contenthash !== $file->get_contenthash()) {
                \core_h5p\api::delete_content($h5p, $factory);
                $h5p = null;
            }
            if (!$h5p) {
                $input = self::project($file, self::key($versionid), null);
                // The trusted NULL-owner object is content-only. Restrict Core to existing libraries in addition.
                $id = \core_h5p\helper::save_h5p($factory, $input, (object) ['export' => 0, 'embed' => 0, 'copyright' => 1], true);
                if (!$id) {
                    throw new \moodle_exception('previewunavailable', 'local_nextpool');
                }
                $h5p = \core_h5p\api::get_content_from_pathnamehash(self::key($versionid));
            }
            if (!$h5p || $h5p->contenthash !== $file->get_contenthash()) {
                throw new \moodle_exception('previewunavailable', 'local_nextpool');
            }
            $core = $factory->get_core();
            $content = $core->loadContent($h5p->id);
            if (!\core_h5p\api::is_library_enabled((object) ['id' => $h5p->mainlibraryid])) {
                throw new \moodle_exception('previewunavailable', 'local_nextpool');
            }
            $embedtype = \core_h5p\core::determineEmbedType($content['embedType'], $content['library']['embedTypes']);
            return ['h5pid' => (int) $h5p->id, 'embedtype' => $embedtype,
                'settings' => self::settings($core, $content, $embedtype, $versionid, $courseid, (int) $version->itemid)];
        } finally {
            $lock->release();
        }
    }

    /**
     * Public Core equivalents of player asset preparation, without its user/state serialisation or viewed event.
     *
     * @param \core_h5p\core $core Core engine.
     * @param array $content Core content.
     * @param string $embedtype Core embed type.
     * @param int $versionid Version.
     * @param int $courseid Course.
     * @param int $itemid Authoritative catalogue item.
     * @return array Sanitised H5PIntegration data.
     */
    private static function settings(
        \core_h5p\core $core,
        array $content,
        string $embedtype,
        int $versionid,
        int $courseid,
        int $itemid
    ): array {
        global $PAGE, $CFG;
        $settings = \core_h5p\helper::get_core_assets('');
        $settings['user'] = [];
        $settings['moodleComponent'] = '';
        $settings['postUserStatistics'] = false;
        $settings['reportingIsEnabled'] = false;
        $settings['saveFreq'] = false;
        $settings['ajax']['contentUserData'] = '';
        $settings['moodleLibraryPaths'] = $core->get_dependency_roots($content['id']);
        $renderer = $PAGE->get_renderer('core_h5p');
        $params = json_decode($core->filterParameters($content));
        $renderer->h5p_alter_filtered_parameters(
            $params,
            $content['library']['name'],
            $content['library']['majorVersion'],
            $content['library']['minorVersion']
        );
        $dependencies = $core->loadContentDependencies($content['id'], 'preloaded');
        $files = $core->getDependenciesFiles($dependencies);
        $renderer->h5p_alter_scripts($files['scripts'], $dependencies, $embedtype);
        $renderer->h5p_alter_styles($files['styles'], $dependencies, $embedtype);
        $cid = 'cid-' . $content['id'];
        $display = $core->getDisplayOptionsForView($content['disable'] ?? \core_h5p\core::DISABLE_NONE, $content['id']);
        $display[\core_h5p\core::DISPLAY_OPTION_DOWNLOAD] = false;
        $display[\core_h5p\core::DISPLAY_OPTION_EMBED] = false;
        $settings['contents'][$cid] = [
            'library' => \core_h5p\core::libraryToString($content['library']), 'fullScreen' => $content['library']['fullscreen'],
            'exportUrl' => '', 'embedCode' => '', 'resizeCode' => '', 'title' => $content['slug'],
            'displayOptions' => $display, 'metadata' => $content['metadata'], 'jsonContent' => json_encode($params),
            'contentUrl' => self::media_url($versionid, $courseid)->out(false),
            'url' => (new \moodle_url('/local/nextpool/preview_h5p.php', compact('itemid', 'versionid', 'courseid')))->out(false),
            'contentUserData' => [0 => ['state' => '{}']],
        ];
        if ($embedtype === 'div') {
            $prefix = '/pluginfile.php/' . \context_system::instance()->id . '/core_h5p';
            foreach (['scripts' => 'loadedJs', 'styles' => 'loadedCss'] as $kind => $loaded) {
                foreach ($files[$kind] as $asset) {
                    $url = $asset->path . $asset->version;
                    $url = str_contains($url, '://') ? $url : $CFG->wwwroot . $prefix . $url;
                    $settings[$loaded][] = $url;
                    if ($kind === 'scripts') {
                        $PAGE->requires->js(new \moodle_url($url), true);
                    } else {
                        $PAGE->requires->css(new \moodle_url($url));
                    }
                }
            }
        } else {
            $settings['contents'][$cid]['scripts'] = $core->getAssetsUrls($files['scripts']);
            $settings['contents'][$cid]['styles'] = $core->getAssetsUrls($files['styles']);
        }
        return $settings;
    }

    /**
     * Register already-sanitised settings before the page header is printed.
     *
     * @param array $preview Prepared preview.
     */
    public static function add_assets_to_page(array $preview): void {
        global $PAGE;
        $PAGE->requires->js(new \moodle_url('/h5p/js/embed.js'));
        $PAGE->requires->js_call_amd('local_nextpool/h5p_preview_bridge', 'init');
        $PAGE->requires->data_for_js('H5PIntegration', $preview['settings'], true);
    }

    /**
     * Render the Core wrapper without creating an h5p_viewed event.
     *
     * @param array $preview Prepared preview.
     * @return string Core wrapper HTML.
     */
    public static function render(array $preview): string {
        global $OUTPUT;
        $template = $preview['embedtype'] === 'div' ? 'core_h5p/h5pdiv' : 'core_h5p/h5piframe';
        return $OUTPUT->render_from_template($template, ['h5pid' => $preview['h5pid']]);
    }

    /**
     * Media directory URL. The final virtual segment is the course id, not an exposed cache filename.
     *
     * @param int $versionid Version.
     * @param int $courseid Course.
     * @return \moodle_url
     */
    public static function media_url(int $versionid, int $courseid): \moodle_url {
        return \moodle_url::make_pluginfile_url(
            \context_system::instance()->id,
            'local_nextpool',
            self::MEDIA_AREA,
            $versionid,
            '/',
            (string) $courseid
        );
    }

    /**
     * Resolve a safe media file only through the authorised version's verified synthetic original.
     *
     * @param int $versionid Version.
     * @param int $courseid Course.
     * @param string $path Relative content path.
     * @return \stored_file|null
     */
    public static function find_media(int $versionid, int $courseid, string $path): ?\stored_file {
        if (
            !static::supported() || !($version = self::authorised_version($versionid, $courseid))
                || page::normalise_path($path) !== $path
        ) {
            return null;
        }
        $files = get_file_storage()->get_area_files(self::context()->id, 'local_nextpool', self::FILEAREA, $versionid, 'id', false);
        $snapshot = version_manager::get_file($versionid);
        $package = $files ? reset($files) : null;
        $h5p = \core_h5p\api::get_content_from_pathnamehash(self::key($versionid));
        if (
            !$package || !$snapshot || !$h5p || (int) $package->get_userid() !== h5p_guard::get_owner()
                || $package->get_source() !== 'nextpool-preview:' . $snapshot->get_contenthash()
                || $h5p->contenthash !== $package->get_contenthash()
        ) {
            return null;
        }
        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            'core_h5p',
            'content',
            $h5p->id,
            dirname($path) === '.' ? '/' : '/' . dirname($path) . '/',
            basename($path)
        );
        $allowed = array_merge(preview_assets::MIME_TYPES, ['image/svg+xml', 'text/vtt']);
        return $file && !$file->is_directory() && in_array($file->get_mimetype(), $allowed, true) ? $file : null;
    }

    /**
     * Delete both private operational packages and their otherwise unresolvable Core content.
     *
     * @param int $versionid Version.
     */
    public static function delete(int $versionid): void {
        global $DB;
        $fs = get_file_storage();
        $factory = new \core_h5p\factory();
        $h5p = \core_h5p\api::get_content_from_pathnamehash(self::key($versionid));
        if ($h5p) {
            \core_h5p\api::delete_content($h5p, $factory);
        }
        $contexts = $DB->get_fieldset('files', 'DISTINCT contextid', [
            'component' => 'local_nextpool', 'filearea' => self::FILEAREA, 'itemid' => $versionid,
        ]);
        foreach ($contexts as $contextid) {
            foreach ($fs->get_area_files($contextid, 'local_nextpool', self::FILEAREA, $versionid, 'id', false) as $file) {
                $legacy = \core_h5p\api::get_content_from_pathnamehash($file->get_pathnamehash());
                if ($legacy) {
                    \core_h5p\api::delete_content($legacy, $factory);
                }
            }
            $fs->delete_area_files($contextid, 'local_nextpool', self::FILEAREA, $versionid);
        }
    }

    /**
     * Remove the unsafe, old system-context preview cache during the unreleased 1591 upgrade.
     *
     * @return int Number of deployed legacy contents removed.
     */
    public static function purge_legacy(): int {
        $contextid = \context_system::instance()->id;
        $removed = h5p_guard::secure_area('local_nextpool', self::FILEAREA, $contextid);
        get_file_storage()->delete_area_files($contextid, 'local_nextpool', self::FILEAREA);
        return $removed;
    }
}
