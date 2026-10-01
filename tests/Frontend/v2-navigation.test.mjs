import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import {installNavigation, progressColor} from '../../resources/js/v2-navigation.js';
import {loadAsset, filePond, disposePond} from '../../resources/js/v2-assets.js';
import {mountUpload} from '../../resources/js/v2-upload.js';

function harness() {
    const events = new Map(), hooks = new Map(), subscriptions = new Map(), frames = new Map();
    let root, next = 0, color;
    const doc = {
        documentElement: {style: {setProperty: (_, value) => {color = value;}}},
        querySelector: () => root,
        addEventListener: (event, callback) => { const list = events.get(event) || []; list.push(callback); events.set(event, list); },
    };
    const win = {location: {href: 'http://localhost/en/app-v2'},
        requestAnimationFrame: cb => {frames.set(++next, cb); return next;}, cancelAnimationFrame: id => frames.delete(id),
        Livewire: {find: () => ({}), hook: (name, cb) => hooks.set(name, cb), on: (name, cb) => {
            const list = subscriptions.get(name) || new Set(); list.add(cb); subscriptions.set(name, list);
            return () => list.delete(cb);
        }},
    };
    const api = installNavigation(win, doc);
    const mount = () => { root = {isConnected: true, dataset: {maxUploadKib: '102400', maxPages: '20'}, closest: () => ({getAttribute: () => 'current'})}; return root; };
    const emit = (event, detail) => (events.get(event) || []).forEach(cb => cb({detail}));
    const flush = async () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(cb => cb()); await new Promise(resolve => setImmediate(resolve)); };
    return {api, win, doc, mount, emit, flush, hooks, subscriptions, events, color: () => color};
}

test('one lifecycle owns repeated navigation, morphs, listeners and delayed component registration', async () => {
    const h = harness(); let boots = 0, destroys = 0, calls = 0;
    h.mount(); h.win.Livewire.find = () => null;
    const def = {key: 'tool', selector: '.page', boot(ctx) { boots++; ctx.on('clear', () => calls++); return {destroy: () => destroys++}; }};
    h.api.register(def); h.api.register(def); await h.flush(); assert.equal(boots, 0);
    h.win.Livewire.find = () => ({}); h.emit('livewire:initialized'); await h.flush();
    // initialized must reconcile even when hooks were bound before the component was registered.
    assert.equal(boots, 1);
    for (let index = 0; index < 5; index++) {
        h.hooks.get('morphed')(); await h.flush();
        assert.equal(h.subscriptions.get('clear').size, 1);
        const stale = [...h.subscriptions.get('clear')][0];
        stale(); h.emit('livewire:navigating'); stale();
        assert.equal(calls, index + 1);
        assert.equal(h.subscriptions.get('clear').size, 0);
        h.mount(); h.emit('livewire:navigated'); await h.flush();
    }
    assert.equal(boots, 6); assert.equal(destroys, 5);
    assert.equal(installNavigation(h.win, h.doc), h.api);
    assert.ok([...h.events.values()].every(list => list.length === 1));
});

test('dependency completion after navigation never boots the old page', async () => {
    const h = harness(); let ready, boots = 0;
    h.mount(); h.api.register({key: 'slow', selector: '.page', prepare: () => new Promise(resolve => {ready = resolve;}), boot() {boots++;}});
    await h.flush(); h.emit('livewire:navigating'); ready(); await h.flush(); assert.equal(boots, 0);
    h.mount(); h.emit('livewire:navigated'); await h.flush(); ready(); await h.flush(); assert.equal(boots, 1);
});

test('progress follows destination including history navigation and keeps unknown-route fallback', async () => {
    const h = harness();
    for (const locale of ['en','ar','ku']) {
        for (const [route, expected] of Object.entries({'text-to-speech/apollo-2':'#93c5fd','clone-text-to-speech/vector-2':'#fd9393','stem/4-stem':'#fdba74','speech-to-text/leo':'#86efac','ocr/scanner':'#7dd3fc',profile:'#93c5fd',storage:'#93c5fd',unknown:'#2299dd'})) {
            h.emit('livewire:navigate', {url: new URL(`http://localhost/${locale}/app-v2/${route}`), history: true});
            assert.equal(h.color(), expected);
        }
    }
    assert.equal(progressColor('/en/app-v2/my-billing'), '#93c5fd');
    assert.equal(progressColor('/ku/app-v2/payments/fib/example'), '#93c5fd');
    assert.equal(progressColor('/en/adm/home'), '#2299dd');
});

test('plugins wait for all library globals, register once and omit unused image preview', async () => {
    const scripts = [], registrations = [];
    const win = {}, doc = {createElement: () => ({dataset: {maxUploadKib: '102400', maxPages: '20'}}), head: {append: script => scripts.push(script)}};
    globalThis.window = win; globalThis.document = doc;
    try {
        const first = filePond(), second = filePond();
        assert.equal(scripts.length, 3);
        assert.ok(!scripts.some(script => script.src.includes('image-preview')));
        win.FilePond = {registerPlugin: plugin => registrations.push(plugin)};
        scripts.find(script => script.src.includes('/filepond/')).onload();
        await Promise.resolve(); assert.equal(registrations.length, 0);
        for (const name of ['FilePondPluginFileValidateType', 'FilePondPluginFileValidateSize']) win[name] = {};
        scripts.filter(script => script.src.includes('plugin')).forEach(script => script.onload());
        await Promise.all([first, second]); await filePond();
        assert.equal(registrations.length, 2); assert.equal(scripts.length, 3);
        assert.equal(await loadAsset('FilePond'), win.FilePond);
    } finally { delete globalThis.window; delete globalThis.document; }
});

test('navigation cancels an in-flight temporary upload and ignores its late callbacks', () => {
    let active = true, args, cancelled = 0, loaded = 0, destroyed = 0, options;
    const cleanups = [], wire = {upload(...input) {args = input;}, cancelUpload() {cancelled++;}};
    const input = {dataset: {maxUploadKib: '102400', maxPages: '20'}, isConnected: true};
    const ctx = {root: {querySelector: () => input}, component: () => active ? wire : null, alive: () => active, on() {}, cleanup: cb => cleanups.push(cb)};
    const ui = mountUpload(ctx, {input: '#file', property: 'audioFile'}, {FilePond: {find() {}, create(_, config) {options = config; return {destroy() {destroyed++;}};}}});
    options.server.process('', {}, {}, () => loaded++, assert.fail, assert.fail, () => {});
    active = false; cleanups.forEach(cb => cb()); ui.destroy(); args[2]('late-token'); args[4]({});
    assert.equal(cancelled, 1); assert.equal(destroyed, 1); assert.equal(loaded, 0);
});

test('a FilePond replacement element survives Livewire morphs without recreating the upload', () => {
    const field = {dataset: {maxUploadKib: '102400', maxPages: '20'}, isConnected: true}, wrapper = {isConnected: true};
    let node = field, creates = 0, destroyed = 0;
    const ctx = {root: {querySelector: () => node}, component: () => ({}), alive: () => true, on() {}, cleanup() {}};
    const ui = mountUpload(ctx, {input: '#file'}, {FilePond: {find() {}, create() {
        creates++; field.isConnected = false; node = wrapper;
        return {element: wrapper, destroy() {destroyed++;}};
    }}});
    ui.update(); ui.update();
    assert.equal(creates, 1); assert.equal(destroyed, 0);
});

test('FilePond unregisters and restores the input before deferred destruction or page caching', () => {
    const calls = [], element = {parentNode: null};
    disposePond({element, destroy() {calls.push('instance');}},
        {FilePond: {destroy(node) {assert.equal(node, element); assert.ok(node.parentNode); calls.push('restore');}}},
        {createDocumentFragment: () => ({append(node) {node.parentNode = {}; calls.push('attach');}})});
    assert.deepEqual(calls, ['attach','restore','instance']);
});

test('OCR unsubscribes stale clear handlers and rejects a PDF finishing after navigation', async () => {
    const source = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-ocr.blade.php', import.meta.url), 'utf8')
        .match(/<script type="module" data-navigate-once>([\s\S]*?)<\/script>/)[1].replace(/import \* as pdfjs[^;]+;/, '');
    const h = harness(); let resolvePdf, destroyed = 0, uploads = 0;
    const root = h.mount(), nodes = new Map(), handlers = new Map();
    root.querySelector = selector => {
        if (!nodes.has(selector)) nodes.set(selector, {dataset: {maxUploadKib: '102400', maxPages: '20'}, style: {}, classList: {remove() {},toggle() {}}, setAttribute() {},
            addEventListener(name, cb) {handlers.set(selector+name, cb);}, removeEventListener(name) {handlers.delete(selector+name);}});
        return nodes.get(selector);
    };
    h.win.Livewire.find = () => ({cancelUpload() {}, upload() {uploads++;}});
    vm.runInNewContext(source, {window: h.win, document: {addEventListener() {}, removeEventListener() {}},
        navigator: {}, URL: {createObjectURL: () => 'blob:fixture', revokeObjectURL() {}},
        pdfjs: {GlobalWorkerOptions: {}, getDocument: () => ({promise: new Promise(resolve => {resolvePdf = resolve;}), destroy: () => {destroyed++;}})}});
    await h.flush();
    const stale = [...h.subscriptions.get('v2-ocr-document-cleared')][0];
    const loading = handlers.get('#v2-ocr-filechange')({target: {files: [{name:'fixture.pdf',type:'application/pdf'}]}});
    h.emit('livewire:navigating'); root.isConnected = false;
    root.querySelector = () => {throw new Error('stale DOM access');};
    stale(); resolvePdf({destroy: async () => {destroyed++;}}); await loading;
    assert.equal(h.subscriptions.get('v2-ocr-document-cleared').size, 0);
    assert.equal(uploads, 0); assert.ok(destroyed > 0);
});

test('OCR uploads valid PDF bytes even when the optional browser preview fails', async () => {
    const source = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-ocr.blade.php', import.meta.url), 'utf8')
        .match(/<script type="module" data-navigate-once>([\s\S]*?)<\/script>/)[1].replace(/import \* as pdfjs[^;]+;/, '');
    const h = harness(); let uploads = 0;
    const root = h.mount(), nodes = new Map(), handlers = new Map();
    root.querySelector = selector => {
        if (!nodes.has(selector)) nodes.set(selector, {dataset: {maxUploadKib: '102400', maxPages: '20'}, style: {}, classList: {remove() {},toggle() {}}, setAttribute() {},
            addEventListener(name, cb) {handlers.set(selector+name, cb);}, removeEventListener(name) {handlers.delete(selector+name);}});
        return nodes.get(selector);
    };
    h.win.Livewire.find = () => ({cancelUpload() {}, upload() {uploads++;}});
    vm.runInNewContext(source, {window: h.win, document: {addEventListener() {}, removeEventListener() {}},
        navigator: {}, URL: {createObjectURL: () => 'blob:fixture', revokeObjectURL() {}},
        pdfjs: {GlobalWorkerOptions: {}, getDocument: () => ({promise: Promise.reject(new Error('Unsupported preview')), destroy() {}})}});
    await h.flush();
    await handlers.get('#v2-ocr-filechange')({target: {files: [{name:'fixture.pdf',type:'application/pdf'}]}});
    assert.equal(uploads, 1);
});

test('OCR browser range selection matches the 1 to 20 page backend contract', () => {
    const view = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-ocr.blade.php', import.meta.url), 'utf8');
    const body = view.match(/const pageRange = ([\s\S]*?);\r?\n\s*const selectedPreviewPages/)[1];
    const select = vm.runInNewContext(`const maxPages = 20; (${body})`);
    for (const total of [null, 100]) {
        assert.equal(select('1-20', total).length, 20);
        assert.equal(select('1-21', total).length, 0);
        assert.equal(select('21-40', total).length, 20);
        assert.equal(select('1-20,21', total).length, 0);
        assert.equal(select('0', total).length, 0);
        assert.equal(select('5-1', total).length, 0);
    }
    assert.equal(select('1-10', 10).length, 10);
    assert.equal(select('1-11', 10).length, 0);
});
