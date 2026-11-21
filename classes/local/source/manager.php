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

use core\encryption;

/**
 * Creates, loads and stores storage backend instances.
 *
 * Several instances of the same backend may exist side by side (e.g. two
 * Nextcloud servers); each catalogue item belongs to exactly one instance.
 * Configuration is stored as JSON encrypted with the site key.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class manager {
    /** @var string Table holding the instances. */
    public const TABLE = 'local_nextpool_source';

    /**
     * Installed backend plugins.
     *
     * @return array<string,string> Plugin name => localised display name, sorted by display name.
     */
    public static function get_available_plugins(): array {
        $plugins = [];
        foreach (array_keys(\core_component::get_plugin_list('nextpoolsource')) as $plugin) {
            if (self::get_class($plugin) !== null) {
                $plugins[$plugin] = get_string('pluginname', 'nextpoolsource_' . $plugin);
            }
        }
        \core_collator::asort($plugins);
        return $plugins;
    }

    /**
     * Fully qualified source class of a backend plugin.
     *
     * @param string $plugin Plugin name without prefix.
     * @return class-string<base>|null Null if the plugin is not installed or does not implement the contract.
     */
    public static function get_class(string $plugin): ?string {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $plugin)) {
            return null;
        }
        $class = '\\nextpoolsource_' . $plugin . '\\source';
        if (!class_exists($class) || !is_subclass_of($class, base::class)) {
            return null;
        }
        return $class;
    }

    /**
     * Create a new instance.
     *
     * @param string $plugin Plugin name without prefix.
     * @param string $name Display name.
     * @param array $config Configuration including 'basefolder'.
     * @param bool $enabled Whether the instance is enabled.
     * @return base
     */
    public static function create(string $plugin, string $name, array $config, bool $enabled = true): base {
        global $DB;
        $class = self::get_class($plugin);
        if ($class === null) {
            throw new \coding_exception('Unknown storage backend: ' . $plugin);
        }
        $now = time();
        $record = (object) [
            'plugin' => $plugin,
            'name' => trim($name),
            'config' => self::encode_config(self::filter_config($class, $config)),
            'enabled' => $enabled ? 1 : 0,
            'sortorder' => (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {' . self::TABLE . '}'),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        return self::get($record->id, false);
    }

    /**
     * Update an existing instance.
     *
     * Empty secret values keep the stored secret, unless a configuration key the
     * secret belongs to changed (see base::get_secret_bindings()); then it is dropped.
     *
     * @param int $id Instance id.
     * @param string $name Display name.
     * @param array $config Configuration including 'basefolder'.
     * @param bool $enabled Whether the instance is enabled.
     * @return base
     */
    public static function update(int $id, string $name, array $config, bool $enabled): base {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $class = self::get_class($record->plugin);
        if ($class === null) {
            throw new \coding_exception('Unknown storage backend: ' . $record->plugin);
        }
        $existing = self::decode_config((string) $record->config);
        $config = self::filter_config($class, $config);
        foreach ($class::get_secret_keys() as $key) {
            if (($config[$key] ?? '') === '' && $class::can_keep_secret($key, $existing, $config)) {
                $config[$key] = $existing[$key];
            }
        }
        $record->name = trim($name);
        $record->config = self::encode_config($config);
        $record->enabled = $enabled ? 1 : 0;
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);
        return self::get($id, false);
    }

    /**
     * Enable or disable an instance.
     *
     * @param int $id Instance id.
     * @param bool $enabled New state.
     */
    public static function set_enabled(int $id, bool $enabled): void {
        global $DB;
        $DB->update_record(self::TABLE, ['id' => $id, 'enabled' => $enabled ? 1 : 0, 'timemodified' => time()]);
    }

    /**
     * Whether an instance can be deleted: no catalogue item uses it and it is not set for direct uploads (TD-404).
     *
     * @param int $id Instance id.
     * @return bool
     */
    public static function can_delete(int $id): bool {
        global $DB;
        return !$DB->record_exists('local_nextpool_item', ['sourceid' => $id])
            && \local_nextpool\local\direct_upload::get_configured_sourceid() !== $id;
    }

    /**
     * Delete an instance that no item uses.
     *
     * @param int $id Instance id.
     * @throws \moodle_exception If items still use the instance, or it is set for direct uploads.
     */
    public static function delete(int $id): void {
        global $DB;
        if (\local_nextpool\local\direct_upload::get_configured_sourceid() === $id) {
            // Another location has to be chosen first, so that uploads never point to a deleted one.
            throw new \moodle_exception('error_sourceisuploadtarget', 'local_nextpool');
        }
        if (!self::can_delete($id)) {
            throw new \moodle_exception('error_sourceinuse', 'local_nextpool');
        }
        $source = self::get($id, false);
        $source->delete_instance_data();
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Load an instance.
     *
     * @param int $id Instance id.
     * @param bool $mustbeenabled Throw if the instance is disabled.
     * @return base
     * @throws source_exception If disabled (and required) or the plugin is missing.
     */
    public static function get(int $id, bool $mustbeenabled = true): base {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $class = self::get_class($record->plugin);
        if ($class === null) {
            throw new source_exception('error_sourcemissing', $record->plugin);
        }
        if ($mustbeenabled && empty($record->enabled)) {
            throw new source_exception('error_sourcedisabled', format_string($record->name));
        }
        return new $class($record, self::decode_config((string) $record->config));
    }

    /**
     * Stored configuration of an instance, without secrets (for edit forms).
     *
     * @param int $id Instance id.
     * @return array<string,mixed>
     */
    public static function get_form_config(int $id): array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $config = self::decode_config((string) $record->config);
        $class = self::get_class($record->plugin);
        foreach ($class ? $class::get_secret_keys() : [] as $key) {
            unset($config[$key]);
        }
        return $config;
    }

    /**
     * Stored configuration of an instance, including secrets (for validation on edit).
     *
     * @param int $id Instance id.
     * @return array<string,mixed>
     */
    public static function get_stored_config(int $id): array {
        global $DB;
        return self::decode_config((string) $DB->get_field(self::TABLE, 'config', ['id' => $id], MUST_EXIST));
    }

    /**
     * All instance records, in display order.
     *
     * @param bool $enabledonly Only enabled instances.
     * @return \stdClass[] Records without the config column.
     */
    public static function get_records(bool $enabledonly = false): array {
        global $DB;
        $conditions = $enabledonly ? ['enabled' => 1] : [];
        $fields = 'id, plugin, name, enabled, sortorder, timecreated, timemodified';
        return $DB->get_records(self::TABLE, $conditions, 'sortorder, id', $fields);
    }

    /**
     * Keep only the keys a backend declares, plus the normalised base folder.
     *
     * @param string $class Source class.
     * @param array $config Raw configuration.
     * @return array<string,mixed>
     */
    private static function filter_config(string $class, array $config): array {
        $filtered = ['basefolder' => path::normalise((string) ($config['basefolder'] ?? ''))];
        foreach ($class::get_config_keys() as $key) {
            if (array_key_exists($key, $config)) {
                $filtered[$key] = $config[$key];
            }
        }
        return $filtered;
    }

    /**
     * Encrypt a configuration array for storage.
     *
     * @param array $config Configuration.
     * @return string
     */
    public static function encode_config(array $config): string {
        return encryption::encrypt(json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * Decrypt a stored configuration.
     *
     * @param string $stored Stored value.
     * @return array<string,mixed>
     */
    public static function decode_config(string $stored): array {
        if ($stored === '') {
            return [];
        }
        $decoded = json_decode(encryption::decrypt($stored), true);
        return is_array($decoded) ? $decoded : [];
    }
}
