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

/**
 * Optional preview providers for types whose runtime lives in another plugin (F16 task207).
 *
 * Providers expose static get_preview($item, $version, $courseid): preview_result.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Add catalogue preview providers')]
#[\core\attribute\tags('local_nextpool')]
final class collect_preview_providers {
    /** @var array Type => provider class. */
    private array $providers = [];

    /**
     * Add an available provider once.
     *
     * @param string $type Content type.
     * @param string $class Provider class with get_preview().
     */
    public function add(string $type, string $class): void {
        if (
            \local_nextpool\local\item_types::is_valid($type) && is_callable([$class, 'get_preview'])
                && !isset($this->providers[$type])
        ) {
            $this->providers[$type] = $class;
        }
    }

    /**
     * Registered providers.
     *
     * @return array
     */
    public function get_providers(): array {
        return $this->providers;
    }
}
