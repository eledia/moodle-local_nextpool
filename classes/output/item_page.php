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

namespace local_nextpool\output;

use local_nextpool\local\access;
use local_nextpool\local\category_manager;
use local_nextpool\local\competency_suggester;
use local_nextpool\local\item_competencies;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\item_types;
use local_nextpool\local\item_viewer;
use local_nextpool\local\license_info;
use local_nextpool\local\placement_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\source\manager as source_manager;
use local_nextpool\local\source\source_exception;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Editorial view of a catalogue item: metadata, authors, competencies, versions and workflow actions (F01, F02, F14).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_page implements \renderable, \templatable {
    /** @var array<string,string> Icon of each target status in menus and notices (task105), "submit" as in next_step (bug232). */
    private const STATUS_ICONS = [
        status::REVIEW => 't/sendmessage',
        status::PUBLISHED => 'i/checkedcircle',
        status::WITHDRAWN => 't/hide',
        status::DRAFT => 't/left',
    ];

    /** @var array<int,string> Icon of the pill "Available in" per context level (task165). */
    private const VISIBILITY_ICONS = [
        CONTEXT_SYSTEM => 'i/siteevent',
        CONTEXT_COURSECAT => 'i/categoryevent',
        CONTEXT_COURSE => 'i/course',
    ];

    /** @var \stdClass[] Users named on the page, keyed by id. */
    private array $users = [];

    /**
     * Constructor.
     *
     * @param \stdClass $item Item record.
     */
    public function __construct(
        /** @var \stdClass Item record. */
        private readonly \stdClass $item,
    ) {
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        global $DB;

        $item = $this->item;
        $context = access::get_context($item);
        $canedit = access::can_edit($item);
        $authors = item_manager::get_authors((int) $item->id);
        $versions = version_manager::get_versions((int) $item->id);
        // Proposals in review have no number yet (q67); they lead the list, so reviewers find the review page (t80-h).
        $proposals = array_reverse(version_manager::get_unnumbered((int) $item->id, status::REVIEW));

        $this->users = $this->load_users(array_merge(
            array_map(static fn($a) => (int) $a->userid, $authors),
            array_map(static fn($v) => (int) $v->createdby, array_merge($versions, $proposals)),
            array_map(static fn($v) => (int) $v->publishedby, $versions),
        ));

        $current = null;
        foreach ($versions as $version) {
            if ((int) $version->id === (int) $item->currentversionid) {
                $current = $version;
            }
        }
        $itemurl = new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]);
        // Versions are newest first; the first one is the latest numbered version.
        $latest = $versions ? reset($versions) : null;
        $next = next_step::for_item($item, $latest);
        $rejectedwithoutcurrent = $latest && $latest->status === status::REJECTED && empty($item->currentversionid);
        $notice = $this->export_status_notice($current, $itemurl);

        // Header (task105, PO decision 1): "Import new version" and at most one labelled button for the next step;
        // everyday actions as icons, the rarer status changes and Delete in the "More actions" menu (bug72), a single one
        // as icon link to its confirmation instead of a menu (PO rule of task166/task167).
        $actions = [];
        $iconactions = [];
        $more = [];
        if ($canedit) {
            $actions[] = $output->single_button(
                new \moodle_url('/local/nextpool/import.php', ['id' => $item->id]),
                get_string('importversion', 'local_nextpool'),
                'get',
                ['type' => \single_button::BUTTON_PRIMARY]
            );
        }
        // The notice carries the item step itself; the header does not repeat it (t105-b).
        $shown = $notice['step']?->to;
        if ($next && !($next->isitem && $next->to === $shown)) {
            $actions[] = $next->button($output);
        }
        if ($next?->isitem) {
            $shown = $next->to;
        }
        foreach (status::get_transitions($item->status, true) as $to) {
            // Publishing an item needs a published version.
            $possible = ($to !== status::PUBLISHED || !empty($item->currentversionid))
                && !($to === status::REVIEW && $rejectedwithoutcurrent);
            if ($to !== $shown && $possible && access::can_change_status($item, $item->status, $to)) {
                // A link without sesskey: item.php asks first and posts the change from there (PO decision 2, bug112).
                $more[] = new \action_menu_link_secondary(
                    new \moodle_url($itemurl, ['action' => 'itemstatus', 'to' => $to]),
                    new \pix_icon(self::STATUS_ICONS[$to], ''),
                    get_string('itemtransition_' . $to, 'local_nextpool')
                );
            }
        }
        $viewurl = item_viewer::get_url($item);
        if ($viewurl) {
            $iconactions[] = table_actions::icon_link($viewurl, 'i/externallink', get_string('view'), '', ['target' => '_blank']);
        }
        if ($canedit) {
            $iconactions[] = table_actions::icon_link(
                new \moodle_url('/local/nextpool/edit.php', ['id' => $item->id]),
                't/edit',
                get_string('edit')
            );
            if (competency_suggester::is_available()) {
                $more[] = new \action_menu_link_secondary(
                    new \moodle_url('/local/nextpool/suggest.php', ['id' => $item->id]),
                    new \pix_icon('i/competencies', ''),
                    get_string('competencysuggest', 'local_nextpool')
                );
            }
            if ($this->has_web_url()) {
                // Only for item editors and the editorial team (q69, F08.AC10); item.php asks the storage location.
                $iconactions[] = table_actions::icon_link(
                    new \moodle_url('/local/nextpool/item.php', ['id' => $item->id, 'action' => 'opensource',
                        'sesskey' => sesskey()]),
                    'i/folder',
                    get_string('openinsource', 'local_nextpool'),
                    '',
                    ['target' => '_blank']
                );
            }
            if ($versions) {
                // Only reads the storage location; the sesskey guards the request to it.
                $more[] = new \action_menu_link_secondary(
                    new \moodle_url('/local/nextpool/item.php', ['id' => $item->id, 'action' => 'checkupdate',
                        'sesskey' => sesskey()]),
                    new \pix_icon('i/reload', ''),
                    get_string('checkupdate', 'local_nextpool')
                );
            }
        }
        if (access::can_manage($item) && $this->is_deletable($versions)) {
            // Leads to the confirmation page; deleting itself is a POST from there.
            $more[] = new \action_menu_link_secondary(
                new \moodle_url('/local/nextpool/item.php', ['id' => $item->id, 'action' => 'delete']),
                new \pix_icon('t/delete', ''),
                get_string('delete'),
                ['class' => 'text-danger']
            );
        }
        $iconactions[] = table_actions::more($more);

        $typename = item_types::is_valid($item->type) ? get_string('type_' . $item->type, 'local_nextpool') : s($item->type);
        $placements = placement_manager::count_for_item((int) $item->id);

        // Key facts as read-only chips in the header, only those the item has (task105).
        $chips = [];
        if ($current) {
            $chips[] = ['icon' => $output->pix_icon('i/files', ''), 'label' => get_string('currentversion', 'local_nextpool'),
                'value' => self::short_with_full(
                    get_string('versionshort', 'local_nextpool', (int) $current->versionnr),
                    get_string('versionnr', 'local_nextpool', (int) $current->versionnr)
                )];
        }
        if ($placements > 0) {
            // The chip leads to the item's statistics with courses and activities (t105-c, p110-e).
            $chips[] = ['icon' => $output->pix_icon('i/course', ''), 'label' => get_string('usedincourses', 'local_nextpool'),
                'value' => \html_writer::link(
                    new \moodle_url('/local/nextpool/statistics.php', ['id' => $item->id]),
                    s(get_string('placementcount', 'local_nextpool', $placements))
                )];
        }
        if ($item->language !== '') {
            $chips[] = ['icon' => $output->pix_icon('i/language', ''), 'label' => get_string('language'),
                'value' => get_string_manager()->get_list_of_languages()[$item->language] ?? s($item->language)];
        }
        if ($item->duration) {
            $chips[] = ['icon' => $output->pix_icon('i/duration', ''), 'label' => get_string('duration', 'local_nextpool'),
                'value' => format_time((int) $item->duration)];
        }
        $origin = self::get_origin_course_link($item);
        if ($origin !== '') {
            $chips[] = ['icon' => $output->pix_icon('i/upload', ''), 'label' => get_string('origincourse', 'local_nextpool'),
                'value' => $origin];
        }
        if ($item->categoryid) {
            $chips[] = ['icon' => $output->pix_icon('i/folder', ''), 'label' => get_string('category', 'local_nextpool'),
                'value' => s(category_manager::get_full_name((int) $item->categoryid))];
        }

        $mode = update_mode::for_type($item->type);
        // Item facts in the card of the latest version (task165): the update mode and "All rights reserved" as icons
        // only (task106, task169); storage location, availability, other licences and last change as pills.
        $iconfacts = [[
            'icon' => update_mode::icon($mode, $output),
            'label' => update_mode::label($mode),
            'description' => update_mode::description($mode),
        ]];
        $facts = [
            self::fact(
                $output->pix_icon('i/repository', ''),
                get_string('source', 'local_nextpool'),
                format_string(
                    (string) $DB->get_field('local_nextpool_source', 'name', ['id' => $item->sourceid]),
                    true,
                    ['escape' => false]
                )
            ),
            self::fact(
                $output->pix_icon(self::VISIBILITY_ICONS[(int) $context->contextlevel] ?? 'i/categoryevent', ''),
                get_string('visibility', 'local_nextpool'),
                self::get_visibility_name($context, false),
                self::get_visibility_short($context)
            ),
        ];
        if (license_info::is_all_rights_reserved($item->license)) {
            // Without a licence "All rights reserved" applies, shown as © only (task169, display only: q133).
            $license = get_string('labelvalue', 'local_nextpool', [
                'label' => get_string('license'),
                'value' => license_info::full_name($item->license),
            ]);
            $iconfacts[] = [
                'icon' => $output->pix_icon('i/copyright', '', 'local_nextpool'),
                'label' => $license,
                'description' => $license,
            ];
        } else {
            $facts[] = self::fact(
                '',
                get_string('license'),
                license_info::full_name($item->license),
                license_info::short_name($item->license)
            );
        }
        $facts[] = self::fact(
            $output->pix_icon('i/calendar', ''),
            get_string('lastmodified'),
            userdate((int) $item->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            userdate((int) $item->timemodified, get_string('strftimedatefullshort', 'core_langconfig'))
        );
        // Further details only when set, in the side column: the list shows what the item has, not what it lacks.
        $metadata = [
            [get_string('tags'), $this->render_tags($item, $output)],
            [get_string('audience', 'local_nextpool'), $item->audience !== null && $item->audience !== ''
                ? format_string($item->audience, true, ['context' => $context]) : ''],
            [get_string('origin', 'local_nextpool'), $item->origin !== null && $item->origin !== ''
                ? format_string($item->origin, true, ['context' => $context]) : ''],
            ...$this->export_customfields($item),
        ];
        $metadata = array_filter($metadata, static fn($m) => $m[1] !== '' && $m[1] !== '-');
        // Technical details only on request (task105): the identifier and the file of the latest version.
        $technical = [[get_string('uuid', 'local_nextpool'), s($item->uuid)]];
        if ($versions) {
            $technical[] = [get_string('sourcepath', 'local_nextpool'), s(reset($versions)->sourceref)];
        }
        $competencies = $this->export_competencies();

        return [
            'title' => format_string($item->title, true, ['context' => $context]),
            'contentlanguage' => str_replace('_', '-', (string) $item->language),
            'typename' => $typename,
            'typeicon' => $this->render_type_icon($item->type, $output),
            'chips' => $chips,
            'description' => format_text((string) $item->description, (int) $item->descriptionformat, ['context' => $context]),
            'hasdescription' => trim((string) $item->description) !== '',
            'status' => status::get_name($item->status),
            'statusclass' => status::get_badge_class($item->status),
            // The same pill with icon as status::badge() in the lists (task166).
            'statusicon' => $output->pix_icon(status::get_icon($item->status), ''),
            'statusnotice' => $notice['text'],
            'latestversionnotice' => $this->export_latest_version_notice($latest),
            'statusnoticeaction' => $notice['step']?->icon() ?? '',
            'actions' => implode('', $actions),
            'iconactions' => implode('', $iconactions),
            'iconfacts' => $iconfacts,
            'facts' => $facts,
            'metadata' => array_map(static fn($m) => ['label' => $m[0], 'value' => $m[1]], array_values($metadata)),
            'hasmetadata' => !empty($metadata),
            'technical' => array_map(static fn($m) => ['label' => $m[0], 'value' => $m[1]], $technical),
            'authors' => array_map(fn($a) => [
                'name' => $a->userid ? $this->linked_name((int) $a->userid) : s((string) $a->name),
                'role' => $a->role !== null && $a->role !== '' ? s($a->role) : '',
                'hasrole' => $a->role !== null && $a->role !== '',
            ], $authors),
            'hasauthors' => !empty($authors),
            ...$this->export_editors($output),
            'competencies' => $competencies,
            'hascompetencies' => !empty($competencies),
            // Versions are newest first, so the next one in the list is the previous version.
            'versions' => array_merge(
                array_map(fn($proposal) => $this->export_version($output, $proposal), $proposals),
                array_map(
                    // The item facts sit in the card of the latest version (task165, t165-a).
                    fn($i) => $this->export_version($output, $versions[$i], $versions[$i + 1] ?? null) + ['showfacts' => $i === 0],
                    array_keys($versions)
                )
            ),
            // Without a numbered version the facts stand under the heading "Versions".
            'factsinversion' => !empty($versions),
            'hasversions' => !empty($versions) || !empty($proposals),
            'versioncount' => count($versions) + count($proposals),
            // The file of the latest version is gone from the storage location (F03O.AC09, AC15).
            'sourcemissing' => !empty($item->timesourcemissing) ? get_string(
                'sourcemissing',
                'local_nextpool',
                userdate((int) $item->timesourcemissing, get_string('strftimedatetimeshort', 'core_langconfig'))
            ) : '',
        ];
    }

    /**
     * Notice when a version is published but the item is not, so the item is missing from course catalogues (task104).
     *
     * Draft or in review: the notice offers the next item step. Withdrawn: the item stays withdrawn when a version is
     * published (PO, 2026-11-14); the notice says so and offers to publish it again.
     *
     * @param \stdClass|null $current Current (published) version.
     * @param \moodle_url $itemurl Item page.
     * @return array{text: string, step: next_step|null} Text '' without notice.
     */
    private function export_status_notice(?\stdClass $current, \moodle_url $itemurl): array {
        $item = $this->item;
        if (!$current || $item->status === status::PUBLISHED) {
            return ['text' => '', 'step' => null];
        }
        if ($item->status !== status::WITHDRAWN) {
            return [
                'text' => get_string('itemnotpublishednotice', 'local_nextpool', (int) $current->versionnr),
                'step' => next_step::for_item_status($item, $itemurl),
            ];
        }
        $step = null;
        if (access::can_change_status($item, status::WITHDRAWN, status::PUBLISHED)) {
            $step = new next_step(
                status::PUBLISHED,
                get_string('itemtransition_published', 'local_nextpool'),
                self::STATUS_ICONS[status::PUBLISHED],
                new \moodle_url($itemurl, ['action' => 'itemstatus', 'to' => status::PUBLISHED]),
                true,
                true
            );
        }
        return ['text' => get_string('itemwithdrawnnotice', 'local_nextpool', (int) $current->versionnr), 'step' => $step];
    }

    /**
     * Explain the newest version's review state without confusing it with the catalogue entry (bug211, bug212).
     *
     * @param \stdClass|null $latest Latest numbered version.
     * @return array|null Message, optional formatted reason and notification class.
     */
    private function export_latest_version_notice(?\stdClass $latest): ?array {
        if (!$latest || !access::can_view($this->item)) {
            return null;
        }
        $name = review_manager::get_version_name($latest);
        if ($latest->status === status::REVIEW) {
            return [
                'message' => get_string('versionreviewstatusnotice', 'local_nextpool', [
                    'version' => $name, 'itemstatus' => status::get_name($this->item->status),
                ]),
                'reason' => '', 'class' => 'alert-info',
            ];
        }
        if ($latest->status !== status::REJECTED) {
            return null;
        }
        $decision = null;
        foreach (version_manager::get_log((int) $latest->id) as $log) {
            if ($log->action === version_manager::LOG_REJECTED) {
                $decision = $log;
            }
        }
        $reason = '';
        if ($decision && $decision->reason !== null && trim($decision->reason) !== '') {
            $reason = format_text($decision->reason, (int) $decision->reasonformat, [
                'context' => access::get_context($this->item), 'para' => false,
            ]);
        } else if ($decision && (int) $decision->userid === 0) {
            // Match the review history after privacy deletion; never fall back to an older return reason.
            $reason = s(get_string('reasonremoved', 'local_nextpool'));
        }
        return [
            'message' => get_string('versionrejectednotice', 'local_nextpool', $name),
            'reason' => $reason !== '' ? get_string('myproposals_reason', 'local_nextpool', $reason) : '',
            'class' => 'alert-warning',
        ];
    }

    /**
     * A short visible text with the full text for assistive technology and as tooltip.
     *
     * @param string $short Short text (plain).
     * @param string $full Full text (plain).
     * @return string HTML
     */
    private static function short_with_full(string $short, string $full): string {
        return \html_writer::span(
            \html_writer::span(s($short), '', ['aria-hidden' => 'true']) . \html_writer::span(s($full), 'visually-hidden'),
            '',
            ['title' => $full]
        );
    }

    /**
     * A read-only fact pill: short text visible, label and full text for assistive technology and as tooltip (task165).
     *
     * @param string $icon Decorative icon (HTML), '' without.
     * @param string $label Label (plain), e.g. "Storage location".
     * @param string $full Full value (plain).
     * @param string|null $short Short value (plain), null for the full value shortened.
     * @return array{icon: string, short: string, full: string}
     */
    private static function fact(string $icon, string $label, string $full, ?string $short = null): array {
        return [
            'icon' => $icon,
            'short' => $short ?? shorten_text($full, 30),
            'full' => get_string('labelvalue', 'local_nextpool', ['label' => $label, 'value' => $full]),
        ];
    }

    /**
     * Short name of the visibility context for the pill: "Site", the course short name or the category name.
     *
     * @param \context $context Visibility context.
     * @return string Plain text.
     */
    private static function get_visibility_short(\context $context): string {
        return match ((int) $context->contextlevel) {
            CONTEXT_SYSTEM => get_string('visibilityshort_site', 'local_nextpool'),
            CONTEXT_COURSE => shorten_text($context->get_context_name(false, true, false), 30),
            default => shorten_text($context->get_context_name(false, false, false), 30),
        };
    }

    /**
     * Section "Item editors" (F08, task84): who they are, removing them and the form to add one (q68).
     *
     * Everyone who sees the item page sees the list; only the editorial team gets the link to add one (editors.php,
     * t84-k) and the remove buttons.
     *
     * @param \renderer_base $output Renderer.
     * @return array Template data: showeditors, editors, haseditors, addeditorurl.
     */
    private function export_editors(\renderer_base $output): array {
        $canmanage = access::can_manage($this->item);
        $editors = [];
        foreach (item_editor_manager::get_editors((int) $this->item->id) as $userid => $record) {
            $user = \core_user::get_user((int) $userid);
            $name = s(fullname($record));
            $editors[] = [
                'name' => $user && user_can_view_profile($user)
                    ? \html_writer::link(new \moodle_url('/user/profile.php', ['id' => $userid]), $name) : $name,
                'remove' => $canmanage ? table_actions::icon_button(
                    new \moodle_url('/local/nextpool/item.php', ['id' => $this->item->id, 'action' => 'removeeditor',
                        'userid' => $userid]),
                    't/delete',
                    get_string('itemeditorremove', 'local_nextpool'),
                    fullname($record)
                ) : '',
            ];
        }
        return [
            'showeditors' => $editors || $canmanage,
            'editors' => $editors,
            'haseditors' => !empty($editors),
            'addeditorurl' => $canmanage
                ? (new \moodle_url('/local/nextpool/editors.php', ['id' => $this->item->id]))->out(false) : '',
        ];
    }

    /**
     * Whether the storage location of the item can link its files in its web interface (F08.AC10, q69).
     *
     * @return bool
     */
    private function has_web_url(): bool {
        try {
            return source_manager::get((int) $this->item->sourceid)->supports_web_url();
        } catch (source_exception | \dml_missing_record_exception $e) {
            return false;
        }
    }

    /**
     * Name of the visibility context of an item, as the item form offers it.
     *
     * @param \context $context Visibility context.
     * @param bool $escape Whether the context name is escaped for HTML (false for form menus, which escape themselves).
     * @return string
     */
    public static function get_visibility_name(\context $context, bool $escape = true): string {
        return match ((int) $context->contextlevel) {
            CONTEXT_SYSTEM => get_string('visibility_site', 'local_nextpool'),
            CONTEXT_COURSE => get_string('visibility_course', 'local_nextpool', $context->get_context_name(false, false, $escape)),
            default => get_string('visibility_category', 'local_nextpool', $context->get_context_name(false, false, $escape)),
        };
    }

    /**
     * Badge "Uploaded from course" with a link to that course, for items uploaded from a course (M06.AC04, TD-402).
     *
     * @param \stdClass $item Item record.
     * @return string HTML, '' for catalogue items.
     */
    public static function get_origin_course_link(\stdClass $item): string {
        if (empty($item->origincourseid)) {
            return '';
        }
        $coursecontext = \context_course::instance((int) $item->origincourseid, IGNORE_MISSING);
        if (!$coursecontext) {
            // The course was deleted; the item keeps its origin (TD-409).
            return s(get_string('origincourse_badge', 'local_nextpool')) . ': '
                . s(get_string('origincourse_deleted', 'local_nextpool'));
        }
        return \html_writer::link(
            new \moodle_url('/course/view.php', ['id' => $item->origincourseid]),
            s(get_string('origincourse_badge', 'local_nextpool')) . ': ' . $coursecontext->get_context_name(false)
        );
    }

    /**
     * Activity icon of the module an item becomes in a course, as the item's picture.
     *
     * @param string $type Item type.
     * @param \renderer_base $output Renderer.
     * @return string HTML
     */
    private function render_type_icon(string $type, \renderer_base $output): string {
        $modname = item_types::get_handler($type)?->get_modname() ?? ($type === 'xapi' ? 'nextpool' : '');
        if ($modname !== '' && \core_component::get_component_directory('mod_' . $modname)) {
            return $output->pix_icon('monologo', '', 'mod_' . $modname, ['class' => 'activityicon']);
        }
        return $output->pix_icon('i/backup', '', 'moodle', ['class' => 'activityicon']);
    }

    /**
     * Competencies of the item with level, function and objectives (F14).
     *
     * An idnumber that does not resolve on this site gets a notice: it is kept,
     * but placing the item cannot link it.
     *
     * @return array[]
     */
    private function export_competencies(): array {
        $context = access::get_context($this->item);
        $rows = [];
        $records = item_competencies::get((int) $this->item->id);
        $resolved = item_competencies::resolve_many(array_column($records, 'competency'));
        foreach ($records as $record) {
            [$resolution, $competency] = $resolved[$record->competency];
            $objectives = [];
            foreach ($record->objectives as $objective) {
                $text = (string) ($objective['text'] ?? '');
                $objectives[] = [
                    'id' => s((string) ($objective['id'] ?? '')),
                    'bloom' => !empty($objective['bloom'])
                        ? get_string('bloom_' . $objective['bloom'], 'local_nextpool') : '',
                    'text' => $text !== '' ? format_string($text, true, ['context' => $context]) : '',
                ];
            }
            $rows[] = [
                'name' => $competency
                    ? format_string($competency->shortname, true, ['context' => \context_system::instance()])
                    : s($record->competency),
                'idnumber' => s($record->competency),
                'level' => get_string('competencylevel_' . (int) $record->skilllevel, 'local_nextpool'),
                'function' => get_string('competencyfunction_' . $record->didacticfunction, 'local_nextpool'),
                'objectives' => $objectives,
                'hasobjectives' => !empty($objectives),
                'notice' => $resolution === item_competencies::RESOLVED
                    ? '' : get_string('competency' . $resolution, 'local_nextpool'),
            ];
        }
        return $rows;
    }

    /**
     * Filled custom fields the user may see, as label/value pairs (F01).
     *
     * @param \stdClass $item Item record.
     * @return array[]
     */
    private function export_customfields(\stdClass $item): array {
        $handler = \local_nextpool\customfield\item_handler::instance();
        $rows = [];
        foreach ($handler->get_instance_data((int) $item->id) as $data) {
            $value = $data->export_value();
            if ($value === null || $value === '' || !$handler->can_view($data->get_field(), (int) $item->id)) {
                continue;
            }
            $rows[] = [$data->get_field()->get_formatted_name(), $value];
        }
        return $rows;
    }

    /**
     * Tags of the item as a tag list, '' without tags.
     *
     * @param \stdClass $item Item record.
     * @param \renderer_base $output Renderer.
     * @return string HTML.
     */
    private function render_tags(\stdClass $item, \renderer_base $output): string {
        $tags = \core_tag_tag::get_item_tags('local_nextpool', item_manager::TAG_ITEMTYPE, (int) $item->id);
        return $tags ? $output->tag_list($tags, null, 'local-nextpool-tags') : '';
    }

    /**
     * Template data of one version row: one line with heading, status and icon actions, one line of facts (task105).
     *
     * @param \renderer_base $output Renderer.
     * @param \stdClass $version Version record.
     * @param \stdClass|null $previous The version before, for the compare link.
     * @return array
     */
    private function export_version(\renderer_base $output, \stdClass $version, ?\stdClass $previous = null): array {
        $context = access::get_context($this->item);
        $versionname = review_manager::get_version_name($version);
        $itemurl = new \moodle_url('/local/nextpool/item.php', ['id' => $this->item->id]);

        // Icons in the row (PO decision 1): the forward step, edit, download; withdrawing and
        // publishing again in the "More actions" menu, a single one as icon link (task166/task167).
        $icons = [];
        $more = [];
        if ($version->status === status::REVIEW && access::can_review($this->item)) {
            // Review decisions are taken on the review page, which also writes into the storage location (task80).
            $icons[] = table_actions::icon_button(
                new \moodle_url('/local/nextpool/review.php', ['versionid' => $version->id]),
                'i/checkedcircle',
                $version->versionnr === null ? get_string('reviewproposal', 'local_nextpool')
                    : get_string('reviewversion', 'local_nextpool', (int) $version->versionnr),
                '',
                'get'
            );
        }
        foreach (status::get_transitions($version->status) as $to) {
            if ($version->status === status::REVIEW || $to === status::CANCELLED) {
                // Proposers cancel their proposals from "My proposals" (task82), not on the item page.
                continue;
            }
            if (!access::can_change_status($this->item, $version->status, $to, null, $version)) {
                continue;
            }
            $label = get_string('versiontransition_' . $to, 'local_nextpool', (int) $version->versionnr);
            $url = new \moodle_url($itemurl, ['action' => 'versionstatus', 'versionid' => $version->id, 'to' => $to]);
            if ($to === status::REVIEW) {
                $icons[] = table_actions::icon_button($url, self::STATUS_ICONS[$to], $label);
            } else {
                // Asks first on item.php, without sesskey in the link (PO decision 2, bug112); publishing again leads to
                // the H5P option where needed.
                $more[] = new \action_menu_link_secondary(
                    $url,
                    new \pix_icon(self::STATUS_ICONS[$to], ''),
                    $label
                );
            }
        }
        $file = version_manager::get_file((int) $version->id);
        $downloadurl = $file ? \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'local_nextpool',
            version_manager::FILEAREA,
            $version->id,
            $file->get_filepath(),
            $file->get_filename(),
            true
        )->out(false) : '';
        // Label and change log stay editable after the import, for draft and review and (t103-a) later (task103).
        if (access::can_edit($this->item) && version_manager::can_edit_details($version)) {
            $icons[] = table_actions::icon_link(
                new \moodle_url('/local/nextpool/versionedit.php', ['versionid' => $version->id]),
                't/edit',
                get_string('editversiondetails_short', 'local_nextpool'),
                $versionname
            );
        }
        $compareurl = $previous ? new \moodle_url('/local/nextpool/compare.php', [
            'id' => $this->item->id,
            'from' => $previous->id,
            'to' => $version->id,
        ]) : null;
        if ($downloadurl !== '') {
            $icons[] = table_actions::icon_link(
                new \moodle_url($downloadurl),
                't/download',
                get_string('download'),
                $versionname
            );
        }
        $icons[] = table_actions::more($more, $versionname);

        // Review hint (q39): an H5P package may bring libraries that would be installed or updated site-wide.
        $h5plibraryhint = '';
        if ($this->item->type === 'h5p' && $file) {
            if ($version->status === status::PUBLISHED || $version->status === status::WITHDRAWN) {
                $h5plibraryhint = \local_nextpool\local\content_type\h5p::allows_library_updates($version)
                    ? get_string('h5plibrariesallowed', 'local_nextpool') : '';
                // Courses cannot place it while the site lacks libraries it needs (bug88): name them.
                $blocking = $version->status === status::PUBLISHED
                    ? \local_nextpool\local\content_type\h5p::get_blocking_libraries($version) : [];
                if ($blocking) {
                    $h5plibraryhint = get_string('h5plibrariesmissing', 'local_nextpool', s(implode(', ', $blocking)));
                }
            } else {
                $changes = \local_nextpool\local\content_type\h5p::get_library_changes($file);
                $h5plibraryhint = $changes ? get_string('h5plibraryreviewhint', 'local_nextpool', s(implode(', ', $changes))) : '';
            }
        }

        // Who and when as icon, name and short date; the full sentence in the tooltip (task105, PO decisions 3 and 4).
        $fulldate = static fn(int $time): string => userdate($time, get_string('strftimedatetimeshort', 'core_langconfig'));
        $shortdate = static fn(int $time): string => userdate($time, get_string('strftimedatefullshort', 'core_langconfig'));
        $automatic = (int) $version->createdby === 0;
        $changelog = $version->changelog !== null && trim($version->changelog) !== ''
            ? format_text($version->changelog, (int) $version->changelogformat, ['context' => $context]) : '';
        $changelogtext = trim(content_to_text($changelog, false));

        return [
            'versionnr' => (int) $version->versionnr,
            'heading' => $versionname,
            'label' => $version->label !== null ? format_string($version->label, true, ['context' => $context]) : '',
            'status' => status::get_name($version->status),
            'statusclass' => status::get_badge_class($version->status),
            'statusicon' => $output->pix_icon(status::get_icon($version->status), ''),
            'iscurrent' => (int) $version->id === (int) $this->item->currentversionid,
            'filename' => s($version->filename),
            'filesize' => display_size((int) $version->filesize),
            'sourceref' => $version->sourceref,
            'downloadurl' => $downloadurl,
            // A version of the storage location check gets its own sentence, not a name in another (bug63, U-32).
            'created' => $automatic
                ? get_string('createdautomaticallyon', 'local_nextpool', $fulldate((int) $version->timecreated))
                : get_string('createdbyon', 'local_nextpool', [
                    'user' => $this->plain_name((int) $version->createdby),
                    'date' => $fulldate((int) $version->timecreated),
                ]),
            'createdicon' => $output->pix_icon($automatic ? 'i/scheduled' : 'i/user', ''),
            'createdprefix' => $automatic ? '' : get_string('createdby_short', 'local_nextpool'),
            'createdname' => $automatic ? s(get_string('createdautomatically_short', 'local_nextpool'))
                : $this->linked_name((int) $version->createdby),
            'createddate' => $shortdate((int) $version->timecreated),
            'createdfulldate' => $fulldate((int) $version->timecreated),
            'published' => $version->timepublished ? get_string('publishedbyon', 'local_nextpool', [
                'user' => $this->plain_name((int) $version->publishedby),
                'date' => $fulldate((int) $version->timepublished),
            ]) : '',
            'publishedicon' => $output->pix_icon('i/checkedcircle', ''),
            'publishedname' => $version->timepublished ? $this->linked_name((int) $version->publishedby) : '',
            'publisheddate' => $version->timepublished ? $shortdate((int) $version->timepublished) : '',
            'publishedfulldate' => $version->timepublished ? $fulldate((int) $version->timepublished) : '',
            'fileicon' => $output->pix_icon('i/files', ''),
            // The change log in one line; a longer one opens on request.
            'changelog' => $changelog,
            'changelogshort' => shorten_text($changelogtext, 100),
            'changeloglong' => \core_text::strlen($changelogtext) > 100,
            'h5plibraryhint' => $h5plibraryhint,
            'actions' => table_actions::group($icons, table_actions::name(get_string('actions'), $versionname)),
            // Compare as a short link in the facts line, as in the sketch of the UX proposal (b) (t105-i).
            'compareurl' => $compareurl?->out(false) ?? '',
            'comparelabel' => $previous ? get_string('comparewith', 'local_nextpool', (int) $previous->versionnr) : '',
            'compareicon' => $output->pix_icon('i/twoway', ''),
        ];
    }

    /**
     * Display name of a user, plain text.
     *
     * @param int $userid User id.
     * @return string
     */
    private function plain_name(int $userid): string {
        return isset($this->users[$userid]) ? fullname($this->users[$userid]) : '-';
    }

    /**
     * Display name of a user as HTML, linked to the profile if the current user may see it (PO decision 4).
     *
     * @param int $userid User id.
     * @return string HTML
     */
    private function linked_name(int $userid): string {
        if (!isset($this->users[$userid])) {
            return '-';
        }
        $user = $this->users[$userid];
        $name = s(fullname($user));
        return user_can_view_profile($user)
            ? \html_writer::link(new \moodle_url('/user/profile.php', ['id' => $userid]), $name) : $name;
    }

    /**
     * Whether the item was never published (same rule as item_manager::delete()).
     *
     * @param \stdClass[] $versions Versions of the item.
     * @return bool
     */
    private function is_deletable(array $versions): bool {
        if (in_array($this->item->status, [status::PUBLISHED, status::WITHDRAWN], true)) {
            return false;
        }
        foreach ($versions as $version) {
            if ((int) $version->timepublished > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Load users for name display.
     *
     * @param int[] $userids User ids (0 is ignored).
     * @return \stdClass[] Keyed by id.
     */
    private function load_users(array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter($userids)));
        if (!$userids) {
            return [];
        }
        $fields = 'id, deleted' . \core_user\fields::for_name()->get_sql()->selects;
        return $DB->get_records_list('user', 'id', $userids, '', $fields);
    }
}
