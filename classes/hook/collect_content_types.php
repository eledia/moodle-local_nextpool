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

namespace local_nextpool\hook;

use local_nextpool\local\content_type\base;
use local_nextpool\local\item_types;

/**
 * Lets plugins add handlers that place and update catalogue items of a type (task45).
 *
 * Only types the catalogue knows (item_types::all()) can get a handler, and the
 * catalogue's own handlers cannot be replaced. Example callback in db/hooks.php:
 * ['hook' => \local_nextpool\hook\collect_content_types::class,
 *  'callback' => [\mod_example\content_type\xapi::class, 'register']]
 * where register($hook) calls $hook->add('xapi', self::class).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Add handlers that place catalogue items of a type in courses')]
#[\core\attribute\tags('local_nextpool')]
final class collect_content_types {
    /** @var array<string,class-string<base>> Registered handler classes by type. */
    private array $handlers = [];

    /**
     * Register a handler class for a type. Invalid registrations are ignored with a debugging message.
     *
     * @param string $type Item type, one of item_types::all().
     * @param string $class Subclass of \local_nextpool\local\content_type\base.
     */
    public function add(string $type, string $class): void {
        if (!item_types::is_valid($type)) {
            debugging("local_nextpool: unknown content type '{$type}' ignored", DEBUG_DEVELOPER);
            return;
        }
        if (item_types::has_builtin_handler($type)) {
            debugging("local_nextpool: content type '{$type}' has a built-in handler, '{$class}' ignored", DEBUG_DEVELOPER);
            return;
        }
        if (!class_exists($class) || !is_subclass_of($class, base::class)) {
            debugging("local_nextpool: '{$class}' is not a content type handler, ignored", DEBUG_DEVELOPER);
            return;
        }
        if (isset($this->handlers[$type])) {
            debugging("local_nextpool: content type '{$type}' already has a handler, '{$class}' ignored", DEBUG_DEVELOPER);
            return;
        }
        $this->handlers[$type] = $class;
    }

    /**
     * Registered handler classes.
     *
     * @return array<string,class-string<base>> By type.
     */
    public function get_handlers(): array {
        return $this->handlers;
    }
}
