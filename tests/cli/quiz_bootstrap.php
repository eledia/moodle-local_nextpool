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

/**
 * Regression for reading quiz packages under a normal Moodle bootstrap (bug239).
 *
 * Run with PHP CLI in an installed Moodle tree; deliberately does not load PHPUnit.
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$path = make_request_directory() . '/quiz.zip';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Cannot create quiz fixture archive.');
}
foreach (['questions.xml', 'quiz.json'] as $name) {
    $zip->addFile(__DIR__ . '/../fixtures/quiz/' . $name, $name);
}
$zip->close();
$package = \local_nextpool\local\quiz_package::read($path);
if (count($package['slots']) !== 3 || array_keys($package['questions']) !== ['tf1', 'sa1', 'mc1']) {
    throw new RuntimeException('Quiz fixture did not produce its three questions and slots.');
}
echo "Quiz package parsed with normal Moodle bootstrap: 3 questions, 3 slots.\n";
