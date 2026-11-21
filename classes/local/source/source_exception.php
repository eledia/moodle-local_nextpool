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
 * Thrown when a storage backend cannot fulfil a request.
 *
 * Messages never contain credentials; HTTP backends pass only the status code.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode Language string key.
     * @param mixed $a Optional language string parameter.
     * @param string $component Component holding the string; backends may name their own (task72).
     */
    public function __construct(string $errorcode, $a = null, string $component = 'local_nextpool') {
        parent::__construct($errorcode, $component, '', $a);
    }
}
