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

namespace local_lernhive\launcher;

/**
 * Stand-in for the launcher query of LernHive (task179), for hook_callbacks_test only.
 *
 * Loaded only when LernHive is not installed.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class launcher_manager {
    /** @var mixed Answer of shows_plugin(); a \Throwable is thrown instead. */
    public static mixed $answer = false;

    /** @var string[] Components asked for. */
    public static array $asked = [];

    /**
     * Whether the launcher shows an entry for a component to the current user.
     *
     * @param string $component Frankenstyle component.
     * @return mixed
     */
    public static function shows_plugin(string $component): mixed {
        self::$asked[] = $component;
        if (self::$answer instanceof \Throwable) {
            throw self::$answer;
        }
        return self::$answer;
    }
}
