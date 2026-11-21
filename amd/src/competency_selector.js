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
 * Competency search of the item form (F14, bug52).
 *
 * Ajax handler of the core autocomplete: the choices come from local_nextpool_search_competencies
 * instead of every competency of the site in every row of the form.
 *
 * @module     local_nextpool/competency_selector
 * @copyright  2026 eLeDia GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getItemId} from 'local_nextpool/user_selector';

/**
 * Turn the result of the web service into autocomplete choices.
 *
 * @param {String} selector Selector of the original select element.
 * @param {Object} result Result of local_nextpool_search_competencies.
 * @returns {Array} Choices with value and label.
 */
export const processResults = (selector, result) =>
    result.competencies.map((competency) => ({value: competency.idnumber, label: competency.label}));

/**
 * Search the competencies.
 *
 * @param {String} selector Selector of the original select element.
 * @param {String} query Text the editor typed.
 * @param {Function} success Called with the result.
 * @param {Function} failure Called with the error.
 */
export const transport = (selector, query, success, failure) => {
    // Item editors may search only for the item they edit (task84); a new item has the id 0.
    const itemid = getItemId(selector);
    Ajax.call([{methodname: 'local_nextpool_search_competencies', args: {query: query, itemid: itemid}}])[0]
        .then(success)
        .catch(failure);
};
