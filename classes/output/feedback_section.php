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
use local_nextpool\local\comment_manager;
use local_nextpool\local\status;
use local_nextpool\local\subscription_manager;
use local_nextpool\local\version_manager;

/**
 * Section "Feedback" of a catalogue item: comments with replies, done, subscription (F08, task81).
 *
 * Shown on the item page (editorial view, courseid 0) and on the course view of the item (feedback.php). The
 * caller checks {@see access::can_comment()}. Threads are a list with a heading per comment, replies a nested
 * list; done threads are collapsed with name and time (F08 accessibility).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_section implements \renderable, \templatable {
    /** @var string Filter value: all threads. */
    public const FILTER_ALL = 'all';
    /** @var string Filter value: threads about the item in general. */
    public const FILTER_GENERAL = 'general';

    /** @var \stdClass Item record. */
    protected \stdClass $item;
    /** @var int Course the user comes from, 0 for the editorial view. */
    protected int $courseid;
    /** @var string Filter: FILTER_ALL, FILTER_GENERAL or a version id. */
    protected string $filter;
    /** @var \stdClass|null Course record, loaded once for the profile links. */
    protected ?\stdClass $course = null;

    /**
     * Constructor.
     *
     * @param \stdClass $item Item record.
     * @param int $courseid Course the user comes from, 0 for the editorial view.
     * @param string $filter FILTER_ALL, FILTER_GENERAL or a version id.
     */
    public function __construct(\stdClass $item, int $courseid = 0, string $filter = self::FILTER_ALL) {
        $this->item = $item;
        $this->courseid = $courseid;
        $this->filter = $filter;
    }

    /**
     * Page that handles the forms and shows the course view.
     *
     * @param array $params Extra parameters.
     * @return \moodle_url
     */
    public function get_action_url(array $params = []): \moodle_url {
        return new \moodle_url('/local/nextpool/feedback.php', ['itemid' => $this->item->id, 'courseid' => $this->courseid]
            + $params);
    }

    #[\Override]
    public function export_for_template(\renderer_base $output): array {
        global $USER;
        $item = $this->item;
        $context = access::get_context($item);
        $versions = $this->get_versions();
        $labels = $this->get_version_labels($versions);

        $filterversion = match ($this->filter) {
            self::FILTER_ALL => null,
            self::FILTER_GENERAL => 0,
            default => (int) $this->filter,
        };
        $threads = comment_manager::get_threads((int) $item->id, $filterversion);

        $userids = [];
        foreach ($threads as $thread) {
            $userids[] = (int) $thread->userid;
            $userids[] = (int) $thread->resolvedby;
            foreach ($thread->replies as $reply) {
                $userids[] = (int) $reply->userid;
            }
        }
        $users = $this->load_users($userids);

        $open = [];
        $done = [];
        foreach ($threads as $thread) {
            $data = $this->export_comment($thread, $users, $labels, $context, 4);
            $data['replies'] = array_map(
                fn($reply) => $this->export_comment($reply, $users, $labels, $context, 5),
                $thread->replies
            );
            $data['hasreplies'] = !empty($data['replies']);
            $data['replyheadingid'] = 'local-nextpool-reply-' . $thread->id;
            if ($thread->timeresolved) {
                $done[] = $data;
            } else {
                $open[] = $data;
            }
        }

        $versionoptions = $this->get_version_options($versions, $labels);
        $filteroptions = [
            ['value' => self::FILTER_ALL, 'label' => get_string('commentfilter_all', 'local_nextpool'),
                'selected' => $this->filter === self::FILTER_ALL],
            ['value' => self::FILTER_GENERAL, 'label' => get_string('commentversion_general', 'local_nextpool'),
                'selected' => $this->filter === self::FILTER_GENERAL],
        ];
        foreach ($this->get_selectable_versions($versions) as $version) {
            $filteroptions[] = [
                'value' => (int) $version->id,
                'label' => $labels[(int) $version->id],
                'selected' => $this->filter === (string) $version->id,
            ];
        }

        $subscribed = subscription_manager::is_subscribed((int) $item->id, (int) $USER->id);
        return [
            'itemid' => (int) $item->id,
            'courseid' => $this->courseid,
            'contextid' => $this->get_js_contextid(),
            'filter' => $this->filter,
            'actionurl' => (new \moodle_url('/local/nextpool/feedback.php'))->out(false),
            'sesskey' => sesskey(),
            'subscribed' => $subscribed,
            'subscribeicon' => $output->pix_icon($subscribed ? 'i/notifications' : 'i/gradingnotifications', ''),
            'subscribehelp' => get_string($subscribed ? 'subscribed_desc' : 'notsubscribed_desc', 'local_nextpool'),
            'shownotice' => !get_user_preferences(comment_manager::PREF_NOTICE, 0),
            'versionoptions' => $versionoptions,
            'filteroptions' => $filteroptions,
            'isfiltered' => $this->filter !== self::FILTER_ALL,
            'open' => $open,
            'hasopen' => !empty($open),
            'done' => $done,
            'hasdone' => !empty($done),
            'donecount' => count($done),
            'empty' => !$open && !$done,
        ];
    }

    /**
     * What a new comment can be about: the item in general and the selectable versions, with the default selected.
     *
     * The form of the section and the feedback area of the item dialog (task173) offer the same choice.
     *
     * @param \stdClass[]|null $versions All versions of the item, null to load them.
     * @param string[]|null $labels Version labels keyed by version id, null to build them.
     * @return array[] Options with value (version id, 0 for the item in general), label and selected.
     */
    public function get_version_options(?array $versions = null, ?array $labels = null): array {
        $versions ??= $this->get_versions();
        $labels ??= $this->get_version_labels($versions);
        $defaultversion = comment_manager::get_default_versionid($this->item, $this->courseid);
        $options = [['value' => 0, 'label' => get_string('commentversion_general', 'local_nextpool'),
            'selected' => $defaultversion === null]];
        foreach ($this->get_selectable_versions($versions) as $version) {
            $options[] = [
                'value' => (int) $version->id,
                'label' => $labels[(int) $version->id],
                'selected' => (int) $version->id === $defaultversion,
            ];
        }
        return $options;
    }

    /**
     * Label of each version: "Version N", or "Proposal" for versions without a number.
     *
     * @param \stdClass[] $versions Versions of the item.
     * @return string[] Keyed by version id.
     */
    protected function get_version_labels(array $versions): array {
        $labels = [];
        foreach ($versions as $version) {
            $labels[(int) $version->id] = $version->versionnr !== null
                ? get_string('versionnr', 'local_nextpool', (int) $version->versionnr)
                : get_string('commentversion_proposal', 'local_nextpool');
        }
        return $labels;
    }

    /**
     * Context id the course view and the editorial view check access in; the fragment uses it (lib.php).
     *
     * @return int
     */
    protected function get_js_contextid(): int {
        return $this->courseid > 0
            ? (int) \context_course::instance($this->courseid)->id
            : (int) \context_system::instance()->id;
    }

    /**
     * Versions of the item the person may refer to: all in the editorial view, numbered ones in a course.
     *
     * @return \stdClass[] Keyed by id.
     */
    protected function get_versions(): array {
        global $DB;
        return $DB->get_records(version_manager::TABLE, ['itemid' => $this->item->id], 'versionnr DESC, id DESC');
    }

    /**
     * Versions offered for a new comment and in the filter, newest first.
     *
     * Editorial view: every numbered version. Course view: published and withdrawn versions, the ones a course can
     * have applied.
     *
     * @param \stdClass[] $versions All versions of the item.
     * @return \stdClass[]
     */
    protected function get_selectable_versions(array $versions): array {
        $versions = array_filter($versions, static fn($version) => $version->versionnr !== null);
        if ($this->courseid > 0) {
            $versions = array_filter(
                $versions,
                static fn($version) => in_array($version->status, [status::PUBLISHED, status::WITHDRAWN], true)
            );
        }
        usort($versions, static fn($a, $b) => (int) $b->versionnr <=> (int) $a->versionnr);
        return $versions;
    }

    /**
     * Template data of one comment or reply.
     *
     * @param \stdClass $comment Comment record.
     * @param \stdClass[] $users Users keyed by id.
     * @param string[] $labels Version labels keyed by version id.
     * @param \context $context Item context.
     * @param int $level Heading level.
     * @return array
     */
    protected function export_comment(\stdClass $comment, array $users, array $labels, \context $context, int $level): array {
        $item = $this->item;
        $name = $this->user_link((int) $comment->userid, $users);
        $plainname = isset($users[(int) $comment->userid]) ? fullname($users[(int) $comment->userid]) : '';
        $created = $this->date((int) $comment->timecreated);
        $edited = !$comment->deleted && (int) $comment->timemodified > (int) $comment->timecreated;
        $version = $comment->versionid ? ($labels[(int) $comment->versionid] ?? '') : '';
        $rowname = $comment->deleted
            ? get_string('commentremoved', 'local_nextpool')
            : get_string('commentby', 'local_nextpool', (object) ['name' => $plainname, 'date' => $created['full']]);

        $actions = [];
        if (access::can_update_comment($comment)) {
            $actions[] = table_actions::icon_link(
                $this->get_action_url(['action' => 'edit', 'commentid' => $comment->id]),
                't/edit',
                get_string('commentedit', 'local_nextpool'),
                $rowname
            );
        }
        if (!$comment->parentid && access::can_resolve_comment($item, $comment)) {
            $resolved = (bool) $comment->timeresolved;
            $actions[] = $this->icon_form(
                $resolved ? 'reopen' : 'resolve',
                (int) $comment->id,
                $resolved ? 'e/undo' : 't/check',
                get_string($resolved ? 'commentreopen' : 'commentresolve', 'local_nextpool'),
                $rowname
            );
        }
        if (access::can_delete_comment($item, $comment)) {
            $actions[] = $this->icon_form(
                'delete',
                (int) $comment->id,
                't/delete',
                get_string('commentdelete', 'local_nextpool'),
                $rowname
            );
        }

        $resolvedtext = '';
        if ($comment->timeresolved) {
            $resolver = $comment->resolvedby ? ($users[(int) $comment->resolvedby] ?? null) : null;
            $resolveddate = $this->date((int) $comment->timeresolved)['full'];
            $resolvedtext = $resolver && !$resolver->deleted
                ? get_string('commentresolvedby', 'local_nextpool', (object) ['name' => fullname($resolver),
                    'date' => $resolveddate])
                : get_string('commentresolvedon', 'local_nextpool', $resolveddate);
        }

        return [
            'id' => (int) $comment->id,
            'anchor' => 'local-nextpool-comment-' . $comment->id,
            'headingid' => 'local-nextpool-comment-heading-' . $comment->id,
            'level' => $level,
            'h4' => $level === 4,
            'deleted' => (bool) $comment->deleted,
            'author' => $name,
            'authorname' => $plainname,
            'date' => $created['short'],
            'datefull' => $created['full'],
            'dateiso' => $created['iso'],
            'edited' => $edited,
            'editedfull' => $edited ? $this->date((int) $comment->timemodified)['full'] : '',
            'version' => $version,
            'hasversion' => $version !== '',
            'content' => $comment->deleted ? '' : format_text(
                (string) $comment->content,
                (int) $comment->contentformat,
                ['context' => $context]
            ),
            'actions' => $actions ? table_actions::group($actions, get_string('commentactions', 'local_nextpool', $rowname)) : '',
            'resolved' => (bool) $comment->timeresolved,
            'resolvedtext' => $resolvedtext,
            'canreply' => !$comment->parentid,
            // Set for replies too: Mustache would otherwise take the values of the parent and recurse endlessly.
            'replies' => [],
            'hasreplies' => false,
            'replyheadingid' => '',
        ];
    }

    /**
     * Icon button posting a feedback action; the JavaScript module sends it without leaving the page.
     *
     * @param string $action Action name.
     * @param int $commentid Comment id.
     * @param string $icon Core pix icon.
     * @param string $label Short label (tooltip).
     * @param string $rowname Name of the comment, for the accessible name.
     * @return string HTML
     */
    protected function icon_form(string $action, int $commentid, string $icon, string $label, string $rowname): string {
        $html = table_actions::icon_button(
            $this->get_action_url(['action' => $action, 'commentid' => $commentid]),
            $icon,
            $label,
            $rowname
        );
        // Mark the form for the module (feedback.js); without JavaScript it posts to feedback.php.
        return preg_replace('/^<form /', '<form data-local-nextpool-action="' . $action . '" ', $html, 1);
    }

    /**
     * Name of a user, linked to the profile when the viewer may see it (UX proposal: name as profile link).
     *
     * @param int $userid User id.
     * @param \stdClass[] $users Users keyed by id.
     * @return string HTML
     */
    protected function user_link(int $userid, array $users): string {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        $user = $users[$userid] ?? null;
        if (!$user || $user->deleted) {
            return s(get_string('unknownuser'));
        }
        $name = s(fullname($user));
        if ($this->courseid > 0 && $this->course === null) {
            $this->course = get_course($this->courseid);
        }
        $course = $this->course ?? null;
        if (!user_can_view_profile($user, $course)) {
            return $name;
        }
        $url = $course
            ? new \moodle_url('/user/view.php', ['id' => $userid, 'course' => $course->id])
            : new \moodle_url('/user/profile.php', ['id' => $userid]);
        return \html_writer::link($url, $name);
    }

    /**
     * Short date with the full time for the tooltip (UX proposal).
     *
     * @param int $time Timestamp.
     * @return array{short:string,full:string,iso:string}
     */
    protected function date(int $time): array {
        return [
            'short' => userdate($time, get_string('strftimedatefullshort', 'core_langconfig')),
            'full' => userdate($time, get_string('strftimedatetime', 'core_langconfig')),
            'iso' => date('c', $time),
        ];
    }

    /**
     * Users with the fields user_can_view_profile() and fullname() need.
     *
     * @param int[] $userids User ids.
     * @return \stdClass[] Keyed by id.
     */
    protected function load_users(array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter($userids)));
        if (!$userids) {
            return [];
        }
        $fields = 'id, deleted, auth, confirmed, suspended' . \core_user\fields::for_name()->get_sql()->selects;
        return $DB->get_records_list('user', 'id', $userids, '', $fields);
    }
}
