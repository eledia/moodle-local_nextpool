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

namespace local_nextpool\local\source;

/**
 * Contract for storage backends (subplugin type nextpoolsource, F03, adr08).
 *
 * A subplugin provides the class \nextpoolsource_<name>\source extending this
 * one. Each configured instance (row in local_nextpool_source) is confined to
 * its base folder: all public methods take paths relative to that folder and
 * implementations must translate them with {@see resolve()} before touching the
 * backend. Content always moves through temporary files, never through strings,
 * so large videos or SCORM packages do not hit the memory limit.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * Constructor.
     *
     * @param \stdClass $record Row from local_nextpool_source.
     * @param array $config Decrypted configuration, including 'basefolder'.
     */
    public function __construct(
        /** @var \stdClass Row from local_nextpool_source. */
        protected readonly \stdClass $record,
        /** @var array<string,mixed> Decrypted configuration. */
        protected readonly array $config,
    ) {
    }

    /**
     * Subplugin name (without the nextpoolsource_ prefix) of the calling class.
     *
     * @return string
     */
    public static function get_plugin(): string {
        $component = explode('\\', static::class)[0];
        return substr($component, strlen('nextpoolsource_'));
    }

    /**
     * Whether a storage location of this backend must name a base folder (adr08, bug26).
     *
     * Backends that reach a whole external account (e.g. Nextcloud) require it, so
     * that the catalogue never sees more than the folder meant for it.
     *
     * @return bool
     */
    public static function requires_basefolder(): bool {
        return false;
    }

    /**
     * Configuration keys this backend stores, besides the common 'basefolder'.
     *
     * @return string[]
     */
    public static function get_config_keys(): array {
        return [];
    }

    /**
     * Configuration keys that hold secrets.
     *
     * Secrets are never sent back to the browser; an empty value on edit keeps the stored secret.
     *
     * @return string[]
     */
    public static function get_secret_keys(): array {
        return [];
    }

    /**
     * Configuration keys a stored secret belongs to, such as the server it is sent to.
     *
     * If one of them changes, the stored secret is not kept and has to be entered
     * again; otherwise it would be sent to a different server.
     *
     * @return array<string,string[]> Secret key => configuration keys.
     */
    public static function get_secret_bindings(): array {
        return [];
    }

    /**
     * Whether a stored secret may be kept when the configuration is edited without entering it again.
     *
     * @param string $key Secret key.
     * @param array $existing Stored configuration.
     * @param array $config New configuration.
     * @return bool False if there is no stored secret or a configuration key it belongs to changed.
     */
    final public static function can_keep_secret(string $key, array $existing, array $config): bool {
        if (($existing[$key] ?? '') === '') {
            return false;
        }
        foreach (static::get_secret_bindings()[$key] ?? [] as $bound) {
            if (trim((string) ($existing[$bound] ?? '')) !== trim((string) ($config[$bound] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Add backend-specific elements to the source configuration form.
     *
     * @param \MoodleQuickForm $mform The form.
     */
    public static function add_config_elements(\MoodleQuickForm $mform): void {
    }

    /**
     * Adjust the configuration form once submitted or stored values are known.
     *
     * Called from the form's definition_after_data(). Backends use it to offer choices that depend on
     * other fields, e.g. the libraries of a SharePoint site after a no-submit button was pressed.
     *
     * @param \MoodleQuickForm $mform The form.
     * @param array $data Current form values (submitted values, otherwise stored or default ones).
     * @param array $existing Stored configuration when editing, including secrets; [] when creating.
     */
    public static function definition_after_data(\MoodleQuickForm $mform, array $data, array $existing): void {
    }

    /**
     * Validate backend-specific configuration.
     *
     * @param array $data Submitted form data.
     * @param array $existing Stored configuration when editing, [] when creating.
     * @return array<string,string> Errors keyed by form element name.
     */
    public static function validate_config(array $data, array $existing): array {
        return [];
    }

    /** @var int Longest time in seconds a request to a backend may take, transfer included (bug163). */
    public const REQUEST_TIMEOUT = 900;
    /** @var int A transfer slower than this many bytes per second for {@see LOW_SPEED_TIME} seconds is stopped (bug163). */
    public const LOW_SPEED_LIMIT = 1024;
    /** @var int Seconds a transfer may stay below {@see LOW_SPEED_LIMIT} (bug163). */
    public const LOW_SPEED_TIME = 60;

    /**
     * Give an HTTP client of a backend time limits, so a hanging server cannot block a request or the scheduled
     * storage location check for good (bug163). Core sets only a connect timeout.
     *
     * @param \curl $curl Client, also an OAuth 2 client.
     * @return \curl The same client.
     */
    public static function limit_transfer(\curl $curl): \curl {
        $curl->setopt([
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
            'CURLOPT_LOW_SPEED_LIMIT' => self::LOW_SPEED_LIMIT,
            'CURLOPT_LOW_SPEED_TIME' => self::LOW_SPEED_TIME,
        ]);
        return $curl;
    }

    /**
     * Instance id.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) $this->record->id;
    }

    /**
     * Instance name as configured by the administrator.
     *
     * @return string
     */
    public function get_name(): string {
        return (string) $this->record->name;
    }

    /**
     * Whether the instance is enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return !empty($this->record->enabled);
    }

    /**
     * A configuration value.
     *
     * @param string $key Configuration key.
     * @param mixed $default Value if not set.
     * @return mixed
     */
    public function get_config(string $key, $default = null) {
        return $this->config[$key] ?? $default;
    }

    /**
     * Normalised base folder of this instance ('' for the backend root).
     *
     * @return string
     */
    public function get_basefolder(): string {
        return path::normalise((string) ($this->config['basefolder'] ?? ''));
    }

    /**
     * Translate a path relative to the base folder into a full backend path.
     *
     * @param string $path Path relative to the base folder.
     * @return string Normalised full path inside the base folder.
     * @throws source_exception For paths that would leave the base folder.
     */
    protected function resolve(string $path): string {
        return path::join($this->get_basefolder(), path::normalise($path));
    }

    /**
     * Translate a full backend path back into a path relative to the base folder.
     *
     * @param string $fullpath Full backend path.
     * @return string
     * @throws source_exception If the path is outside the base folder.
     */
    protected function unresolve(string $fullpath): string {
        $relative = path::relative_to($this->get_basefolder(), path::normalise($fullpath));
        if ($relative === null) {
            throw new source_exception('error_invalidpath');
        }
        return $relative;
    }

    /**
     * Describe a file or folder.
     *
     * @param string $path Path relative to the base folder.
     * @return entry|null Null if it does not exist.
     * @throws source_exception On backend errors.
     */
    abstract public function stat(string $path): ?entry;

    /**
     * Find a file by its stable backend id ({@see entry::$externalid}), wherever it is now (q46, TD-201).
     *
     * Used when a file is no longer at its stored path, to follow a rename or move. Implementations ask
     * for exactly this id, never walk the whole backend, and return null for anything outside the base
     * folder: a file moved out of it counts as not found.
     *
     * @param string $externalid Backend id from an earlier entry.
     * @return entry|null The file inside the base folder, or null if not found or not supported.
     * @throws source_exception On backend errors.
     */
    public function find_by_externalid(string $externalid): ?entry {
        return null;
    }

    /**
     * Warnings an administrator should act on although the storage location still works (task73).
     *
     * The system status check shows them with the name of the storage location, e.g. credentials that expire soon.
     *
     * @return string[] Plain-text warnings, [] if none.
     */
    public function get_status_warnings(): array {
        return [];
    }

    /**
     * Short plain-text facts shown under the type in the list of storage locations, e.g. an expiry date.
     *
     * @return string '' if none.
     */
    public function get_summary(): string {
        return '';
    }

    /**
     * List the direct children of a folder.
     *
     * @param string $path Folder path relative to the base folder ('' for the base folder).
     * @return entry[] Folders first, then files, each sorted by name.
     * @throws source_exception On backend errors or if the folder does not exist.
     */
    abstract public function list_entries(string $path = ''): array;

    /**
     * Copy the current content of a file into a local file.
     *
     * @param string $path File path relative to the base folder.
     * @param string $targetfile Absolute path of the local file to write.
     * @throws source_exception On backend errors or if the file does not exist.
     */
    abstract public function read(string $path, string $targetfile): void;

    /**
     * Write a local file into the backend, creating missing folders.
     *
     * @param string $path File path relative to the base folder.
     * @param string $sourcefile Absolute path of the local file to upload.
     * @return entry The written file.
     * @throws source_exception On backend errors or if the backend is read-only.
     */
    abstract public function write(string $path, string $sourcefile): entry;

    /**
     * Delete a file written by {@see write()} (M06 direct upload, TD-405).
     *
     * Used to undo a write when a later step fails, so that no file is left without an item. A file that does
     * not exist counts as deleted. Backends that cannot delete keep the default, which does nothing.
     *
     * @param string $path File path relative to the base folder.
     * @throws source_exception On backend errors or if the path is a folder.
     */
    public function delete(string $path): void {
    }

    /**
     * Whether {@see get_web_url()} can link files of this storage location (F08.AC10, task84).
     *
     * Lets the item page offer the link without asking the backend on every page view (t84-e).
     *
     * @return bool
     */
    public function supports_web_url(): bool {
        return false;
    }

    /**
     * Address of a file or folder in the web interface of the backend, to edit it there (F08 q69).
     *
     * Only a link: whether the person may open or edit it there is up to the backend (q69). Nextpool shares nothing.
     *
     * @param string $path Path relative to the base folder ('' for the base folder).
     * @return string|null Absolute https URL, null if the backend has no web interface or the path does not exist.
     * @throws source_exception On backend errors.
     */
    public function get_web_url(string $path): ?string {
        return null;
    }

    /**
     * Whether {@see write()} is supported.
     *
     * @return bool
     */
    public function supports_write(): bool {
        return true;
    }

    /**
     * Check that the backend is reachable and the base folder exists.
     *
     * @throws source_exception If not.
     */
    public function test_connection(): void {
        $this->list_entries('');
    }

    /**
     * Remove data the backend keeps inside Moodle when the instance is deleted.
     */
    public function delete_instance_data(): void {
    }

    /**
     * Sort entries: folders first, then by name (natural, case-insensitive).
     *
     * @param entry[] $entries Entries.
     * @return entry[]
     */
    protected static function sort_entries(array $entries): array {
        usort($entries, static function (entry $a, entry $b): int {
            if ($a->isfolder !== $b->isfolder) {
                return $a->isfolder ? -1 : 1;
            }
            return strnatcasecmp($a->get_name(), $b->get_name());
        });
        return $entries;
    }
}
