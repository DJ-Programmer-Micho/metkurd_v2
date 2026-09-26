import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/app/v2/pages/api/⚡app-api.blade.php', import.meta.url), 'utf8');
const partial = readFileSync(new URL('../../resources/views/app/v2/partials/api-voices.blade.php', import.meta.url), 'utf8');
const expression = view.match(/x-data="([^"]+)"/)[1]
    .replace("@js(__('Copy failed. Select and copy the text manually.'))", JSON.stringify('Localized copy failure'));

function setup({hash = '', writeText = async () => {}} = {}) {
    const timers = [], alerts = [];
    const state = vm.runInNewContext(`(${expression})`, {
        window: {location: {hash}}, navigator: {clipboard: {writeText}},
        setTimeout: callback => timers.push(callback),
        $dispatch: (name, payload) => alerts.push({name, payload}),
    });
    return {state, timers, alerts};
}

test('copies the exact public voice ID and clears localized success feedback', async () => {
    const writes = [];
    const {state, timers} = setup({writeText: async value => writes.push(value)});
    await state.copy('public-voice-code');
    assert.deepEqual(writes, ['public-voice-code']);
    assert.equal(state.copied, true);
    timers[0]();
    assert.equal(state.copied, false);
    assert.ok(partial.includes('@click="copy(@js($voice[\'code\']))"'));
});

test('clipboard rejection uses the existing localized alert and does not report success', async () => {
    const {state, alerts} = setup({writeText: async () => {throw new Error('denied');}});
    await state.copy('voice');
    assert.equal(state.copied, false);
    assert.equal(alerts[0].name, 'alert');
    assert.equal(alerts[0].payload.message, 'Localized copy failure');
});

test('opens a direct Available Voices link without changing secret handling', () => {
    assert.equal(setup({hash: '#available-voices'}).state.section, 'voices');
    assert.equal(setup().state.section, 'overview');
    assert.equal(setup().state.secret, '');
    assert.ok(view.includes('x-on:livewire:navigating.window="secret = \'\'"'));
    assert.ok(partial.includes('controls preload="none"'));
    assert.ok(!partial.includes('wire:poll'));
});
