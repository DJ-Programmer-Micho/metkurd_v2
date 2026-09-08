import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = readFileSync(new URL('../../resources/js/admin.js', import.meta.url), 'utf8').replace(/import .*?;\s*/, '').replace('export function', 'function');
function fixture() {
    const events = new Map(); const calls = []; const popups = []; let answer = async () => ({isConfirmed:false});
    const win = {addEventListener:(n,fn)=>{const list=events.get(n)||[];list.push(fn);events.set(n,list);}, Swal:{fire:async options=>{popups.push(options);return answer(options);},close(){}},Livewire:{find:()=>({$call:async (...args)=>calls.push(args)}),hook(){}}};
    const settings = {confirm:'Confirm',cancel:'Cancel',close:'Close',failed:'Safe failure'};
    const doc = {querySelector:()=>({dataset:settings}),querySelectorAll:()=>[],documentElement:{dir:'ltr'},body:{classList:{remove(){}},style:{removeProperty(){}}},addEventListener:win.addEventListener,activeElement:{isConnected:true,focus(){}}};
    const owner={getAttribute:()=> 'fixture-component',querySelector:()=>null};
    const button={tagName:'BUTTON',dataset:{adminMethod:'applyAddonAdjustment',adminArgs:'[7]',adminTarget:'کڕیار',adminImpact:'App + API credits'},textContent:'Grant',isConnected:true,setAttribute(){},removeAttribute(){},closest:s=>s==='[wire\\:id]'?owner:null};
    const context=vm.createContext({window:win,document:doc});vm.runInContext(source,context);
    return {win,doc,button,calls,popups,events,settings,setAnswer:fn=>answer=fn,api:win.__metkurdAdminUi,install:()=>context.installAdminUi(win,doc)};
}
test('installs one listener per event across repeated initialization/navigation',()=>{const f=fixture();for(let i=0;i<5;i++){f.install();f.events.get('livewire:navigated')[0]();} for(const list of f.events.values())assert.equal(list.length,1);});
test('cancel performs no Livewire call and returns focus',async()=>{const f=fixture();let focused=0;f.doc.activeElement.focus=()=>focused++;await f.api.confirm(f.button);assert.equal(f.calls.length,0);assert.equal(focused,1);});
test('confirm calls the original action once without generating an intent ID',async()=>{const f=fixture();f.setAnswer(async()=>({isConfirmed:true}));await f.api.confirm(f.button);assert.equal(f.calls.length,1);assert.deepEqual(f.calls[0],['applyAddonAdjustment',7]);assert.equal(f.button.dataset.adminMethod,'applyAddonAdjustment');});
test('double click and another action in the same component cannot double allocate',async()=>{const f=fixture();let resolve;f.setAnswer(()=>new Promise(r=>resolve=r));const one=f.api.confirm(f.button);await f.api.confirm({...f.button,dataset:{...f.button.dataset,adminMethod:'applyStoragePlanAdjustment'}});resolve({isConfirmed:true});await one;assert.equal(f.popups.length,1);assert.equal(f.calls.length,1);});
test('navigation cancels a stale confirmed action',async()=>{const f=fixture();let resolve;f.setAnswer(()=>new Promise(r=>resolve=r));const one=f.api.confirm(f.button);f.events.get('livewire:navigating')[0]();resolve({isConfirmed:true});await one;assert.equal(f.calls.length,0);});
test('current locale direction and labels are used after navigation',async()=>{const f=fixture();f.doc.documentElement.dir='rtl';f.settings.confirm='تأكيد';await f.api.confirm(f.button);assert.equal(f.popups[0].confirmButtonText,'تأكيد');let dir;f.popups[0].didOpen({setAttribute:(_,v)=>dir=v});assert.equal(dir,'rtl');assert.equal(f.popups[0].focusCancel,true);assert.match(f.popups[0].text,/کڕیار/);});
test('disabled actions and invalid forms do not open a dialog',async()=>{const f=fixture();f.button.disabled=true;await f.api.confirm(f.button);f.button.disabled=false;f.button.tagName='FORM';f.button.reportValidity=()=>false;await f.api.confirm(f.button);assert.equal(f.popups.length,0);});
test('request failure keeps the same action retryable and exposes only a safe message',async()=>{const f=fixture();f.setAnswer(async()=>({isConfirmed:true}));f.win.Livewire.find=()=>({$call:async()=>{throw Error('secret SQL');}});await f.api.confirm(f.button);assert.equal(f.popups[1].titleText,'Safe failure');assert.equal(f.popups[1].text,undefined);await f.api.confirm(f.button);assert.equal(f.popups.length,4);});
test('no SweetAlert means no mutation',async()=>{const f=fixture();delete f.win.Swal;await f.api.confirm(f.button);assert.equal(f.calls.length,0);});
