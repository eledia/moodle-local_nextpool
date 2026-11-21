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

namespace nextpoolsource_nextcloud;

use core\oauth2\api as oauth2_api;
use local_nextpool\local\source\base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\source_exception;
use nextpoolsource_nextcloud\local\webdav_client;

/**
 * Storage backend for Nextcloud over WebDAV.
 *
 * Authentication (adr16): a Moodle core OAuth 2 issuer with a connected system
 * account (recommended), or an app password of a dedicated account as a warned
 * fallback. Learners never need a Nextcloud account. The revision of a file is
 * its ETag.
 *
 * @package   nextpoolsource_nextcloud
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source extends base {
    /** @var string Authentication through a core OAuth 2 system account. */
    public const AUTH_OAUTH2 = 'oauth2';
    /** @var string Authentication through an app password (WebDAV Basic Auth). */
    public const AUTH_APPPASSWORD = 'apppassword';

    /** @var webdav_client|null Lazily created client. */
    private ?webdav_client $client = null;

    #[\Override]
    public static function requires_basefolder(): bool {
        return true;
    }

    #[\Override]
    public static function get_config_keys(): array {
        return ['authmode', 'issuerid', 'serverurl', 'username', 'apppassword'];
    }

    #[\Override]
    public static function get_secret_keys(): array {
        return ['apppassword'];
    }

    #[\Override]
    public static function get_secret_bindings(): array {
        // The app password must not be sent to another server than the one it was entered for.
        return ['apppassword' => ['serverurl']];
    }

    #[\Override]
    public static function add_config_elements(\MoodleQuickForm $mform): void {
        $mform->addElement('select', 'authmode', get_string('authmode', 'nextpoolsource_nextcloud'), [
            self::AUTH_OAUTH2 => get_string('authmode_oauth2', 'nextpoolsource_nextcloud'),
            self::AUTH_APPPASSWORD => get_string('authmode_apppassword', 'nextpoolsource_nextcloud'),
        ]);
        $mform->setDefault('authmode', self::AUTH_OAUTH2);

        $issuers = [0 => get_string('choosedots')];
        foreach (oauth2_api::get_all_issuers() as $issuer) {
            if ($issuer->get('enabled')) {
                $issuers[$issuer->get('id')] = format_string($issuer->get('name'));
            }
        }
        $mform->addElement('select', 'issuerid', get_string('issuerid', 'nextpoolsource_nextcloud'), $issuers);
        $mform->addHelpButton('issuerid', 'issuerid', 'nextpoolsource_nextcloud');
        $mform->setType('issuerid', PARAM_INT);
        $mform->hideIf('issuerid', 'authmode', 'neq', self::AUTH_OAUTH2);

        $mform->addElement('static', 'apppasswordwarning', '', \html_writer::div(
            get_string('apppassword_warning', 'nextpoolsource_nextcloud'),
            'alert alert-warning'
        ));
        $mform->hideIf('apppasswordwarning', 'authmode', 'neq', self::AUTH_APPPASSWORD);

        $mform->addElement('text', 'serverurl', get_string('serverurl', 'nextpoolsource_nextcloud'), ['size' => 50]);
        $mform->setType('serverurl', PARAM_URL);
        $mform->addHelpButton('serverurl', 'serverurl', 'nextpoolsource_nextcloud');
        $mform->hideIf('serverurl', 'authmode', 'neq', self::AUTH_APPPASSWORD);

        $mform->addElement('text', 'username', get_string('username', 'nextpoolsource_nextcloud'), ['size' => 30]);
        $mform->setType('username', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('username', 'username', 'nextpoolsource_nextcloud');
        $mform->hideIf('username', 'authmode', 'neq', self::AUTH_APPPASSWORD);

        $mform->addElement('passwordunmask', 'apppassword', get_string('apppassword', 'nextpoolsource_nextcloud'));
        $mform->addHelpButton('apppassword', 'apppassword', 'nextpoolsource_nextcloud');
        $mform->setType('apppassword', PARAM_RAW);
        $mform->hideIf('apppassword', 'authmode', 'neq', self::AUTH_APPPASSWORD);
    }

    #[\Override]
    public static function validate_config(array $data, array $existing): array {
        $errors = [];
        if (($data['authmode'] ?? '') === self::AUTH_APPPASSWORD) {
            $url = trim((string) ($data['serverurl'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                $errors['serverurl'] = get_string('error_serverurl', 'nextpoolsource_nextcloud');
            } else if (!self::is_https($url)) {
                // Basic Auth sends the app password with every request.
                $errors['serverurl'] = get_string('error_serverurlhttps', 'nextpoolsource_nextcloud');
            }
            if (trim((string) ($data['username'] ?? '')) === '') {
                $errors['username'] = get_string('required');
            }
            if (($data['apppassword'] ?? '') === '' && !self::can_keep_secret('apppassword', $existing, $data)) {
                $errors['apppassword'] = empty($existing['apppassword'])
                    ? get_string('required')
                    : get_string('error_apppasswordreenter', 'nextpoolsource_nextcloud');
            }
        } else if (($data['authmode'] ?? '') === self::AUTH_OAUTH2) {
            if (empty($data['issuerid'])) {
                $errors['issuerid'] = get_string('required');
            }
        } else {
            $errors['authmode'] = get_string('required');
        }
        return $errors;
    }

    #[\Override]
    public function stat(string $path): ?entry {
        $props = $this->get_client()->stat($this->resolve($path));
        return $props === null ? null : $this->to_entry($props);
    }

    #[\Override]
    public function find_by_externalid(string $externalid): ?entry {
        $props = $this->get_client()->find_by_fileid($externalid, $this->get_basefolder());
        if ($props === null) {
            return null;
        }
        try {
            return $this->to_entry($props);
        } catch (source_exception $e) {
            // Outside the base folder: not found for this storage location.
            return null;
        }
    }

    #[\Override]
    public function list_entries(string $path = ''): array {
        $entries = [];
        try {
            $children = $this->get_client()->list_folder($this->resolve($path));
        } catch (source_exception $e) {
            if (in_array($e->errorcode, ['error_nextcloudnotfound', 'error_notfound'], true)) {
                return [];
            }
            throw $e;
        }
        foreach ($children as $props) {
            $entries[] = $this->to_entry($props);
        }
        return self::sort_entries($entries);
    }

    #[\Override]
    public function test_connection(): void {
        // Listing a missing child is empty (bug236); a missing configured base folder is a configuration error.
        $this->get_client()->list_folder($this->resolve(''));
    }

    #[\Override]
    public function read(string $path, string $targetfile): void {
        $this->get_client()->download_to_file($this->resolve($path), $targetfile);
    }

    #[\Override]
    public function write(string $path, string $sourcefile): entry {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_invalidpath');
        }
        $client = $this->get_client();
        $client->upload_file($full, $sourcefile);
        $props = $client->stat($full);
        if ($props === null) {
            throw new source_exception('error_notfound');
        }
        return $this->to_entry($props);
    }

    #[\Override]
    public function delete(string $path): void {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_notafile');
        }
        $client = $this->get_client();
        $props = $client->stat($full);
        if ($props === null) {
            return;
        }
        // WebDAV deletes folders with their content: never let a file path reach a folder.
        if ($props['isfolder']) {
            throw new source_exception('error_notafile');
        }
        $client->delete_file($full);
    }

    #[\Override]
    public function supports_web_url(): bool {
        return true;
    }

    #[\Override]
    public function get_web_url(string $path): ?string {
        $props = $this->get_client()->stat($this->resolve($path));
        if ($props === null || ($props['fileid'] ?? '') === '') {
            return null;
        }
        // Nextcloud opens files and folders by their id (task84, q69).
        return $this->get_client()->get_server_url() . '/index.php/f/' . rawurlencode((string) $props['fileid']);
    }

    /**
     * The WebDAV client for this instance.
     *
     * @return webdav_client
     * @throws source_exception If the instance is not fully configured or the system account is not connected.
     */
    protected function get_client(): webdav_client {
        if ($this->client === null) {
            $this->client = $this->create_client();
        }
        return $this->client;
    }

    /**
     * Build the WebDAV client from the configuration.
     *
     * @return webdav_client
     * @throws source_exception If not configured.
     */
    protected function create_client(): webdav_client {
        if ($this->get_config('authmode') === self::AUTH_APPPASSWORD) {
            $serverurl = rtrim((string) $this->get_config('serverurl', ''), '/');
            $username = trim((string) $this->get_config('username', ''));
            $password = (string) $this->get_config('apppassword', '');
            // Never send the app password unencrypted, also for configurations stored before https was required.
            if ($serverurl === '' || !self::is_https($serverurl) || $username === '' || $password === '') {
                throw new source_exception('error_sourcenotconfigured');
            }
            global $CFG;
            // Core class curl lives in filelib.php, which the OAuth 2 path loads on its own.
            require_once($CFG->libdir . '/filelib.php');
            return new webdav_client(self::limit_transfer(new \curl()), $serverurl, $username, $password);
        }

        $issuerid = (int) $this->get_config('issuerid', 0);
        try {
            $issuer = $issuerid > 0 ? oauth2_api::get_issuer($issuerid) : null;
        } catch (\dml_missing_record_exception $e) {
            $issuer = null;
        }
        if (!$issuer || !$issuer->get('enabled')) {
            throw new source_exception('error_sourcenotconfigured');
        }
        $systemaccount = oauth2_api::get_system_account($issuer);
        $oauthclient = $systemaccount ? oauth2_api::get_system_oauth_client($issuer) : false;
        $serverurl = rtrim((string) $issuer->get('baseurl'), '/');
        $username = $systemaccount ? (string) $systemaccount->get('username') : '';
        if (!$oauthclient || $serverurl === '' || $username === '') {
            throw new source_exception('error_sourcenotconfigured');
        }
        return new webdav_client(self::limit_transfer($oauthclient), $serverurl, $username);
    }

    /**
     * Whether a server URL uses https.
     *
     * @param string $url URL.
     * @return bool
     */
    private static function is_https(string $url): bool {
        return (bool) preg_match('#^https://[^/]#i', trim($url));
    }

    /**
     * Convert parsed WebDAV properties into an entry relative to the base folder.
     *
     * @param array $props Entry from {@see \nextpoolsource_nextcloud\local\webdav_parser::parse_entries()}.
     * @return entry
     */
    private function to_entry(array $props): entry {
        return new entry(
            path: $this->unresolve($props['path']),
            isfolder: $props['isfolder'],
            size: $props['isfolder'] ? 0 : $props['size'],
            mimetype: $props['isfolder'] ? '' : $props['mimetype'],
            revision: $props['etag'],
            mtime: $props['mtime'],
            externalid: $props['fileid'],
        );
    }
}
