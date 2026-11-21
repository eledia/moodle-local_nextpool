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
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\system_report;
use lang_string;
use local_nextpool\local\review_queue as queue;
use local_nextpool\local\version_manager;
use local_nextpool\reportbuilder\local\entities\item;
use local_nextpool\reportbuilder\local\entities\version;

/**
 * "Completed reviews": review decisions of the last 90 days from the review history (F07, task85).
 *
 * Published, returned and rejected versions of items the user may review (TD-310), newest first, with who decided
 * and "self-approved" (q71, F07.AC17). Direct uploads are not reviews (q54) and are left out.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reviews_done extends system_report {
    /** @var int Days the report looks back. */
    public const DAYS = 90;

    /** @var string[] History actions that are review decisions. */
    private const DECISIONS = [
        version_manager::LOG_PUBLISHED,
        version_manager::LOG_RETURNED,
        version_manager::LOG_REJECTED,
    ];

    #[\Override]
    protected function initialise(): void {
        global $DB;
        $l = database::generate_alias();
        $this->set_main_table('local_nextpool_version_log', $l);

        $versionentity = new version();
        $v = $versionentity->get_table_alias('local_nextpool_version');
        $this->add_entity($versionentity->add_join("JOIN {local_nextpool_version} {$v} ON {$v}.id = {$l}.versionid"));

        $itementity = new item();
        $i = $itementity->get_table_alias('local_nextpool_item');
        $this->add_entity($itementity->add_joins([
            "JOIN {local_nextpool_version} {$v} ON {$v}.id = {$l}.versionid",
            "JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid",
        ]));

        $decider = (new user())->set_entity_name('decider')
            ->set_entity_title(new lang_string('reviewdecidedby', 'local_nextpool'));
        $du = $decider->get_table_alias('user');
        $this->add_entity($decider->add_join("LEFT JOIN {user} {$du} ON {$du}.id = {$l}.userid"));

        $contextids = queue::get_contextids() ?: [0];
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, database::generate_param_name() . '_');
        [$actsql, $actparams] = $DB->get_in_or_equal(self::DECISIONS, SQL_PARAMS_NAMED, database::generate_param_name() . '_');
        $since = database::generate_param_name();
        $upload = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$l}.action {$actsql} AND {$l}.timecreated >= :{$since} AND {$v}.origin <> :{$upload}
             AND {$i}.contextid {$ctxsql}",
            $ctxparams + $actparams + [
                $since => time() - self::DAYS * DAYSECS,
                $upload => version_manager::ORIGIN_UPLOAD,
            ]
        );
        // The condition refers to the joined tables.
        $this->add_join("JOIN {local_nextpool_version} {$v} ON {$v}.id = {$l}.versionid");
        $this->add_join("JOIN {local_nextpool_item} {$i} ON {$i}.id = {$v}.itemid");

        $this->add_columns_and_filters($l);
        $this->set_initial_sort_column('version:timedecided', SORT_DESC);
        $this->set_default_no_results_notice(new lang_string('reviewsdone_empty', 'local_nextpool'));
        $this->set_downloadable(true, get_string('reviewsdone', 'local_nextpool'));
    }

    #[\Override]
    public static function get_name(): string {
        return get_string('reviewsdone', 'local_nextpool');
    }

    #[\Override]
    protected function can_view(): bool {
        return queue::can_view();
    }

    /**
     * Columns and filters.
     *
     * @param string $l Alias of the history table.
     */
    private function add_columns_and_filters(string $l): void {
        $this->add_column_from_entity('item:title');
        $this->add_column_from_entity('version:name');
        $this->add_column_from_entity('version:kind');

        $this->add_column((new column('decision', new lang_string('reviewdecision', 'local_nextpool'), 'version'))
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$l}.action")
            ->set_is_sortable(true)
            ->add_callback(static fn(?string $action): string => $action === null ? ''
                : get_string('reviewdecision_' . $action, 'local_nextpool')));

        $this->add_column_from_entity('decider:fullname')
            ->set_title(new lang_string('reviewdecidedby', 'local_nextpool'));

        $this->add_column((new column('timedecided', new lang_string('reviewdecidedon', 'local_nextpool'), 'version'))
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$l}.timecreated")
            ->set_is_sortable(true)
            ->add_callback(static fn(?int $value, \stdClass $row): string => $value
                ? format::userdate($value, $row, get_string('strftimedatetimeshort', 'core_langconfig')) : ''));

        // Self-approved applies to the publication only.
        $published = database::generate_param_name();
        $versionalias = $this->get_entity('version')->get_table_alias('local_nextpool_version');
        $this->add_column((new column('selfapprovedpublication', new lang_string('selfapproved', 'local_nextpool'), 'version'))
            ->add_join("JOIN {local_nextpool_version} {$versionalias} ON {$versionalias}.id = {$l}.versionid")
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field(
                "CASE WHEN {$l}.action = :{$published} AND {$versionalias}.selfapproved = 1 THEN 1 ELSE 0 END",
                'selfapproved',
                [$published => version_manager::LOG_PUBLISHED]
            )
            ->set_is_sortable(true)
            ->add_callback(static fn(?bool $value): string => $value ? get_string('selfapproved', 'local_nextpool') : ''));

        $this->add_column_from_entity('item:visibility');

        $this->add_filter((new filter(
            select::class,
            'decision',
            new lang_string('reviewdecision', 'local_nextpool'),
            'version',
            "{$l}.action"
        ))->set_options(array_combine(self::DECISIONS, array_map(
            static fn(string $action): string => get_string('reviewdecision_' . $action, 'local_nextpool'),
            self::DECISIONS
        ))));
        $this->add_filter_from_entity('version:kind');
        $this->add_filter_from_entity('version:selfapproved');
        $this->add_filter_from_entity('item:visibility');
        $this->add_filter_from_entity('item:title');
    }
}
