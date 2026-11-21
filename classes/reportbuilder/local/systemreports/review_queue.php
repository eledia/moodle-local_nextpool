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

namespace local_nextpool\reportbuilder\local\systemreports;

use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\system_report;
use lang_string;
use local_nextpool\local\access;
use local_nextpool\local\review_manager;
use local_nextpool\local\review_queue as queue;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\table_actions;
use local_nextpool\reportbuilder\local\entities\item;
use local_nextpool\reportbuilder\local\entities\version;

/**
 * "Waiting for review": versions in review and drafts from the storage location (F07, task85, TD-309).
 *
 * Only versions of items the user may review (TD-310, q77), oldest submission first. Row actions are icons with
 * their name as tooltip and accessible name (UX standard of the item page): review, "I review" and assign.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_queue extends system_report {
    /** @var int Filter value: assigned to me. */
    public const ASSIGNED_ME = 1;
    /** @var int Filter value: not assigned. */
    public const ASSIGNED_NOBODY = 2;
    /** @var int Filter value: assigned to someone else. */
    public const ASSIGNED_OTHER = 3;

    #[\Override]
    protected function initialise(): void {
        global $DB, $USER;
        $versionentity = new version();
        $v = $versionentity->get_table_alias('local_nextpool_version');
        $this->set_main_table('local_nextpool_version', $v);
        $this->add_entity($versionentity);

        $itementity = new item();
        $i = $itementity->get_table_alias('local_nextpool_item');
        $this->add_entity($itementity->add_join("JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid"));

        // The person who last submitted the version; drafts from the storage location have none.
        $log = database::generate_alias();
        $submitter = (new user())->set_entity_name('submitter')
            ->set_entity_title(new lang_string('reviewsubmittedby', 'local_nextpool'));
        $su = $submitter->get_table_alias('user');
        $submitted = version_manager::LOG_SUBMITTED;
        $this->add_entity($submitter->add_joins([
            "LEFT JOIN {local_nextpool_version_log} {$log}
                    ON {$log}.id = (SELECT MAX(l2.id) FROM {local_nextpool_version_log} l2
                                     WHERE l2.versionid = {$v}.id AND l2.action = '{$submitted}')",
            "LEFT JOIN {user} {$su} ON {$su}.id = {$log}.userid",
        ]));

        $assignee = (new user())->set_entity_name('assignee')
            ->set_entity_title(new lang_string('reviewassignedto', 'local_nextpool'));
        $au = $assignee->get_table_alias('user');
        $this->add_entity($assignee->add_join("LEFT JOIN {user} {$au} ON {$au}.id = {$v}.assignedto"));

        // Only waiting versions of items the user may review; the condition needs the item in every query.
        $this->add_join("JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid");
        $contextids = queue::get_contextids() ?: [0];
        [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, database::generate_param_name() . '_');
        [$waiting, $waitingparams] = queue::get_waiting_sql($v, database::generate_param_name() . '_');
        $this->add_base_condition_sql("{$i}.contextid {$insql} AND {$waiting}", $inparams + $waitingparams);

        $this->add_base_fields("{$v}.id, {$v}.itemid, {$v}.status, {$v}.assignedto, {$v}.versionnr, {$v}.createdby, "
            . "{$v}.timecreated, {$i}.contextid AS itemcontextid, {$i}.title AS itemtitle");

        $this->add_review_columns($v, $i, (int) $USER->id);
        $this->add_review_filters($v, (int) $USER->id);
        $this->set_initial_sort_column('version:timesubmitted', SORT_ASC);
        // Items waiting without a version are listed above the report (reviews.php): then only the versions are empty.
        $this->set_default_no_results_notice(new lang_string(
            \local_nextpool\local\review_queue::get_waiting_items() ? 'reviewqueue_noversions' : 'reviewqueue_empty',
            'local_nextpool'
        ));
        $this->set_downloadable(true, get_string('reviewqueue', 'local_nextpool'));
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('reviewqueue', 'local_nextpool');
    }

    #[\Override]
    protected function can_view(): bool {
        return queue::can_view();
    }

    #[\Override]
    public function get_exclude_columns_for_download(): array {
        return ['version:actions'];
    }

    /**
     * Columns: item, version, kind, submitted by and on, waiting since, availability, outdated base, assigned to,
     * actions.
     *
     * @param string $v Alias of the version table.
     * @param string $i Alias of the item table.
     * @param int $userid Current user.
     */
    private function add_review_columns(string $v, string $i, int $userid): void {
        $this->add_column_from_entity('item:title');
        $this->add_column_from_entity('version:name');
        $this->add_column_from_entity('version:kind');

        $submitted = $this->get_entity('submitter')->get_column('fullname');
        $submitted->set_title(new lang_string('reviewsubmittedby', 'local_nextpool'));
        $this->add_column($submitted)->add_callback(static function (string $value, \stdClass $row): string {
            return $value !== '' ? $value : get_string('reviewsubmittedauto', 'local_nextpool');
        });

        $this->add_column_from_entity('version:timesubmitted');
        $this->add_column_from_entity('version:waiting');
        $this->add_column_from_entity('item:visibility');

        // F07.AC07: a newer version was published after the one this version builds on.
        $base = database::generate_alias();
        $current = database::generate_alias();
        $this->add_column((new column('outdated', new lang_string('reviewoutdatedbase', 'local_nextpool'), 'version'))
            ->add_join("JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid")
            ->add_join("LEFT JOIN {local_nextpool_version} {$base} ON {$base}.id = {$v}.basedonversionid")
            ->add_join("LEFT JOIN {local_nextpool_version} {$current} ON {$current}.id = {$i}.currentversionid")
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("CASE WHEN {$base}.versionnr IS NOT NULL AND {$current}.versionnr > {$base}.versionnr
                              THEN 1 ELSE 0 END", 'outdated')
            ->set_is_sortable(true)
            ->add_callback(static fn(?bool $value): string => $value ? get_string('yes') : ''));

        $this->add_column_from_entity('assignee:fullname')
            ->set_title(new lang_string('reviewassignedto', 'local_nextpool'))
            ->add_callback(static function (string $value): string {
                return $value !== '' ? $value : get_string('reviewnotassigned', 'local_nextpool');
            });

        $this->add_column((new column('actions', new lang_string('actions'), 'version'))
            ->add_join("JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid")
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$v}.id, {$v}.status, {$v}.assignedto, {$v}.versionnr, {$v}.createdby, {$v}.timecreated, "
                . "{$i}.id AS itemid, {$i}.contextid, {$i}.title")
            ->set_is_sortable(false)
            ->add_callback(static fn($value, \stdClass $row): string => self::render_actions($row, $userid)));
    }

    /**
     * Filters: assigned to me / not assigned / to others, kind, availability.
     *
     * @param string $v Alias of the version table.
     * @param int $userid Current user.
     */
    private function add_review_filters(string $v, int $userid): void {
        $me = database::generate_param_name();
        $this->add_filter((new filter(
            select::class,
            'assignment',
            new lang_string('reviewassignment', 'local_nextpool'),
            'version',
            "CASE WHEN {$v}.assignedto = :{$me} THEN " . self::ASSIGNED_ME
                . " WHEN {$v}.assignedto IS NULL THEN " . self::ASSIGNED_NOBODY
                . " ELSE " . self::ASSIGNED_OTHER . " END",
            [$me => $userid]
        ))->set_options([
            self::ASSIGNED_ME => get_string('reviewassignedme', 'local_nextpool'),
            self::ASSIGNED_NOBODY => get_string('reviewnotassigned', 'local_nextpool'),
            self::ASSIGNED_OTHER => get_string('reviewassignedother', 'local_nextpool'),
        ]));
        $this->add_filter_from_entity('version:kind');
        $this->add_filter_from_entity('item:visibility');
        $this->add_filter_from_entity('item:title');
    }

    /**
     * Row actions as icons: review, "I review" (POST), assign.
     *
     * @param \stdClass $row Version fields and item id, context and title.
     * @param int $userid Current user.
     * @return string HTML
     */
    public static function render_actions(\stdClass $row, int $userid): string {
        $item = (object) ['id' => $row->itemid, 'contextid' => $row->contextid];
        $context = access::get_context($item);
        $rowname = format_string($row->title, true, ['context' => $context, 'escape' => false]) . ', '
            . review_manager::get_version_name($row);
        $actions = [];
        if ($row->status === status::REVIEW) {
            $actions[] = table_actions::icon_link(
                new \moodle_url('/local/nextpool/review.php', ['versionid' => $row->id]),
                't/preview',
                get_string('reviewopen', 'local_nextpool'),
                $rowname
            );
        } else {
            // Drafts from the storage location are submitted on the item page.
            $actions[] = table_actions::icon_link(
                new \moodle_url('/local/nextpool/item.php', ['id' => $row->itemid]),
                't/preview',
                get_string('reviewopenitem', 'local_nextpool'),
                $rowname
            );
        }
        if (access::can_review($item, $userid)) {
            $actions[] = (int) $row->assignedto === $userid
                ? table_actions::spacer()
                : table_actions::icon_button(
                    new \moodle_url('/local/nextpool/reviews.php', ['action' => 'take', 'versionid' => $row->id]),
                    'i/user',
                    get_string('reviewtake', 'local_nextpool'),
                    $rowname
                );
            $actions[] = table_actions::icon_link(
                new \moodle_url('/local/nextpool/reviews.php', ['action' => 'assign', 'versionid' => $row->id]),
                'i/assignroles',
                get_string('reviewassign', 'local_nextpool'),
                $rowname
            );
        }
        return table_actions::group($actions, table_actions::name(get_string('actions'), $rowname));
    }
}
