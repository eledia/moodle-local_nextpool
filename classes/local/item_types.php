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
 * Content types a catalogue item can have (F04).
 *
 * The type decides which handler places and updates the content. All types
 * except 'generic' are updated in place (adr07); 'generic' (.mbz) falls back to
 * soft-replace. The catalogue brings handlers for 'file', 'h5p', 'scorm', 'book',
 * 'page', 'url' and 'quiz'; other plugins add handlers for further types through the hook
 * collect_content_types (task45), for example mod_nextpool for 'xapi'. Types
 * without a handler can be stored and published but not yet placed in courses.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_types {
    /** @var string[] Types with an in-place handler. */
    private const IN_PLACE = ['quiz', 'scorm', 'h5p', 'xapi', 'file', 'page', 'url', 'book', 'assign'];

    /** @var string Fallback type, updated by soft-replace. */
    public const GENERIC = 'generic';

    /**
     * All types.
     *
     * @return string[]
     */
    public static function all(): array {
        return [...self::IN_PLACE, self::GENERIC];
    }

    /**
     * Whether a type exists.
     *
     * @param string $type Type.
     * @return bool
     */
    public static function is_valid(string $type): bool {
        return in_array($type, self::all(), true);
    }

    /**
     * Whether updates of this type keep the course module and learner data (in place).
     *
     * @param string $type Type.
     * @return bool
     */
    public static function supports_in_place(string $type): bool {
        return in_array($type, self::IN_PLACE, true);
    }

    /** @var array<string,class-string<content_type\base>> Types that can already be placed in courses. */
    private const HANDLERS = [
        'file' => content_type\file::class,
        'h5p' => content_type\h5p::class,
        'scorm' => content_type\scorm::class,
        'book' => content_type\book::class,
        'page' => content_type\page::class,
        'url' => content_type\url::class,
        'quiz' => content_type\quiz::class,
    ];

    /** @var array<string,class-string<content_type\base>>|null Handlers of other plugins, collected once per request. */
    private static ?array $pluginhandlers = null;

    /**
     * Handler that places and updates content of a type.
     *
     * @param string $type Type.
     * @return content_type\base|null Null while the type cannot be placed yet.
     */
    public static function get_handler(string $type): ?content_type\base {
        $class = self::get_handler_class($type);
        return $class ? new $class() : null;
    }

    /**
     * Whether items of a type can be placed in courses yet.
     *
     * @param string $type Type.
     * @return bool
     */
    public static function is_placeable(string $type): bool {
        return self::get_handler_class($type) !== null;
    }

    /**
     * Whether the catalogue itself brings the handler of a type. Plugins cannot replace these.
     *
     * @param string $type Type.
     * @return bool
     */
    public static function has_builtin_handler(string $type): bool {
        return isset(self::HANDLERS[$type]);
    }

    /**
     * Forget the handlers collected from other plugins (tests, plugin installation).
     */
    public static function reset_cache(): void {
        self::$pluginhandlers = null;
    }

    /**
     * Handler class of a type: the built-in one first, then one registered through the hook.
     *
     * @param string $type Type.
     * @return class-string<content_type\base>|null
     */
    private static function get_handler_class(string $type): ?string {
        if (isset(self::HANDLERS[$type])) {
            return self::HANDLERS[$type];
        }
        if (self::$pluginhandlers === null) {
            $hook = new \local_nextpool\hook\collect_content_types();
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            self::$pluginhandlers = $hook->get_handlers();
        }
        return self::$pluginhandlers[$type] ?? null;
    }

    /**
     * Options for a select element; types that cannot be placed yet say so (bug27).
     *
     * @return array<string,string>
     */
    public static function get_menu(): array {
        $menu = [];
        foreach (self::all() as $type) {
            $name = get_string('type_' . $type, 'local_nextpool');
            $menu[$type] = self::is_placeable($type) ? $name : get_string('type_noplacement', 'local_nextpool', $name);
        }
        return $menu;
    }

    /** @var string[] Order of the types in the item form, most common first (bug207, t157-c). */
    private const FORM_ORDER = ['file', 'h5p', 'scorm', 'xapi', 'page', 'book', 'url', 'quiz'];

    /**
     * Options for the type in the item form: only types that can be placed in courses, most common first (bug207).
     *
     * Types of other plugins follow in the order of all(). The current type of an existing item stays in the
     * list even if it cannot be placed, marked as in get_menu().
     *
     * @param string $current Type of the item being edited, '' when adding.
     * @return array<string,string>
     */
    public static function get_form_menu(string $current = ''): array {
        $all = self::get_menu();
        $menu = [];
        foreach (array_unique([...self::FORM_ORDER, ...self::all()]) as $type) {
            if (isset($all[$type]) && (self::is_placeable($type) || $type === $current)) {
                $menu[$type] = $all[$type];
            }
        }
        return $menu;
    }

    /** @var string Type proposed for a new item (bug27). */
    private const DEFAULT = 'file';

    /**
     * Type proposed for a new item: 'file', or the first one that can be placed.
     *
     * @return string
     */
    public static function get_default(): string {
        if (self::is_placeable(self::DEFAULT)) {
            return self::DEFAULT;
        }
        foreach (self::all() as $type) {
            if (self::is_placeable($type)) {
                return $type;
            }
        }
        return self::all()[0];
    }
}
