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
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_nextpool\local\review_queue;

/**
 * Report Builder entity of a catalogue item (F07, task85).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item extends base {
    #[\Override]
    protected function get_default_tables(): array {
        return ['local_nextpool_item'];
    }

    #[\Override]
    protected function get_default_entity_title(): lang_string {
        return new lang_string('reviewitem', 'local_nextpool');
    }

    #[\Override]
    public function initialise(): base {
        foreach ($this->get_item_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_item_filters() as $filter) {
            $this->add_filter($filter);
        }
        return $this;
    }

    /**
     * Display name of the context an item is available in ("Whole site", "Course category: X").
     *
     * @param int|null $contextid Context id.
     * @return string Plain text.
     */
    public static function get_visibility_name(?int $contextid): string {
        $context = $contextid ? \context::instance_by_id($contextid, IGNORE_MISSING) : null;
        if (!$context) {
            return '';
        }
        return match ((int) $context->contextlevel) {
            CONTEXT_SYSTEM => get_string('visibility_site', 'local_nextpool'),
            CONTEXT_COURSE => get_string('visibility_course', 'local_nextpool', $context->get_context_name(false, false, false)),
            default => get_string('visibility_category', 'local_nextpool', $context->get_context_name(false, false, false)),
        };
    }

    /**
     * Columns of the entity.
     *
     * @return column[]
     */
    protected function get_item_columns(): array {
        $alias = $this->get_table_alias('local_nextpool_item');
        $columns = [];

        // Title, linked to the item page.
        $columns[] = (new column('title', new lang_string('title', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.title, {$alias}.id, {$alias}.contextid")
            ->set_is_sortable(true, ["{$alias}.title"])
            ->add_callback(static function (?string $title, \stdClass $row): string {
                if ($title === null) {
                    return '';
                }
                $context = \context::instance_by_id((int) $row->contextid, IGNORE_MISSING) ?: \context_system::instance();
                return \html_writer::link(
                    new \moodle_url('/local/nextpool/item.php', ['id' => $row->id]),
                    format_string($title, true, ['context' => $context])
                );
            });

        // Where the item is available: its context (TD-310).
        $columns[] = (new column('visibility', new lang_string('visibility', 'local_nextpool'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$alias}.contextid")
            ->set_is_sortable(true)
            ->add_callback(static fn(?int $contextid): string => self::get_visibility_name($contextid));

        return $columns;
    }

    /**
     * Filters of the entity.
     *
     * @return filter[]
     */
    protected function get_item_filters(): array {
        $alias = $this->get_table_alias('local_nextpool_item');
        $filters = [];

        $filters[] = (new filter(
            text::class,
            'title',
            new lang_string('title', 'local_nextpool'),
            $this->get_entity_name(),
            "{$alias}.title"
        ))->add_joins($this->get_joins());

        // Only the contexts the user reviews in (q77).
        $filters[] = (new filter(
            select::class,
            'visibility',
            new lang_string('visibility', 'local_nextpool'),
            $this->get_entity_name(),
            "{$alias}.contextid"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (review_queue::get_contextids() as $contextid) {
                    $options[$contextid] = self::get_visibility_name($contextid);
                }
                \core_collator::asort($options);
                return $options;
            });

        return $filters;
    }
}
