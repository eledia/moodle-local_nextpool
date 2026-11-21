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

use local_nextpool\external\search_competencies;
use local_nextpool\external\search_users;
use local_nextpool\form\item_form;
use local_nextpool\form\version_details_form;
use local_nextpool\local\access;
use local_nextpool\local\comment_manager;
use local_nextpool\local\item_editor_manager;
use local_nextpool\local\item_manager;
use local_nextpool\local\status;
use local_nextpool\local\subscription_manager;
use local_nextpool\local\version_manager;
use local_nextpool\privacy\provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Item editors and editing together (F08.AC08–AC10, test67, task84).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(item_editor_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(search_users::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_nextpool\form\edit_lock::class)]
final class item_editor_test extends \externallib_advanced_testcase {
    /** @var \local_nextpool_generator Generator. */
    private \local_nextpool_generator $generator;
    /** @var \stdClass Item with an item editor. */
    private \stdClass $item;
    /** @var \stdClass Another item in the same context. */
    private \stdClass $other;
    /** @var \stdClass Item editor without editorial rights. */
    private \stdClass $editor;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_nextpool');
        $this->item = $this->generator->create_item(['title' => 'Fire safety']);
        $this->other = $this->generator->create_item(['title' => 'First aid']);
        $this->editor = $this->getDataGenerator()->create_user(['firstname' => 'Eda', 'lastname' => 'Editor']);
    }

    /**
     * Adding records the person once, subscribes them and tells them; removing takes the right away.
     */
    public function test_add_and_remove(): void {
        $admin = get_admin();
        $this->setAdminUser();
        $sink = $this->redirectMessages();

        $this->assertTrue(item_editor_manager::add((int) $this->item->id, (int) $this->editor->id));
        $this->assertFalse(
            item_editor_manager::add((int) $this->item->id, (int) $this->editor->id),
            'Adding twice changes nothing.'
        );

        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $this->assertSame('editoradded', $messages[0]->eventtype);
        $this->assertEquals($this->editor->id, $messages[0]->useridto);
        $this->assertStringContainsString('Fire safety', $messages[0]->subject);
        $this->assertStringContainsString(fullname($admin), $messages[0]->fullmessage);

        $this->assertTrue(subscription_manager::is_subscribed((int) $this->item->id, (int) $this->editor->id));
        $this->assertSame([(int) $this->editor->id], array_keys(item_editor_manager::get_editors((int) $this->item->id)));
        $this->assertSame([(int) $this->item->id], array_keys(item_editor_manager::get_items((int) $this->editor->id)));
        $this->assertTrue(item_editor_manager::has_items((int) $this->editor->id));

        $this->assertTrue(item_editor_manager::remove((int) $this->item->id, (int) $this->editor->id));
        $this->assertFalse(item_editor_manager::remove((int) $this->item->id, (int) $this->editor->id));
        $this->assertFalse(access::can_edit($this->item, (int) $this->editor->id));
        $this->assertFalse(item_editor_manager::has_items((int) $this->editor->id));
    }

    /**
     * Item editors import and upload only the file of their item; the editorial team any file (bug102, q68, q69).
     */
    public function test_import_path_rights(): void {
        $this->setAdminUser();
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $userid = (int) $this->editor->id;

        $this->assertFalse(access::can_import_path($this->item, 'handout.pdf', $userid), 'No version yet: no file of the item.');
        $this->create_draft_version($this->item);
        $this->assertTrue(access::can_import_path($this->item, 'handout.pdf', $userid));
        $this->assertFalse(access::can_import_path($this->item, 'other.pdf', $userid));
        $this->assertFalse(access::can_import_path($this->item, '', $userid));
        $this->assertFalse(access::can_import_path($this->other, 'handout.pdf', $userid));
        $this->assertTrue(access::can_import_path($this->item, 'other.pdf', (int) get_admin()->id));

        $this->setUser($this->editor);
        $this->expectException(\required_capability_exception::class);
        access::require_import_path($this->item, 'Internal/other.pdf');
    }

    /**
     * Guests, deleted and suspended accounts cannot be added (q68: active accounts of the site).
     */
    public function test_add_rejects_inactive_accounts(): void {
        $this->setAdminUser();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        foreach ([guest_user()->id, $suspended->id, $deleted->id, 999999] as $userid) {
            try {
                item_editor_manager::add((int) $this->item->id, (int) $userid);
                $this->fail('Expected exception for user ' . $userid);
            } catch (\moodle_exception $e) {
                $this->assertSame('error_invaliditemeditor', $e->errorcode);
            }
        }
    }

    /**
     * Item editors change and submit their item, but neither publish nor touch other items (F08.AC08).
     */
    public function test_editor_rights(): void {
        $this->setAdminUser();
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $userid = (int) $this->editor->id;
        $version = $this->create_draft_version($this->item);

        $this->assertTrue(access::can_view($this->item, $userid));
        $this->assertTrue(access::can_edit($this->item, $userid));
        $this->assertFalse(access::can_manage($this->item, $userid), 'No deleting, no adding editors.');
        $this->assertTrue(access::can_change_status($this->item, status::DRAFT, status::REVIEW, $userid, $version));
        $this->assertTrue(access::can_change_status($this->item, status::DRAFT, status::REVIEW, $userid));
        $this->assertFalse(access::can_change_status($this->item, status::REVIEW, status::PUBLISHED, $userid, $version));
        $this->assertFalse(access::can_change_status($this->item, status::REVIEW, status::PUBLISHED, $userid));
        $this->assertFalse(access::can_change_status($this->item, status::REVIEW, status::DRAFT, $userid));
        $this->assertFalse(access::can_review($this->item, $userid));

        $this->assertFalse(access::can_view($this->other, $userid));
        $this->assertFalse(access::can_edit($this->other, $userid));

        // Comments: mark as done yes, remove other people's no.
        $comment = comment_manager::add((int) $this->item->id, 'Typo on page 2', FORMAT_MOODLE, null, null, (int) get_admin()->id);
        $this->assertTrue(access::can_resolve_comment($this->item, $comment, $userid));
        $this->assertFalse(access::can_delete_comment($this->item, $comment, $userid));

        $this->setUser($this->editor);
        $this->assertFalse(access::can_use_editorial());
        access::require_edit($this->item);
        $this->expectException(\required_capability_exception::class);
        access::require_edit($this->other);
    }

    /**
     * The editorial team in the item's context keeps all rights; editors in another category do not get them.
     */
    public function test_editorial_team_rights(): void {
        $category = $this->getDataGenerator()->create_category();
        $incategory = $this->generator->create_item(['contextid' => \context_coursecat::instance($category->id)->id]);
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::MANAGE, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $manager->id, \context_coursecat::instance($category->id)->id);

        $this->assertTrue(access::can_manage($incategory, (int) $manager->id));
        $this->assertTrue(access::can_edit($incategory, (int) $manager->id));
        $this->assertFalse(access::can_manage($this->item, (int) $manager->id));
        $this->assertFalse(access::can_edit($this->item, (int) $manager->id));
    }

    /**
     * Saving the item form after someone else saved is refused with their name; saving again goes through (F08.AC09).
     */
    public function test_item_form_edit_lock(): void {
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Olga', 'lastname' => 'Other']);
        global $DB;
        $openedat = (int) $this->item->timemodified;
        // Someone else saves after the form was opened.
        $DB->update_record('local_nextpool_item', (object) ['id' => $this->item->id, 'title' => 'Fire safety 2',
            'usermodified' => $other->id, 'timemodified' => $openedat + 60]);
        $saved = item_manager::get((int) $this->item->id);

        $form = $this->make_item_form($saved);
        $errors = $form->validation($this->item_form_data($saved, $openedat), []);
        $this->assertArrayHasKey('timemodified', $errors);
        $this->assertStringContainsString('Olga Other', $form->get_edit_conflict());

        $form = $this->make_item_form($saved);
        $errors = $form->validation($this->item_form_data($saved, (int) $saved->timemodified), []);
        $this->assertArrayNotHasKey('timemodified', $errors);
        $this->assertSame('', $form->get_edit_conflict());
    }

    /**
     * Item editors cannot widen the availability: the form offers only the current one (q68, TD-310).
     */
    public function test_item_form_locked_visibility(): void {
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $form = $this->make_item_form($this->item, true);
        $data = $this->item_form_data($this->item, (int) $this->item->timemodified);
        $this->assertArrayNotHasKey('contextid', $form->validation($data, []));
        $data['contextid'] = \context_coursecat::instance($category->id)->id;
        $this->assertArrayHasKey('contextid', $form->validation($data, []));
    }

    /**
     * Saving label and changelog after someone else changed them is refused; the form then carries the new state
     * (F08.AC09, t84-b, task103).
     */
    public function test_version_details_form_edit_lock(): void {
        $this->setAdminUser();
        $version = $this->create_draft_version($this->item);
        $opened = version_details_form::state($version);
        $version = version_manager::update_details((int) $version->id, 'Changed', '', FORMAT_HTML);
        $this->assertNotSame($opened, version_details_form::state($version));

        $form = new version_details_form(null, ['versionid' => $version->id, 'state' => version_details_form::state($version)]);
        $errors = $form->validation(['versionid' => $version->id, 'detailsstate' => $opened], []);
        $this->assertArrayHasKey('detailsstate', $errors);
        $this->assertNotSame('', $form->get_edit_conflict());

        $form = new version_details_form(null, ['versionid' => $version->id, 'state' => version_details_form::state($version)]);
        $this->assertSame([], $form->validation([
            'versionid' => $version->id,
            'detailsstate' => version_details_form::state($version),
        ], []));
    }

    /**
     * The account search needs the right to edit the item, or to create items without one (t84-f).
     */
    public function test_search_users(): void {
        $this->setAdminUser();
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $this->getDataGenerator()->create_user(['firstname' => 'Sam', 'lastname' => 'Searchable']);
        $this->setUser($this->editor);

        $result = search_users::execute('Searchable', (int) $this->item->id);
        $result = \core_external\external_api::clean_returnvalue(search_users::execute_returns(), $result);
        $this->assertCount(1, $result['users']);
        $this->assertStringContainsString('Sam Searchable', $result['users'][0]['label']);
        $this->assertSame([], search_users::execute('', (int) $this->item->id)['users']);

        $competencies = search_competencies::execute('x', (int) $this->item->id);
        $this->assertArrayHasKey('competencies', $competencies);

        foreach ([[(int) $this->other->id], [0]] as [$itemid]) {
            try {
                search_users::execute('Searchable', $itemid);
                $this->fail('Expected exception for item ' . $itemid);
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * "Open in storage location" for item editors and the editorial team only, where the storage location has a
     * web interface (F08.AC10, q69).
     */
    public function test_open_in_source_link(): void {
        global $PAGE;
        $PAGE->set_url('/local/nextpool/item.php');
        $output = $PAGE->get_renderer('core');
        $cloud = $this->generator->create_source('nextcloud', ['basefolder' => 'Nextpool', 'authmode' => 'apppassword']);
        $incloud = $this->generator->create_item(['title' => 'Cloud handout', 'sourceid' => $cloud->get_id()]);
        $this->setAdminUser();
        item_editor_manager::add((int) $incloud->id, (int) $this->editor->id);
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $reviewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::REVIEW, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $reviewer->id, \context_system::instance()->id);

        $haslink = static function (\stdClass $item) use ($output): bool {
            $data = (new \local_nextpool\output\item_page($item))->export_for_template($output);
            return str_contains($data['iconactions'], 'action=opensource');
        };
        $this->assertTrue($haslink($incloud), 'Editorial team.');
        $this->setUser($this->editor);
        $this->assertTrue($haslink($incloud), 'Item editor.');
        $this->assertFalse($haslink($this->item), 'The Moodle file storage has no web interface.');
        $this->setUser($reviewer);
        $this->assertFalse($haslink($incloud), 'Reviewers without the edit right do not get it.');
    }

    /**
     * The user menu links "My items" for item editors, also without editorial rights (adr25).
     */
    public function test_user_menu(): void {
        $this->setUser($this->editor);
        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $this->assertSame([], $hook->get_navitems());

        $this->setAdminUser();
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $this->setUser($this->editor);
        $hook = new \core_user\hook\extend_user_menu();
        hook_callbacks::extend_user_menu($hook);
        $urls = array_map(static fn($navitem) => $navitem->url->out_as_local_url(false), $hook->get_navitems());
        $this->assertSame(['/local/nextpool/myitems.php'], $urls);
    }

    /**
     * Privacy: being an item editor is exported and deleted, "added by" loses the person.
     */
    public function test_privacy(): void {
        $this->setAdminUser();
        $admin = get_admin();
        item_editor_manager::add((int) $this->item->id, (int) $this->editor->id);
        $context = access::get_context($this->item);

        $contexts = provider::get_contexts_for_userid((int) $this->editor->id)->get_contextids();
        $this->assertContainsEquals($context->id, $contexts);
        $userlist = new \core_privacy\local\request\userlist($context, 'local_nextpool');
        provider::get_users_in_context($userlist);
        $this->assertContainsEquals($this->editor->id, $userlist->get_userids());
        $this->assertContainsEquals($admin->id, $userlist->get_userids());

        $writer = \core_privacy\local\request\writer::with_context($context);
        provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            $this->editor,
            'local_nextpool',
            [$context->id]
        ));
        $data = $writer->get_data([get_string('pluginname', 'local_nextpool'), $this->item->uuid]);
        $this->assertSame(get_string('yes'), $data->itemeditor['youareitemeditor']);

        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $admin,
            'local_nextpool',
            [$context->id]
        ));
        $this->assertEquals(0, $GLOBALS['DB']->get_field(item_editor_manager::TABLE, 'addedby', ['userid' => $this->editor->id]));
        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $this->editor,
            'local_nextpool',
            [$context->id]
        ));
        $this->assertFalse(access::is_item_editor($this->item, (int) $this->editor->id));
    }

    /**
     * Import a draft version from a new file in the item's storage location.
     *
     * @param \stdClass $item Item.
     * @return \stdClass Version.
     */
    private function create_draft_version(\stdClass $item): \stdClass {
        $source = \local_nextpool\local\source\manager::get((int) $item->sourceid);
        $this->generator->put_file($source, 'handout.pdf', 'draft');
        return version_manager::create_from_source((int) $item->id, 'handout.pdf');
    }

    /**
     * Item form for an existing item, as edit.php builds it.
     *
     * @param \stdClass $item Item with the stored state.
     * @param bool $visibilitylocked Whether the visibility is locked (item editor).
     * @return item_form
     */
    private function make_item_form(\stdClass $item, bool $visibilitylocked = false): item_form {
        return new item_form(null, [
            'id' => $item->id,
            'locked' => false,
            'sourceid' => (int) $item->sourceid,
            'contextid' => (int) $item->contextid,
            'authorcount' => 1,
            'authorusers' => [],
            'competencycount' => 0,
            'timemodified' => (int) $item->timemodified,
            'usermodified' => (int) $item->usermodified,
            'visibilitylocked' => $visibilitylocked,
        ]);
    }

    /**
     * Submitted data of the item form.
     *
     * @param \stdClass $item Item.
     * @param int $timemodified State the form was opened with.
     * @return array
     */
    private function item_form_data(\stdClass $item, int $timemodified): array {
        return [
            'id' => $item->id,
            'timemodified' => $timemodified,
            'title' => 'Fire safety, revised',
            'contextid' => (int) $item->contextid,
            'sourceid' => (int) $item->sourceid,
            'author_name' => ['Jane Doe'],
            'author_role' => [''],
        ];
    }
}
