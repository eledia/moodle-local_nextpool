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
 * Account search of the person fields of the catalogue: item editors and authors (F08, F09, task84).
 *
 * Ajax handler of the core autocomplete. The choices come from local_nextpool_search_users, which checks the
 * right to edit the item of the form (hidden field "id", 0 for a new item) instead of a site-wide capability.
 *
 * @module     local_nextpool/user_selector
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Item id of the form the field belongs to.
 *
 * @param {String} selector Selector of the original select element.
 * @returns {Number} Item id, 0 for a new item.
 */
export const getItemId = (selector) => {
    const select = document.querySelector(selector);
    const idfield = select && select.form ? select.form.querySelector('input[name="id"]') : null;
    return idfield ? parseInt(idfield.value, 10) || 0 : 0;
};

/**
 * Turn the result of the web service into autocomplete choices.
 *
 * @param {String} selector Selector of the original select element.
 * @param {Object} result Result of local_nextpool_search_users.
 * @returns {Array} Choices with value and label.
 */
export const processResults = (selector, result) =>
    result.users.map((user) => ({value: user.id, label: user.label}));

/**
 * Search the accounts.
 *
 * @param {String} selector Selector of the original select element.
 * @param {String} query Text the person typed.
 * @param {Function} success Called with the result.
 * @param {Function} failure Called with the error.
 */
export const transport = (selector, query, success, failure) => {
    Ajax.call([{methodname: 'local_nextpool_search_users', args: {query: query, itemid: getItemId(selector)}}])[0]
        .then(success)
        .catch(failure);
};
