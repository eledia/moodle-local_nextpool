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

namespace local_nextpool\fixtures;

/**
 * Content type handler registered through the hook collect_content_types, for content_types_hook_test only.
 *
 * Places a URL resource like the built-in url handler and records the options it receives.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_content_type extends \local_nextpool\local\content_type\url {
    /** @var array[] Options received by place(), in call order. */
    public static array $placeoptions = [];

    #[\Override]
    public function place(\stdClass $item, \stdClass $version, \stdClass $course, int $section, array $options = []): int {
        self::$placeoptions[] = $options;
        return parent::place($item, $version, $course, $section, $options);
    }

    #[\Override]
    public function extend_place_form(\MoodleQuickForm $form): void {
        $form->addElement('select', 'testdisplay', 'Display', ['embed' => 'Embed', 'popup' => 'Popup']);
        $form->setType('testdisplay', PARAM_ALPHA);
        $form->setDefault('testdisplay', 'embed');
    }

    #[\Override]
    public function get_place_options(\stdClass $data): array {
        return ['display' => (string) $data->testdisplay];
    }
}
