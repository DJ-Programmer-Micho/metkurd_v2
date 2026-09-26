import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {mountStemPlayer} from '../../resources/js/v2-stem-player.js';

const view = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-stem.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script data-navigate-once>\s*([\s\S]*?)<\/script>@endpush/)[1];
function element(children = {}) {
    return {dataset: {}, isConnected: true, attributes: {}, handlers: {}, classList: {toggle() {}},
        querySelector: selector => children[selector] ?? null, querySelectorAll: selector => children[selector] ?? [],
        setAttribute(key, value) {this.attributes[key] = String(value);},
        getAttribute(key) {return this.attributes[key] ?? null;},
        removeAttribute(key) {delete this.attributes[key];}, pause() {}, load() {}, replaceChildren() {},
        addEventListener(name, callback, options) {this.handlers[name] = callback; options?.signal?.addEventListener('abort', () => delete this.handlers[name]);},
        async click() {await this.handlers.click?.();},
    };
}
function setup(mode = 4, original = false) {
    const names = [...(original ? ['original'] : []), ...(mode === 4 ? ['vocals', 'drums', 'bass', 'other'] : ['vocals', 'instrumental'])];
    const tracks = names.map(name => {
        const wave = element(); wave.dataset.url = `/same-origin/${name}?proxy=1`;
        const toggle = element({i: element()}), mute = element(), solo = element();
        const row = element({'[data-stem-wave]': wave, '[data-stem-audio]': element(), '[data-stem-toggle]': toggle,
            '[data-stem-mute]': mute, '[data-stem-solo]': solo, '[data-stem-track-time]': element()});
        row.dataset.stemTrack = name;
        return {row, wave, mute, solo, toggle};
    });
    const all = element({span: element(), i: element()}), stop = element(), timeline = element(), status = element();
    const root = element({'[data-stem-track]': tracks.map(track => track.row), '[data-stem-play-all]': all,
        '[data-stem-stop-all]': stop, '[data-stem-timeline]': timeline, '[data-stem-player-status]': status});
    const page = element(); page.dataset.stemMessages = JSON.stringify({'Pause All': 'Localized pause'});
    const players = [], contexts = [], sources = [], gains = [], frames = new Map();
    let frameId = 0;
    class AudioContext {
        constructor() {this.currentTime = 0; this.sampleRate = 48000; this.destination = {}; contexts.push(this);}
        resume() {return this.resumePromise || Promise.resolve();}
        close() {this.closed = true; return Promise.resolve();}
        createGain() {const node = {gain: {value: 1, cancelScheduledValues() {}, setTargetAtTime(value, time, ramp) {this.value = value; this.ramp = ramp;}}, connect() {}, disconnect() {this.disconnected = true;}}; gains.push(node); return node;}
        createBufferSource() {const source = {connect(node) {this.gain = node;}, start(...args) {this.startArgs = args;}, stop() {this.stopped = true;}, disconnect() {this.disconnected = true;}}; sources.push(source); return source;}
    }
    const window = {AudioContext, MetKurdStemPlayer: {mount: (root, t, format) => mountStemPlayer(root, t, format, window)},
        requestAnimationFrame: callback => {frames.set(++frameId, callback); return frameId;}, cancelAnimationFrame: id => frames.delete(id),
        WaveSurfer: {create(options) {
            const player = {options, callbacks: {}, setTimes: [], destroyed: false, buffer: {duration: 90},
                on(name, cb) {this.callbacks[name] = cb;}, getDecodedData() {return this.buffer;},
                setTime(time) {this.setTimes.push(time);}, destroy() {this.destroyed = true;},
            };
            players.push(player); return player;
        }},
    };
    page.closest = () => null; page.querySelectorAll = () => root.isConnected ? [root] : [];
    vm.runInNewContext(script, {window, AbortController});
    const ctx = {root: page, alive: () => true, component: () => null, on() {}, listen() {}, cleanup() {}};
    const controller = window.MetKurdV2Pages[0].boot(ctx);
    const ready = () => players.forEach(player => player.callbacks.ready());
    const tick = seconds => {contexts.at(-1).currentTime += seconds; const callbacks = [...frames.values()]; frames.clear(); callbacks.forEach(cb => cb());};
    return {root, tracks, all, stop, timeline, status, players, contexts, sources, gains, frames, controller, ready, tick,
        remount: () => window.MetKurdV2Pages[0].boot(ctx)};
}
for (const mode of [2, 4]) {
    test(`STEM${mode} loads once and rapid mixer controls never restart, seek, fetch or morph players`, async () => {
        const s = setup(mode); s.ready(); await s.all.click();
        const sources = [...s.sources], counts = s.players.map(p => p.setTimes.length);
        for (let i = 0; i < 20; i++) {
            await s.tracks[0].mute.click(); await s.tracks[0].mute.click();
            await s.tracks[1].solo.click(); await s.tracks[0].solo.click();
            await s.tracks[1].solo.click(); await s.tracks[0].solo.click();
            s.controller.update();
        }
        assert.equal(s.players.length, mode); assert.deepEqual(s.sources, sources);
        assert.ok(sources.every(source => !source.stopped));
        assert.deepEqual(s.players.map(p => p.setTimes.length), counts, 'mixer controls do not even seek display media');
        assert.deepEqual(s.gains.map(g => g.gain.value), Array(mode).fill(1));
        assert.ok(s.gains.every(g => g.gain.ramp === .005));
        assert.equal(s.all.querySelector('span').textContent, 'Localized pause');
    });
}
test('shared clock schedules identical starts and only explicit seeking replaces audio sources', async () => {
    const s = setup(); s.ready(); await s.all.click();
    assert.ok(s.sources.every(source => source.startArgs[0] === .025 && source.startArgs[1] === 0));
    assert.ok(s.players.every(p => p.options.sampleRate === 48000));
    s.tick(23.025); assert.equal(s.timeline.value, '23'); assert.equal(s.sources.length, 4);
    s.timeline.value = 25; s.timeline.handlers.input(); assert.equal(s.sources.length, 4);
    s.timeline.handlers.change();
    assert.ok(s.sources.slice(0, 4).every(source => source.stopped));
    assert.ok(s.sources.slice(4).every(source => Math.abs(source.startArgs[0] - 23.05) < 1e-9 && source.startArgs[1] === 25));
    await s.stop.click(); assert.equal(s.timeline.value, '0'); assert.equal(s.frames.size, 0);
    await s.all.click(); assert.ok(s.sources.slice(-4).every(source => source.startArgs[1] === 0));
});
test('multiple solos preserve previous mute state and do not stop inaudible tracks', async () => {
    const s = setup(); s.ready(); await s.all.click();
    await s.tracks[0].mute.click(); await s.tracks[1].solo.click(); await s.tracks[2].solo.click();
    assert.deepEqual(s.gains.map(g => g.gain.value), [0, 1, 1, 0]);
    await s.tracks[1].solo.click(); assert.deepEqual(s.gains.map(g => g.gain.value), [0, 0, 1, 0]);
    await s.tracks[2].solo.click(); assert.deepEqual(s.gains.map(g => g.gain.value), [0, 1, 1, 1]);
    assert.ok(s.sources.every(source => !source.stopped));
});
test('Play All excludes the original comparison and track playback can select it explicitly', async () => {
    const s = setup(4, true); s.ready(); await s.all.click();
    assert.deepEqual(s.gains.map(g => g.gain.value), [0, 1, 1, 1, 1]);
    await s.stop.click(); await s.tracks[0].toggle.click();
    assert.deepEqual(s.gains.map(g => g.gain.value), [1, 0, 0, 0, 0]);
});
test('readiness gates playback and load failure does not recreate or refetch players', async () => {
    const s = setup(); assert.equal(s.all.disabled, true); await s.all.click(); assert.equal(s.sources.length, 0);
    s.ready(); await s.all.click(); s.players[0].callbacks.error(new Error('load failure'));
    assert.equal(s.players.length, 4); assert.ok(s.sources.every(source => source.stopped));
    assert.equal(s.all.disabled, true); assert.match(s.status.textContent, /Unable to play/);
    s.controller.update(); assert.equal(s.players.length, 4);
});
test('removal and navigation dispose the graph, listeners and waveforms, then remount once', async () => {
    const s = setup(); s.ready(); await s.all.click(); s.controller.destroy();
    assert.ok(s.players.every(player => player.destroyed)); assert.ok(s.sources.every(source => source.stopped));
    assert.ok(s.gains.every(gain => gain.disconnected)); assert.equal(s.contexts[0].closed, true);
    assert.equal(s.frames.size, 0); assert.equal(s.all.handlers.click, undefined);
    const next = s.remount(); assert.equal(s.players.length, 8); next.update(); assert.equal(s.players.length, 8);
    s.root.isConnected = false; next.update(); assert.ok(s.players.every(player => player.destroyed));
});
test('leaving during context resume cannot resurrect audio', async () => {
    const s = setup(); s.ready(); let finish;
    s.contexts[0].resumePromise = new Promise(resolve => finish = resolve);
    const pending = s.all.click(); s.controller.destroy(); finish(); await pending;
    assert.equal(s.sources.length, 0); assert.equal(s.frames.size, 0);
});
test('result mixer controls have no Livewire actions and player DOM is keyed and ignored', () => {
    const region = view.slice(view.indexOf('@elseif($render)'), view.indexOf('@else<div class="v2-stem-empty'));
    assert.match(region, /wire:ignore wire:key="stem-player-/);
    assert.doesNotMatch(region, /wire:(click|model)/);
    assert.doesNotMatch(view, /proxy_stream_urls|fallbackUrl|bindPlayer/);
});
