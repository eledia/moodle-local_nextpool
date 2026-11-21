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
 * Read-only result of detecting a direct upload (M08, task191).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upload_detection {
    /** @var array Metadata by recognised type, in preferred order. */
    public readonly array $candidates;
    /** @var array Reasons for types the user cannot create. */
    public readonly array $reasons;

    /**
     * Keep all recognised types, including those the user may not create.
     *
     * @param array $candidates Metadata by type, in preferred order.
     * @param array $reasons Rejected types and their reasons.
     */
    public function __construct(array $candidates, array $reasons) {
        $this->candidates = $candidates;
        $this->reasons = $reasons;
    }

    /**
     * Types the user may choose.
     *
     * @return string[]
     */
    public function get_allowed_types(): array {
        return array_values(array_diff(array_keys($this->candidates), array_keys($this->reasons)));
    }

    /**
     * Preferred allowed type, null if none is allowed.
     *
     * @return string|null
     */
    public function get_type(): ?string {
        return $this->get_allowed_types()[0] ?? null;
    }

    /**
     * Explain where progress for a recognised type is recorded.
     *
     * @param string $type Recognised type.
     * @return string Localised sentence.
     */
    public function get_learning_data_notice(string $type): string {
        $handler = item_types::get_handler($type);
        return get_string(
            $handler->supports_learning_data() ? 'uploadlearningdata' : 'uploadactivitydata',
            'local_nextpool',
            get_string('modulename', 'mod_' . $handler->get_modname())
        );
    }
}
