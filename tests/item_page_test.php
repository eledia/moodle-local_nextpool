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

namespace local_nextpool;

use local_nextpool\local\access;
use local_nextpool\local\catalogue;
use local_nextpool\local\item_manager;
use local_nextpool\local\source\manager;
use local_nextpool\local\status;
use local_nextpool\local\version_manager;
use local_nextpool\output\item_page;
use local_nextpool\output\next_step;
use local_nextpool\output\update_mode;

/**
 * Tests for the compact item page, the next step and the catalogue hint (task102, task104, task105).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(next_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(update_mode::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(catalogue::class)]
final class item_page_test extends \advanced_testcase {
    /** @var \local_nextpool_generator */
    private \local_nextpool_generator $generator;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
    }

    /**
     * Template data of the item page.
     *
     * @param int $itemid Item id.
     * @return array
     */
    private function export(int $itemid): array {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/item.php', ['id' => $itemid]);
        return (new item_page(item_manager::get($itemid)))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * The step concerns the latest version while it is a draft or in review, then the item status (task102).
     */
    public function test_next_step(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'one');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $version = version_manager::create_from_source((int) $item->id, 'handout.pdf');

        $step = next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id));
        $this->assertSame(status::REVIEW, $step->to);
        $this->assertFalse($step->isitem);
        $this->assertTrue($step->post);
        $this->assertSame(get_string('versiontransition_review', 'local_nextpool', 1), $step->label);

        version_manager::set_status((int) $version->id, status::REVIEW);
        $step = next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id));
        $this->assertFalse($step->post, 'A version in review is decided on the review page (task80).');
        $this->assertStringContainsString('/local/nextpool/review.php', $step->url->out(false));

        version_manager::set_status((int) $version->id, status::PUBLISHED);
        $returnurl = new \moodle_url('/local/nextpool/items.php', ['search' => 'Fire']);
        $step = next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id), $returnurl);
        $this->assertTrue($step->isitem);
        $this->assertSame(status::REVIEW, $step->to);
        $this->assertSame('/local/nextpool/items.php?search=Fire', $step->url->param('returnurl'));

        item_manager::set_status((int) $item->id, status::REVIEW);
        $step = next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id));
        $this->assertSame(status::PUBLISHED, $step->to);

        item_manager::set_status((int) $item->id, status::PUBLISHED);
        $this->assertNull(next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id)));

        // Without the right there is no step.
        $draft = $this->generator->create_item();
        $this->assertNotNull(next_step::for_item($draft, null));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull(next_step::for_item($draft, null));
    }

    /**
     * A review notice distinguishes the entry's state from its newest version (bug211).
     *
     * @param bool $published Whether an older version is already published.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('review_entry_states')]
    public function test_review_notice_names_both_states(bool $published): void {
        $item = $published ? $this->generator->create_published_item() : $this->generator->create_item();
        $version = $this->import_review_version($item);
        $data = $this->export((int) $item->id);
        $item = item_manager::get((int) $item->id);
        $this->assertSame($published ? status::PUBLISHED : status::DRAFT, $item->status);
        $this->assertSame(get_string('versionreviewstatusnotice', 'local_nextpool', [
            'version' => \local_nextpool\local\review_manager::get_version_name($version),
            'itemstatus' => status::get_name($item->status),
        ]), $data['latestversionnotice']['message']);
        $this->assertSame('', $data['latestversionnotice']['reason']);
        $this->assertSame('alert-info', $data['latestversionnotice']['class']);
    }

    /**
     * Entry states that may have a version in review.
     *
     * @return array
     */
    public static function review_entry_states(): array {
        return [[false], [true]];
    }

    /**
     * Rejecting the only version gives a reason and no unusable entry-review action; a new draft restores the step.
     */
    public function test_rejected_latest_version_has_reason_and_requires_new_draft(): void {
        global $PAGE;
        $item = $this->generator->create_item();
        $version = $this->import_review_version($item);
        \local_nextpool\local\review_manager::reject(
            (int) $version->id,
            '<p>Replace <strong>obsolete</strong> guidance.</p><script>alert(1)</script>',
            0,
            FORMAT_HTML
        );
        $data = $this->export((int) $item->id);
        $this->assertStringContainsString('<strong>obsolete</strong>', $data['latestversionnotice']['reason']);
        $this->assertStringNotContainsString('<script', $data['latestversionnotice']['reason']);
        $this->assertStringNotContainsString(get_string('itemtransition_review', 'local_nextpool'), $data['actions']);
        $this->assertStringNotContainsString(get_string('itemtransition_review', 'local_nextpool'), $data['iconactions']);
        $this->assertNull(next_step::for_item(item_manager::get((int) $item->id), version_manager::get_latest((int) $item->id)));
        $html = $PAGE->get_renderer('core')->render_from_template('local_nextpool/item_page', $data);
        $this->assertStringContainsString('local-nextpool-versionnotice', $html);
        $this->assertStringContainsString('Replace <strong>obsolete</strong> guidance.', $html);

        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'corrected.pdf', 'corrected guidance');
        $draft = version_manager::create_from_source((int) $item->id, 'corrected.pdf');
        $step = next_step::for_item(item_manager::get((int) $item->id), $draft);
        $this->assertNotNull($step);
        $this->assertFalse($step->isitem);
        $this->assertSame(status::REVIEW, $step->to);
        $this->assertNull($this->export((int) $item->id)['latestversionnotice']);
    }

    /**
     * A caller without access to the editorial item view receives no rejection reason.
     */
    public function test_rejection_reason_requires_editorial_access(): void {
        $item = $this->generator->create_item();
        $version = $this->import_review_version($item);
        \local_nextpool\local\review_manager::reject((int) $version->id, 'Editorial-only reason.');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(access::can_view(item_manager::get((int) $item->id)));
        $this->assertNull($this->export((int) $item->id)['latestversionnotice']);
    }

    /**
     * An older published version remains a legitimate basis for submitting the entry after a newer rejection.
     */
    public function test_rejected_update_does_not_block_existing_published_version(): void {
        $item = $this->generator->create_item();
        $published = $this->generator->publish_new_version((int) $item->id, 'approved content');
        $version = $this->import_review_version($item);
        \local_nextpool\local\review_manager::reject((int) $version->id, 'This update needs replacing.');
        $item = item_manager::get((int) $item->id);
        $this->assertSame((int) $published->id, (int) $item->currentversionid);
        $this->assertSame(status::PUBLISHED, version_manager::get((int) $published->id)->status);
        $step = next_step::for_item($item, version_manager::get_latest((int) $item->id));
        $this->assertNotNull($step);
        $this->assertTrue($step->isitem);
    }

    /**
     * Privacy deletion removes the last rejection reason without exposing a different, older return reason.
     */
    public function test_removed_rejection_reason_never_falls_back_to_old_return_reason(): void {
        $this->redirectMessages();
        $item = $this->generator->create_item();
        $version = $this->import_review_version($item);
        \local_nextpool\local\review_manager::return_for_revision((int) $version->id, 'Old correction request.');
        version_manager::set_status((int) $version->id, status::REVIEW);
        $reviewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_system::instance();
        assign_capability(access::REVIEW, CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $reviewer->id, $context->id);
        \local_nextpool\local\review_manager::reject((int) $version->id, 'Private rejection reason.', $reviewer->id);
        \local_nextpool\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $reviewer,
            'local_nextpool',
            [$context->id]
        ));
        $reason = $this->export((int) $item->id)['latestversionnotice']['reason'];
        $this->assertStringContainsString(get_string('reasonremoved', 'local_nextpool'), $reason);
        $this->assertStringNotContainsString('Old correction request.', $reason);
        $this->assertStringNotContainsString('Private rejection reason.', $reason);
    }

    /**
     * Import an actual file and submit it for review.
     *
     * @param \stdClass $item Entry.
     * @return \stdClass Submitted version.
     */
    private function import_review_version(\stdClass $item): \stdClass {
        $source = manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'review.pdf', 'content for review');
        $version = version_manager::create_from_source((int) $item->id, 'review.pdf');
        return version_manager::set_status((int) $version->id, status::REVIEW);
    }

    /**
     * A published version of an unpublished item gets a notice with the item step, which the header does not repeat
     * (task104); empty blocks are left out (task105).
     */
    public function test_status_notice_and_empty_blocks(): void {
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'one');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->generator->publish_new_version((int) $item->id, 'one');

        $data = $this->export((int) $item->id);
        $this->assertSame(get_string('itemnotpublishednotice', 'local_nextpool', 1), $data['statusnotice']);
        $this->assertStringContainsString('name="to" value="review"', $data['statusnoticeaction']);
        $this->assertStringNotContainsString(get_string('itemtransition_review', 'local_nextpool'), $data['actions']);
        $this->assertFalse($data['hascompetencies']);
        $labels = array_column($data['metadata'], 'label');
        $this->assertNotContains(get_string('itemtype', 'local_nextpool'), $labels);
        $this->assertNotContains(get_string('tags'), $labels);
        $this->assertSame([], $labels, 'Without tags, audience, origin or custom fields there is no details panel.');
        $this->assertFalse($data['hasmetadata']);
        $this->assertSame(s($item->uuid), $data['technical'][0]['value']);
        // No placements: no chip.
        $this->assertNotContains(get_string('usedincourses', 'local_nextpool'), array_column($data['chips'], 'label'));

        // A withdrawn item stays withdrawn; the notice says so and offers to publish it again.
        item_manager::set_status((int) $item->id, status::REVIEW);
        item_manager::set_status((int) $item->id, status::PUBLISHED);
        $this->assertSame('', $this->export((int) $item->id)['statusnotice']);
        item_manager::set_status((int) $item->id, status::WITHDRAWN);
        $data = $this->export((int) $item->id);
        $this->assertSame(get_string('itemwithdrawnnotice', 'local_nextpool', 1), $data['statusnotice']);
        $this->assertStringContainsString('name="to" value="published"', $data['statusnoticeaction']);
        $this->assertStringNotContainsString('to=published', html_entity_decode($data['iconactions']));

        // Withdrawing a published version is the only rarer action of its row: an icon link, no menu (task166/task167).
        $version = array_column($data['versions'], 'actions', 'versionnr')[1];
        $this->assertStringContainsString('to=withdrawn', html_entity_decode($version));
        $this->assertStringContainsString(fullname(get_admin()), $data['versions'][0]['createdname']);
    }

    /**
     * Status pills look like status::badge() in the lists, submitting uses the "send" icon, and a single rarer action
     * is an icon link instead of a menu; from two on it is the "More actions" menu (task166, task167, bug232).
     */
    public function test_status_pills_and_more_actions(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $moreactions = get_string('moreactions');

        // A never published draft without versions: Delete is the only rarer action.
        $draft = $this->generator->create_item();
        $data = $this->export((int) $draft->id);
        $this->assertSame($output->pix_icon(status::get_icon(status::DRAFT), ''), $data['statusicon']);
        $this->assertStringNotContainsString($moreactions, $data['iconactions']);
        $this->assertStringContainsString('action=delete', html_entity_decode($data['iconactions']));
        $this->assertStringContainsString('local-nextpool-iconaction', $data['iconactions']);
        $html = $output->render_from_template('local_nextpool/item_page', $data);
        $this->assertMatchesRegularExpression(
            '~<span class="badge rounded-pill[^"]*local-nextpool-statuspill bg-light text-dark border local-nextpool-item__status">'
                . '<span class="visually-hidden">[^<]+</span>' . preg_quote($data['statusicon'], '~') . '~',
            $html
        );

        // A draft version is submitted with the "send" icon, never the upload arrow.
        $source = $this->generator->create_source();
        $this->generator->put_file($source, 'handout.pdf', 'one');
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $version = $this->export((int) $item->id)['versions'][0];
        $this->assertSame($output->pix_icon(status::get_icon(status::DRAFT), ''), $version['statusicon']);
        $this->assertStringContainsString($output->pix_icon('t/sendmessage', ''), $version['actions']);
        $this->assertStringNotContainsString($output->pix_icon('i/export', ''), $version['actions']);

        // Published item with a published version: withdrawing and checking for updates make a menu in the header,
        // withdrawing the version alone is an icon link in its row.
        $item = $this->generator->create_item(['sourceid' => $source->get_id()]);
        $this->generator->publish_new_version((int) $item->id, 'two');
        if (item_manager::get((int) $item->id)->status !== status::PUBLISHED) {
            item_manager::set_status((int) $item->id, status::REVIEW);
            item_manager::set_status((int) $item->id, status::PUBLISHED);
        }
        $data = $this->export((int) $item->id);
        $this->assertSame($output->pix_icon(status::get_icon(status::PUBLISHED), ''), $data['statusicon']);
        $this->assertStringContainsString($moreactions, $data['iconactions']);
        $published = $data['versions'][0]['actions'];
        $this->assertStringNotContainsString($moreactions, $published);
        $this->assertStringContainsString('to=withdrawn', html_entity_decode($published));
        $html = $output->render_from_template('local_nextpool/item_page', $data);
        $this->assertStringContainsString(
            '<span class="badge rounded-pill text-wrap text-start local-nextpool-statuspill bg-success">'
                . $data['versions'][0]['statusicon'],
            $html
        );
    }

    /**
     * The item facts sit in the card of the latest version: the update mode as named icon, the rest as pills with
     * short text and full text (task165).
     */
    public function test_item_facts_in_version_card(): void {
        global $PAGE;
        $source = $this->generator->create_source('moodle', [], 'Library');
        $this->generator->put_file($source, 'handout.pdf', 'one');
        $item = $this->generator->create_item(['sourceid' => $source->get_id(), 'license' => 'cc-sa-4.0']);

        // Without a version the facts stand under the heading "Versions".
        $data = $this->export((int) $item->id);
        $this->assertFalse($data['factsinversion']);
        $this->assertSame(get_string('labelvalue', 'local_nextpool', [
            'label' => get_string('updatemode', 'local_nextpool'),
            'value' => get_string('updatemodeshort_inplace', 'local_nextpool'),
        ]), $data['iconfacts'][0]['label']);
        $this->assertStringContainsString(
            get_string('updatemode_inplace', 'local_nextpool'),
            $data['iconfacts'][0]['description']
        );

        $facts = array_column($data['facts'], 'short', 'full');
        $this->assertSame('Library', $facts[get_string('source', 'local_nextpool') . ': Library']);
        $this->assertSame(
            get_string('visibilityshort_site', 'local_nextpool'),
            $facts[get_string('visibility', 'local_nextpool') . ': ' . get_string('visibility_site', 'local_nextpool')]
        );
        $this->assertCount(1, $data['iconfacts'], 'A licence other than "All rights reserved" is a pill.');
        $this->assertSame('CC BY-SA 4.0', $facts[get_string('license') . ': ' . get_string('cc-sa-4.0', 'core_license')]);
        $date = userdate((int) $item->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'));
        $this->assertSame(
            userdate((int) $item->timemodified, get_string('strftimedatefullshort', 'core_langconfig')),
            $facts[get_string('lastmodified') . ': ' . $date]
        );

        // With versions, only the card of the latest numbered version carries them.
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $this->generator->put_file($source, 'handout.pdf', 'two');
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $data = $this->export((int) $item->id);
        $this->assertTrue($data['factsinversion']);
        $this->assertSame([2 => true, 1 => false], array_column($data['versions'], 'showfacts', 'versionnr'));

        $html = $PAGE->get_renderer('core')->render_from_template('local_nextpool/item_page', $data);
        $this->assertSame(1, substr_count($html, 'local-nextpool-itemfacts'));
        $this->assertStringNotContainsString('id="local-nextpool-details"', $html, 'No details card of its own.');
        $label = $data['iconfacts'][0]['label'];
        $this->assertStringContainsString('<span class="visually-hidden">' . s($label) . '</span>', $html);
        $this->assertStringContainsString('tabindex="0" data-bs-toggle="tooltip"', $html);
    }

    /**
     * Without a licence, or with "Licence not specified", the item page shows "All rights reserved" as © icon only;
     * the stored value stays (task169, q133).
     *
     * @param string $stored Stored licence.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([''])]
    #[\PHPUnit\Framework\Attributes\TestWith(['unknown'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['allrightsreserved'])]
    public function test_all_rights_reserved_icon(string $stored): void {
        global $DB, $PAGE;
        $item = $this->generator->create_item();
        $DB->set_field('local_nextpool_item', 'license', $stored, ['id' => $item->id]);

        $data = $this->export((int) $item->id);
        $name = get_string('labelvalue', 'local_nextpool', [
            'label' => get_string('license'),
            'value' => get_string('allrightsreserved', 'core_license'),
        ]);
        $this->assertCount(2, $data['iconfacts']);
        $this->assertSame($name, $data['iconfacts'][1]['label']);
        $this->assertSame($name, $data['iconfacts'][1]['description']);
        $labels = array_map(static fn($fact) => explode(':', $fact['full'])[0], $data['facts']);
        $this->assertNotContains(get_string('license'), $labels, 'No licence pill.');
        $this->assertStringNotContainsString(get_string('unknown', 'core_license'), json_encode($data));
        $this->assertSame($stored, (string) $DB->get_field('local_nextpool_item', 'license', ['id' => $item->id]));

        $html = $PAGE->get_renderer('core')->render_from_template('local_nextpool/item_page', $data);
        $this->assertStringContainsString('<span class="visually-hidden">' . s($name) . '</span>', $html);
    }

    /**
     * Each update mode has its one icon (task165); only the fallback type is replaced.
     */
    public function test_update_mode_icons(): void {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $this->assertSame(update_mode::INPLACE, update_mode::for_type('file'));
        $this->assertSame(update_mode::SOFTREPLACE, update_mode::for_type(\local_nextpool\local\item_types::GENERIC));
        foreach (update_mode::ICONS as $mode => $pix) {
            $this->assertSame($output->pix_icon($pix, ''), update_mode::icon($mode, $output));
        }
        $this->assertSame(update_mode::short_name(update_mode::SOFTREPLACE), update_mode::short_name('other'));
    }

    /**
     * Editors learn about matching items that are not published yet (task104).
     */
    public function test_count_unpublished(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->generator->create_published_item(['title' => 'Published']);
        $this->generator->create_item(['title' => 'Fire draft']);
        $review = $this->generator->create_item(['title' => 'Fire review']);
        item_manager::set_status((int) $review->id, status::REVIEW);
        $withdrawn = $this->generator->create_published_item(['title' => 'Fire withdrawn']);
        item_manager::set_status((int) $withdrawn->id, status::WITHDRAWN);

        $contextids = access::get_editorial_contextids();
        $this->assertSame(2, catalogue::count_unpublished((int) $course->id, $contextids));
        $this->assertSame(2, catalogue::count_unpublished((int) $course->id, $contextids, 'Fire'));
        $this->assertSame(0, catalogue::count_unpublished((int) $course->id, $contextids, 'Nothing'));
        $this->assertSame(0, catalogue::count_unpublished((int) $course->id, []));
        $this->assertSame(1, catalogue::count_items((int) $course->id), 'The catalogue itself is unchanged.');
    }

    /**
     * The editorial list knows the latest numbered version of each item (task102).
     */
    public function test_search_latest_version(): void {
        $item = $this->generator->create_published_item(['title' => 'Fire safety']);
        $this->generator->put_file(manager::get((int) $item->sourceid), 'handout.pdf', 'two');
        version_manager::create_from_source((int) $item->id, 'handout.pdf');
        $found = item_manager::search(access::get_editorial_contextids(), ['search' => 'Fire safety']);
        $this->assertCount(1, $found);
        $row = reset($found);
        $this->assertSame(2, (int) $row->latestversionnr);
        $this->assertSame(status::DRAFT, $row->latestversionstatus);
    }
}
