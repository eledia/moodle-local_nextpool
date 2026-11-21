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

use local_nextpool\local\notification_sender;
use local_nextpool\local\review_manager;

/**
 * Recipient language and plain-text notification titles (bug169).
 *
 * @package local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notification_sender::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review_manager::class)]
final class notification_sender_test extends \advanced_testcase {
    /**
     * Two recipients receive different languages, with a literal ampersand, without changing the caller language.
     */
    public function test_recipient_language_and_plain_title(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // A minimal offline language pack uses the actual shipped German plugin translations.
        $oldroots = [$CFG->langotherroot, $CFG->langlocalroot];
        $langroot = make_temp_directory('nextpool_notification_language');
        mkdir($langroot . '/de');
        file_put_contents($langroot . '/de/langconfig.php', '<?php $string["thislanguage"] = "Deutsch";'
            . '$string["parentlanguage"] = "en";');
        copy(__DIR__ . '/../lang/de/local_nextpool.php', $langroot . '/de/local_nextpool.php');
        $CFG->langotherroot = $langroot;
        $CFG->langlocalroot = $langroot;
        get_string_manager(true);
        get_string_manager()->reset_caches();
        $previous = force_current_language('en');
        try {
            $english = $this->getDataGenerator()->create_user(['lang' => 'en']);
            $german = $this->getDataGenerator()->create_user(['lang' => 'de']);
            $context = \context_system::instance();
            $sink = $this->redirectMessages();
            foreach ([$english, $german] as $recipient) {
                review_manager::send_message(
                    'versionpublished',
                    (int) $recipient->id,
                    'versionpublished_subject',
                    'versionpublished_body',
                    'https://example.com/item',
                    SITEID,
                    static fn() => (object) [
                        'item' => format_string('Safety & health', true, ['context' => $context, 'escape' => false]),
                        'version' => '2', 'url' => 'https://example.com/item',
                    ]
                );
                $this->assertSame('en', current_language());
            }
            $messages = $sink->get_messages();
            $this->assertCount(2, $messages);
            $this->assertNotSame($messages[0]->subject, $messages[1]->subject);
            $languages = [(int) $english->id => 'en', (int) $german->id => 'de'];
            foreach ($messages as $message) {
                $this->assertArrayHasKey((int) $message->useridto, $languages);
                $lang = $languages[(int) $message->useridto];
                $expected = get_string_manager()->get_string(
                    'versionpublished_subject',
                    'local_nextpool',
                    (object) ['item' => 'Safety & health', 'version' => '2'],
                    $lang
                );
                $this->assertSame($expected, $message->subject);
                $this->assertStringNotContainsString('&amp;', $message->subject);
                $this->assertStringContainsString('Safety & health', $message->subject);
            }
        } finally {
            force_current_language($previous);
            [$CFG->langotherroot, $CFG->langlocalroot] = $oldroots;
            get_string_manager(true);
        }
    }

    /**
     * A failed builder must not leave later messages in the failed recipient's language.
     */
    public function test_language_is_restored_after_exception(): void {
        global $SESSION;
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $previous = force_current_language('en');
        try {
            try {
                notification_sender::send((int) $user->id, static function () {
                    force_current_language('');
                    throw new \RuntimeException('Builder failed');
                });
                $this->fail('Expected the builder exception');
            } catch (\RuntimeException $e) {
                $this->assertSame('Builder failed', $e->getMessage());
                $this->assertSame('en', $SESSION->forcelang);
                $this->assertSame('en', current_language());
            }
        } finally {
            force_current_language($previous);
        }
    }
}
