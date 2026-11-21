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
 * Keeps stored files of Nextpool out of core's H5P player (/h5p/embed.php, bug234, bug238).
 *
 * Core's H5P player checks no access for files in the system context and, without forced login, none in a course
 * category context either. It also treats a file without an owner as trusted ("owned by the system"): it shows such
 * an .h5p file to anybody who knows its address and installs the libraries in it for the whole site. Every file
 * Nextpool stores for its own use (version snapshots, files of the storage location "Moodle files", unpacked xAPI
 * packages) therefore gets the guest user as owner: core_h5p\helper::can_deploy_package() then refuses, because core
 * never grants a write capability to the guest user. Placing copies the content with the publisher as owner (q18).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class h5p_guard {
    /** @var int H5P contents deleted per query in secure_area(). */
    private const BATCH = 100;

    /**
     * Owner of files that core must never deploy as H5P content: the guest user.
     *
     * @return int User id.
     */
    public static function get_owner(): int {
        global $CFG;
        return (int) $CFG->siteguest;
    }

    /**
     * Protect files of an area stored without the guarded owner (upgrade steps of bug234 and bug238).
     *
     * First the owner of every file of the area (not the directory entries) becomes the guest user, in one statement,
     * so core deploys nothing new from them. Then every H5P content core already deployed from a file of the area is
     * deleted through core's API: core would keep showing it without any check, because it only checks the owner
     * before a first deployment. Libraries installed that way stay; they may be used by other content.
     *
     * Both parts look at all files of the area, not only at the ones changed now, so the method can run again after
     * an interruption at any point and finishes the job; a second run changes nothing.
     *
     * @param string $component Component of the file area.
     * @param string $filearea File area.
     * @param int|null $contextid Only this context; null for the area in every context.
     * @return int Number of deployed H5P contents removed.
     */
    public static function secure_area(string $component, string $filearea, ?int $contextid = null): int {
        global $DB;
        $params = ['component' => $component, 'filearea' => $filearea, 'directory' => '.'];
        $files = 'component = :component AND filearea = :filearea AND filename <> :directory';
        if ($contextid !== null) {
            $files .= ' AND contextid = :contextid';
            $params['contextid'] = $contextid;
        }

        $owner = self::get_owner();
        $DB->set_field_select(
            'files',
            'userid',
            $owner,
            $files . ' AND (userid IS NULL OR userid <> :owner)',
            $params + ['owner' => $owner]
        );

        // Ids only and a few at a time: H5P records carry the whole content.json, and a recordset would be buffered in
        // full by some drivers. Each round asks again, so deleted contents drop out of the next batch.
        $sql = "SELECT h.id
                  FROM {h5p} h
                 WHERE h.pathnamehash IN (SELECT pathnamehash FROM {files} WHERE $files)
              ORDER BY h.id";
        $factory = new \core_h5p\factory();
        $removed = 0;
        $done = [];
        while ($ids = array_keys($DB->get_records_sql($sql, $params, 0, self::BATCH))) {
            foreach ($ids as $id) {
                if (isset($done[$id])) {
                    throw new \coding_exception("H5P content $id could not be deleted");
                }
                $done[$id] = true;
                if ($content = $DB->get_record('h5p', ['id' => $id])) {
                    \core_h5p\api::delete_content($content, $factory);
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /**
     * Report H5P contents an upgrade step removed: warning on the upgrade page or CLI and a notice in the upgrade log.
     *
     * Upgrade steps use no language strings.
     *
     * @param string $plugin Component whose upgrade step ran.
     * @param int $removed Number of removed contents, see secure_area().
     * @param string $what What the files are, e.g. "Nextpool version files".
     * @param string $issue Issue, e.g. "bug234".
     */
    public static function report_removed(string $plugin, int $removed, string $what, string $issue): void {
        global $OUTPUT;
        if ($removed <= 0) {
            return;
        }
        // Libraries are not uninstalled: other content may use them.
        $info = "Removed $removed H5P contents that core had deployed from $what ($issue).";
        $details = 'Anybody who knew the address of these files could deploy them through /h5p/embed.php, which '
            . 'installs the H5P libraries in the package. Check the H5P content types installed on this site '
            . '(Site administration > H5P > Manage H5P content types, /h5p/libraries.php) and remove or update '
            . 'libraries you do not expect.';
        upgrade_log(UPGRADE_LOG_NOTICE, $plugin, $info, $details);
        echo $OUTPUT->notification($info . ' ' . $details, \core\output\notification::NOTIFY_WARNING);
    }
}
