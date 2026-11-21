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

namespace nextpoolsource_nextcloud\tests;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Core curl that records requests instead of sending them.
 *
 * Only the protected request() is replaced, so webdav_client goes through the
 * public curl API exactly as against a real server.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_curl extends \curl {
    /** @var array[] Recorded requests: url, options, headers, tofile (body written into an open CURLOPT_FILE). */
    public array $requests = [];
    /** @var int HTTP status of the canned responses. */
    public int $status = 207;
    /** @var string Body of the canned responses. */
    public string $body = '';

    #[\Override]
    protected function request($url, $options = []) {
        // Option names are normalised only in the replaced request(): download_one() passes the handle as 'file'.
        $file = $options['CURLOPT_FILE'] ?? $options['file'] ?? null;
        $this->requests[] = ['url' => $url, 'options' => $options, 'headers' => $this->header, 'tofile' => is_resource($file)];
        $this->info = ['http_code' => $this->status];
        if (is_resource($file)) {
            // Like curl_exec() with CURLOPT_FILE: the body goes into the open file, the result is true.
            fwrite($file, $this->body);
            return true;
        }
        return $this->body;
    }
}
