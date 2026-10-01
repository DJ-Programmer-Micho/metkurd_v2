import assert from 'node:assert/strict';
import test from 'node:test';
import {mountSegments} from '../../resources/js/v2-multi-speaker.js';
import {mountThetaUpload} from '../../resources/js/v2-upload.js';

test('drag sorting preserves identities through repeated reorder and navigation remount', async () => {
    const events = {}, calls = [];
    let rows = ['A', 'B', 'C', 'D'].map(segmentId => ({dataset: {segmentId}})), finish;
    const ctx = {root: {querySelectorAll: () => rows}, listen: (_root, event, fn) => events[event] = fn,
        on() {}, component: () => ({call: (...args) => { calls.push(args); return new Promise(resolve => finish = resolve); }})};
    const controller = mountSegments(ctx);
    const target = index => ({closest: selector => selector === '[data-segment-handle]' ? {} : rows[index]});
    events.dragstart({target: target(3), dataTransfer: {setData() {}}});
    events.drop({target: target(1), preventDefault() {}});
    assert.deepEqual(calls, [['reorderSegments', ['A', 'D', 'B', 'C']]]);
    events.dragstart({target: target(2)});
    events.drop({target: target(0), preventDefault() {}});
    assert.equal(calls.length, 1, 'another drag waits for Livewire to finish');
    rows = ['A', 'D', 'B', 'C'].map(segmentId => ({dataset: {segmentId}}));
    finish(); await new Promise(resolve => setImmediate(resolve));
    events.dragstart({target: target(0)});
    events.drop({target: target(3), preventDefault() {}});
    assert.deepEqual(calls[1], ['reorderSegments', ['D', 'B', 'A', 'C']]);
    controller.destroy();
    events.drop({target: target(0), preventDefault() {}});
    assert.equal(calls.length, 2);
    finish(); await new Promise(resolve => setImmediate(resolve));
    // The navigation registry cleans old listeners; a new page resolves its own owner.
    rows = ['new-A', 'new-B'].map(segmentId => ({dataset: {segmentId}}));
    const nextCalls = [];
    mountSegments({...ctx, component: () => ({call: (...args) => nextCalls.push(args)})});
    events.dragstart({target: target(1)});
    events.drop({target: target(0), preventDefault() {}});
    assert.deepEqual(nextCalls, [['reorderSegments', ['new-B', 'new-A']]]);
    assert.equal(calls.length, 2);
});

test('Theta FilePond remounts after navigation and reuses the current Livewire upload owner', () => {
    const ponds = [];
    const win = {FilePond: {find() {}, create(field, options) {
        const pond = {element: field, options, destroy() { this.destroyed = true; }, removeFiles(options) { this.removal = options; }};
        ponds.push(pond); return pond;
    }}};
    const page = () => {
        const cleanups = [], events = {}, uploads = [], calls = [], cancelled = [];
        const field = {isConnected: true, dataset: {maxUploadKib: '20480'}};
        const component = {upload: (...args) => uploads.push(args), call: name => { calls.push(name); return Promise.resolve(); }, cancelUpload: name => cancelled.push(name)};
        const ctx = {root: {querySelector: selector => { assert.equal(selector, '#v2-theta-reference-pond'); return field; }}, alive: () => true,
            component: () => component, cleanup: fn => cleanups.push(fn), on: (name, fn) => events[name] = fn};
        return {controller: mountThetaUpload(ctx, win), cleanups, events, uploads, calls, cancelled};
    };
    const first = page();
    const upload = pond => pond.options.server.process('file', {name: 'reference.wav'}, {}, () => {}, () => {}, () => {}, () => {});
    upload(ponds[0]);
    first.cleanups.forEach(fn => fn()); first.controller.destroy();
    assert.equal(ponds[0].destroyed, true);
    assert.deepEqual(first.cancelled, ['referenceAudio']);
    const second = page(); second.controller.update();
    assert.equal(ponds.length, 2);
    upload(ponds[1]);
    assert.equal(first.uploads.length, 1); assert.equal(second.uploads.length, 1);
    assert.equal(second.uploads[0][0], 'referenceAudio');
    second.events['theta-reference-audio-cleared']();
    assert.deepEqual(ponds[1].removal, {revert: false});
});
