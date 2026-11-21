// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later: http://www.gnu.org/copyleft/gpl.html
// Copyright 2026 eLeDia GmbH.

/** Exercise the actual feedback module against overlapping submissions and failed requests (bug171). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../amd/src/feedback.js'), 'utf8')
    .replace(/^import .*;$/gm, '').replace('export const init', 'const init');
let listener;
let requests = 0;
let pending;
const button = {disabled: false};
const form = {
    dataset: {localNextpoolAction: 'add'},
    elements: {content: {value: 'One comment'}, versionid: {value: '0'}},
    querySelectorAll: () => [button],
};
const status = {textContent: ''};
const section = {
    dataset: {itemid: '1', courseid: '2', contextid: '3'},
    setAttribute() {},
    removeAttribute() {},
    addEventListener: (type, callback) => { listener = callback; },
    querySelector: (selector) => selector.includes('feedback-status') ? status : null,
};
const errors = [];
const runtime = {
    document: {getElementById: () => section},
    window: {setTimeout: (callback) => callback()},
    Ajax: {call: () => {
        requests++;
        return [new Promise((resolve, reject) => { pending = {resolve, reject}; })];
    }},
    Fragment: {loadFragment: () => Promise.resolve('')},
    Templates: {replaceNodeContents: () => Promise.resolve()},
    Notification: {exception: (error) => errors.push(error), deleteCancelPromise: () => Promise.reject(new Error('Cancel'))},
    getString: (key) => Promise.resolve(key),
};
vm.runInNewContext(source + '\ninit("feedback");', runtime);
const submit = () => listener({target: {closest: () => form}, preventDefault() {}});
const settle = () => new Promise((resolve) => setImmediate(resolve));
(async() => {
    submit();
    submit();
    assert.equal(requests, 1, 'Two synchronous submits must send one request.');
    assert.equal(button.disabled, true);
    pending.reject(new Error('Request failed'));
    await settle();
    assert.equal(errors.length, 1);
    assert.equal(button.disabled, false, 'A failed request must allow retrying.');
    submit();
    assert.equal(requests, 2);
    pending.resolve({message: 'Posted', commentid: 7});
    await settle();
    assert.equal(button.disabled, false);
    form.dataset.localNextpoolAction = 'delete';
    submit();
    await settle();
    assert.equal(requests, 2, 'Cancelling a confirmation must send nothing.');
    assert.equal(button.disabled, false, 'Cancelling must release the submit guard.');
    form.dataset.localNextpoolAction = 'add';
    submit();
    assert.equal(requests, 3, 'A later submission must still work.');
    pending.resolve({message: 'Posted'});
    await settle();
    console.log('Feedback duplicate-submit, failure retry and confirmation cancellation passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
