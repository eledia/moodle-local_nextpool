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
 * Item dialog of the catalogue browser (task173): a modal titled with the item, with one panel per area.
 *
 * Links marked with data-local-nextpool-itemdialog="<area>" open the dialog in that area instead of following the
 * link; the href stays the fallback without JavaScript, for a new tab and for the context menu. Each area has its
 * own fragment, loaded when the area is first shown, and if needed a setup function. Today there is one area,
 * "feedback" (latest comments, link to all, form local_nextpool\form\item_dialog_feedback_form). A second area such
 * as "preview" (task176) is one more entry in AREAS plus its fragment; the template shows tabs as soon as there is
 * more than one area. Badges are updated by item id, whichever link opened the dialog.
 *
 * The core modal moves the focus into the dialog, keeps it there, closes on Escape and returns the focus to the
 * link that opened it.
 *
 * @module     local_nextpool/item_dialog
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';
import Fragment from 'core/fragment';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {add as addToast} from 'core/toast';
import {getString} from 'core/str';

/** @type {string} Attribute that marks a link opening the dialog; its value is the start area. */
const TRIGGER = 'data-local-nextpool-itemdialog';

/**
 * Areas of the dialog in display order.
 *
 * @type {Array<{key: string, label: Function, fragment: string, setup: Function|null}>}
 */
const AREAS = [
    {
        key: 'preview',
        label: () => getString('preview', 'local_nextpool'),
        fragment: 'itemdialog_preview',
        setup: (panel, trigger, modal) => setupPreview(panel, trigger, modal),
    },
    {
        key: 'feedback',
        label: () => getString('feedback', 'local_nextpool'),
        fragment: 'itemdialog_feedback',
        setup: (panel, trigger, modal) => setupFeedback(panel, trigger, modal),
    },
];

/**
 * Plain text as HTML, for the modal title.
 *
 * @param {string} text Plain text.
 * @returns {string}
 */
const escapeHtml = (text) => {
    const node = document.createElement('span');
    node.textContent = text;
    return node.innerHTML;
};

/**
 * Update the badges with the number of open comments and the accessible names of the feedback links of an item.
 *
 * Found by item id, so it works whichever link opened the dialog (e.g. the title for the area "preview", task176).
 *
 * @param {number} itemid Item id.
 * @param {number} count Open comments.
 */
const updateFeedbackCount = async(itemid, count) => {
    const links = document.querySelectorAll(`[${TRIGGER}="feedback"][data-itemid="${itemid}"]`);
    for (const link of links) {
        const badge = link.querySelector('[data-region="local-nextpool-feedbackcount"]');
        const name = link.querySelector('[data-region="local-nextpool-feedbackname"]');
        const title = link.dataset.title;
        if (name) {
            name.textContent = count > 0
                ? await getString('feedbackforopen', 'local_nextpool', {title, count})
                : await getString('feedbackfor', 'local_nextpool', title);
        }
        if (badge) {
            badge.textContent = String(count);
            badge.hidden = count <= 0;
        }
    }
};

/**
 * Area "feedback": load the form for a new comment; after posting, close, update the badge and show a toast.
 *
 * @param {HTMLElement} panel Panel of the area.
 * @param {HTMLElement} trigger Link that opened the dialog.
 * @param {Modal} modal The dialog.
 */
const setupFeedback = async(panel, trigger, modal) => {
    const container = panel.querySelector('[data-region="local-nextpool-itemdialog-form"]');
    if (!container) {
        return;
    }
    const form = new DynamicForm(container, 'local_nextpool\\form\\item_dialog_feedback_form');
    form.addEventListener(form.events.FORM_SUBMITTED, async(e) => {
        e.preventDefault();
        await updateFeedbackCount(Number(e.detail.itemid), Number(e.detail.opencount));
        // Keep the preview and any progress in its iframe while refreshing the feedback area.
        await loadArea(AREAS.find((area) => area.key === 'feedback'), panel, trigger, modal);
        panel.querySelector('textarea,button')?.focus();
        addToast(e.detail.message);
    });
    await form.load({itemid: Number(trigger.dataset.itemid), courseid: Number(trigger.dataset.courseid),
        startarea: trigger.dataset.startarea || 'feedback'});
};

/**
 * Fill the panel of an area from its fragment.
 *
 * @param {Object} area Entry of AREAS.
 * @param {HTMLElement} panel Panel of the area.
 * @param {HTMLElement} trigger Link that opened the dialog.
 * @param {Modal} modal The dialog.
 * @param {Object} extra Chapter navigation parameters.
 */
const loadArea = async(area, panel, trigger, modal, extra = {}) => {
    const {html, js} = await Fragment.loadFragment('local_nextpool', area.fragment, Number(trigger.dataset.contextid), {
        ...catalogueFilters(trigger),
        itemid: Number(trigger.dataset.itemid),
        courseid: Number(trigger.dataset.courseid),
        ...extra,
    }).then((html, js) => ({html, js}));
    await Templates.replaceNodeContents(panel, html, js);
    panel.removeAttribute('aria-busy');
    if (area.setup) {
        await area.setup(panel, trigger, modal);
    }
};

/**
 * Only catalogue filters are carried through fragment requests.
 *
 * @param {HTMLElement} trigger Catalogue link.
 * @returns {Object}
 */
const catalogueFilters = (trigger) => {
    const url = new URL(trigger.href, window.location.href);
    const params = {};
    for (const name of ['search', 'category', 'type', 'tag', 'page', 'section']) {
        if (url.searchParams.has(name)) {
            params[name] = url.searchParams.get(name);
        }
    }
    if (trigger.dataset.section !== undefined && trigger.dataset.section !== '') {
        params.section = trigger.dataset.section;
    }
    return params;
};

/**
 * Pause media in an area without replacing the iframe or discarding its in-memory state.
 *
 * @param {HTMLElement} panel Preview area.
 */
const pausePreview = (panel) => {
    panel.querySelectorAll('video,audio').forEach((media) => media.pause());
    panel.querySelectorAll('iframe').forEach((frame) => {
        frame.contentWindow?.postMessage({type: 'pause'}, '*');
        try {
            frame.contentDocument?.querySelectorAll('video,audio').forEach((media) => media.pause());
        } catch (error) {
            // Sandboxed packages have an opaque origin and receive the message above instead.
        }
    });
};

/**
 * Fullscreen and chapter navigation within the preview area.
 *
 * @param {HTMLElement} panel Preview area.
 * @param {HTMLElement|null} trigger Catalogue link, null on the fallback page.
 * @param {Modal|null} modal Dialog, null on the fallback page.
 */
const setupPreview = (panel, trigger, modal) => {
    if (panel.dataset.previewSetup) {
        return;
    }
    panel.dataset.previewSetup = '1';
    panel.addEventListener('click', (event) => {
        const fullscreen = event.target.closest('[data-action="preview-fullscreen"]');
        if (fullscreen) {
            const target = panel.querySelector('iframe,video,img');
            target?.requestFullscreen?.().catch(Notification.exception);
        }
        const chapter = event.target.closest('[data-preview-chapter]');
        if (chapter && trigger && modal) {
            event.preventDefault();
            loadArea(AREAS[0], panel, trigger, modal,
                {chapter: Number(chapter.dataset.previewChapter), chapterchange: 1}).catch((error) => showError(panel, error));
        }
    });
};

/** Bind fullscreen on the standalone preview page. */
export const initPreviewPage = () => {
    const panel = document.querySelector('[data-region="local-nextpool-preview"]');
    if (panel) {
        setupPreview(panel, null, null);
    }
};

/**
 * Show why an area cannot be loaded (e.g. no permission) inside its panel, without the stack trace.
 *
 * @param {HTMLElement} panel Panel of the area.
 * @param {Object} error Error of the fragment call.
 */
const showError = async(panel, error) => {
    try {
        const message = error && error.message ? error.message : await getString('error', 'core');
        const {html} = await Templates.renderForPromise('core/notification_error', {
            message: escapeHtml(message),
            closebutton: false,
        });
        panel.removeAttribute('aria-busy');
        panel.innerHTML = html;
    } catch (fatal) {
        Notification.exception(fatal);
    }
};

/**
 * Open the dialog for the item of a link.
 *
 * @param {HTMLElement} trigger Link with the data attributes of the item.
 * @param {string} start Key of the area to show first.
 */
export const open = async(trigger, start) => {
    trigger.dataset.startarea = start;
    const startkey = AREAS.some((area) => area.key === start) ? start : AREAS[0].key;
    const labels = await Promise.all(AREAS.map((area) => area.label()));
    const {html} = await Templates.renderForPromise('local_nextpool/item_dialog', {
        hastabs: AREAS.length > 1,
        areas: AREAS.map((area, index) => ({key: area.key, label: labels[index], active: area.key === startkey})),
    });
    const modal = await Modal.create({
        title: escapeHtml(trigger.dataset.title),
        body: html,
        large: true,
        removeOnClose: true,
        returnElement: trigger,
        show: true,
    });
    // Full screen on narrow screens (Bootstrap 5); the area "preview" will need the room (task176).
    modal.getModal()[0].classList.add('modal-fullscreen-md-down', 'local-nextpool-itemdialog--wide');
    const root = modal.getRoot()[0];
    const panelOf = (key) => root.querySelector(`[data-region="local-nextpool-itemdialog-area"][data-area="${key}"]`);
    const header = root.querySelector('[data-region="local-nextpool-itemdialog-header"]');
    loadArea({fragment: 'itemdialog_header'}, header, trigger, modal).catch((error) => showError(header, error));
    const messages = new AbortController();
    window.addEventListener('message', (event) => {
        const ownFrame = Array.from(root.querySelectorAll('iframe')).some((frame) => frame.contentWindow === event.source);
        if (ownFrame && event.data?.source === 'local_nextpool_preview' && event.data?.type === 'escape') {
            modal.hide();
        }
    }, {signal: messages.signal});
    // PDF/H5P are on the Moodle origin; forward an unhandled Escape from their documents as well.
    root.addEventListener('load', (event) => {
        if (event.target.tagName !== 'IFRAME') {
            return;
        }
        try {
            event.target.contentDocument?.addEventListener('keydown', (key) => {
                if (key.key === 'Escape' && !key.defaultPrevented) {
                    modal.hide();
                }
            });
        } catch (error) {
            // Sandboxed frames use the validated postMessage handler instead.
        }
    }, true);
    modal.getRoot().on(ModalEvents.hidden, () => {
        pausePreview(root);
        messages.abort();
    });
    root.addEventListener('hide.bs.tab', (event) => {
        const target = event.target.getAttribute('data-bs-target');
        if (target?.endsWith('-preview')) {
            pausePreview(panelOf('preview'));
        }
    });
    // An area loads when it is first shown; switching areas keeps the panels, so unsent input stays.
    const loaded = new Set();
    const show = (key) => {
        const area = AREAS.find((entry) => entry.key === key);
        const panel = panelOf(key);
        if (!area || !panel || loaded.has(key)) {
            return;
        }
        loaded.add(key);
        loadArea(area, panel, trigger, modal).catch((error) => showError(panel, error));
    };
    root.addEventListener('shown.bs.tab', (e) => {
        const target = e.target.getAttribute('data-bs-target') || '';
        const panel = target ? root.querySelector(target) : null;
        if (panel) {
            show(panel.dataset.area);
        }
    });
    show(startkey);
};

/**
 * Open the dialog from the marked links inside a region.
 *
 * @param {string} selector Region with the links, e.g. the list of catalogue cards.
 */
export const init = (selector) => {
    const region = document.querySelector(selector);
    if (!region || region.dataset.localNextpoolItemdialogInit) {
        return;
    }
    region.dataset.localNextpoolItemdialogInit = '1';
    region.addEventListener('click', (e) => {
        const trigger = e.target.closest(`[${TRIGGER}]`);
        // Modifier keys open the link itself (new tab or window), as the browser would.
        if (!trigger || !region.contains(trigger) || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
            return;
        }
        e.preventDefault();
        open(trigger, trigger.getAttribute(TRIGGER)).catch(Notification.exception);
    });
};
