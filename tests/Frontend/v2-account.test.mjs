import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAccountPhone, installPurchaseRefresh } from '../../resources/js/v2-account.js';

test('phone input can initialize after delayed library loading and navigation', async () => {
    const root = { dataset: {phoneConfig: JSON.stringify({allowedCountries:['iq'], preferredCountries:['iq'], invalidMessage:'Invalid'})}, querySelector:()=>({value:'iq'}) };
    await initializeAccountPhone(root, undefined);
    const calls = [];
    const library = {init: async config => calls.push(config)};
    await initializeAccountPhone(root, library);
    root.dataset.phoneConfig = JSON.stringify({allowedCountries:['iq','gb'], preferredCountries:['gb'], invalidMessage:'Translated error'});
    root.querySelector = () => ({value:'gb'});
    await initializeAccountPhone(root, library);
    assert.equal(calls.length, 2);
    assert.equal(calls[1].initialCountry, 'gb');
    assert.equal(calls[1].invalidMessage, 'Translated error');
    assert.deepEqual(calls[1].onlyCountries, ['iq','gb']);
    assert.equal(calls[1].hiddenPhoneSelector, '#profile_phone_number_hidden');
});

test('account initializer tolerates other pages and reports library failures', async () => {
    await initializeAccountPhone(null, {init:()=>assert.fail('must not initialize')});
    await assert.rejects(initializeAccountPhone({dataset:{},querySelector:()=>null}, {init:async()=>{throw new Error('asset unavailable');}}), /asset unavailable/);
});

test('destroys the old phone widget before navigation replaces its DOM', async () => {
    const {readFileSync} = await import('node:fs');
    const {runInNewContext} = await import('node:vm');
    const events = new Map();
    const calls = [];
    const root = {dataset:{},querySelector:()=>null};
    const source = readFileSync(new URL('../../resources/js/v2-account.js', import.meta.url), 'utf8').replaceAll('export ', '');
    runInNewContext(source, {
        document: {querySelector:()=>root, addEventListener:(name, handler)=>events.set(name,handler)},
        window: {addEventListener:()=>{}, MetIntlTelInput:{init:async()=>calls.push('init'),destroy:key=>calls.push(`destroy:${key}`)}},
    });
    events.get('livewire:navigating')();
    events.get('livewire:navigated')();
    assert.deepEqual(calls, ['init','destroy:v2-profile-phone','init']);
});

test('checkout closure refreshes open reviews across tabs using server state and tolerates disabled storage', async () => {
    const events = new Map();
    const writes = [];
    const calls = [];
    let page = {closest:()=>({getAttribute:()=> 'purchase-component'})};
    const win = {
        addEventListener:(name, handler)=>events.set(name, handler),
        localStorage:{setItem:(...args)=>writes.push(args)},
        Livewire:{find:id=>({$call: async method=>calls.push([id,method])})},
    };
    const doc = {querySelector:()=>page, visibilityState:'visible', addEventListener:(name,handler)=>events.set(name,handler)};
    const api = installPurchaseRefresh(win, doc);
    assert.equal(installPurchaseRefresh(win, doc), api);
    events.get('v2-checkout-changed')();
    await Promise.resolve();
    assert.equal(writes[0][0], 'metkurd:v2:checkout-changed');
    events.get('storage')({key: writes[0][0]});
    await Promise.resolve();
    events.get('focus')();
    await Promise.resolve();
    events.get('pageshow')({persisted:true});
    await Promise.resolve();
    events.get('visibilitychange')();
    await Promise.resolve();
    assert.equal(calls.length, 5);
    assert.ok(calls.every(([id,method])=>id==='purchase-component' && method==='$refresh'));
    win.localStorage.setItem=()=>{throw new Error('storage disabled');};
    events.get('v2-checkout-changed')();
    await Promise.resolve();
    assert.equal(calls.length, 6);
    page=null;
    events.get('focus')();
    await Promise.resolve();
    assert.equal(calls.length, 6);
});
