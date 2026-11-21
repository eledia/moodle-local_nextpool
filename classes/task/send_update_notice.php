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

namespace local_nextpool\task;

use local_nextpool\local\item_manager;
use local_nextpool\local\placement_manager;
use local_nextpool\local\version_manager;

/**
 * Tell the teachers of a course that a catalogue version waits for their decision (F06).
 *
 * Custom data: placementid, versionid. Recipients are the active enrolled users
 * who may manage activities and see the catalogue in the course, the same people
 * who can act on the placements page. Nothing is sent if the placement is gone
 * or the version is no longer the pending one, so a late task does no harm.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_update_notice extends \core\task\adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('task_sendupdatenotice', 'local_nextpool');
    }

    #[\Override]
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $placement = $DB->get_record(placement_manager::TABLE, ['id' => (int) $data->placementid]);
        if (!$placement || (int) $placement->pendingversionid !== (int) $data->versionid) {
            mtrace('Placement ' . (int) $data->placementid . ' no longer waits for this version.');
            return;
        }
        $cm = placement_manager::get_cm($placement);
        if ($cm === null) {
            // The course module is being deleted; its placement goes with it (bug63, Q-24).
            mtrace('Placement ' . (int) $placement->id . ': the activity no longer exists.');
            return;
        }
        $course = get_course((int) $placement->courseid);
        $context = \context_course::instance($course->id);
        $item = item_manager::get((int) $placement->itemid);
        $version = version_manager::get((int) $data->versionid);
        $url = new \moodle_url('/local/nextpool/placements.php', ['courseid' => $course->id]);

        $count = 0;
        foreach (self::get_recipients($context) as $recipient) {
            \local_nextpool\local\notification_sender::send((int) $recipient->id, static function () use (
                $item,
                $context,
                $version,
                $cm,
                $course,
                $url,
                $placement
            ) {
                $a = (object) [
                    'item' => format_string($item->title, true, ['context' => $context, 'escape' => false]),
                    'version' => (int) $version->versionnr,
                    'activity' => format_string($cm->name, true, ['context' => $context, 'escape' => false]),
                    'course' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                    'url' => $url->out(false),
                ];
                $body = get_string('notice_body', 'local_nextpool', $a);
                $reason = placement_manager::get_downgrade_reason($placement);
                if ($reason !== null) {
                    $body .= "\n\n" . get_string('notice_reason', 'local_nextpool', (object) ['reason' => $reason]);
                }
                $subject = get_string('notice_subject', 'local_nextpool', $a);

                $message = new \core\message\message();
                $message->component = 'local_nextpool';
                $message->name = 'updateavailable';
                $message->userfrom = \core_user::get_noreply_user();
                $message->subject = $subject;
                $message->fullmessage = $body;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = '';
                $message->smallmessage = $subject;
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = get_string('placementsheading', 'local_nextpool');
                $message->courseid = $course->id;
                return $message;
            });
            $count++;
        }
        mtrace('Placement ' . (int) $placement->id . ': ' . $count . ' teacher(s) notified.');
    }

    /**
     * Users who can decide about updates in the course.
     *
     * @param \context_course $context Course context.
     * @return \stdClass[]
     */
    private static function get_recipients(\context_course $context): array {
        $users = get_enrolled_users($context, 'moodle/course:manageactivities', 0, 'u.*', null, 0, 0, true);
        return array_filter($users, fn($user) => has_capability('local/nextpool:viewcatalogue', $context, $user));
    }
}
