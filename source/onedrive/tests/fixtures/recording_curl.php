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

namespace nextpoolsource_onedrive\tests;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Canned wire responses below the real Moodle curl API (task148).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recording_curl extends \curl {
    /** @var array Recorded outgoing requests. */
    public array $requests = [];
    /** @var array Canned status, body, headers and optional error per request. */
    public array $responses = [];

    #[\Override]
    protected function request($url, $options = []) {
        if (!$this->responses) {
            throw new \coding_exception('Unexpected transport request in graph_client_test: ' . $url);
        }
        $response = array_shift($this->responses);
        $file = $options['CURLOPT_FILE'] ?? $options['file'] ?? null;
        $this->requests[] = ['url' => $url, 'options' => $options, 'headers' => $this->header,
            'download' => is_resource($file)];
        $this->info = ['http_code' => $response['status']];
        $this->response = $response['headers'] ?? [];
        $this->error = $response['error'] ?? '';
        if ($this->error !== '') {
            return false;
        }
        if (is_resource($file)) {
            fwrite($file, $response['body']);
            return true;
        }
        return $response['body'];
    }
}
