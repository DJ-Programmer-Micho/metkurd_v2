import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const source = readFileSync(new URL('../../resources/js/admin.js', import.meta.url), 'utf8').replace(/import .*?;\s*/, '').replace('export function', 'function');
function fixture() {
    const events = new Map(); const calls = []; const popups = []; let answer = async () => ({isConfirmed:false});
    const win = {addEventListener:(n,fn)=>{const list=events.get(n)||[];list.push(fn);events.set(n,list);}, Swal:{fire:async options=>{popups.push(options);return answer(options);},close(){}},Livewire:{find:()=>({$call:async (...args)=>calls.push(args)}),hook(){}}};
    const settings = {confirm:'Confirm',cancel:'Cancel',close:'Close',failed:'Safe failure'};
    const doc = {querySelector:()=>({dataset:settings}),querySelectorAll:()=>[],documentElement:{dir:'ltr'},body:{classList:{remove(){},contains(){return false;}},style:{removeProperty(){}}},addEventListener:win.addEventListener,activeElement:{isConnected:true,focus(){}}};
    const owner={getAttribute:()=> 'fixture-component',querySelector:()=>null};
    const button={tagName:'BUTTON',dataset:{adminMethod:'applyAddonAdjustment',adminArgs:'[7]',adminTarget:'کڕیار',adminImpact:'App + API credits'},textContent:'Grant',isConnected:true,setAttribute(){},removeAttribute(){},closest:s=>s==='[wire\\:id]'?owner:null};
    const context=vm.createContext({window:win,document:doc,URL});vm.runInContext(source,context);
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

test('audited actions collect a fresh reason and set it only after confirmation', async()=>{
 const f=fixture(); const changes=[];
 f.button.dataset.adminReasonField='adminChangeReason';
 f.settings.reason='Reason'; f.settings.reasonRequired='Ten characters required';
 f.win.Livewire.find=()=>({$set:(...args)=>changes.push(args),$call:async(...args)=>f.calls.push(args)});
 f.setAnswer(async options=>{assert.equal(options.input,'textarea');assert.equal(options.inputValue,'');assert.equal(options.inputValidator('short'),'Ten characters required');return {isConfirmed:true,value:'Approved fixture change.'};});
 await f.api.confirm(f.button);
 assert.deepEqual(changes,[['adminChangeReason','Approved fixture change.',false]]);
 assert.equal(f.calls.length,1);
});
test('missing or canceled action reasons never mutate',async()=>{
 for(const result of [{isConfirmed:false,value:'Approved fixture change.'},{isConfirmed:true,value:''},{isConfirmed:true,value:'short'}]) {
  const f=fixture();f.button.dataset.adminReasonField='adminChangeReason';f.setAnswer(async()=>result);
  await f.api.confirm(f.button);assert.equal(f.calls.length,0);
 }
});
test('Admin sidebar and modal scroll are independently bounded',()=>{
 const css=readFileSync(new URL('../../resources/css/admin.css',import.meta.url),'utf8');
 assert.match(css,/\.navbar-menu #scrollbar\s*\{[^}]*min-height: 0;[^}]*overflow-y: auto/);
 assert.match(css,/\.modal-dialog-scrollable \.modal-body\s*\{[^}]*overflow-y: auto/);
 assert.match(css,/\.modal-content > form > fieldset\s*\{[^}]*max-height: calc\(100dvh - 2 \* var\(--vz-modal-margin, \.5rem\) - 2px\)/);
 assert.match(source,/classList\.add\('modal-dialog-scrollable'\)/);
});

test('request failures show translated permission denial without server response details', async()=>{
 const f=fixture(); const hooks={};let failed;let prevented=false;
 f.settings.forbidden='You do not have permission.';
 f.win.Livewire.hook=(name,callback)=>hooks[name]=callback;
 f.events.get('livewire:initialized')[0]();
 hooks.request({fail:callback=>failed=callback});
 failed({status:403,content:'secret SQL provider response',preventDefault:()=>prevented=true});
 assert.equal(prevented,true);assert.equal(f.popups[0].titleText,'You do not have permission.');
 assert.equal(f.popups[0].text,undefined);
});

test('validation keeps focus on the error summary instead of scrolling back to submit',async()=>{
 const f=fixture();let focused=0;let triggerFocus=0;let scrolled=0;
 const owner=f.button.closest('[wire\\:id]');
 owner.querySelector=selector=>selector==='.admin-validation-summary'?{scrollIntoView:()=>scrolled++,focus:()=>focused++}:null;
 f.doc.activeElement.focus=()=>triggerFocus++;
 f.setAnswer(async()=>({isConfirmed:true}));
 await f.api.confirm(f.button);
 assert.equal(focused,1);assert.equal(scrolled,1);assert.equal(triggerFocus,0);
});

const classes = () => { const values = new Set(); return {contains:k=>values.has(k),add:k=>values.add(k),remove:k=>values.delete(k),toggle:(k,force)=>{const on=force??!values.has(k);if(on)values.add(k);else values.delete(k);return on;}}; };
test('query changes select exactly the correct localized Operations link and page context',()=>{
 const f=fixture(); const context={textContent:''}, group={textContent:''}; const system={open:false};
 const links=['jobs','payments','api','audit'].map(section=>({href:`https://localhost:8443/ku/adm/operations/jobs?section=${section}`,textContent:section,dataset:{adminGroup:section==='audit'?'System':'Operations'},classList:classes(),attributes:{},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];},closest:()=>section==='audit'?system:null}));
 f.doc.querySelectorAll=s=>s==='[data-admin-nav]'?links:s==='[data-admin-page-context]'?[context]:s==='[data-admin-page-group]'?[group]:[];
 f.win.location={href:'https://localhost:8443/ku/adm/operations/jobs?section=payments&status=paid'};
 f.api.syncNavigation();assert.deepEqual(links.filter(l=>l.attributes['aria-current']).map(l=>l.textContent),['payments']);assert.equal(context.textContent,'payments');
 f.win.location.href='https://localhost:8443/ku/adm/operations/jobs?section=audit';f.api.syncNavigation();assert.equal(system.open,true);assert.equal(group.textContent,'System');
 f.win.location.href='https://localhost:8443/ku/adm/operations/jobs';f.api.syncNavigation();assert.deepEqual(links.filter(l=>l.classList.contains('active')).map(l=>l.textContent),['jobs']);
});
test('mobile drawer has keyboard dismissal focus return and resets on navigation',()=>{
 const f=fixture();let focused=0;const attrs={};const toggle={setAttribute:(k,v)=>attrs[k]=v,focus:()=>focused++};const sidebar={inert:false,querySelector:()=>({focus:()=>focused++})};
 f.doc.body.classList=classes();f.win.matchMedia=()=>({matches:true});f.doc.getElementById=id=>id==='admin-sidebar'?sidebar:null;
 f.doc.querySelector=s=>s==='[data-admin-ui]'?{dataset:f.settings}:s==='[data-admin-sidebar-toggle]'?toggle:null;
 const click=selector=>f.events.get('click')[0]({type:'click',target:{closest:s=>s===selector?{}:null}});
 f.api.sidebarState();assert.equal(sidebar.inert,true);assert.equal(attrs['aria-expanded'],'false');
 click('[data-admin-sidebar-toggle]');assert.equal(sidebar.inert,false);assert.equal(attrs['aria-expanded'],'true');
 f.events.get('keydown')[0]({key:'Escape'});assert.equal(sidebar.inert,true);assert.equal(focused,2);
 click('[data-admin-sidebar-toggle]');f.events.get('livewire:navigating')[0]();assert.equal(f.doc.body.classList.contains('admin-sidebar-open'),false);
});
test('desktop sidebar visibility and mobile focus trap remain independent',()=>{
 const f=fixture();const state=classes();f.doc.body.classList=state;let isMobile=false;f.win.matchMedia=()=>({matches:isMobile});
 const focused=[]; const nodes=['first','last'].map(name=>({getClientRects:()=>[1],focus:()=>focused.push(name)}));const sidebar={querySelectorAll:()=>nodes,querySelector:()=>nodes[0]};
 f.doc.getElementById=()=>sidebar;f.doc.querySelector=s=>s==='[data-admin-ui]'?{dataset:f.settings}:null;
 f.events.get('click')[0]({type:'click',target:{closest:s=>s==='[data-admin-sidebar-toggle]'?{}:null}});assert.equal(state.contains('admin-sidebar-hidden'),true);assert.equal(sidebar.inert,true);
 isMobile=true;state.add('admin-sidebar-open');f.api.sidebarState();assert.equal(sidebar.inert,false);
 let prevented=0;f.doc.activeElement=nodes[1];f.events.get('keydown')[0]({key:'Tab',shiftKey:false,preventDefault:()=>prevented++});assert.equal(focused.at(-1),'first');
 f.doc.activeElement=nodes[0];f.events.get('keydown')[0]({key:'Tab',shiftKey:true,preventDefault:()=>prevented++});assert.equal(focused.at(-1),'last');assert.equal(prevented,2);
});
test('Admin shell uses one owner and mirrored mobile positioning without template menu cloning',()=>{
 const layout=readFileSync(new URL('../../resources/views/admin/layouts/app.blade.php',import.meta.url),'utf8');
 const css=readFileSync(new URL('../../resources/css/admin.css',import.meta.url),'utf8');
 assert.doesNotMatch(layout,/<script[^>]+(?:admin\/js\/app\.js|app\/js\/layout\.js)/);
 assert.match(css,/max-width:991\.98px/);assert.match(css,/\[dir="rtl"\] \.admin-shell \.navbar-menu \{ transform: translateX\(100%\)/);
 assert.match(css,/prefers-reduced-motion/);assert.match(source,/sidebar\.inert = !open/);
});


test('service and plan modals reuse one shared show/hide listener through navigation',()=>{
 const f=fixture();const calls=[];
 const modal={classList:{contains:name=>name==='modal'}};
 f.doc.getElementById=id=>id==='fixture-modal'?modal:null;
 f.win.bootstrap={Modal:{getOrCreateInstance:el=>({show:()=>calls.push(['show',el]),hide:()=>calls.push(['hide',el])})}};
 for(const prefix of ['admin','services-tools','services-entitlements','services-pricing','services-voices','payments-plans','customers-list','payments-addons','payments-storage','payments-coupons','payments-methods','payments-currencies','landing-contact','landing-tools']) {
  for(const action of ['show','hide']) {
   const event=prefix+':modal-'+action;
   assert.equal(f.events.get(event).length,1);
   f.events.get(event)[0]({detail:{id:'fixture-modal'}});
   assert.deepEqual(calls.at(-1),[action,modal]);
  }
 }
 f.install();f.events.get('livewire:navigated')[0]();
 assert.equal(f.events.get('services-pricing:modal-show').length,1);
 const prior=calls.length;
 f.events.get('services-pricing:modal-show')[0]({detail:{id:'absent'}});
 assert.equal(calls.length,prior);
 for(const name of ['tools','entitlements','pricing','voices']) {
  const view=readFileSync(new URL('../../resources/views/admin/pages/services/⚡adm-services-'+name+'.blade.php',import.meta.url),'utf8');
  assert.doesNotMatch(view,/addEventListener|<script\b/);
 }
 for(const path of ['customers/⚡adm-customers-list','payments/⚡adm-payments-addons','payments/⚡adm-payments-storages','payments/⚡adm-payments-coupons','payments/⚡adm-payments-methods','payments/⚡adm-payments-currencies','landing/⚡adm-landing-contact','landing/⚡adm-landing-tools']) {
  const view=readFileSync(new URL('../../resources/views/admin/pages/'+path+'.blade.php',import.meta.url),'utf8');
  assert.doesNotMatch(view,/addEventListener|<script\b/);
 }
});


test('navigation snapshots cannot retain an open service modal or its dialog attributes',()=>{
 const f=fixture();const attributes={'aria-modal':'true',role:'dialog'};let disposed=0;
 const modal={classList:classes(),style:{display:'block'},setAttribute:(k,v)=>attributes[k]=v,removeAttribute:k=>delete attributes[k]};
 modal.classList.add('modal');modal.classList.add('show');
 f.doc.querySelectorAll=s=>s.includes('[data-bs-toggle="dropdown"],')?[modal]:[];
 f.win.bootstrap={Dropdown:{getInstance:()=>null},Tooltip:{getInstance:()=>null},Modal:{getInstance:()=>({dispose:()=>disposed++})}};
 f.events.get('livewire:navigating')[0]();
 assert.equal(disposed,1);assert.equal(modal.style.display,'none');assert.equal(modal.classList.contains('show'),false);
 assert.equal(attributes['aria-hidden'],'true');assert.equal(attributes['aria-modal'],undefined);assert.equal(attributes.role,undefined);
});

test('programmatic modal close restores its opener but never restores stale navigation focus',()=>{
 const f=fixture();let focused=0;f.doc.activeElement.focus=()=>focused++;
 const modal={classList:classes()};modal.classList.add('modal');
 f.doc.getElementById=()=>modal;
 f.win.bootstrap={Modal:{getOrCreateInstance:()=>({show:()=>modal.classList.add('show')})}};
 const show=()=>f.events.get('admin:modal-show')[0]({detail:{id:'review'}});
 const hidden=()=>{modal.classList.remove('show');f.events.get('hidden.bs.modal')[0]({target:modal});};
 show();hidden();assert.equal(focused,1);
 show();f.events.get('livewire:navigating')[0]();hidden();assert.equal(focused,1);
});

test('Bootstrap focus trap resumes before focusing modal validation errors',async()=>{
 const f=fixture();const order=[];const owner=f.button.closest('[wire\\:id]');
 const modal={classList:classes(),querySelectorAll:()=>[],querySelector:()=>({scrollIntoView(){},focus:()=>order.push('errors')})};modal.classList.add('show');
 f.button.closest=s=>s==='.modal'?modal:s==='[wire\\:id]'?owner:null;
 f.doc.querySelector=s=>s==='.modal.show'?modal:s==='[data-admin-ui]'?{dataset:f.settings}:null;
 f.win.bootstrap={Modal:{getInstance:()=>({_focustrap:{deactivate:()=>order.push('deactivate'),activate:()=>order.push('activate')}})}};
 f.setAnswer(async()=>({isConfirmed:true}));await f.api.confirm(f.button);
 assert.deepEqual(order.slice(-2),['activate','errors']);
});

test('permission popup retains focus until dismissed before restoring the underlying modal',async()=>{
 const f=fixture();const hooks={};let failed,resolve;let visible=false;const focus=[];
 const owner=f.button.closest('[wire\\:id]');
 const modal={classList:classes(),querySelectorAll:()=>[],querySelector:()=>null};modal.classList.add('show');
 f.button.closest=s=>s==='.modal'?modal:s==='[wire\\:id]'?owner:null;
 f.doc.querySelector=s=>s==='.modal.show'?modal:s==='[data-admin-ui]'?{dataset:f.settings}:null;
 f.doc.activeElement.focus=()=>focus.push('opener');
 f.win.bootstrap={Modal:{getInstance:()=>({_focustrap:{deactivate(){},activate:()=>focus.push('trap')}})}};
 f.win.Swal.isVisible=()=>visible;
 f.setAnswer(async options=>{if(options.icon==='warning')return {isConfirmed:true};visible=true;return new Promise(r=>resolve=r);});
 f.win.Livewire.hook=(name,callback)=>hooks[name]=callback;f.events.get('livewire:initialized')[0]();hooks.request({fail:callback=>failed=callback});
 f.win.Livewire.find=()=>({$call:async()=>{failed({status:403,preventDefault(){}});throw Error('private response');}});
 await f.api.confirm(f.button);assert.deepEqual(focus,[]);assert.equal(f.popups.length,2);
 visible=false;resolve({});await new Promise(r=>setImmediate(r));assert.deepEqual(focus,['trap','opener']);
});

test('mobile modal drawer isolates background and restores pre-existing inert state',()=>{
 const f=fixture();const attributes={};const sidebar={setAttribute:(k,v)=>attributes[k]=v,removeAttribute:k=>delete attributes[k]};
 const background=[{inert:false},{inert:true}];f.doc.body.classList=classes();f.win.matchMedia=()=>({matches:true});
 f.doc.getElementById=()=>sidebar;f.doc.querySelectorAll=s=>s==='[data-admin-drawer-background]'?background:[];
 f.doc.body.classList.add('admin-sidebar-open');f.api.sidebarState();
 assert.equal(attributes.role,'dialog');assert.equal(attributes['aria-modal'],'true');assert.ok(background.every(el=>el.inert));
 f.api.sidebarState();f.events.get('livewire:navigating')[0]();
 assert.deepEqual(background.map(el=>el.inert),[false,true]);assert.equal(attributes.role,undefined);
});

test('table scroll regions have keyboard access and preserve explicit accessible names',()=>{
 const f=fixture();f.settings.tableRegion='Scrollable data table';
 const region=(attributes,caption)=>({attributes,hasAttribute:k=>k in attributes,setAttribute:(k,v)=>attributes[k]=v,querySelector:()=>caption?{textContent:caption}:null});
 const regions=[region({},'Payment evidence'),region({},null),region({'aria-labelledby':'owned-label',tabindex:'-1'},null)];
 f.doc.querySelectorAll=s=>s==='.page-content .table-responsive'?regions:[];
 f.events.get('livewire:navigated')[0]();
 assert.deepEqual(regions[0].attributes,{tabindex:'0',role:'region','aria-label':'Payment evidence'});
 assert.equal(regions[1].attributes['aria-label'],'Scrollable data table');
 assert.equal(regions[2].attributes['aria-label'],undefined);assert.equal(regions[2].attributes.tabindex,'-1');
});

test('unnamed Bootstrap modals use their visible title without changing explicit names',()=>{
 const f=fixture();const title={id:''};const attrs={};
 const modal={id:'servicesToolModal',querySelector:()=>title,setAttribute:(k,v)=>attrs[k]=v};
 f.doc.querySelectorAll=s=>s==='.modal[data-admin-title-label], .modal:not([aria-label]):not([aria-labelledby])'?[modal]:[];
 f.events.get('livewire:navigated')[0]();
 assert.equal(title.id,'servicesToolModal-title');assert.equal(attrs['aria-labelledby'],title.id);
 title.id='';f.events.get('livewire:navigated')[0]();assert.equal(title.id,'servicesToolModal-title');
 title.id='existing-title';f.events.get('livewire:navigated')[0]();assert.equal(attrs['aria-labelledby'],'existing-title');
 f.doc.querySelectorAll=()=>[];f.events.get('livewire:navigated')[0]();assert.equal(attrs['aria-labelledby'],'existing-title');
});
