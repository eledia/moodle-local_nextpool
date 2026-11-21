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
use local_nextpool\local\proposal_manager;
use local_nextpool\local\review_manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;

/**
 * Review page of a version: facts, outdated base, metadata comparison, history and the decision form (F07, task80).
 *
 * Compact like the item page (docs/18): facts only when they are set, the decision form below.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_page implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass $version Version record.
     * @param string $formhtml Rendered decision form, '' if the user cannot decide.
     * @param string $actionshtml Actions of the person who proposed the version (change, cancel), '' if none.
     */
    public function __construct(
        /** @var \stdClass Item record. */
        private readonly \stdClass $item,
        /** @var \stdClass Version record. */
        private readonly \stdClass $version,
        /** @var string Rendered decision form. */
        private readonly string $formhtml = '',
        /** @var string Actions of the person who proposed the version. */
        private readonly string $actionshtml = '',
    ) {
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        global $DB;
        $item = $this->item;
        $version = $this->version;
        $context = access::get_context($item);
        $short = get_string('strftimedatetimeshort', 'core_langconfig');

        $facts = [];
        $facts[] = [get_string('reviewitem', 'local_nextpool'), access::can_view($item)
            ? \html_writer::link(
                new \moodle_url('/local/nextpool/item.php', ['id' => $item->id]),
                format_string($item->title, true, ['context' => $context])
            )
            : format_string($item->title, true, ['context' => $context])];
        $facts[] = [get_string('reviewkind', 'local_nextpool'), get_string('versionorigin_' . $version->origin, 'local_nextpool')];
        // A proposal without a person was proposed by someone who was deleted since (q70); only versions from the
        // storage location check are created automatically.
        $automatic = (int) $version->createdby === 0 && $version->origin !== version_manager::ORIGIN_PROPOSAL;
        $facts[] = [get_string('reviewcreated', 'local_nextpool'), $automatic
            ? get_string('createdautomaticallyon', 'local_nextpool', userdate($version->timecreated, $short))
            : get_string('createdbyon', 'local_nextpool', [
                'user' => s($this->get_username((int) $version->createdby)),
                'date' => userdate($version->timecreated, $short),
            ])];
        $course = !empty($version->courseid)
            ? $DB->get_record('course', ['id' => $version->courseid], 'id, fullname') : null;
        if ($course) {
            $facts[] = [get_string('reviewcourse', 'local_nextpool'), \html_writer::link(
                new \moodle_url('/course/view.php', ['id' => $course->id]),
                format_string($course->fullname, true, ['context' => \context_course::instance($course->id)])
            )];
        }
        if (!empty($version->timesubmitted)) {
            $facts[] = [get_string('reviewsubmitted', 'local_nextpool'), userdate((int) $version->timesubmitted, $short)];
        }
        if (!empty($version->assignedto)) {
            $facts[] = [get_string('reviewassignedto', 'local_nextpool'), s($this->get_username((int) $version->assignedto))];
        }
        $file = version_manager::get_file((int) $version->id);
        if ($file) {
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                'local_nextpool',
                version_manager::FILEAREA,
                $version->id,
                $file->get_filepath(),
                $file->get_filename(),
                true
            );
            $facts[] = [get_string('reviewfile', 'local_nextpool'), \html_writer::link($url, s($version->filename))
                . ' · ' . display_size((int) $version->filesize)];
        }
        if (!empty($version->basedonversionid)) {
            $base = version_manager::get((int) $version->basedonversionid);
            $facts[] = [get_string('reviewbasedon', 'local_nextpool'), s(review_manager::get_version_name($base))];
        }
        $changelog = $version->changelog !== null && trim($version->changelog) !== ''
            ? format_text($version->changelog, (int) $version->changelogformat, ['context' => $context]) : '';

        // F07.AC07: a newer version was published since this one was based on its version.
        $outdated = '';
        $outdatedbase = review_manager::get_outdated_base($version);
        if ($outdatedbase) {
            $outdated = get_string('reviewoutdated', 'local_nextpool', [
                'base' => (int) $outdatedbase['base']->versionnr,
                'current' => (int) $outdatedbase['current']->versionnr,
            ]);
            // The comparison page needs the editorial view of the item; people who only proposed do not get it (bug103).
            if (access::can_view($item)) {
                $outdated .= ' ' . \html_writer::link(new \moodle_url('/local/nextpool/compare.php', [
                    'id' => $item->id,
                    'from' => $outdatedbase['current']->id,
                    'to' => $version->id,
                ]), get_string('reviewcomparecurrent', 'local_nextpool', (int) $outdatedbase['current']->versionnr));
            }
        }

        $history = $this->export_history();
        $comments = $this->export_comments();
        return [
            'heading' => get_string('reviewheading', 'local_nextpool', review_manager::get_version_name($version)),
            'status' => status::get_name($version->status),
            'statusclass' => status::get_badge_class($version->status),
            'statusicon' => $output->pix_icon(status::get_icon($version->status), ''),
            'selfapproved' => !empty($version->selfapproved),
            'outdated' => $outdated,
            'facts' => array_map(static fn($f) => ['label' => $f[0], 'value' => $f[1]], $facts),
            'changelog' => $changelog,
            'comparison' => $this->export_comparison(),
            'history' => $history,
            'hashistory' => !empty($history),
            'form' => $this->formhtml,
            'hasform' => $this->formhtml !== '',
            'comments' => $comments,
            'hascomments' => !empty($comments),
            'actions' => $this->actionshtml,
            'hasactions' => $this->actionshtml !== '',
        ];
    }

    /**
     * Comments the version takes up, with author and whether they are done (F07 "Inhalt des Vorschlags").
     *
     * @return array[]
     */
    private function export_comments(): array {
        $context = access::get_context($this->item);
        $comments = [];
        foreach (proposal_manager::get_linked_comments((int) $this->version->id) as $comment) {
            $comments[] = [
                'author' => s($this->get_username((int) $comment->userid)),
                'text' => !empty($comment->deleted) ? get_string('commentremoved', 'local_nextpool')
                    : format_text((string) $comment->content, (int) $comment->contentformat, ['context' => $context]),
                'done' => !empty($comment->timeresolved),
            ];
        }
        return $comments;
    }

    /**
     * Metadata comparison with the version this one is based on, or with the current version.
     *
     * @return array|null Template data or null if there is nothing to compare with.
     */
    private function export_comparison(): ?array {
        $version = $this->version;
        $otherid = (int) ($version->basedonversionid ?: $this->item->currentversionid);
        if (!$otherid || $otherid === (int) $version->id) {
            return null;
        }
        $comparison = version_manager::compare($otherid, (int) $version->id);
        $context = access::get_context($this->item);
        $rows = [];
        foreach ($comparison['rows'] as $field => $row) {
            if (
                $field === 'versionnr' || $field === 'status'
                    || (!access::can_view($this->item) && in_array($field, ['sourceref', 'contenthash'], true))
            ) {
                continue;
            }
            $format = static fn($value): string => match ($field) {
                'label' => $value !== null && $value !== '' ? format_string($value, true, ['context' => $context]) : '-',
                'filesize' => display_size((int) $value),
                'contenthash' => s(substr((string) $value, 0, 12)),
                default => $value !== null && $value !== '' ? s((string) $value) : '-',
            };
            $rows[] = [
                'field' => get_string('comparefield_' . $field, 'local_nextpool'),
                'from' => $format($row['from']),
                'to' => $format($row['to']),
                'changed' => $row['changed'],
            ];
        }
        return [
            'fromname' => review_manager::get_version_name($comparison['from']),
            'toname' => review_manager::get_version_name($comparison['to']),
            'rows' => $rows,
            'contentchanged' => $comparison['rows']['contenthash']['changed'],
        ];
    }

    /**
     * Review history, oldest first, with reasons and the "self-approved" note (F07.AC05, AC06, AC17).
     *
     * @return array[]
     */
    private function export_history(): array {
        $short = get_string('strftimedatetimeshort', 'core_langconfig');
        $entries = [];
        foreach (version_manager::get_log((int) $this->version->id) as $log) {
            $action = $log->action;
            $a = [
                'user' => s($this->get_username((int) $log->userid)),
                'date' => userdate((int) $log->timecreated, $short),
            ];
            if ($action === version_manager::LOG_ASSIGNED) {
                // Null: the assignment was removed; 0: the assigned person was deleted (privacy, t86-f).
                if ($log->relateduserid === null) {
                    $action = 'unassigned';
                } else {
                    $a['assignee'] = s($this->get_username((int) $log->relateduserid));
                }
            }
            $reason = '';
            if ($log->reason !== null && trim($log->reason) !== '') {
                $reason = format_text($log->reason, (int) $log->reasonformat, ['context' => access::get_context($this->item)]);
            } else if (
                (int) $log->userid === 0
                && in_array($action, [version_manager::LOG_RETURNED, version_manager::LOG_REJECTED], true)
            ) {
                // The person who gave the reason was deleted; the reason went with them (q70, t86-g).
                $reason = s(get_string('reasonremoved', 'local_nextpool'));
            }
            $entries[] = [
                'text' => get_string('reviewlog_' . $action, 'local_nextpool', $a),
                'reason' => $reason,
                'selfapproved' => $log->action === version_manager::LOG_PUBLISHED && !empty($this->version->selfapproved),
            ];
        }
        return $entries;
    }

    /**
     * Full name of a user, '-' if unknown.
     *
     * @param int $userid User id.
     * @return string Plain text.
     */
    private function get_username(int $userid): string {
        $user = $userid ? \core_user::get_user($userid) : null;
        return $user ? fullname($user) : '-';
    }
}
