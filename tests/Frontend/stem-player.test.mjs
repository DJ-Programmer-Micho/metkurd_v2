import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Execute the actual workspace controller against media/DOM test doubles.
// Browser layout, decoding, CORS and autoplay still require browser acceptance.
const view = readFileSync(new URL('../../resources/views/app/v2/pages/tools/⚡app-stem.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script data-navigate-once>\s*([\s\S]*?)<\/script>@endpush/)[1];

function element(children = {}) {
    return {
        dataset: {}, isConnected: true, attributes: {}, handlers: {},
        classList: { toggle() {} },
        querySelector(selector) { return children[selector] ?? null; },
        querySelectorAll(selector) { return children[selector] ?? []; },
        setAttribute(key, value) { this.attributes[key] = String(value); },
        getAttribute(key) { return this.attributes[key] ?? null; },
        addEventListener(name, callback) { this.handlers[name] = callback; },
        async click() { await this.handlers.click?.(); },
    };
}

function setup() {
    const tracks = ['vocals', 'drums', 'bass', 'other'].map(name => {
        const wave = element(); wave.dataset.url = `/private/${name}`;
        const toggle = element({ i: element() }), mute = element(), solo = element();
        const row = element({ '[data-stem-wave]': wave, '[data-stem-audio]': element(), '[data-stem-toggle]': toggle,
            '[data-stem-mute]': mute, '[data-stem-solo]': solo, '[data-stem-track-time]': element() });
        return { row, mute, solo, toggle };
    });
    const all = element({ span: element(), i: element() }), stop = element(), timeline = element();
    const root = element({ '[data-stem-track]': tracks.map(track => track.row), '[data-stem-play-all]': all,
        '[data-stem-stop-all]': stop, '[data-stem-timeline]': timeline });
    const page = element(); page.dataset.stemMessages = JSON.stringify({ 'Pause All': 'Localized pause' });
    const hooks = {}, events = {}, players = [];
    const window = {
        Livewire: { on() {}, hook(name, callback) { hooks[name] = callback; } },
        WaveSurfer: { create() {
            const player = { playing: false, time: 0, volume: 1, destroyed: false,
                on() {}, isPlaying() { return this.playing; }, getCurrentTime() { return this.time; },
                getDuration() { return 90; }, setTime(time) { this.time = time; },
                setVolume(volume) { this.volume = volume; }, play() { this.playing = true; return Promise.resolve(); },
                pause() { this.playing = false; }, destroy() { this.destroyed = true; } };
            players.push(player); return player;
        } },
    };
    const document = {
        querySelector: selector => selector === '.v2-stem-page' ? page : null,
        querySelectorAll: () => root.isConnected ? [root] : [], getElementById: () => null,
        addEventListener: (name, callback) => { events[name] = callback; },
    };
    page.closest = () => null;
    page.querySelectorAll = () => root.isConnected ? [root] : [];
    vm.runInNewContext(script, { window, document, AbortController });
    const controller = window.MetKurdV2Pages[0].boot({root: page, alive: () => true, component: () => null,
        on() {}, listen() {}, cleanup() {}});
    hooks.morphed = hooks['morph.removed'] = controller.update;
    events['livewire:navigating'] = controller.destroy;
    return { root, tracks, all, stop, timeline, hooks, events, players };
}

test('unchanged Livewire updates preserve playback, time, mute state and audio instances', async () => {
    const state = setup();
    state.players[0].time = 12;
    await state.all.click();
    await state.tracks[1].mute.click();
    for (let i = 0; i < 5; i++) state.hooks.morphed();
    assert.equal(state.players.length, 4);
    assert.ok(state.players.every(player => player.playing && player.time === 12 && !player.destroyed));
    assert.equal(state.players[1].volume, 0);
    assert.equal(state.all.querySelector('span').textContent, 'Localized pause');
});

test('play all, mute, solo, seek, pause and stop share transport without overriding mute', async () => {
    const state = setup();
    await state.all.click();
    await state.tracks[1].mute.click();
    await state.tracks[0].solo.click();
    assert.deepEqual(state.players.map(player => player.volume), [1, 0, 0, 0]);
    await state.tracks[0].solo.click();
    assert.deepEqual(state.players.map(player => player.volume), [1, 0, 1, 1]);
    state.timeline.value = 25; state.timeline.handlers.input(); state.timeline.handlers.change();
    assert.ok(state.players.every(player => player.time === 25));
    await state.all.click();
    assert.ok(state.players.every(player => !player.playing));
    await state.stop.click();
    assert.ok(state.players.every(player => player.time === 0));
});

test('removed results and page navigation release the media instances', () => {
    const removed = setup(); removed.root.isConnected = false; removed.hooks['morph.removed']();
    assert.ok(removed.players.every(player => player.destroyed));
    const navigated = setup(); navigated.events['livewire:navigating']();
    assert.ok(navigated.players.every(player => player.destroyed));
});
