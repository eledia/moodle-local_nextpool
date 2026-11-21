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
 * Section "Feedback" of a catalogue item without page reloads (F08, task81, TD-305).
 *
 * The forms of the section post to feedback.php. This module sends them through the external functions
 * instead, reloads the section content (fragment "feedback") and announces the result in the live region.
 * Without JavaScript the forms keep working.
 *
 * @module     local_nextpool/feedback
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Fragment from 'core/fragment';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';

/** @type {Object<string,Function>} External function and arguments per form action. */
const CALLS = {
    add: (form, section) => ({
        methodname: 'local_nextpool_add_comment',
        args: {
            itemid: Number(section.dataset.itemid),
            courseid: Number(section.dataset.courseid),
            content: form.elements.content.value,
            versionid: Number(form.elements.versionid ? form.elements.versionid.value : 0),
            parentid: 0,
        },
    }),
    reply: (form, section) => ({
        methodname: 'local_nextpool_add_comment',
        args: {
            itemid: Number(section.dataset.itemid),
            courseid: Number(section.dataset.courseid),
            content: form.elements.content.value,
            versionid: 0,
            parentid: Number(form.elements.parentid.value),
        },
    }),
    resolve: (form, section) => ({
        methodname: 'local_nextpool_set_comment_resolved',
        args: {commentid: Number(form.elements.commentid.value), courseid: Number(section.dataset.courseid), resolved: true},
    }),
    reopen: (form, section) => ({
        methodname: 'local_nextpool_set_comment_resolved',
        args: {commentid: Number(form.elements.commentid.value), courseid: Number(section.dataset.courseid), resolved: false},
    }),
    delete: (form, section) => ({
        methodname: 'local_nextpool_delete_comment',
        args: {commentid: Number(form.elements.commentid.value), courseid: Number(section.dataset.courseid)},
    }),
    subscribe: (form, section) => ({
        methodname: 'local_nextpool_set_subscription',
        args: {itemid: Number(section.dataset.itemid), courseid: Number(section.dataset.courseid), subscribed: true},
    }),
    unsubscribe: (form, section) => ({
        methodname: 'local_nextpool_set_subscription',
        args: {itemid: Number(section.dataset.itemid), courseid: Number(section.dataset.courseid), subscribed: false},
    }),
};

/**
 * Reload the content of the section and move the focus to a sensible place.
 *
 * @param {HTMLElement} section The section.
 * @param {String} focus Selector of the element to focus after reloading.
 * @returns {Promise}
 */
const reload = (section, focus) => {
    const content = section.querySelector('[data-region="local-nextpool-feedback-content"]');
    const params = {
        itemid: section.dataset.itemid,
        courseid: section.dataset.courseid,
        filter: section.dataset.filter,
    };
    // The fragment promise resolves with two values (HTML and JavaScript), so it is not awaited.
    return Fragment.loadFragment('local_nextpool', 'feedback', Number(section.dataset.contextid), params)
        .then((html, js) => Templates.replaceNodeContents(content, html, js))
        .then(() => {
            let target = section.querySelector(focus) || section.querySelector('#local-nextpool-feedback-heading');
            if (!target) {
                return;
            }
            // A done thread stays collapsed; the focus goes to its summary.
            const details = target.closest('details');
            if (details && !details.open) {
                target = details.querySelector('summary');
            }
            if (!target.matches('a, button, input, textarea, select, summary, [tabindex]')) {
                target.setAttribute('tabindex', '-1');
            }
            target.focus();
            return;
        });
};

/**
 * Send one form of the section.
 *
 * @param {HTMLElement} section The section.
 * @param {HTMLFormElement} form The submitted form.
 * @param {String} action Action of the form.
 */
const send = async(section, form, action) => {
    if (section.dataset.sending) {
        return;
    }
    section.dataset.sending = '1';
    section.setAttribute('aria-busy', 'true');
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'))
        .map((button) => ({button, disabled: button.disabled}));
    buttons.forEach(({button}) => {
        button.disabled = true;
    });
    try {
        if (action === 'delete') {
            try {
                await Notification.deleteCancelPromise(
                    getString('commentdelete', 'local_nextpool'),
                    getString('commentdeleteconfirm', 'local_nextpool'),
                    getString('delete', 'core')
                );
            } catch (e) {
                // Cancelled: nothing to do.
                return;
            }
        }
        const call = CALLS[action](form, section);
        const status = section.querySelector('[data-region="local-nextpool-feedback-status"]');
        try {
            const [result] = await Promise.all(Ajax.call([call]));
            let focus = '#local-nextpool-feedback-heading';
            if (result.commentid) {
                focus = '#local-nextpool-comment-' + result.commentid;
            } else if (action === 'subscribe' || action === 'unsubscribe') {
                focus = '[data-local-nextpool-action="subscribe"] button, [data-local-nextpool-action="unsubscribe"] button';
            } else if (action === 'add') {
                focus = '#local-nextpool-feedback-text';
            }
            await reload(section, focus);
            // Clear first, so the same message is announced again.
            status.textContent = '';
            window.setTimeout(() => {
                status.textContent = result.message;
            }, 100);
        } catch (error) {
            Notification.exception(error);
        }
    } finally {
        delete section.dataset.sending;
        section.removeAttribute('aria-busy');
        buttons.forEach(({button, disabled}) => {
            button.disabled = disabled;
        });
    }
};

/**
 * Make the forms of the feedback section work without page reloads.
 *
 * @param {String} id Element id of the section.
 */
export const init = (id) => {
    const section = document.getElementById(id);
    if (!section || section.dataset.initialised) {
        return;
    }
    section.dataset.initialised = '1';
    section.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-local-nextpool-action]');
        const action = form ? form.dataset.localNextpoolAction : '';
        if (!CALLS[action]) {
            // The version filter and unknown forms keep their normal submit.
            return;
        }
        e.preventDefault();
        if (form.elements.content && form.elements.content.value.trim() === '') {
            form.elements.content.focus();
            return;
        }
        send(section, form, action);
    });
};
