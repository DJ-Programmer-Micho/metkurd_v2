import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the shared Vector uploader with the same deferred component registration
// as Livewire's initial page load. Actual file transfer still needs browser acceptance.
const view = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-tool.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script data-navigate-once>\s*([\s\S]*?)<\/script>/)[1]
    .replace(/\{\{\s*__\('([^']+)'\)\s*\}\}/g, (_, label) => label);

function setup({ ready = false } = {}) {
    const events = {}, hooks = {}, listeners = {}, components = new Map(), ponds = [];
    const page = { host: null, field: null };
    const mount = (id) => {
        const wire = {
            uploads: [], cancelled: [], calls: [],
            upload(...args) { this.uploads.push(args); },
            cancelUpload(name) { this.cancelled.push(name); },
            call(name) { this.calls.push(name); return this.result ?? Promise.resolve(); },
        };
        page.host = { getAttribute: () => id };
        page.field = { isConnected: true };
        return wire;
    };
    const wire = mount('vector-1');
    if (ready) components.set('vector-1', wire);
    const FilePond = {
        registerPlugin() {},
        create(field, options) {
            const pond = {
                element: { isConnected: true }, options, destroyed: false, removals: [],
                destroy() { this.destroyed = true; },
                removeFiles(options) { this.removals.push(options); },
            };
            ponds.push(pond);
            return pond;
        },
    };
    const window = { FilePond, Livewire: {
        find: id => components.get(id),
        hook: (name, callback) => { hooks[name] = callback; },
        on: (name, callback) => { listeners[name] = callback; },
    } };
    const document = {
        getElementById: () => page.field,
        querySelector: () => page.host ? { closest: () => page.host } : null,
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    vm.runInNewContext(script, { window, document, FilePond,
        FilePondPluginFileValidateType: {}, FilePondPluginFileValidateSize: {},
        requestAnimationFrame: callback => callback() });
    return { events, hooks, listeners, components, ponds, page, mount, wire };
}

function upload(pond) {
    const result = { file: { name: 'reference.wav' }, loaded: [], errors: [], progress: [], aborted: false };
    result.request = pond.options.server.process('file', result.file, {},
        value => result.loaded.push(value), error => result.errors.push(error),
        (...args) => result.progress.push(args), () => { result.aborted = true; });
    return result;
}

test('initial load waits for Livewire registration and returns the temporary upload ID', () => {
    const state = setup();
    assert.equal(state.ponds.length, 0);
    state.components.set('vector-1', state.wire);
    state.events['livewire:initialized']();
    assert.equal(state.ponds.length, 1);
    const result = upload(state.ponds[0]);
    const [name, file, finish, , progress] = state.wire.uploads[0];
    assert.equal(name, 'referenceAudio');
    assert.equal(file, result.file);
    progress({ lengthComputable: true, loaded: 10, total: 20 });
    finish('temporary-reference-token.wav');
    assert.deepEqual(result.loaded, ['temporary-reference-token.wav']);
    assert.deepEqual(result.progress, [[true, 10, 20]]);
    assert.deepEqual(result.errors, []);
});

test('navigation after Livewire initialization recreates the uploader for Vector 2', () => {
    const state = setup({ ready: true });
    assert.equal(typeof state.hooks.morphed, 'function');
    state.events['livewire:navigating']();
    assert.equal(state.ponds[0].destroyed, true);
    const nextWire = state.mount('vector-2');
    state.components.delete('vector-1');
    state.components.set('vector-2', nextWire);
    state.events['livewire:navigated']();
    state.hooks.morphed();
    assert.equal(state.ponds.length, 2);
    upload(state.ponds[1]);
    assert.equal(nextWire.uploads.length, 1);
    assert.equal(state.wire.uploads.length, 0);
});

test('selecting a saved reference releases the removed uploader and switching back restores it', () => {
    const state = setup({ ready: true });
    state.hooks.morphed();
    assert.equal(state.ponds.length, 1);
    state.ponds[0].element.isConnected = false;
    state.page.field = null;
    state.hooks.morphed();
    assert.equal(state.ponds[0].destroyed, true);
    state.page.field = { isConnected: true };
    state.hooks.morphed();
    assert.equal(state.ponds.length, 2);
    upload(state.ponds[1]);
    assert.equal(state.wire.uploads.length, 1);
});

test('a disappeared component produces a handled upload error', () => {
    const state = setup({ ready: true });
    state.components.clear();
    const result = upload(state.ponds[0]);
    assert.deepEqual(result.errors, ['Upload failed']);
    assert.equal(state.wire.uploads.length, 0);
});

test('cancellation stops the active Livewire upload', () => {
    const state = setup({ ready: true });
    const result = upload(state.ponds[0]);
    result.request.abort();
    assert.deepEqual(state.wire.cancelled, ['referenceAudio']);
    assert.equal(result.aborted, true);
});

test('revert waits for server removal and server clear does not trigger another revert', async () => {
    const state = setup({ ready: true });
    let resolveRemoval;
    state.wire.result = new Promise(resolve => { resolveRemoval = resolve; });
    let loaded = false;
    const errors = [];
    state.ponds[0].options.server.revert('temporary-reference-token.wav', () => { loaded = true; }, error => errors.push(error));
    assert.deepEqual(state.wire.calls, ['removeReferenceAudio']);
    assert.equal(loaded, false);
    state.listeners['ctts-reference-audio-cleared']();
    assert.equal(state.ponds[0].removals[0].revert, false);
    resolveRemoval();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(loaded, true);
    assert.deepEqual(errors, []);
    state.wire.result = Promise.reject(new Error('connection lost'));
    state.ponds[0].options.server.revert('temporary-reference-token.wav', () => {}, error => errors.push(error));
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(errors, ['Upload failed']);
});
