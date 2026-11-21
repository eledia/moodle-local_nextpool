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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Pick the AI proposals to add to an item (F14).
 *
 * Custom data:
 * - 'id' (int) item id
 * - 'entries' (array) proposals in the nugget format
 * - 'vocabulary' (array) competencies keyed by idnumber, for the labels
 * - 'existing' (string[]) idnumbers the item already has
 *
 * The proposals travel as JSON in a hidden field and are validated again on submit.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency_suggestion_form extends \moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('hidden', 'id', (int) $custom['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'suggestions', json_encode($custom['entries']));
        $mform->setType('suggestions', PARAM_RAW);

        foreach (array_values($custom['entries']) as $i => $entry) {
            $idnumber = $entry['competency'];
            $label = get_string('competencyoption', 'local_nextpool', (object) [
                'name' => format_string($custom['vocabulary'][$idnumber]['name'] ?? $idnumber),
                'idnumber' => s($idnumber),
                'framework' => format_string($custom['vocabulary'][$idnumber]['framework'] ?? ''),
            ]);
            $details = get_string('competencylevel_' . $entry['level'], 'local_nextpool')
                . ', ' . get_string('competencyfunction_' . $entry['function'], 'local_nextpool');
            foreach ($entry['objectives'] as $objective) {
                // The Bloom level by its name, not its key (bug63, Q-23).
                $bloom = $objective['bloom'] ? get_string('bloom_' . $objective['bloom'], 'local_nextpool') . ': ' : '';
                $details .= \html_writer::empty_tag('br') . s($bloom . $objective['text']);
            }
            $exists = in_array($idnumber, $custom['existing'], true);
            if ($exists) {
                $details .= \html_writer::empty_tag('br') . get_string('competencysuggestexists', 'local_nextpool');
            }
            $mform->addElement('advcheckbox', 'pick[' . $i . ']', $label, $details);
            $mform->setDefault('pick[' . $i . ']', $exists ? 0 : 1);
            if ($exists) {
                $mform->freeze('pick[' . $i . ']');
            }
        }

        $this->add_action_buttons(true, get_string('competencysuggestapply', 'local_nextpool'));
    }

    /**
     * Proposals the editor picked.
     *
     * @param \stdClass $data Data from get_data().
     * @param array $entries Proposals as validated from the hidden field.
     * @return array Picked entries.
     */
    public static function picked(\stdClass $data, array $entries): array {
        $pick = (array) ($data->pick ?? []);
        return array_values(array_filter(
            array_values($entries),
            static fn($entry, $i) => !empty($pick[$i]),
            ARRAY_FILTER_USE_BOTH
        ));
    }
}
