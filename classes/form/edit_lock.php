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

namespace local_nextpool\form;

/**
 * Optimistic lock of the editing forms (F08.AC09, task84).
 *
 * The form carries the state it was opened with (a timestamp or a version id) in a hidden field. If the
 * stored state differs when saving, validation fails with the name of the person who changed it in the
 * meantime; the entries stay in the form. The hidden field then holds the current state, so saving
 * again on purpose replaces the other changes (t84-d).
 *
 * The page passes the current state in the custom data; the form calls {@see add_edit_lock()} in
 * definition(), {@see refresh_edit_lock()} in definition_after_data() and {@see check_edit_lock()} in
 * validation(). The page shows {@see get_edit_conflict()} as an error notification.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait edit_lock {
    /** @var string Message about a conflicting change, '' if none. */
    protected string $editconflict = '';

    /**
     * Add the hidden field with the state the form is opened with.
     *
     * @param string $name Field name.
     * @param int $current Current state.
     */
    protected function add_edit_lock(string $name, int $current): void {
        $this->_form->addElement('hidden', $name, $current);
        $this->_form->setType($name, PARAM_INT);
    }

    /**
     * Put the current state into the hidden field, so the next save is measured against it.
     *
     * Only changes what the form shows: validation still sees the submitted state.
     *
     * @param string $name Field name.
     * @param int $current Current state.
     */
    protected function refresh_edit_lock(string $name, int $current): void {
        if ($this->_form->elementExists($name)) {
            $this->_form->getElement($name)->setValue($current);
        }
    }

    /**
     * Compare the submitted state with the current one.
     *
     * @param array $data Submitted data.
     * @param string $name Field name.
     * @param int $current Current state.
     * @param callable $message Returns the message naming the person who changed it; called on a conflict only.
     * @return array Validation errors (the hidden field), [] without a conflict.
     */
    protected function check_edit_lock(array $data, string $name, int $current, callable $message): array {
        if (!array_key_exists($name, $data) || (int) $data[$name] === $current) {
            return [];
        }
        $this->editconflict = (string) $message();
        return [$name => $this->editconflict];
    }

    /**
     * Message about a change by someone else since the form was opened.
     *
     * @return string '' if the last save had no conflict.
     */
    public function get_edit_conflict(): string {
        return $this->editconflict;
    }
}
