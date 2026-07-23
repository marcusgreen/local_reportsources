// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * "Test" button — asks the test_query external for advisory feedback on the
 * current SQL (date columns, row count, indexes / full scans) and renders it.
 *
 * @module     local_reportsources/test
 * @copyright  2026 Marcus Green
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {get_string as getString} from 'core/str';
import Ajax from 'core/ajax';
import {exception as displayException} from 'core/notification';

/**
 * Wire the Test button to the analyser endpoint.
 *
 * @param {string} btnid - Test button element id.
 * @param {string} sqlid - SQL textarea element id.
 * @param {string} courseid - Course select element id (may be absent).
 * @param {string} resultid - Results container element id.
 */
export const init = (btnid, sqlid, courseid, resultid) => {
    const btn = document.getElementById(btnid);
    const sqlField = document.getElementById(sqlid);
    const results = document.getElementById(resultid);
    if (!btn || !sqlField || !results) {
        return;
    }

    btn.addEventListener('click', async() => {
        const sql = sqlField.value.trim();
        if (!sql) {
            return;
        }
        const courseField = document.getElementById(courseid);
        const course = courseField ? parseInt(courseField.value, 10) || 0 : 0;

        btn.disabled = true;
        results.textContent = await getString('testquery', 'local_reportsources');
        try {
            const data = await Ajax.call([{
                methodname: 'local_reportsources_test_query',
                args: {sql: sql, courseid: course},
            }])[0];
            await render(results, data);
        } catch (error) {
            displayException(error);
        } finally {
            btn.disabled = false;
        }
    });
};

/**
 * Render the feedback into the results container.
 *
 * @param {HTMLElement} container - Results element.
 * @param {object} data - Response from test_query.
 */
const render = async(container, data) => {
    container.innerHTML = '';

    if (!data.ok) {
        container.appendChild(alertBox('alert-danger', data.error));
        return;
    }

    if (data.rowcount >= 0) {
        const count = await getString('checkrowcount', 'local_reportsources', data.rowcount);
        container.appendChild(alertBox('alert-info', count));
    }

    appendList(container, data.suggestions, 'alert-warning');
    appendList(container, data.warnings, 'alert-warning');
    appendList(container, data.indexinfo, 'alert-secondary');

    if (!data.suggestions.length && !data.warnings.length) {
        const ok = await getString('checkallgood', 'local_reportsources');
        container.appendChild(alertBox('alert-success', ok));
    }
};

/**
 * Append one alert per line to the container.
 *
 * @param {HTMLElement} container
 * @param {string[]} lines
 * @param {string} cls - Bootstrap alert class.
 */
const appendList = (container, lines, cls) => {
    (lines || []).forEach((line) => container.appendChild(alertBox(cls, line)));
};

/**
 * Build a Bootstrap alert div with text content (no HTML injection).
 *
 * @param {string} cls - Bootstrap alert class.
 * @param {string} text
 * @return {HTMLElement}
 */
const alertBox = (cls, text) => {
    const div = document.createElement('div');
    div.className = 'alert ' + cls + ' mb-1 py-1';
    div.setAttribute('role', 'alert');
    div.textContent = text;
    return div;
};
