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

use local_nextpool\local\content_type\base;
use local_nextpool\local\content_type\h5p;
use local_nextpool\local\content_type\scorm;
use local_nextpool\local\quiz_package;

/**
 * Metadata cannot exhaust memory, while media remains streamable (bug131).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(h5p::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scorm::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(quiz_package::class)]
final class archive_metadata_test extends \advanced_testcase {
    /**
     * Write an archive for the real parser.
     *
     * @param array $files Entries.
     * @return string
     */
    private function archive(array $files): string {
        $path = make_request_directory() . '/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    /**
     * Parsers reject oversized metadata before materialising it.
     *
     * @param string $type Parser.
     * @param string $name Entry name.
     * @param int $size Entry size.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('metadata_provider')]
    public function test_oversized_metadata_is_rejected(string $type, string $name, int $size): void {
        $this->resetAfterTest();
        $path = $this->archive([$name => str_repeat('x', $size)]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_archiveentrytoolarge', 'local_nextpool', display_size($size - 1)));
        if ($type === 'quiz') {
            quiz_package::read($path);
        } else {
            $handler = $type === 'h5p' ? new h5p() : new scorm();
            $handler->validate_package($path);
        }
    }

    /**
     * Parser-specific metadata budgets.
     *
     * @return array
     */
    public static function metadata_provider(): array {
        return [
            'h5p json' => ['h5p', 'h5p.json', 1048577],
            'scorm manifest' => ['scorm', 'imsmanifest.xml', 1048577],
            'quiz xml' => ['quiz', 'questions.xml', 10485761],
        ];
    }

    /**
     * Repacking does not impose the metadata limit on legitimate media files.
     */
    public function test_content_only_h5p_streams_large_media(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $content = str_repeat('m', 12 * 1024 * 1024);
        $path = $this->archive([
            'h5p.json' => '{"mainLibrary":"H5P.Test"}', 'content/content.json' => '{}',
            'content/media.mp4' => $content, 'H5P.Test-1.0/library.json' => '{}',
        ]);
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_system::instance()->id, 'component' => 'local_nextpool', 'filearea' => 'test',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'media.h5p',
        ], $path);
        $result = h5p::create_content_only_package($file);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result) === true);
        $this->assertFalse($zip->locateName('H5P.Test-1.0/library.json'));
        $this->assertSame(strlen($content), $zip->statName('content/media.mp4')['size']);
        $stream = $zip->getStream('content/media.mp4');
        $this->assertSame(sha1($content), (function () use ($stream) {
            $hash = hash_init('sha1');
            hash_update_stream($hash, $stream);
            return hash_final($hash);
        })());
        fclose($stream);
        $zip->close();
    }
}
