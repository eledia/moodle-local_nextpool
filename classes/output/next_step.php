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
use local_nextpool\local\status;

/**
 * The next due workflow step of an item, for the item page and the item list (task102, task105).
 *
 * The step concerns the latest version while it is a draft or in review, otherwise the item status (PO, 2026-11-14).
 * A version in review is decided on the review page (task80), so its step opens that page. There is no step when the
 * user may not take it.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class next_step {
    /**
     * Constructor.
     *
     * @param string $to Target status.
     * @param string $label Full label, names the version or the item.
     * @param string $icon Core pix icon.
     * @param \moodle_url $url Target; for a POST the action parameters without sesskey.
     * @param bool $post Whether the step changes something (POST) or only opens the review page (GET).
     * @param bool $isitem Whether the step changes the item status (not a version).
     */
    public function __construct(
        /** @var string Target status. */
        public readonly string $to,
        /** @var string Full label. */
        public readonly string $label,
        /** @var string Core pix icon. */
        public readonly string $icon,
        /** @var \moodle_url Target. */
        public readonly \moodle_url $url,
        /** @var bool POST (true) or GET. */
        public readonly bool $post,
        /** @var bool Item status step. */
        public readonly bool $isitem,
    ) {
    }

    /**
     * The next step of an item for the current user.
     *
     * @param \stdClass $item Item record.
     * @param \stdClass|null $latest Latest numbered version (id, status, versionnr and, if known, origin and createdby).
     * @param \moodle_url|null $returnurl Page to return to after a POST, e.g. the filtered list; null for the item page.
     * @return self|null
     */
    public static function for_item(\stdClass $item, ?\stdClass $latest, ?\moodle_url $returnurl = null): ?self {
        $params = ['id' => $item->id] + ($returnurl ? ['returnurl' => $returnurl->out_as_local_url(false)] : []);
        $itemurl = new \moodle_url('/local/nextpool/item.php', $params);
        if ($latest && $latest->status === status::DRAFT) {
            if (!access::can_change_status($item, status::DRAFT, status::REVIEW, null, $latest)) {
                return null;
            }
            return new self(
                status::REVIEW,
                get_string('versiontransition_review', 'local_nextpool', (int) $latest->versionnr),
                't/sendmessage',
                new \moodle_url($itemurl, ['action' => 'versionstatus', 'versionid' => $latest->id, 'to' => status::REVIEW]),
                true,
                false
            );
        }
        if ($latest && $latest->status === status::REVIEW) {
            if (!access::can_review($item)) {
                return null;
            }
            return new self(
                status::PUBLISHED,
                get_string('reviewversion', 'local_nextpool', (int) $latest->versionnr),
                'i/checkedcircle',
                new \moodle_url('/local/nextpool/review.php', ['versionid' => $latest->id]),
                false,
                false
            );
        }
        if ($latest && $latest->status === status::REJECTED && empty($item->currentversionid)) {
            // The only offered version is rejected; submitting the item itself cannot make it reviewable (bug212).
            return null;
        }
        return self::for_item_status($item, $itemurl);
    }

    /**
     * The forward step of the item status: submit a draft, publish an item in review that has a published version.
     *
     * @param \stdClass $item Item record.
     * @param \moodle_url $itemurl Item page with the return URL, without action.
     * @return self|null
     */
    public static function for_item_status(\stdClass $item, \moodle_url $itemurl): ?self {
        $to = match ($item->status) {
            status::DRAFT => status::REVIEW,
            status::REVIEW => !empty($item->currentversionid) ? status::PUBLISHED : null,
            default => null,
        };
        if ($to === null || !access::can_change_status($item, $item->status, $to)) {
            return null;
        }
        return new self(
            $to,
            get_string('itemtransition_' . $to, 'local_nextpool'),
            $to === status::PUBLISHED ? 'i/checkedcircle' : 't/sendmessage',
            new \moodle_url($itemurl, ['action' => 'itemstatus', 'to' => $to]),
            true,
            true
        );
    }

    /**
     * The step as an icon button with tooltip.
     *
     * @param string $rowname Name of the row (plain text), or '' when the label names its target already.
     * @return string HTML
     */
    public function icon(string $rowname = ''): string {
        return table_actions::icon_button($this->url, $this->icon, $this->label, $rowname, $this->post ? 'post' : 'get');
    }

    /**
     * The step as a labelled button, for the header of the item page.
     *
     * @param \renderer_base $output Renderer.
     * @return string HTML
     */
    public function button(\renderer_base $output): string {
        $url = $this->post ? new \moodle_url($this->url, ['sesskey' => sesskey()]) : $this->url;
        return $output->single_button($url, $this->label, $this->post ? 'post' : 'get', [
            'type' => \single_button::BUTTON_SECONDARY,
        ]);
    }
}
