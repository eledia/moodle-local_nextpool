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

namespace local_nextpool\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Report Builder entity of a catalogue item version (F07, task85, TD-309).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version extends base {
    #[\Override]
    protected function get_default_tables(): array {
        return ['local_nextpool_version'];
    }

    #[\Override]
    protected function get_default_entity_title(): lang_string {
        return new lang_string('reviewversionentity', 'local_nextpool');
    }

    #[\Override]
    public function initialise(): base {
        foreach ($this->get_version_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_version_filters() as $filter) {
            $this->add_filter($filter);
        }
        return $this;
    }

    /**
     * Kinds of versions in the review overview, with "New from the storage location" for drafts the storage location
     * check imported (q73). Direct uploads never wait for review (q54).
     *
     * @return array<string, string>
     */
    public static function get_kind_options(): array {
        return [
            version_manager::ORIGIN_PROPOSAL => get_string('versionorigin_proposal', 'local_nextpool'),
            version_manager::ORIGIN_EDITORIAL => get_string('versionorigin_editorial', 'local_nextpool'),
            version_manager::ORIGIN_SOURCE => get_string('reviewkind_source', 'local_nextpool'),
        ];
    }

    /**
     * Columns of the entity.
     *
     * @return column[]
     */
    protected function get_version_columns(): array {
        $alias = $this->get_table_alias('local_nextpool_version');
        $columns = [];

        // Version name, linked to the review page: "Version N" or "Proposal by X on D" (q67).
        $columns[] = (new column('name', new lang_string('reviewversionentity', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.id, {$alias}.versionnr, {$alias}.createdby, {$alias}.timecreated")
            ->set_is_sortable(true, ["{$alias}.versionnr", "{$alias}.timecreated"])
            ->add_callback(static function ($value, \stdClass $row): string {
                if (empty($row->id)) {
                    return '';
                }
                return \html_writer::link(
                    new \moodle_url('/local/nextpool/review.php', ['versionid' => $row->id]),
                    s(review_manager::get_version_name($row))
                );
            });

        // Kind, from the origin of the version.
        $columns[] = (new column('kind', new lang_string('reviewkind', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.origin")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $origin): string {
                $manager = get_string_manager();
                return $origin !== null && $manager->string_exists('versionorigin_' . $origin, 'local_nextpool')
                    ? get_string('versionorigin_' . $origin, 'local_nextpool') : (string) $origin;
            });

        // Status, as text badge.
        $columns[] = (new column('status', new lang_string('status'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.status")
            ->set_is_sortable(true)
            ->add_callback(static fn(?string $value): string => $value === null ? '' : status::badge($value));

        // Submitted on.
        $columns[] = (new column('timesubmitted', new lang_string('reviewsubmitted', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.timesubmitted")
            ->set_is_sortable(true)
            ->add_callback(static fn(?int $value, \stdClass $row): string => $value
                ? format::userdate($value, $row, get_string('strftimedatetimeshort', 'core_langconfig')) : '');

        // Waiting since: time since the submission.
        $columns[] = (new column('waiting', new lang_string('reviewwaiting', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.timesubmitted")
            ->set_is_sortable(true)
            ->add_callback(static fn(?int $value): string => $value ? format_time(max(60, time() - $value), null, 1) : '');

        // Self-approved (q71, F07.AC17).
        $columns[] = (new column('selfapproved', new lang_string('selfapproved', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$alias}.selfapproved")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'boolean_as_text']);

        return $columns;
    }

    /**
     * Filters of the entity.
     *
     * @return filter[]
     */
    protected function get_version_filters(): array {
        $alias = $this->get_table_alias('local_nextpool_version');
        $filters = [];

        // Kind, including "New from the storage location" (q73).
        $filters[] = (new filter(
            select::class,
            'kind',
            new lang_string('reviewkind', 'local_nextpool'),
            $this->get_entity_name(),
            "{$alias}.origin"
        ))
            ->add_joins($this->get_joins())
            ->set_options(self::get_kind_options());

        $filters[] = (new filter(
            boolean_select::class,
            'selfapproved',
            new lang_string('selfapproved', 'local_nextpool'),
            $this->get_entity_name(),
            "{$alias}.selfapproved"
        ))->add_joins($this->get_joins());

        return $filters;
    }
}
