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
 * Relay Escape and pause HTML5 media throughout nested same-origin Core H5P frames (F16).
 *
 * @module     local_nextpool/h5p_preview_bridge
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
let initialised = false;

/** Observe each document once, including Core's dynamically created inner iframe documents. */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    const documents = new WeakSet();
    const frames = new WeakSet();
    const visit = (doc, pause = false) => {
        if (!doc) {
            return;
        }
        if (!documents.has(doc)) {
            documents.add(doc);
            doc.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && window.parent !== window) {
                    window.setTimeout(() => {
                        if (!event.defaultPrevented) {
                            window.parent.postMessage({source: 'local_nextpool_preview', type: 'escape'}, window.location.origin);
                        }
                    }, 0);
                }
            }, true);
            new MutationObserver(() => visit(doc)).observe(doc, {childList: true, subtree: true});
        }
        if (pause) {
            doc.querySelectorAll('video,audio').forEach((media) => media.pause());
        }
        doc.querySelectorAll('iframe').forEach((frame) => {
            if (!frames.has(frame)) {
                frames.add(frame);
                frame.addEventListener('load', () => visit(doc));
            }
            try {
                visit(frame.contentDocument, pause);
            } catch (error) {
                // An external player cannot be inspected; its own pause API is required.
            }
        });
    };
    window.addEventListener('message', (event) => {
        if (event.source === window.parent && event.origin === window.location.origin && event.data?.type === 'pause') {
            visit(document, true);
        }
    });
    visit(document);
};
