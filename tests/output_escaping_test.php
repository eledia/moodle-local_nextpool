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

use local_nextpool\form\item_form;
use local_nextpool\form\proposal_form;
use local_nextpool\form\review_assign_form;
use local_nextpool\local\comment_manager;
use local_nextpool\local\proposal_manager;

/**
 * Names and texts from people and storage locations are escaped where core prints them as HTML (task140).
 *
 * Every test uses markup as payload and checks both that it does not reach the page as markup and that "&", quotes
 * and umlauts, which no cleaning removes, show once escaped, not twice.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review_assign_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(item_form::class)]
final class output_escaping_test extends \advanced_testcase {
    /** @var string Markup that runs script if it is printed unescaped. */
    private const PAYLOAD = '<img src=x onerror=alert(1)>';
    /** @var string The payload as the page must contain it. */
    private const ESCAPED = '&lt;img src=x onerror=alert(1)&gt;';
    /** @var string Full name of the marked user as the page must contain it. */
    private const NAME_ESCAPED = 'Jörg ' . self::ESCAPED . ' &amp; &quot;Co&quot; O&#039;Neil';

    #[\Override]
    protected function setUp(): void {
        global $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/local/nextpool/index.php');
        $PAGE->set_context(\context_system::instance());
    }

    /**
     * A user whose name carries the payload, an ampersand, quotes and an umlaut.
     *
     * Moodle strips tags from names it saves itself (PARAM_NOTAGS in user_create_user()), but names also arrive
     * through channels that skip this, such as authentication plugins or direct database syncs. The payload is
     * therefore written to the database afterwards; "&" and quotes survive any cleaning and are set normally.
     *
     * @return \stdClass The user record as stored.
     */
    private function create_marked_user(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Jörg', 'lastname' => '& "Co" O\'Neil']);
        $DB->set_field('user', 'firstname', 'Jörg ' . self::PAYLOAD, ['id' => $user->id]);
        $user = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $this->assertSame('Jörg ' . self::PAYLOAD . ' & "Co" O\'Neil', fullname($user), 'The fixture keeps the payload.');
        return $user;
    }

    /**
     * Assert that HTML shows the payload and the special characters escaped exactly once.
     *
     * @param string $expected Escaped text that must occur.
     * @param string $html Rendered HTML.
     */
    private function assert_escaped_once(string $expected, string $html): void {
        $this->assertStringNotContainsString(self::PAYLOAD, $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringContainsString($expected, $html);
    }

    /**
     * Comments the proposal form offers to take up show the commenter's text and name as text (bug120).
     */
    public function test_proposal_form_comment_options(): void {
        $item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_published_item();
        $user = $this->create_marked_user();
        // A comment typed as markup is stored with entities; html_to_text() turns them back into markup.
        $content = s(self::PAYLOAD) . ' &quot;Müller&quot; &amp; Söhne';
        comment_manager::add((int) $item->id, $content, FORMAT_HTML, null, null, (int) $user->id);
        $this->assertSame(self::PAYLOAD . ' "Müller" & Söhne', html_to_text($content, 0, false));

        $comments = proposal_manager::get_open_comments((int) $item->id);
        $this->assertCount(1, $comments);
        $this->assertSame(
            'Jörg ' . self::PAYLOAD . ' & "Co" O\'Neil: ' . self::PAYLOAD . ' "Müller" & Söhne',
            reset($comments),
            'The label is plain text.'
        );

        $form = new proposal_form(null, [
            'mode' => proposal_form::MODE_VERSION,
            'type' => (string) $item->type,
            'itemid' => (int) $item->id,
            'comments' => $comments,
            'params' => ['itemid' => (int) $item->id],
            'cm' => null,
        ]);
        $label = self::NAME_ESCAPED . ': ' . self::ESCAPED . ' &quot;Müller&quot; &amp; Söhne';
        $this->assert_escaped_once($label, $form->render());
    }

    /**
     * The reviewer menu shows full names as text (bug122).
     */
    public function test_review_assign_form_names(): void {
        $user = $this->create_marked_user();
        $form = new review_assign_form(null, ['versionid' => 1, 'reviewers' => [(int) $user->id => fullname($user)]]);
        $this->assert_escaped_once(self::NAME_ESCAPED, $form->render());
    }

    /**
     * Authors already chosen in the item form show their full names as text (bug122).
     */
    public function test_item_form_author_names(): void {
        $user = $this->create_marked_user();
        $form = new item_form(null, [
            'id' => 0,
            'locked' => false,
            'sourceid' => 0,
            'authorcount' => 1,
            'authorusers' => [(int) $user->id => fullname($user)],
        ]);
        $form->set_data(['author_userid' => [(int) $user->id]]);
        $this->assert_escaped_once(self::NAME_ESCAPED, $form->render());
    }

    /**
     * The edit lock names the person who saved meanwhile as text, since the page shows it as HTML (bug122).
     */
    public function test_item_form_edit_conflict_name(): void {
        $item = $this->getDataGenerator()->get_plugin_generator('local_nextpool')->create_item();
        $openedat = (int) $item->timemodified;
        // Someone else saved after the form was opened.
        $other = $this->create_marked_user();

        $form = new item_form(null, [
            'id' => $item->id,
            'locked' => false,
            'sourceid' => (int) $item->sourceid,
            'contextid' => (int) $item->contextid,
            'authorcount' => 1,
            'authorusers' => [],
            'timemodified' => $openedat + 60,
            'usermodified' => (int) $other->id,
        ]);
        $form->validation([
            'id' => $item->id,
            'timemodified' => $openedat,
            'title' => 'Fire safety',
            'contextid' => (int) $item->contextid,
            'sourceid' => (int) $item->sourceid,
            'author_name' => ['Jane Doe'],
            'author_role' => [''],
        ], []);
        $this->assert_escaped_once(self::NAME_ESCAPED, $form->get_edit_conflict());
    }
}
