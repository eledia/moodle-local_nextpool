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

namespace local_nextpool\local;

/**
 * Content-type-independent preview representation (F16).
 *
 * @package   local_nextpool
 * @copyright 2026 eLeDia GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_result {
    /** @var string Frame, html, media, link, chapters, info or unavailable. */
    public string $kind;
    /** @var string Content URL. */
    public string $url;
    /** @var string Purified content markup. */
    public string $html;
    /** @var string MIME type, for media. */
    public string $mimetype;
    /** @var string Localised explanation, plain text. */
    public string $message;
    /** @var array Chapter links with title and html. */
    public array $chapters;

    /**
     * Constructor.
     *
     * @param string $kind Representation kind.
     * @param string $url Content URL.
     * @param string $html Purified HTML.
     * @param string $mimetype Media MIME type.
     * @param string $message Localised explanation.
     * @param array $chapters Chapters.
     */
    public function __construct(
        string $kind = 'unavailable',
        string $url = '',
        string $html = '',
        string $mimetype = '',
        string $message = '',
        array $chapters = []
    ) {
        $this->kind = $kind;
        $this->url = $url;
        $this->html = $html;
        $this->mimetype = $mimetype;
        $this->message = $message;
        $this->chapters = $chapters;
    }
}
