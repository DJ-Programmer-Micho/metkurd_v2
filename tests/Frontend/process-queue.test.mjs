import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {queueState, queuePoller, mountProcessQueue, ACTIVE_MS, IDLE_MS} from '../../resources/js/v2-process-queue.js';
import {installNavigation} from '../../resources/js/v2-navigation.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
const done = {id: 'ocr', status: 'done', terminal_key: 'ocr:done:time'};

test('OCR completes elsewhere, stays green at zero active, and acknowledgement survives navigation and locale', () => {
    const state = queueState();
    state.observe([{id: 'ocr', status: 'running'}]);
    assert.equal(state.indicator(true), 'active');
    state.observe([done]);
    assert.equal(state.indicator(false), 'ready');
    state.observe([]); // A later bounded read cannot silently acknowledge it.
    assert.equal(state.indicator(false), 'ready');
    const remounted = queueState(state.save());
    remounted.observe([done]);
    assert.equal(remounted.count(), 1);
    remounted.acknowledge();
    remounted.observe([done]);
    assert.equal(remounted.indicator(false), 'idle');
    assert.equal(queueState(remounted.save()).indicator(false), 'idle');
});

test('concurrent jobs keep yellow while new done and failed notifications remain independent', () => {
    const state = queueState();
    state.observe([done, {status: 'running'}, {status: 'saving'}]);
    assert.equal(state.indicator(true), 'active');
    assert.equal(state.count(), 1);
    state.observe([{status: 'failed', terminal_key: 'apollo:failed:time'}]);
    assert.equal(state.indicator(true), 'active');
    assert.equal(state.indicator(false), 'failed');
    assert.equal(state.count(), 2);
    state.observe([done], true); // Results arriving while open are already visible.
    assert.equal(state.count(), 0);
    assert.equal(queueState().indicator(false), 'idle');
});

function clock() {
    const timers = new Map(); let serial = 0;
    return {timers, schedule(cb, ms) {timers.set(++serial, {cb, ms}); return serial;}, cancel(id) {timers.delete(id);},
        fire() { const [id, timer] = timers.entries().next().value; timers.delete(id); return timer.cb(); }};
}

test('adaptive single timer pauses hidden, resumes immediately, and refreshes on submission', async () => {
    const c = clock(); let hidden = false, calls = 0, active = true;
    const poller = queuePoller({...c, read: async () => {calls++; return {has_active: active};}, apply() {}, error: assert.fail,
        visible: () => !hidden, alive: () => true, gate: {flight: null}});
    await poller.refresh();
    assert.equal(c.timers.size, 1); assert.equal([...c.timers.values()][0].ms, ACTIVE_MS);
    hidden = true; poller.visibility(); assert.equal(c.timers.size, 0);
    await poller.refresh(); assert.equal(calls, 1);
    hidden = false; active = false; poller.visibility(); await tick();
    assert.equal(calls, 2); assert.equal([...c.timers.values()][0].ms, IDLE_MS);
    await poller.refresh(); assert.equal(calls, 3); assert.equal(c.timers.size, 1);
    poller.destroy(); assert.equal(c.timers.size, 0);
});

test('submission bursts coalesce and old in-flight requests cannot update the replacement shell', async () => {
    const c = clock(), gate = {flight: null}; let finish, reads = 0, oldApplied = 0, newApplied = 0;
    const old = queuePoller({...c, gate, visible: () => true, alive: () => true, error: assert.fail,
        read: () => {reads++; return new Promise(resolve => {finish = resolve;});}, apply: () => oldApplied++});
    const pending = old.refresh(); await tick();
    void old.refresh(); void old.refresh();
    assert.equal(reads, 1);
    old.destroy();
    const next = queuePoller({...c, gate, visible: () => true, alive: () => true, error: assert.fail,
        read: async () => {reads++; return {has_active: false};}, apply: () => newApplied++});
    const replacement = next.refresh(); await tick(); assert.equal(reads, 1);
    finish({has_active: true}); await Promise.all([pending, replacement]);
    assert.equal(oldApplied, 0); assert.equal(newApplied, 1); assert.equal(reads, 2);
    assert.equal(c.timers.size, 1); next.destroy();
});

test('failed reads retain display and retry slowly without stale work after destruction', async () => {
    const c = clock(); let errors = 0, applied = 0;
    const poller = queuePoller({...c, gate: {flight: null}, visible: () => true, alive: () => true,
        read: async () => {throw Error('network');}, apply: () => applied++, error: () => errors++});
    await poller.refresh();
    assert.equal(errors, 1); assert.equal(applied, 0);
    assert.equal([...c.timers.values()][0].ms, IDLE_MS);
    poller.destroy(); await poller.refresh(); assert.equal(errors, 1); assert.equal(c.timers.size, 0);
});

class Target {
    constructor() {this.events = new Map(); this.dataset = {}; this.hidden = false; this.textContent = '';}
    addEventListener(name, cb) {if (!this.events.has(name)) this.events.set(name, new Set()); this.events.get(name).add(cb);}
    removeEventListener(name, cb) {this.events.get(name)?.delete(cb);}
    setAttribute(name, value) {this[name] = value;}
    emit(name, detail) {this.events.get(name)?.forEach(cb => cb({detail}));}
    count(name) {return this.events.get(name)?.size || 0;}
}

test('real navigation registry owns one queue through morphs, Back/Forward and locale remounts', async () => {
    const c = clock(), doc = new Target(), win = new Target(), frames = new Map(), hooks = new Map(), stored = new Map();
    let root, frameId = 0, reads = 0, current = {jobs: [], has_active: true, truncated: false};
    doc.documentElement = {style: {setProperty() {}}}; doc.querySelector = () => root;
    win.location = {href: 'http://localhost/en/app-v2/ocr/scanner'};
    win.requestAnimationFrame = cb => {frames.set(++frameId, cb); return frameId;}; win.cancelAnimationFrame = id => frames.delete(id);
    win.setTimeout = c.schedule; win.clearTimeout = c.cancel;
    win.sessionStorage = {getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value)};
    const subscriptions = new Target();
    win.Livewire = {find: () => ({refreshQueue: async () => {reads++; return current;}, refreshQueueLimit: async () => 4}),
        hook: (name, cb) => hooks.set(name, cb), on: (name, cb) => {subscriptions.addEventListener(name, cb); return () => subscriptions.removeEventListener(name, cb);}};
    const mount = () => {
        const nodes = new Map();
        root = new Target(); root.isConnected = true;
        root.dataset = {customer: 'registry-fixture', snapshot: JSON.stringify(current)};
        root.closest = () => ({getAttribute: () => 'queue-component'});
        root.querySelector = selector => {
            if (!nodes.has(selector)) {
                const node = new Target(); node.replaceChildren = (...rows) => {node.rows = rows;};
                node.dataset = {idle: 'Idle', active: 'Processing', ready: 'Ready', failed: 'Failed', new: 'new'};
                node.content = {firstElementChild: {cloneNode: () => ({dataset: {}, querySelector: () => new Target()})}};
                nodes.set(selector, node);
            }
            return nodes.get(selector);
        };
        return root;
    };
    const flush = async () => {const pending = [...frames.values()]; frames.clear(); pending.forEach(cb => cb()); await tick();};
    const api = installNavigation(win, doc);
    mount(); api.register({key: 'process-queue', selector: '[data-process-queue]', boot: ctx => mountProcessQueue(ctx, win, doc)});
    await flush();
    for (let i = 0; i < 8; i++) {
        hooks.get('morphed')(); await flush();
        assert.equal(c.timers.size, 1); assert.equal(win.count('metkurd:job-submitted'), 1); assert.equal(doc.count('visibilitychange'), 1);
        assert.equal(subscriptions.count('customerPlanUpdated'), 1);
        doc.emit('livewire:navigating'); root.isConnected = false;
        assert.equal(c.timers.size, 0); assert.equal(win.count('metkurd:job-submitted'), 0); assert.equal(doc.count('visibilitychange'), 0);
        win.location.href = `http://localhost/${i % 2 ? 'ar' : 'ku'}/app-v2/text-to-speech/apollo-2`;
        mount(); doc.emit('livewire:navigated', {history: true}); await flush();
    }
    assert.equal(reads, 9);
    current = {jobs: [done], has_active: false, truncated: false};
    win.emit('metkurd:job-submitted'); await tick();
    const toggle = root.querySelector('[data-queue-toggle]');
    assert.equal(toggle.dataset.state, 'ready');
    toggle.emit('shown.bs.dropdown'); await tick();
    assert.equal(toggle.dataset.state, 'idle');
    const readsBeforeHidden = reads;
    doc.hidden = true; doc.emit('visibilitychange'); win.emit('metkurd:job-submitted'); await tick();
    assert.equal(reads, readsBeforeHidden); assert.equal(c.timers.size, 0);
    doc.hidden = false; doc.emit('visibilitychange'); await tick(); assert.equal(reads, readsBeforeHidden + 1);
    doc.emit('livewire:navigating'); assert.equal(c.timers.size, 0);
});

test('all eight App submission paths dispatch the same event after receiving their job', () => {
    const files = {'app-tool': 2, 'multi-speaker': 1, 'app-leo': 1, 'app-caption': 1, 'app-ocr': 1, 'app-stem': 1, 'app-harakat': 1};
    for (const [file, count] of Object.entries(files)) {
        const source = readFileSync(new URL(`../../resources/views/app/v2/pages/tools/⚡${file}.blade.php`, import.meta.url), 'utf8');
        assert.equal([...source.matchAll(/\$this->currentJobId\s*=\s*\(string\)\s*\$job->id;\s*\$this->dispatch\('metkurd:job-submitted'\)/g)].length, count, file);
    }
    const source = readFileSync(new URL('../../resources/js/v2-process-queue.js', import.meta.url), 'utf8');
    assert.ok(!source.includes('livewire:navigated'));
    assert.ok(!source.includes('setInterval'));
});
