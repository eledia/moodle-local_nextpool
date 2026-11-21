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

namespace local_nextpool\local;

/**
 * Editorial status of items and versions and the allowed transitions.
 *
 * Draft → review → published; review can go back to draft; published content
 * can be withdrawn and re-published. Withdrawn is never deleted, because
 * placements and learner history may still reference it.
 *
 * Versions additionally end as rejected (a review decision) or cancelled (a
 * proposal withdrawn by the person who proposed it, TD-302). Items never reach
 * these two statuses.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status {
    /** @var string Being prepared, invisible to teachers. */
    public const DRAFT = 'draft';
    /** @var string Waiting for review by a content editor. */
    public const REVIEW = 'review';
    /** @var string Visible and usable. */
    public const PUBLISHED = 'published';
    /** @var string No longer offered, kept for existing references. */
    public const WITHDRAWN = 'withdrawn';
    /** @var string Rejected in review with a reason; final (versions only). */
    public const REJECTED = 'rejected';
    /** @var string Cancelled by the person who proposed it; final (versions only). */
    public const CANCELLED = 'cancelled';

    /** @var array<string,string[]> Allowed transitions: from => to[]. */
    private const TRANSITIONS = [
        self::DRAFT => [self::REVIEW, self::CANCELLED],
        self::REVIEW => [self::DRAFT, self::PUBLISHED, self::REJECTED, self::CANCELLED],
        self::PUBLISHED => [self::WITHDRAWN],
        self::WITHDRAWN => [self::PUBLISHED],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** @var string[] Statuses only versions can have. */
    private const VERSION_ONLY = [self::REJECTED, self::CANCELLED];

    /**
     * All statuses.
     *
     * @param bool $item Only the statuses of items.
     * @return string[]
     */
    public static function all(bool $item = false): array {
        $all = array_keys(self::TRANSITIONS);
        return $item ? array_values(array_diff($all, self::VERSION_ONLY)) : $all;
    }

    /**
     * Whether a status is final: a version in it never changes again.
     *
     * @param string $status Status.
     * @return bool
     */
    public static function is_final(string $status): bool {
        return in_array($status, self::VERSION_ONLY, true);
    }

    /**
     * Whether a transition is allowed.
     *
     * @param string $from Current status.
     * @param string $to Target status.
     * @param bool $item For an item: rejected and cancelled are not available.
     * @return bool
     */
    public static function can_transition(string $from, string $to, bool $item = false): bool {
        return in_array($to, self::get_transitions($from, $item), true);
    }

    /**
     * Statuses reachable from a status.
     *
     * @param string $from Current status.
     * @param bool $item For an item: rejected and cancelled are not available.
     * @return string[]
     */
    public static function get_transitions(string $from, bool $item = false): array {
        $transitions = self::TRANSITIONS[$from] ?? [];
        return $item ? array_values(array_diff($transitions, self::VERSION_ONLY)) : $transitions;
    }

    /**
     * Throw unless a transition is allowed.
     *
     * @param string $from Current status.
     * @param string $to Target status.
     * @param bool $item For an item: rejected and cancelled are not available.
     * @throws \moodle_exception
     */
    public static function require_transition(string $from, string $to, bool $item = false): void {
        if (!self::can_transition($from, $to, $item)) {
            throw new \moodle_exception('error_statustransition', 'local_nextpool', '', [
                'from' => in_array($from, self::all(), true) ? self::get_name($from) : $from,
                'to' => in_array($to, self::all(), true) ? self::get_name($to) : $to,
            ]);
        }
    }

    /**
     * Localised status name.
     *
     * @param string $status Status.
     * @return string
     */
    public static function get_name(string $status): string {
        return get_string('status_' . $status, 'local_nextpool');
    }

    /**
     * Bootstrap classes for a status badge.
     *
     * The badge always carries the status name as text; colour is only an additional cue.
     *
     * @param string $status Status.
     * @return string
     */
    public static function get_badge_class(string $status): string {
        return match ($status) {
            self::PUBLISHED => 'bg-success',
            self::REVIEW => 'bg-warning text-dark',
            self::WITHDRAWN, self::CANCELLED => 'bg-secondary',
            self::REJECTED => 'bg-danger',
            default => 'bg-light text-dark border',
        };
    }

    /**
     * Core icon of a status, so that colour is never the only cue (task166).
     *
     * @param string $status Status.
     * @return string Core pix icon name.
     */
    public static function get_icon(string $status): string {
        return match ($status) {
            self::PUBLISHED => 't/check',
            self::REVIEW => 'i/duration',
            self::WITHDRAWN => 't/show',
            self::REJECTED => 'e/cancel',
            self::CANCELLED => 't/block',
            default => 'i/edit',
        };
    }

    /**
     * A status as a Moodle core badge (U-26): a pill with the status icon and the status name as text (task166).
     *
     * @param string $status Status.
     * @return string HTML.
     */
    public static function badge(string $status): string {
        global $OUTPUT;
        return \html_writer::span(
            $OUTPUT->pix_icon(self::get_icon($status), '') . s(self::get_name($status)),
            'badge rounded-pill text-wrap text-start local-nextpool-statuspill ' . self::get_badge_class($status)
        );
    }

    /**
     * Badge for versions that wait for review, beside the item status in the editorial list.
     *
     * Same pill, icon and colour as the review status, with the text saying what waits, so the item
     * status (for example published) and a pending review are both visible without relying on colour.
     *
     * @param int $count Number of versions waiting for review, at least 1.
     * @return string HTML.
     */
    public static function review_pending_badge(int $count): string {
        global $OUTPUT;
        $text = $count === 1
            ? get_string('reviewpending_one', 'local_nextpool')
            : get_string('reviewpending_many', 'local_nextpool', $count);
        return \html_writer::span(
            $OUTPUT->pix_icon(self::get_icon(self::REVIEW), '') . s($text),
            'badge rounded-pill text-wrap text-start local-nextpool-statuspill local-nextpool-reviewpending ' .
                self::get_badge_class(self::REVIEW)
        );
    }

    /**
     * Any status text as a Moodle core badge (U-26).
     *
     * The text stays readable: dark text on light colours, white text only on dark
     * ones (axe colour contrast), and long texts wrap instead of overflowing (reflow).
     *
     * @param string $text Plain text, escaped here.
     * @param string $class Bootstrap colour classes, e.g. 'bg-success' or 'bg-warning text-dark'.
     * @return string HTML.
     */
    public static function text_badge(string $text, string $class): string {
        return \html_writer::span(s($text), 'badge text-wrap text-start ' . $class);
    }
}
