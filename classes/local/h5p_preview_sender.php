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
 * Inline media delivery for the permission-checked H5P preview proxy.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5p_preview_sender {
    /**
     * Mandatory response headers, including a scriptless document sandbox for SVG.
     *
     * @param \stored_file $file Media file.
     * @return array Header names and values.
     */
    public static function headers(\stored_file $file): array {
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
        if ($file->get_mimetype() === 'image/svg+xml') {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
        }
        return $headers;
    }

    /**
     * Send only the already-authorised safe media file, without a public permission cache.
     *
     * @param \stored_file $file Resolved media.
     * @param array $options File API options.
     */
    public function send(\stored_file $file, array $options): void {
        foreach (self::headers($file) as $name => $value) {
            header($name . ': ' . $value);
        }
        send_stored_file($file, 0, 0, false, ['cacheability' => 'private'] + $options);
    }
}
