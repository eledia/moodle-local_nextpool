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

namespace nextpoolsource_onedrive;

use local_nextpool\local\source\base;
use local_nextpool\local\source\entry;
use local_nextpool\local\source\path;
use local_nextpool\local\source\source_exception;
use nextpoolsource_onedrive\local\graph_client;

/**
 * Storage backend for a SharePoint document library or a personal OneDrive (F03-onedrive).
 *
 * Nextpool signs in as an app registered by the customer IT in Microsoft Entra,
 * with the client credentials grant and the application permission Sites.Selected
 * (q45, adr20); no Microsoft user account or licence is involved. One storage
 * location is one drive (library or personal OneDrive) and a base folder in it.
 * The revision of a file is its cTag, which changes with the content only; the
 * backend id is the driveItem id, which stays when a file is renamed or moved in
 * its drive (adr21).
 *
 * @package   nextpoolsource_onedrive
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source extends base {
    /** @var int Days before the app secret expires from which the status check warns (F03O.AC13). */
    public const SECRET_WARNING_DAYS = 30;
    /** @var string Drive item properties read for entries. */
    private const SELECT = 'id,name,size,file,folder,cTag,lastModifiedDateTime,parentReference,deleted';

    /** @var graph_client|null Lazily created client. */
    private ?graph_client $client = null;

    #[\Override]
    public static function requires_basefolder(): bool {
        return true;
    }

    #[\Override]
    public static function get_config_keys(): array {
        return ['tenantid', 'clientid', 'clientsecret', 'secretexpires', 'locationurl', 'driveid', 'drivename'];
    }

    #[\Override]
    public static function get_secret_keys(): array {
        return ['clientsecret'];
    }

    #[\Override]
    public static function get_secret_bindings(): array {
        // The secret belongs to one app in one tenant; it must not be sent for another.
        return ['clientsecret' => ['tenantid', 'clientid']];
    }

    #[\Override]
    public static function add_config_elements(\MoodleQuickForm $mform): void {
        $component = 'nextpoolsource_onedrive';
        $mform->addElement('header', 'appheader', get_string('appheader', $component));
        $mform->addElement('static', 'appintro', '', get_string('appintro', $component));

        $mform->addElement('text', 'tenantid', get_string('tenantid', $component), ['size' => 40]);
        $mform->setType('tenantid', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('tenantid', 'tenantid', $component);

        $mform->addElement('text', 'clientid', get_string('clientid', $component), ['size' => 40]);
        $mform->setType('clientid', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('clientid', 'clientid', $component);

        $mform->addElement('passwordunmask', 'clientsecret', get_string('clientsecret', $component));
        $mform->setType('clientsecret', PARAM_RAW);
        $mform->addHelpButton('clientsecret', 'clientsecret', $component);

        $mform->addElement('date_selector', 'secretexpires', get_string('secretexpires', $component));
        $mform->addHelpButton('secretexpires', 'secretexpires', $component);
        $mform->setDefault('secretexpires', time() + 180 * DAYSECS);

        $mform->addElement('header', 'driveheader', get_string('driveheader', $component));
        $mform->setExpanded('driveheader');
        $mform->addElement('text', 'locationurl', get_string('locationurl', $component), ['size' => 70]);
        $mform->setType('locationurl', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('locationurl', 'locationurl', $component);

        // A plain submit button that does not save (adr05): the form comes back with the libraries to choose from.
        $mform->registerNoSubmitButton('loaddrives');
        $mform->addElement('submit', 'loaddrives', get_string('loaddrives', $component));

        $mform->addElement('select', 'driveid', get_string('driveid', $component), ['' => get_string('choosedots')]);
        $mform->addHelpButton('driveid', 'driveid', $component);
        $mform->addElement('hidden', 'drivename', '');
        $mform->setType('drivename', PARAM_TEXT);
        // Libraries found by the last "Load libraries", so the choice survives until saving. Not stored.
        $mform->addElement('hidden', 'drivechoices', '');
        $mform->setType('drivechoices', PARAM_RAW);
    }

    #[\Override]
    public static function definition_after_data(\MoodleQuickForm $mform, array $data, array $existing): void {
        $choices = self::decode_choices((string) ($data['drivechoices'] ?? ''));
        $driveid = (string) ($data['driveid'] ?? '');
        if ($driveid === '' && !$mform->isSubmitted()) {
            // The method exportValues() leaves out a value the menu does not offer yet: take the stored library from the element.
            $value = $mform->getElement('driveid')->getValue();
            $driveid = (string) (is_array($value) ? reset($value) : $value);
        }
        if ($driveid !== '' && !isset($choices[$driveid])) {
            // The stored library stays selectable when editing.
            $choices[$driveid] = (string) ($data['drivename'] ?? '') ?: $driveid;
        }

        if ($mform->isSubmitted() && $mform->getSubmitValue('loaddrives') !== null) {
            $config = self::with_kept_secret($data, $existing);
            try {
                $choices = static::resolve_location($config);
                $mform->setConstant('drivechoices', json_encode($choices));
                if (count($choices) === 1 || !isset($choices[$driveid])) {
                    $driveid = count($choices) === 1 ? (string) array_key_first($choices) : '';
                    $mform->setConstant('driveid', $driveid);
                }
            } catch (source_exception $e) {
                $mform->setElementError('locationurl', $e->getMessage());
            }
        }

        /** @var \MoodleQuickForm_select $select */
        $select = $mform->getElement('driveid');
        $select->removeOptions();
        $select->addOption(get_string('choosedots'), '');
        foreach ($choices as $id => $name) {
            // Plain text from Graph or from the form; core prints option texts as HTML (bug123).
            $select->addOption(s($name), (string) $id);
        }
        $mform->setConstant('drivename', $choices[$driveid] ?? '');
    }

    #[\Override]
    public static function validate_config(array $data, array $existing): array {
        $component = 'nextpoolsource_onedrive';
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', trim((string) ($data['tenantid'] ?? '')))) {
            $errors['tenantid'] = get_string('error_tenantid', $component);
        }
        $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($guid, trim((string) ($data['clientid'] ?? '')))) {
            $errors['clientid'] = get_string('error_clientid', $component);
        }
        if (($data['clientsecret'] ?? '') === '' && !self::can_keep_secret('clientsecret', $existing, $data)) {
            $errors['clientsecret'] = empty($existing['clientsecret'])
                ? get_string('required')
                : get_string('error_secretreenter', $component);
        }
        if (empty($data['secretexpires'])) {
            $errors['secretexpires'] = get_string('required');
        }
        if (trim((string) ($data['locationurl'] ?? '')) === '') {
            $errors['locationurl'] = get_string('required');
        }
        if (trim((string) ($data['driveid'] ?? '')) === '') {
            $errors['driveid'] = get_string('error_nodrive', $component);
        }
        if ($errors) {
            return $errors;
        }

        // Check the connection before saving and name the cause at its field (F03O.AC01, AC02).
        $config = self::with_kept_secret($data, $existing);
        try {
            $config['basefolder'] = path::normalise((string) ($data['basefolder'] ?? ''));
            $record = (object) ['id' => 0, 'plugin' => 'onedrive', 'name' => '', 'enabled' => 1];
            $source = new static($record, $config);
            $source->test_connection();
        } catch (source_exception $e) {
            $field = match ($e->errorcode) {
                'error_tenant' => 'tenantid',
                'error_credentials' => 'clientsecret',
                'error_forbidden' => 'locationurl',
                'error_drivenotfound' => 'driveid',
                'error_basefoldernotfound', 'error_notafolder', 'error_invalidpath' => 'basefolder',
                default => 'locationurl',
            };
            $errors[$field] = $e->getMessage();
        }
        return $errors;
    }

    /**
     * Find the drives behind an address pasted from the browser (F03O.AC17).
     *
     * Accepts the address of a SharePoint site (all its libraries), of a library or a folder in it (that
     * library only), of a personal OneDrive, or a Graph site id "host,siteid,webid" as a fallback.
     *
     * @param array $config Configuration with tenantid, clientid, clientsecret and locationurl.
     * @return array<string,string> Drive id => display name, sorted by name.
     * @throws source_exception If the address cannot be resolved; the message names the cause.
     */
    public static function resolve_location(array $config): array {
        $client = static::create_graph_client($config);
        $client->check_credentials();
        [$siteref, $librarypath] = self::parse_location((string) ($config['locationurl'] ?? ''));
        $site = $client->get('/sites/' . $siteref . '?$select=id,displayName,webUrl');
        if ($site === null || empty($site['id'])) {
            throw new source_exception('error_sitenotfound', null, 'nextpoolsource_onedrive');
        }
        $drives = $client->get_all('/sites/' . rawurlencode((string) $site['id']) . '/drives?$select=id,name,webUrl');
        $choices = [];
        foreach ($drives as $drive) {
            $webpath = rawurldecode((string) parse_url((string) ($drive['webUrl'] ?? ''), PHP_URL_PATH));
            $matches = $librarypath === ''
                || $librarypath === $webpath || str_starts_with($librarypath, rtrim($webpath, '/') . '/');
            if ($matches && !empty($drive['id'])) {
                $name = trim(($site['displayName'] ?? '') . ' – ' . ($drive['name'] ?? ''), ' –');
                // Names are set in SharePoint, outside Moodle: plain text like the choices sent back by the form (bug123).
                $choices[(string) $drive['id']] = clean_param($name, PARAM_TEXT);
            }
        }
        if (!$choices) {
            throw new source_exception('error_drivenotfound', null, 'nextpoolsource_onedrive');
        }
        \core_collator::asort($choices);
        return $choices;
    }

    /**
     * Split a browser address into a Graph site reference and the path of a library inside the site.
     *
     * @param string $url Address of a site, library, folder or personal OneDrive, or a Graph site id.
     * @return array{0:string,1:string} Site reference for /sites/{ref}; server-relative library path or ''.
     * @throws source_exception If it is not such an address.
     */
    public static function parse_location(string $url): array {
        $url = trim($url);
        if (preg_match('/^[a-z0-9.-]+\.sharepoint\.(com|us|de|cn),[0-9a-f-]{36},[0-9a-f-]{36}$/i', $url)) {
            return [$url, ''];
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !preg_match('/^[a-z0-9-]+(-my)?\.sharepoint\.(com|us|de|cn)$/', $host)) {
            throw new source_exception('error_locationurl', null, 'nextpoolsource_onedrive');
        }
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        if (preg_match('#^/:[a-z]:/#i', $path)) {
            // Sharing links ("/:f:/s/...") do not name the site.
            throw new source_exception('error_sharinglink', null, 'nextpoolsource_onedrive');
        }
        // Views and pages are not part of the library path.
        $path = preg_replace('#/(Forms/[^/]*|_layouts/.*|SitePages/.*|[^/]+\.aspx)$#i', '', $path);
        $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $sitesegments = [];
        if ($segments && in_array(strtolower($segments[0]), ['sites', 'teams', 'personal'], true) && isset($segments[1])) {
            $sitesegments = array_slice($segments, 0, 2);
        }
        $sitepath = $sitesegments ? '/' . implode('/', $sitesegments) : '';
        // No trailing colon: nothing follows the site path in the request (spike finding 2).
        $siteref = $host . ($sitepath !== '' ? ':' . implode('/', array_map('rawurlencode', explode('/', $sitepath))) : '');
        $rest = array_slice($segments, count($sitesegments));
        $librarypath = $rest ? $sitepath . '/' . implode('/', $rest) : '';
        return [$siteref, $librarypath];
    }

    #[\Override]
    public function stat(string $path): ?entry {
        $item = $this->get_client()->get($this->item_path($this->resolve($path)) . '?$select=' . self::SELECT);
        return $item === null || isset($item['deleted']) ? null : $this->to_entry($item);
    }

    #[\Override]
    public function list_entries(string $path = ''): array {
        $full = $this->resolve($path);
        $children = $full === ''
            ? $this->drive_path() . '/root/children'
            : $this->item_path($full) . '/children';
        try {
            $items = $this->get_client()->get_all($children . '?$select=' . self::SELECT . '&$top=999');
        } catch (source_exception $e) {
            if ($e->errorcode === 'error_notfound') {
                // Exception messages are shown as HTML: the path can come from a link (bug121).
                throw new source_exception('error_onedrivenotfound', s('/' . $full), 'nextpoolsource_onedrive');
            }
            throw $e;
        }
        $entries = [];
        foreach ($items as $item) {
            if (!isset($item['deleted']) && (isset($item['file']) || isset($item['folder']))) {
                $entries[] = $this->to_entry($item);
            }
        }
        return self::sort_entries($entries);
    }

    #[\Override]
    public function read(string $path, string $targetfile): void {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_notafile');
        }
        $this->get_client()->download($this->item_path($full), $targetfile);
    }

    #[\Override]
    public function write(string $path, string $sourcefile): entry {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_invalidpath');
        }
        return $this->to_entry($this->get_client()->upload($this->item_path($full), $sourcefile));
    }

    #[\Override]
    public function delete(string $path): void {
        $full = $this->resolve($path);
        if ($full === '') {
            throw new source_exception('error_notafile');
        }
        $client = $this->get_client();
        $item = $client->get($this->item_path($full) . '?$select=id,file,folder,deleted');
        if ($item === null || isset($item['deleted'])) {
            return;
        }
        // Graph deletes folders with their content: never let a file path reach a folder.
        if (!isset($item['file']) || !preg_match('/^[A-Za-z0-9!_.-]+$/', (string) ($item['id'] ?? ''))) {
            throw new source_exception('error_notafile');
        }
        $client->delete($this->drive_path() . '/items/' . rawurlencode((string) $item['id']));
    }

    #[\Override]
    public function supports_web_url(): bool {
        return true;
    }

    #[\Override]
    public function get_web_url(string $path): ?string {
        $item = $this->get_client()->get($this->item_path($this->resolve($path)) . '?$select=id,webUrl,deleted');
        if ($item === null || isset($item['deleted'])) {
            return null;
        }
        // The webUrl of the drive item opens it in SharePoint or OneDrive (task84, q69).
        $url = (string) ($item['webUrl'] ?? '');
        return preg_match('#^https://[^/]#i', $url) ? $url : null;
    }

    #[\Override]
    public function find_by_externalid(string $externalid): ?entry {
        if (!preg_match('/^[A-Za-z0-9!_.-]+$/', $externalid)) {
            return null;
        }
        $item = $this->get_client()->get($this->drive_path() . '/items/' . rawurlencode($externalid) . '?$select=' . self::SELECT);
        if ($item === null || isset($item['deleted'])) {
            return null;
        }
        try {
            return $this->to_entry($item);
        } catch (source_exception $e) {
            // Outside the base folder or in another drive: not found for this storage location (F03O.AC15).
            return null;
        }
    }

    #[\Override]
    public function test_connection(): void {
        $client = $this->get_client();
        $client->check_credentials();
        $drive = $client->get($this->drive_path() . '?$select=id,name');
        if ($drive === null) {
            throw new source_exception('error_drivenotfound', null, 'nextpoolsource_onedrive');
        }
        $basefolder = $this->get_basefolder();
        if ($basefolder === '') {
            return;
        }
        $folder = $client->get($this->item_path($basefolder) . '?$select=id,folder,deleted');
        if ($folder === null || isset($folder['deleted'])) {
            throw new source_exception('error_basefoldernotfound', s('/' . $basefolder), 'nextpoolsource_onedrive');
        }
        if (!isset($folder['folder'])) {
            throw new source_exception('error_notafolder', s('/' . $basefolder), 'nextpoolsource_onedrive');
        }
    }

    #[\Override]
    public function get_status_warnings(): array {
        // Microsoft does not reveal the expiry to an app with Sites.Selected, so admins enter it (q61).
        $expires = (int) $this->get_config('secretexpires', 0);
        if ($expires <= 0 || $expires - time() >= self::SECRET_WARNING_DAYS * DAYSECS) {
            return [];
        }
        $date = userdate($expires, get_string('strftimedate', 'core_langconfig'));
        $key = $expires <= time() ? 'warning_secretexpired' : 'warning_secretexpires';
        return [get_string($key, 'nextpoolsource_onedrive', $date)];
    }

    #[\Override]
    public function get_summary(): string {
        $parts = [];
        if ((string) $this->get_config('drivename', '') !== '') {
            $parts[] = (string) $this->get_config('drivename');
        }
        $expires = (int) $this->get_config('secretexpires', 0);
        if ($expires > 0) {
            $date = userdate($expires, get_string('strftimedate', 'core_langconfig'));
            $parts[] = get_string('secretexpireson', 'nextpoolsource_onedrive', $date);
        }
        return implode(' · ', $parts);
    }

    /**
     * Graph client of this storage location.
     *
     * @return graph_client
     * @throws source_exception If not fully configured.
     */
    protected function get_client(): graph_client {
        return $this->client ??= static::create_graph_client($this->config);
    }

    /**
     * Build a Graph client from a configuration.
     *
     * @param array $config Configuration with tenantid, clientid and clientsecret.
     * @return graph_client
     * @throws source_exception If a value is missing.
     */
    protected static function create_graph_client(array $config): graph_client {
        global $CFG;
        $tenantid = trim((string) ($config['tenantid'] ?? ''));
        $clientid = trim((string) ($config['clientid'] ?? ''));
        $secret = (string) ($config['clientsecret'] ?? '');
        if ($tenantid === '' || $clientid === '' || $secret === '') {
            throw new source_exception('error_sourcenotconfigured');
        }
        require_once($CFG->libdir . '/filelib.php');
        return new graph_client(self::limit_transfer(new \curl()), $tenantid, $clientid, $secret);
    }

    /**
     * Form data with the stored secret, if it may be kept.
     *
     * @param array $data Form data.
     * @param array $existing Stored configuration.
     * @return array
     */
    private static function with_kept_secret(array $data, array $existing): array {
        if (($data['clientsecret'] ?? '') === '' && self::can_keep_secret('clientsecret', $existing, $data)) {
            $data['clientsecret'] = $existing['clientsecret'];
        }
        return $data;
    }

    /**
     * Libraries remembered in the form.
     *
     * @param string $json JSON object drive id => name.
     * @return array<string,string>
     */
    private static function decode_choices(string $json): array {
        $decoded = json_decode($json, true);
        $choices = [];
        foreach (is_array($decoded) ? $decoded : [] as $id => $name) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9!_.-]+$/', (string) $id)) {
                $choices[(string) $id] = clean_param($name, PARAM_TEXT);
            }
        }
        return $choices;
    }

    /**
     * Graph path of the configured drive.
     *
     * @return string
     * @throws source_exception If no drive is configured.
     */
    private function drive_path(): string {
        $driveid = (string) $this->get_config('driveid', '');
        if ($driveid === '') {
            throw new source_exception('error_sourcenotconfigured');
        }
        return '/drives/' . rawurlencode($driveid);
    }

    /**
     * Graph path of an item by its full path in the drive.
     *
     * @param string $full Normalised full path ('' for the root).
     * @return string
     */
    private function item_path(string $full): string {
        if ($full === '') {
            return $this->drive_path() . '/root';
        }
        return $this->drive_path() . '/root:/' . implode('/', array_map('rawurlencode', explode('/', $full))) . ':';
    }

    /**
     * Convert a drive item into an entry relative to the base folder.
     *
     * @param array $item Drive item JSON.
     * @return entry
     * @throws source_exception If the item lies outside the base folder or the configured drive.
     */
    private function to_entry(array $item): entry {
        $parent = (array) ($item['parentReference'] ?? []);
        $driveid = (string) $this->get_config('driveid', '');
        if (isset($parent['driveId']) && strcasecmp((string) $parent['driveId'], $driveid) !== 0) {
            throw new source_exception('error_invalidpath');
        }
        // The parent path looks like "/drives/{id}/root:/Kurse/A"; the root item has no parent path.
        $parentpath = (string) ($parent['path'] ?? '');
        $pos = strpos($parentpath, 'root:');
        $parentfull = $pos === false ? '' : trim(rawurldecode(substr($parentpath, $pos + 5)), '/');
        $isroot = !isset($item['parentReference']['path']) && isset($item['root']);
        $full = $isroot ? '' : path::join($parentfull === '' ? '' : path::normalise($parentfull), (string) ($item['name'] ?? ''));
        $isfolder = isset($item['folder']);
        return new entry(
            path: $this->unresolve($full),
            isfolder: $isfolder,
            size: $isfolder ? 0 : (int) ($item['size'] ?? 0),
            mimetype: $isfolder ? '' : (string) ($item['file']['mimeType'] ?? ''),
            revision: (string) ($item['cTag'] ?? ''),
            mtime: !empty($item['lastModifiedDateTime']) ? (int) strtotime((string) $item['lastModifiedDateTime']) : 0,
            externalid: (string) ($item['id'] ?? ''),
        );
    }
}
