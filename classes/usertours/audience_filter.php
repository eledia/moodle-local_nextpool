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

namespace local_nextpool\usertours;

use local_nextpool\local\access;
use local_nextpool\local\item_manager;
use tool_usertours\tour;

/**
 * Core tour audience filter based on capabilities, including custom editorial roles (task177).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audience_filter extends \tool_usertours\local\filter\base {
    #[\Override]
    public static function get_filter_name() {
        return 'nextpool_audience';
    }

    #[\Override]
    public static function get_filter_options() {
        return [
            'teacher' => get_string('tour_audience_teacher', 'local_nextpool'),
            'editorial' => get_string('tour_audience_editorial', 'local_nextpool'),
        ];
    }

    #[\Override]
    public static function add_filter_to_form(\MoodleQuickForm &$mform) {
        $mform->addElement(
            'select',
            'filter_nextpool_audience',
            get_string('tour_audience', 'local_nextpool'),
            [self::ANYVALUE => get_string('all')] + self::get_filter_options(),
            ['multiple' => true]
        );
        $mform->setDefault('filter_nextpool_audience', self::ANYVALUE);
    }

    #[\Override]
    public static function filter_matches(tour $tour, \context $context) {
        global $PAGE, $USER;
        $values = $tour->get_filter_values(self::get_filter_name());
        if (!$values) {
            return true;
        }
        if (in_array('teacher', $values, true) && $context->contextlevel >= CONTEXT_COURSE) {
            $course = $context->get_course_context();
            if (
                has_capability('local/nextpool:viewcatalogue', $course)
                    && has_capability('moodle/course:manageactivities', $course)
            ) {
                return true;
            }
        }
        if (in_array('editorial', $values, true)) {
            if ($PAGE->url->compare(new \moodle_url('/local/nextpool/item.php'), URL_MATCH_BASE)) {
                $id = (int) $PAGE->url->get_param('id');
                return $id > 0 && access::can_view(item_manager::get($id));
            }
            // Core's reset AJAX request has a service URL, rather than the original item URL.
            // The actual item page already checks that particular item's access before rendering the tour.
            if ($tour->get_pathmatch() === '/local/nextpool/item.php%') {
                return access::can_use_editorial() || \local_nextpool\local\item_editor_manager::has_items((int) $USER->id);
            }
            return access::can_use_editorial();
        }
        return false;
    }
}
