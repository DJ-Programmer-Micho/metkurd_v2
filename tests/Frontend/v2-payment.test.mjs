import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {installPaymentUi, countdownText} from '../../resources/js/v2-payment.js';

function fixture() {
    const events = new Map(), calls = [], timeouts = [];
    const time = {textContent:''};
    const countdown = {dataset:{expires:'1065',serverNow:'1000',checking:'Checking expiry'},querySelector:()=>time};
    const page = {querySelector:()=>countdown,closest:()=>({getAttribute:()=> 'owned-component'})};
    let current = page, elapsed = 0;
    const doc = {querySelector:()=>current,addEventListener:(name,fn)=>events.set(name,fn),visibilityState:'visible'};
    const win = {performance:{now:()=>elapsed},navigator:{clipboard:{writeText:async text=>calls.push(['copy',text])}},
        setInterval:()=>1,clearInterval:()=>{},setTimeout:fn=>timeouts.push(fn),addEventListener:(name,fn)=>events.set(name,fn),
        Livewire:{find:id=>({$call:async method=>calls.push([id,method])})}};
    const api = installPaymentUi(win,doc);
    return {api,win,doc,events,calls,time,timeouts,countdown,setElapsed:value=>elapsed=value,leave:()=>current=null};
}

test('countdown uses server time, supports long sessions, and never writes financial status', async () => {
    const f=fixture(); assert.equal(f.time.textContent,'01:05');
    assert.equal(countdownText(3661),'61:01');
    f.setElapsed(5000);f.api.tick();assert.equal(f.time.textContent,'01:00');
    f.setElapsed(66000);f.api.tick();f.api.tick();await Promise.resolve();
    assert.equal(f.time.textContent,'Checking expiry');
    assert.deepEqual(f.calls,[['owned-component','$refresh']]);
});

test('return from the FIB app re-renders server state without treating launch as payment', async () => {
    const f=fixture();f.events.get('visibilitychange')();await Promise.resolve();
    assert.deepEqual(f.calls,[['owned-component','$refresh']]);
    f.events.get('pageshow')({persisted:true});await Promise.resolve();
    assert.equal(f.calls.length,2);
    f.leave();f.events.get('visibilitychange')();assert.equal(f.calls.length,2);
});

test('navigation and repeated installation retain a single helper and discard stale countdown', () => {
    const f=fixture();assert.equal(installPaymentUi(f.win,f.doc),f.api);
    f.events.get('livewire:navigating')();f.leave();f.api.tick();assert.deepEqual(f.calls,[]);
});

function copyButton(f) {
    const feedback={textContent:''};
    const box={querySelector:selector=>selector==='[data-v2-payment-code]' ? {textContent:'ABCD-1234'} : feedback};
    const button={dataset:{copyLabel:'Copy Code',copiedLabel:'Copied',copyFailed:'Select code manually'},disabled:false,isConnected:true,
        closest:()=>box,textContent:'Copy Code'};
    return {button,feedback};
}
test('Copy uses only readable code and supplies temporary accessible feedback without reload',async()=>{
    const f=fixture(),{button,feedback}=copyButton(f);await f.api.copy(button);
    assert.deepEqual(f.calls,[['copy','ABCD-1234']]);assert.equal(button.textContent,'Copied');assert.equal(feedback.textContent,'Copied');
    f.timeouts[0]();assert.equal(button.textContent,'Copy Code');assert.equal(feedback.textContent,'');
});
test('clipboard rejection has a translated manual fallback and navigation suppresses stale feedback',async()=>{
    const f=fixture(),{button,feedback}=copyButton(f);
    f.win.navigator.clipboard.writeText=async()=>{throw Error('denied')};await f.api.copy(button);
    assert.equal(feedback.textContent,'Select code manually');assert.equal(button.disabled,false);
    f.events.get('livewire:navigating')();feedback.textContent='new page';f.timeouts[0]();assert.equal(feedback.textContent,'new page');
});

test('responsive rules promote mobile app links, collapse optional QR and keep no-link QR fallback',()=>{
    const css=readFileSync(new URL('../../resources/css/v2-account.css',import.meta.url),'utf8');
    assert.match(css,/@media\(max-width:700px\).*?\.v2-payment-action\.has-app-links \.v2-payment-qr-primary\{display:none\}/s);
    assert.match(css,/\.v2-payment-mobile-qr\{display:none\}/);
    assert.match(css,/@media\(max-width:700px\).*?\.v2-payment-mobile-qr\{display:block\}/s);
    assert.match(css,/\.v2-payment-code-box \.btn,\.v2-payment-app-link\{min-height:44px\}/);
});
