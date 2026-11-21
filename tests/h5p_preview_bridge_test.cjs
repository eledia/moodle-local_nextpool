// Copyright 2026 eLeDia GmbH. GNU GPL v3 or later.
// Exercise the shipped bridge against nested/dynamically replaced documents without browser or npm dependencies.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Document {
    constructor() {
        this.listeners = {};
        this.frames = [];
        this.media = [{pauses: 0, pause() { this.pauses++; }}];
    }
    addEventListener(type, callback) {
        (this.listeners[type] ??= []).push(callback);
    }
    querySelectorAll(selector) {
        return selector === 'iframe' ? this.frames : this.media;
    }
    emit(type, event) {
        this.listeners[type]?.forEach(callback => callback(event));
    }
}
class Frame {
    constructor(doc) {
        this.contentDocument = doc;
        this.listeners = {};
    }
    addEventListener(type, callback) {
        (this.listeners[type] ??= []).push(callback);
    }
    load() {
        this.listeners.load?.forEach(callback => callback());
    }
}
const root = new Document();
const child = new Document();
const inner = new Document();
const childFrame = new Frame(child);
root.frames.push(childFrame);
child.frames.push(new Frame(inner));
const external = new Frame(null);
Object.defineProperty(external, 'contentDocument', {get() { throw new Error('Cross-origin document'); }});
root.frames.push(external);
const messages = [];
const timers = [];
const listeners = {};
const parent = {postMessage(value, origin) { messages.push({value, origin}); }};
const window = {
    parent,
    location: {origin: 'https://moodle.test'},
    addEventListener(type, callback) { (listeners[type] ??= []).push(callback); },
    setTimeout(callback) { timers.push(callback); },
};
const observers = [];
class MutationObserver {
    constructor(callback) { this.callback = callback; observers.push(this); }
    observe(target) { this.target = target; }
}
const source = fs.readFileSync(path.resolve(__dirname, '../amd/src/h5p_preview_bridge.js'), 'utf8')
    .replace('export const init', 'const init');
vm.runInNewContext(source + '\ninit();init();', {window, document: root, MutationObserver});
assert.equal(root.listeners.keydown.length, 1, 'Initialisation is idempotent');
assert.equal(inner.listeners.keydown.length, 1, 'The inner Core iframe is observed');
const flush = () => { while (timers.length) timers.shift()(); };
inner.emit('keydown', {key: 'Escape', defaultPrevented: false});
assert.equal(messages.length, 0, 'H5P gets the event before the modal');
flush();
assert.equal(messages.length, 1);
assert.equal(JSON.stringify(messages[0].value), JSON.stringify({source: 'local_nextpool_preview', type: 'escape'}));
assert.equal(messages[0].origin, window.location.origin);
const handled = {key: 'Escape', defaultPrevented: false};
inner.emit('keydown', handled);
handled.defaultPrevented = true;
flush();
assert.equal(messages.length, 1, 'An H5P dialog can consume Escape first');
const message = (source, origin) => listeners.message.forEach(callback => callback({source, origin, data: {type: 'pause'}}));
message(parent, 'https://untrusted.test');
message({}, window.location.origin);
assert.equal(root.media[0].pauses, 0, 'Forged pause messages are ignored');
message(parent, window.location.origin);
[root, child, inner].forEach(doc => assert.equal(doc.media[0].pauses, 1, 'All reachable HTML5 media pause'));
const late = new Document();
root.frames.push(new Frame(late));
observers.find(observer => observer.target === root).callback();
late.emit('keydown', {key: 'Escape', defaultPrevented: false});
flush();
assert.equal(messages.length, 2, 'Dynamically inserted frames relay Escape');
const replaced = new Document();
childFrame.contentDocument = replaced;
childFrame.load();
assert.equal(replaced.listeners.keydown.length, 1, 'Replaced Core iframe documents are observed again');
message(parent, window.location.origin);
assert.equal(replaced.media[0].pauses, 1);
assert.equal(late.media[0].pauses, 1);
console.log('H5P bridge: nested Escape, consumed Escape, origin/source checks, recursive pause and dynamic frames passed.');
