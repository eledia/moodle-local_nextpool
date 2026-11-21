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

use local_nextpool\local\source\path;
use local_nextpool\local\source\source_exception;

/**
 * Tests for the path rules that confine backends to their base folder (bug01).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(path::class)]
final class path_test extends \basic_testcase {
    /**
     * Valid paths and their normalised form.
     *
     * @return array
     */
    public static function valid_provider(): array {
        return [
            'root' => ['', ''],
            'slash only' => ['/', ''],
            'plain' => ['Docs/handout.pdf', 'Docs/handout.pdf'],
            'leading, trailing and double slashes' => [' /Docs//sub/handout.pdf/ ', 'Docs/sub/handout.pdf'],
            'dots inside names' => ['a..b/.hidden/v1.2.pdf', 'a..b/.hidden/v1.2.pdf'],
            'unicode' => ['Übungen/Lösung ä.pdf', 'Übungen/Lösung ä.pdf'],
        ];
    }

    /**
     * Normalising valid paths.
     *
     * @param string $input Raw path.
     * @param string $expected Normalised path.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_provider')]
    public function test_normalise_valid(string $input, string $expected): void {
        $this->assertSame($expected, path::normalise($input));
    }

    /**
     * Paths that could leave the base folder.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'parent segment' => ['../secret.pdf'],
            'parent in the middle' => ['Docs/../../secret.pdf'],
            'current segment' => ['./Docs'],
            'backslash' => ['Docs\\..\\secret.pdf'],
            'null byte' => ["Docs/a\0.pdf"],
            'newline' => ["Docs/a\n.pdf"],
            'blank segment' => ['Docs/ /a.pdf'],
        ];
    }

    /**
     * Rejecting unsafe paths.
     *
     * @param string $input Raw path.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    public function test_normalise_rejects(string $input): void {
        $this->expectException(source_exception::class);
        path::normalise($input);
    }

    /**
     * Joining and splitting against a base folder.
     */
    public function test_join_and_relative_to(): void {
        $this->assertSame('a/b', path::join('a', 'b'));
        $this->assertSame('b', path::join('', 'b'));
        $this->assertSame('a', path::join('a', ''));

        $this->assertSame('b/c', path::relative_to('a', 'a/b/c'));
        $this->assertSame('', path::relative_to('a', 'a'));
        $this->assertSame('x', path::relative_to('', 'x'));
        $this->assertNull(path::relative_to('a', 'ab/c'));
        $this->assertNull(path::relative_to('a', 'b'));
    }
}
