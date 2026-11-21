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

use local_nextpool\customfield\item_handler;
use local_nextpool\local\access;
use local_nextpool\local\category_manager;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\license_info;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\path;
use local_nextpool\output\item_page;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/licenselib.php');

/**
 * Add or edit a catalogue item (F01, F09).
 *
 * Custom data:
 * - 'id' (int, 0 when adding)
 * - 'locked' (bool) type and storage location cannot change because versions exist
 * - 'sourceid' (int) current storage location, kept in the menu even if disabled
 * - 'contextid' (int) current visibility context, 0 when adding; a course context limits the menu (TD-403)
 * - 'authorcount' (int) number of author rows to show
 * - 'authorusers' (array<int,string>) labels (plain text) of the Moodle users already chosen as authors
 * - 'competencycount' (int) number of competency rows to show (F14)
 * - 'timemodified' (int) and 'usermodified' (int) stored state of the item, for the edit lock (F08.AC09)
 * - 'visibilitylocked' (bool) only the current visibility is offered: item editors cannot change it (q68)
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_form extends \moodleform {
    use edit_lock;

    #[\Override]
    protected function definition() {
        global $OUTPUT;
        $mform = $this->_form;
        $custom = $this->_customdata;

        $mform->addElement('hidden', 'id', (int) ($custom['id'] ?? 0));
        $mform->setType('id', PARAM_INT);
        if (!empty($custom['id'])) {
            $this->add_edit_lock('timemodified', (int) ($custom['timemodified'] ?? 0));
        }

        $mform->addElement('header', 'general', get_string('general'));

        $mform->addElement('text', 'title', get_string('title', 'local_nextpool'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Required fields and the file first, the description after them (bug207, task157b). Types that cannot be placed in
        // courses are not offered for new items (t157-c).
        $types = item_types::get_form_menu((string) ($custom['type'] ?? ''));
        $mform->addElement('select', 'type', get_string('itemtype', 'local_nextpool'), $types);
        $mform->setDefault('type', item_types::get_default());
        $mform->addHelpButton('type', 'itemtype', 'local_nextpool');

        $mform->addElement('select', 'sourceid', get_string('source', 'local_nextpool'), $this->get_source_menu());
        $mform->setType('sourceid', PARAM_INT);
        $mform->addHelpButton('sourceid', 'source', 'local_nextpool');

        if (!empty($custom['locked'])) {
            $mform->hardFreeze(['type', 'sourceid']);
        }

        // A new item can get its first version right away (task101).
        if (empty($custom['id'])) {
            $mform->addElement('filepicker', 'initialfile', get_string('initialfile', 'local_nextpool'), null, [
                'accepted_types' => '*',
            ]);
            $mform->addHelpButton('initialfile', 'initialfile', 'local_nextpool');
            // Which file the chosen type expects; only the line of the chosen type is shown (bug206).
            foreach (array_keys($types) as $type) {
                $expected = item_types::get_handler($type)?->get_expected_file() ?? '';
                if ($expected !== '') {
                    $mform->addElement('static', 'expectedfile_' . $type, get_string('expectedfile', 'local_nextpool'), $expected);
                    $mform->hideIf('expectedfile_' . $type, 'type', 'neq', $type);
                }
            }
        }

        // Licence and authors are required like the title, so they stay in the first section (PO 2026-11-15).
        $mform->addElement('select', 'license', get_string('license'), \license_manager::get_active_licenses_as_array());
        // Core shortnames contain dots (cc-4.0); PARAM_ALPHANUMEXT would drop them (bug82). The select limits the values.
        $mform->setType('license', PARAM_TEXT);
        $mform->addHelpButton('license', 'itemlicense', 'local_nextpool');
        // New items get "All rights reserved" unless the site sets another default (task169).
        $mform->setDefault('license', license_info::default_for_new());
        $mform->addRule('license', null, 'required', null, 'client');

        // The authors are required like title and licence, but by a rule of the form: mark them the same way (bug207).
        $required = $OUTPUT->pix_icon('req', get_string('requiredelement', 'form'), 'moodle', ['class' => 'text-danger']);
        $mform->addElement(
            'static',
            'authorsintro',
            get_string('authors', 'local_nextpool') . ' ' . $required,
            get_string('authors_desc', 'local_nextpool')
        );
        $repeat = [
            // Every label carries the number, so each field names its row (bug28, bug65).
            $mform->createElement('static', 'author_heading', get_string('authorno', 'local_nextpool', '{no}'), ''),
            $mform->createElement(
                'autocomplete',
                'author_userid',
                get_string('authoruserno', 'local_nextpool', '{no}'),
                // Full names are plain text; core prints option texts as HTML, like the labels of the user search (bug122).
                array_map('s', $custom['authorusers'] ?? []),
                [
                    'ajax' => 'local_nextpool/user_selector',
                    'multiple' => false,
                    'noselectionstring' => get_string('authornouser', 'local_nextpool'),
                ]
            ),
            $mform->createElement('text', 'author_name', get_string('authornameno', 'local_nextpool', '{no}'), ['size' => 40]),
            $mform->createElement('text', 'author_role', get_string('authorroleno', 'local_nextpool', '{no}'), ['size' => 40]),
            $mform->createElement('submit', 'author_delete', get_string('authorremove', 'local_nextpool', '{no}'), [], false),
        ];
        $options = [
            'author_userid' => ['type' => PARAM_INT],
            'author_name' => ['type' => PARAM_TEXT],
            'author_role' => ['type' => PARAM_TEXT],
        ];
        $repeats = $this->repeat_elements(
            $repeat,
            max(1, (int) ($custom['authorcount'] ?? 1)),
            $options,
            'author_repeats',
            'author_add',
            1,
            get_string('authoradd', 'local_nextpool'),
            true,
            'author_delete'
        );
        $this->add_repeated_help($repeats, ['author_name' => 'authorname']);

        $mform->addElement('editor', 'description_editor', get_string('description'), null, ['maxfiles' => 0]);
        $mform->setType('description_editor', PARAM_RAW);

        $mform->addElement('select', 'contextid', get_string('visibility', 'local_nextpool'), $this->get_visibility_menu());
        $mform->setType('contextid', PARAM_INT);
        $mform->addHelpButton('contextid', 'visibility', 'local_nextpool');

        $categories = ['' => get_string('nocategory', 'local_nextpool')] + category_manager::get_menu();
        $mform->addElement('select', 'categoryid', get_string('category', 'local_nextpool'), $categories);
        $mform->addHelpButton('categoryid', 'category', 'local_nextpool');
        $mform->setType('categoryid', PARAM_INT);

        $mform->addElement('header', 'details', get_string('itemdetails', 'local_nextpool'));

        $languages = ['' => get_string('notspecified', 'local_nextpool')] + get_string_manager()->get_list_of_languages();
        $mform->addElement('select', 'language', get_string('language'), $languages);
        $mform->setType('language', PARAM_ALPHANUMEXT);

        $mform->addElement('duration', 'duration', get_string('duration', 'local_nextpool'), [
            'optional' => false,
            'defaultunit' => MINSECS,
            'units' => [MINSECS, HOURSECS],
        ]);
        $mform->addHelpButton('duration', 'duration', 'local_nextpool');

        $mform->addElement('text', 'audience', get_string('audience', 'local_nextpool'), ['size' => 60]);
        $mform->setType('audience', PARAM_TEXT);
        $mform->addHelpButton('audience', 'audience', 'local_nextpool');
        $mform->addRule('audience', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('tags', 'tags', get_string('tags'), [
            'itemtype' => item_manager::TAG_ITEMTYPE,
            'component' => 'local_nextpool',
        ]);

        $mform->addElement('text', 'origin', get_string('origin', 'local_nextpool'), ['size' => 60]);
        $mform->setType('origin', PARAM_TEXT);
        $mform->addRule('origin', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('origin', 'origin', 'local_nextpool');

        $this->define_competencies((int) ($custom['competencycount'] ?? 0));

        // Custom fields defined by the admin (F01); they bring their own headers.
        $this->get_customfield_handler()->instance_form_definition($mform, (int) ($custom['id'] ?? 0));

        $this->add_action_buttons();
    }

    /**
     * Repeated rows for the competencies of the item (F14).
     *
     * The competency field searches the competencies of visible frameworks by idnumber or name
     * (local_nextpool_search_competencies); an idnumber that does not exist yet can be typed in
     * and is kept, with a notice to the editors.
     *
     * @param int $count Number of filled rows.
     */
    private function define_competencies(int $count): void {
        $mform = $this->_form;
        $mform->addElement('header', 'competenciesheader', get_string('competencies', 'local_nextpool'));
        $mform->setExpanded('competenciesheader', $count > 0);
        $mform->addElement('static', 'competenciesintro', '', get_string('competencies_desc', 'local_nextpool'));

        $levels = [];
        for ($level = item_competencies::LEVEL_MIN; $level <= item_competencies::LEVEL_MAX; $level++) {
            $levels[$level] = get_string('competencylevel_' . $level, 'local_nextpool');
        }
        $functions = [];
        foreach (item_competencies::FUNCTIONS as $function) {
            $functions[$function] = get_string('competencyfunction_' . $function, 'local_nextpool');
        }
        $repeat = [
            // Every label carries the number, so each field names its row (bug28, bug65).
            $mform->createElement(
                'autocomplete',
                'competency_idnumber',
                get_string('competencyno', 'local_nextpool', '{no}'),
                [],
                [
                    'multiple' => false,
                    'tags' => true,
                    // The choices come from a search instead of the whole list of the site in every row (bug52).
                    'ajax' => 'local_nextpool/competency_selector',
                    // A callable that survives the serialisation in repeat_elements(), unlike a closure.
                    'valuehtmlcallback' => [self::class, 'get_competency_label'],
                    'noselectionstring' => get_string('competencynone', 'local_nextpool'),
                ]
            ),
            $mform->createElement(
                'select',
                'competency_level',
                get_string('competencylevelno', 'local_nextpool', '{no}'),
                $levels
            ),
            $mform->createElement(
                'select',
                'competency_function',
                get_string('competencyfunctionno', 'local_nextpool', '{no}'),
                $functions
            ),
            $mform->createElement(
                'textarea',
                'competency_objectives',
                get_string('competencyobjectivesno', 'local_nextpool', '{no}'),
                ['rows' => 3, 'cols' => 60]
            ),
            $mform->createElement(
                'submit',
                'competency_delete',
                get_string('competencyremove', 'local_nextpool', '{no}'),
                [],
                false
            ),
        ];
        $options = [
            'competency_idnumber' => ['type' => PARAM_RAW_TRIMMED],
            'competency_level' => ['type' => PARAM_INT, 'default' => 1],
            'competency_function' => ['type' => PARAM_ALPHA],
            'competency_objectives' => ['type' => PARAM_TEXT],
        ];
        $repeats = $this->repeat_elements(
            $repeat,
            max(1, $count),
            $options,
            'competency_repeats',
            'competency_add',
            1,
            get_string('competencyadd', 'local_nextpool'),
            true,
            'competency_delete'
        );
        $this->add_repeated_help($repeats, [
            'competency_idnumber' => 'competency',
            'competency_level' => 'competencylevel',
            'competency_function' => 'competencyfunction',
            'competency_objectives' => 'competencyobjectives',
        ]);
    }

    /**
     * Label of a chosen competency in the autocomplete (bug52).
     *
     * @param string $idnumber Competency idnumber.
     * @return string|false Label (HTML), or false to show the idnumber as typed.
     */
    public static function get_competency_label($idnumber) {
        return item_competencies::get_label((string) $idnumber) ?? false;
    }

    /**
     * Help buttons for the rows of a repeated block that were not removed.
     *
     * repeat_elements() would add them to removed rows as well and trigger a debugging message.
     *
     * @param int $repeats Number of rows.
     * @param array $help Element name => help string identifier.
     */
    private function add_repeated_help(int $repeats, array $help): void {
        $mform = $this->_form;
        for ($i = 0; $i < $repeats; $i++) {
            foreach ($help as $element => $identifier) {
                if ($mform->elementExists($element . "[$i]")) {
                    $mform->addHelpButton($element . "[$i]", $identifier, 'local_nextpool');
                }
            }
        }
    }

    #[\Override]
    public function definition_after_data() {
        parent::definition_after_data();
        $this->refresh_edit_lock('timemodified', (int) ($this->_customdata['timemodified'] ?? 0));
        $this->get_customfield_handler()->instance_form_definition_after_data(
            $this->_form,
            (int) ($this->_customdata['id'] ?? 0)
        );
    }

    /**
     * Custom field handler of catalogue items.
     *
     * @return item_handler
     */
    private function get_customfield_handler(): item_handler {
        return item_handler::instance();
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }

        $hasauthor = false;
        foreach (array_keys($data['author_name'] ?? []) as $i) {
            $name = trim((string) ($data['author_name'][$i] ?? ''));
            $role = trim((string) ($data['author_role'][$i] ?? ''));
            if (!empty($data['author_userid'][$i]) || $name !== '') {
                $hasauthor = true;
            }
            if (\core_text::strlen($name) > 255) {
                $errors["author_name[$i]"] = get_string('maximumchars', '', 255);
            }
            if (\core_text::strlen($role) > 100) {
                $errors["author_role[$i]"] = get_string('maximumchars', '', 100);
            }
        }
        if (!$hasauthor) {
            // Removed rows leave gaps; the message goes to the first row that is still there.
            $first = array_key_first($data['author_name'] ?? []);
            $errors[$first === null ? 'authorsintro' : "author_name[$first]"] = get_string('error_noauthor', 'local_nextpool');
        }

        $errors += self::validate_competencies($data);

        if (!array_key_exists((int) ($data['contextid'] ?? 0), $this->get_visibility_menu())) {
            $errors['contextid'] = get_string('error_invalidcontext', 'local_nextpool');
        }

        if (!empty($data['id'])) {
            $stored = (int) ($this->_customdata['timemodified'] ?? 0);
            $by = (int) ($this->_customdata['usermodified'] ?? 0);
            $errors += $this->check_edit_lock($data, 'timemodified', $stored, fn() => self::get_conflict_message($by, $stored));
        }

        $draftfiles = empty($data['id']) && !empty($data['initialfile']) ? $this->get_draft_files('initialfile') : [];
        if ($draftfiles) {
            try {
                $source = source_manager::get((int) ($data['sourceid'] ?? 0));
                if (!$source->supports_write()) {
                    $errors['initialfile'] = get_string('error_sourcereadonly', 'local_nextpool');
                } else {
                    // Show a clash with an existing file next to the field instead of after the save.
                    $filename = clean_param(reset($draftfiles)->get_filename(), PARAM_FILE);
                    $path = $filename === '' ? null : path::normalise($filename);
                    if ($path !== null && $source->stat($path) !== null) {
                        $errors['initialfile'] = get_string('error_fileexists', 'local_nextpool', s($path));
                    } else {
                        // A file the chosen type cannot use is named at the field, not after the save (bug205).
                        $tempfile = make_request_directory() . '/initialfile';
                        reset($draftfiles)->copy_content_to($tempfile);
                        item_types::get_handler((string) ($data['type'] ?? ''))?->validate_package($tempfile);
                    }
                }
            } catch (\moodle_exception $e) {
                $errors['initialfile'] = $e->getMessage();
            }
        }
        return array_merge($errors, $this->get_customfield_handler()->instance_form_validation($data, $files));
    }

    /**
     * Errors of the competency rows, using the rules of item_competencies.
     *
     * @param array $data Submitted data.
     * @return array<string,string> Element name => error.
     */
    private static function validate_competencies(array $data): array {
        $errors = [];
        $seen = [];
        foreach (array_keys($data['competency_idnumber'] ?? []) as $i) {
            $entry = self::competency_entry($data, $i);
            if ($entry['competency'] === '') {
                continue;
            }
            $field = 'competency_idnumber';
            try {
                if (isset($seen[$entry['competency']])) {
                    throw new \moodle_exception('error_competencyduplicate', 'local_nextpool', '', s($entry['competency']));
                }
                $seen[$entry['competency']] = true;
                $entry['objectives'] = self::text_to_objectives($entry['objectives']);
                item_competencies::validate([$entry]);
            } catch (\moodle_exception $e) {
                $field = match ($e->errorcode) {
                    'error_competencylevel' => 'competency_level',
                    'error_competencyfunction' => 'competency_function',
                    'error_competencybloom' => 'competency_objectives',
                    default => 'competency_idnumber',
                };
                $errors["{$field}[$i]"] = $e->getMessage();
            }
        }
        return $errors;
    }

    /**
     * One competency row of the submitted data, objectives still as text.
     *
     * @param array $data Submitted data (array form).
     * @param int $i Row index.
     * @return array{competency:string,level:int,function:string,objectives:string}
     */
    private static function competency_entry(array $data, int $i): array {
        return [
            'competency' => trim((string) ($data['competency_idnumber'][$i] ?? '')),
            'level' => (int) ($data['competency_level'][$i] ?? 0),
            'function' => (string) ($data['competency_function'][$i] ?? ''),
            'objectives' => (string) ($data['competency_objectives'][$i] ?? ''),
        ];
    }

    /**
     * Learning objectives from the text field: one per line, "id | bloom | text", "bloom | text" or "text".
     *
     * @param string $text Field content.
     * @return array[] Objectives with id, bloom and text.
     */
    public static function text_to_objectives(string $text): array {
        $objectives = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) === 3) {
                $objectives[] = ['id' => $parts[0], 'bloom' => $parts[1], 'text' => $parts[2]];
            } else if (count($parts) === 2) {
                $objectives[] = ['id' => '', 'bloom' => $parts[0], 'text' => $parts[1]];
            } else if ($parts[0] !== '') {
                $objectives[] = ['id' => '', 'bloom' => '', 'text' => $parts[0]];
            }
        }
        return $objectives;
    }

    /**
     * Learning objectives as text for the form, the reverse of text_to_objectives().
     *
     * @param array $objectives Objectives with id, bloom and text.
     * @return string
     */
    public static function objectives_to_text(array $objectives): string {
        $lines = [];
        foreach ($objectives as $objective) {
            $id = (string) ($objective['id'] ?? '');
            $bloom = (string) ($objective['bloom'] ?? '');
            $text = (string) ($objective['text'] ?? '');
            if ($id !== '') {
                $lines[] = "$id | $bloom | $text";
            } else if ($bloom !== '') {
                $lines[] = "$bloom | $text";
            } else {
                $lines[] = $text;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Competency rows prepared for set_data() (F14).
     *
     * @param \stdClass $data Form data to extend.
     * @param \stdClass[] $competencies Records from item_competencies::get().
     */
    public static function prepare_competencies(\stdClass $data, array $competencies): void {
        foreach (array_values($competencies) as $i => $competency) {
            $data->competency_idnumber[$i] = $competency->competency;
            $data->competency_level[$i] = (int) $competency->skilllevel;
            $data->competency_function[$i] = $competency->didacticfunction;
            $data->competency_objectives[$i] = self::objectives_to_text($competency->objectives);
        }
    }

    /**
     * Competency entries of the submitted data for item_competencies::set() (F14).
     *
     * @param \stdClass $data Data from get_data().
     * @return array[] Entries in nugget format.
     */
    public static function extract_competencies(\stdClass $data): array {
        $array = (array) $data;
        $entries = [];
        foreach (array_keys($array['competency_idnumber'] ?? []) as $i) {
            $entry = self::competency_entry($array, $i);
            if ($entry['competency'] === '') {
                continue;
            }
            $entry['objectives'] = self::text_to_objectives($entry['objectives']);
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Item record and author rows prepared for set_data().
     *
     * @param \stdClass $item Item record.
     * @param \stdClass[] $authors Author records.
     * @return \stdClass
     */
    public static function prepare_data(\stdClass $item, array $authors): \stdClass {
        $data = clone $item;
        $data->description_editor = ['text' => (string) $item->description, 'format' => (int) $item->descriptionformat];
        $data->categoryid = (int) $item->categoryid ?: '';
        $data->tags = item_manager::get_tags((int) $item->id);
        item_handler::instance()->instance_form_before_set_data($data);
        foreach (array_values($authors) as $i => $author) {
            $data->author_userid[$i] = (int) $author->userid ?: '';
            $data->author_name[$i] = (string) $author->name;
            $data->author_role[$i] = (string) $author->role;
        }
        self::prepare_competencies($data, item_competencies::get((int) $item->id));
        return $data;
    }

    /**
     * Split submitted data into item fields and the author list for item_manager.
     *
     * @param \stdClass $data Data from get_data().
     * @return array{0:\stdClass,1:array} Item fields and authors.
     */
    public static function extract_data(\stdClass $data): array {
        // Selects whose empty option is chosen may be missing from the submitted data.
        $item = (object) [
            'title' => $data->title,
            'description' => $data->description_editor['text'] ?? '',
            'descriptionformat' => (int) ($data->description_editor['format'] ?? FORMAT_HTML),
            'language' => (string) ($data->language ?? ''),
            'duration' => (int) ($data->duration ?? 0),
            'audience' => (string) ($data->audience ?? ''),
            'origin' => (string) ($data->origin ?? ''),
            'license' => (string) ($data->license ?? ''),
            'categoryid' => (int) ($data->categoryid ?? 0) ?: null,
            'contextid' => (int) $data->contextid,
        ];
        foreach (['type', 'sourceid'] as $field) {
            if (isset($data->$field)) {
                $item->$field = $data->$field;
            }
        }
        $authors = [];
        foreach (array_keys($data->author_name ?? []) as $i) {
            $authors[] = [
                'userid' => (int) ($data->author_userid[$i] ?? 0),
                'name' => $data->author_name[$i] ?? '',
                'role' => $data->author_role[$i] ?? '',
            ];
        }
        return [$item, $authors];
    }

    /**
     * Message of the edit lock: who saved the item when (F08.AC09).
     *
     * @param int $userid Person who saved last.
     * @param int $time When.
     * @return string
     */
    private static function get_conflict_message(int $userid, int $time): string {
        $user = $userid ? \core_user::get_user($userid) : null;
        return get_string('error_editconflict', 'local_nextpool', (object) [
            // The message is shown as HTML (bug122).
            'user' => $user && !$user->deleted ? s(fullname($user)) : get_string('unknownuser'),
            'time' => userdate($time, get_string('strftimedatetimeshort', 'core_langconfig')),
        ]);
    }

    /**
     * Enabled storage locations, plus the current one of the item.
     *
     * @return array<int,string>
     */
    private function get_source_menu(): array {
        $current = (int) ($this->_customdata['sourceid'] ?? 0);
        $menu = [];
        foreach (source_manager::get_records() as $record) {
            if ($record->enabled || (int) $record->id === $current) {
                $menu[(int) $record->id] = format_string($record->name);
            }
        }
        return $menu;
    }

    /**
     * Contexts the user may place items in: the site, or course categories.
     *
     * An item uploaded from a course keeps "Only course" as its current value and can be made available
     * in the course categories above that course or on the whole site, where the user may review
     * versions (TD-403, q54). No item is ever bound to a course here.
     *
     * @return array<int,string> Context id => name.
     */
    private function get_visibility_menu(): array {
        $currentid = (int) ($this->_customdata['contextid'] ?? 0);
        $current = $currentid ? \context::instance_by_id($currentid, IGNORE_MISSING) : false;
        if ($current && !empty($this->_customdata['visibilitylocked'])) {
            return [$current->id => item_page::get_visibility_name($current, false)];
        }
        if ($current && $current->contextlevel == CONTEXT_COURSE) {
            $menu = [$current->id => item_page::get_visibility_name($current, false)];
            foreach (array_reverse($current->get_parent_contexts()) as $parent) {
                if (has_capability(access::REVIEW, $parent)) {
                    $menu[$parent->id] = item_page::get_visibility_name($parent, false);
                }
            }
            return $menu;
        }
        $menu = [];
        $system = \context_system::instance();
        if (has_capability(access::MANAGE, $system)) {
            $menu[$system->id] = get_string('visibility_site', 'local_nextpool');
        }
        foreach (\core_course_category::make_categories_list(access::MANAGE) as $categoryid => $name) {
            $menu[\context_coursecat::instance($categoryid)->id] = get_string('visibility_category', 'local_nextpool', $name);
        }
        return $menu;
    }
}
