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

namespace local_nextpool\check;

use core\check\check;
use core\check\result;
use local_nextpool\local\source\base;
use local_nextpool\local\source\manager;

/**
 * System status check: every enabled storage location is reachable (F12).
 *
 * Shown under Site administration > Reports > System status.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sources extends check {
    #[\Override]
    public function get_action_link(): ?\action_link {
        return new \action_link(new \moodle_url('/local/nextpool/sources.php'), get_string('sources', 'local_nextpool'));
    }

    #[\Override]
    public function get_result(): result {
        $records = manager::get_records(true);
        if (!$records) {
            return new result(result::NA, get_string('checksources_none', 'local_nextpool'));
        }
        $failed = 0;
        $warned = 0;
        $rows = [];
        foreach ($records as $record) {
            $warnings = [];
            try {
                $source = $this->load_source((int) $record->id);
                // Warnings do not depend on the connection, e.g. an app secret that expires soon (task73).
                $warnings = $source->get_status_warnings();
                $source->test_connection();
                $state = get_string('testconnection_ok', 'local_nextpool');
            } catch (\moodle_exception $e) {
                $failed++;
                $state = get_string('testconnection_failed', 'local_nextpool', $e->getMessage());
            }
            $warned += $warnings ? 1 : 0;
            // The state is a language string and may hold an exception message, which escapes its own values (bug121).
            $text = trim($state . ' ' . s(implode(' ', $warnings)));
            $rows[] = \html_writer::tag('li', format_string($record->name) . ': ' . $text);
        }
        $details = \html_writer::tag('ul', implode('', $rows));
        if ($failed === 0 && $warned > 0) {
            $key = $warned === 1 ? 'checksources_warning_one' : 'checksources_warning';
            return new result(result::WARNING, get_string($key, 'local_nextpool', $warned), $details);
        }
        if ($failed === 0) {
            $key = count($records) === 1 ? 'checksources_ok_one' : 'checksources_ok';
            return new result(result::OK, get_string($key, 'local_nextpool', count($records)), $details);
        }
        $status = $failed === count($records) ? result::CRITICAL : result::ERROR;
        $key = $failed === 1 ? 'checksources_failed_one' : 'checksources_failed';
        return new result($status, get_string($key, 'local_nextpool', $failed), $details);
    }

    /**
     * Load an enabled storage location with its decrypted configuration.
     *
     * @param int $id Storage location id.
     * @return base
     */
    protected function load_source(int $id): base {
        return manager::get($id);
    }
}
