import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-stem.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script data-navigate-once>\s*([\s\S]*?)<\/script>@endpush/)[1];

function setup({ libraryReady = true, componentReady = true } = {}) {
    const events = {}, hooks = {}, listeners = {}, components = new Map(), ponds = [];
    const state = {};
    function mount(mode) {
        const host = { getAttribute: () => `stem-${mode}` };
        state.page = { dataset: {}, closest: () => host, isConnected: true, querySelectorAll: () => [],
            querySelector: selector => selector === '#v2-stem-audio-pond' ? state.input : null, addEventListener() {} };
        state.input = {};
        const wire = { uploads: [], cancelled: [],
            upload(...args) { this.uploads.push(args); },
            cancelUpload(name) { this.cancelled.push(name); },
            call() { return Promise.resolve(); },
        };
        components.set(`stem-${mode}`, wire);
        return wire;
    }
    const wire = mount(2);
    if (!componentReady) components.clear();
    const FilePond = { find() {}, create(input, options) {
        const pond = { options, element: { isConnected: true }, destroyed: false,
            destroy() { this.destroyed = true; }, removeFiles() {},
        };
        ponds.push(pond);
        return pond;
    } };
    const window = { MetKurdV2Assets: {disposePond: pond => pond?.destroy()}, Livewire: {
        find: id => components.get(id),
        on: (name, callback) => { listeners[name] = callback; },
        hook: (name, callback) => { hooks[name] = callback; },
    } };
    if (libraryReady) window.FilePond = FilePond;
    const document = {
        querySelector: selector => selector === '.v2-stem-page' ? state.page : null,
        querySelectorAll: () => [], getElementById: () => state.input,
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    vm.runInNewContext(script, { window, document, AbortController });
    let controller, cleanups = [], active = true;
    const boot = () => {
        active = true;
        if (!window.FilePond || !components.get(state.page.closest().getAttribute())) return;
        controller ||= window.MetKurdV2Pages[0].boot({root: state.page, alive: () => active,
            component: () => active ? components.get(state.page.closest().getAttribute()) : null,
            on: (name, cb) => {listeners[name] = cb;}, listen() {}, cleanup: cb => cleanups.push(cb)});
        controller.update();
    };
    events['livewire:navigating'] = () => { cleanups.forEach(fn => fn()); cleanups = []; active = false; controller?.destroy(); controller = null; };
    events['livewire:navigated'] = events['livewire:initialized'] = events['FilePond:loaded'] = hooks.morphed = boot;
    boot();
    return { window, FilePond, state, events, hooks, components, ponds, wire, mount };
}

test('first navigation initializes STEM when FilePond arrives after the page script', () => {
    const state = setup({ libraryReady: false });
    state.events['livewire:navigated']();
    assert.equal(state.ponds.length, 0);
    state.window.FilePond = state.FilePond;
    state.events['FilePond:loaded']();
    assert.equal(state.ponds.length, 1);
    assert.equal(state.ponds[0].options.instantUpload, false);
});

test('initial page load waits for the registered Livewire component', () => {
    const state = setup({ componentReady: false });
    assert.equal(state.ponds.length, 0);
    state.components.set('stem-2', state.wire);
    state.events['livewire:initialized']();
    assert.equal(state.ponds.length, 1);
});

test('switching STEM modes reconnects uploads to the new component and preserves cancellation', () => {
    const state = setup();
    state.events['livewire:navigating']();
    assert.equal(state.ponds[0].destroyed, true);
    const next = state.mount(4);
    state.events['livewire:navigated']();
    state.hooks.morphed();
    assert.equal(state.ponds.length, 2);
    let loaded, aborted = false;
    const request = state.ponds[1].options.server.process('file', { name: 'track.wav' }, {},
        value => { loaded = value; }, assert.fail, () => {}, () => { aborted = true; });
    assert.equal(state.wire.uploads.length, 0);
    assert.equal(next.uploads[0][0], 'audioFile');
    next.uploads[0][2]('temporary-track-token.wav');
    assert.equal(loaded, 'temporary-track-token.wav');
    request.abort();
    assert.deepEqual(next.cancelled, ['audioFile']);
    assert.equal(aborted, true);
});

test('morphs preserve a connected uploader and replace a removed uploader', () => {
    const state = setup();
    state.hooks.morphed();
    assert.equal(state.ponds.length, 1);
    state.ponds[0].element.isConnected = false;
    state.hooks.morphed();
    assert.equal(state.ponds[0].destroyed, true);
    assert.equal(state.ponds.length, 2);
});
