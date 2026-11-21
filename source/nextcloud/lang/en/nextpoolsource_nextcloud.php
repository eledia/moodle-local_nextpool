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

/**
 * Language strings for nextpoolsource_nextcloud.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apppassword'] = 'App password';
$string['apppassword_help'] = 'App password of a dedicated, non-administrator Nextcloud account (Nextcloud: Personal settings → Security → Devices & sessions). Leave empty when editing to keep the stored password.';
$string['apppassword_warning'] = '<strong>Security warning:</strong> App passwords are a supported fallback, but an OAuth 2 system account is preferred. An app password grants access to the whole account and is not rotated automatically. Use a dedicated account that only holds catalogue content, use HTTPS and rotate the password regularly.';
$string['authmode'] = 'Authentication';
$string['authmode_apppassword'] = 'App password (WebDAV Basic Auth, fallback)';
$string['authmode_oauth2'] = 'OAuth 2 system account (recommended)';
$string['error_apppasswordreenter'] = 'The base URL has changed. Enter the app password again, so it is not sent to another server.';
$string['error_serverurl'] = 'Enter the base URL of the Nextcloud server, starting with https://.';
$string['error_serverurlhttps'] = 'App passwords are sent with every request, so the base URL must start with https://.';
$string['issuerid'] = 'OAuth 2 service';
$string['issuerid_help'] = 'A Nextcloud OAuth 2 service from Site administration → Server → OAuth 2 services with a connected system account. Content is read and written with that account.';
$string['pluginname'] = 'Nextcloud';
$string['privacy:metadata:nextcloud'] = 'Catalogue content is read from and written to a Nextcloud server with a system account. No personal data of the acting user is sent.';
$string['privacy:metadata:nextcloud:content'] = 'Content files of catalogue items.';
$string['serverurl'] = 'Nextcloud base URL';
$string['serverurl_help'] = 'Enter the base URL of your Nextcloud site, for example https://cloud.example.org. Do not enter the WebDAV path here.';
$string['username'] = 'Nextcloud username';
$string['username_help'] = 'Use the user ID shown in your Nextcloud WebDAV address under Files > Files settings. It can differ from the name used to log in.';
