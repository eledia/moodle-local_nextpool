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
 * AJAX enhancement of Core placement-policy menus, preserving their server-side links (task175).
 *
 * @module     local_nextpool/placement_policy
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';
import Templates from 'core/templates';

let initialised = false;

/** Attach once to the placements page. */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;
    document.addEventListener('click', async(event) => {
        const link = event.target.closest('[data-policy]');
        const control = link?.closest('[data-region="policy-control"]');
        if (!control || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        if (control.dataset.busy) {
            return;
        }
        control.dataset.busy = '1';
        control.setAttribute('aria-busy', 'true');
        try {
            const result = await Ajax.call([{
                methodname: 'local_nextpool_set_placement_policy',
                args: {placementid: Number(control.dataset.placementid), policy: link.dataset.policy},
            }])[0];
            const selected = control.querySelector(`[data-policy="${result.policy}"]`);
            const icon = selected?.querySelector('.icon');
            if (icon) {
                control.querySelector('[data-region="policy-current-icon"]').replaceChildren(icon.cloneNode(true));
            }
            control.querySelector('[data-region="policy-trigger"]').setAttribute('aria-label', result.name);
            control.querySelector('[data-region="policy-current-label"]').textContent = result.name;
            control.querySelectorAll('[data-policy]').forEach((option) => {
                const current = option.dataset.policy === result.policy;
                option.setAttribute('aria-current', String(current));
                option.classList.toggle('active', current);
            });
            const status = control.closest('tr')?.querySelector('[data-region="placement-status"]');
            if (status) {
                Templates.replaceNodeContents(status, result.statushtml, '');
            }
            addToast(result.message);
        } catch (error) {
            Notification.exception(error);
        } finally {
            delete control.dataset.busy;
            control.removeAttribute('aria-busy');
        }
    });
};
