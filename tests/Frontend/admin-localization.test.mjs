import assert from 'node:assert/strict';
import {readFileSync, readdirSync, existsSync} from 'node:fs';
import path from 'node:path';
import test from 'node:test';
const root = path.resolve(import.meta.dirname, '../..');
const read = p => readFileSync(path.join(root,p),'utf8');
const files = directory => readdirSync(path.join(root,directory)).filter(f=>f.endsWith('.php')).map(f=>directory+'/'+f);
const pages = ['home','services','customers','payments','operations'].flatMap(area=>files('resources/views/admin/pages/'+area));
const sources = [...pages,...files('resources/views/admin/layouts'),...files('resources/views/admin/partials'),...files('resources/views/components').filter(f=>path.basename(f).startsWith('admin'))];
for (const file of [...sources]) {
 for (const match of read(file).matchAll(/(?:use\s+|Admin\\)(\w+)/g)) {
  const trait = 'app/Support/Admin/'+match[1]+'.php'; if(existsSync(path.join(root,trait))) sources.push(trait);
 }
}
sources.push('app/Support/Admin/InteractsWithCustomerAdmin.php','app/Support/Admin/InteractsWithPaymentAdmin.php');
const catalogs = Object.fromEntries(['en','ar','ku'].map(locale=>[locale,JSON.parse(read('resources/lang/admin/'+locale+'.json'))]));
const tokens = value => [...value.matchAll(/:[a-zA-Z_]+/g)].map(m=>m[0]).sort();
test('all literal scoped Admin JSON messages exist in EN AR KU with matching replacement tokens',()=>{
 const keys = new Set();
 for(const file of sources) {
  const text=read(file).replace(/{{--[\s\S]*?--}}/g,'');
  for(const m of text.matchAll(/(?:__|@lang)\(\s*'((?:\\.|[^'\\])*)'/g)) {
   const key=m[1].replace(/\\'/g,"'").replace(/\\\\/g,'\\');
   if(!/^(admin_p|validation\.)/.test(key)) keys.add(key);
  }
 }
 for(const key of keys) for(const locale of ['en','ar','ku']) {
  assert.ok(Object.hasOwn(catalogs[locale],key),`${locale}: ${key}`);
  assert.deepEqual(tokens(catalogs[locale][key]),tokens(key),`${locale} placeholders: ${key}`);
 }
});
test('scoped mutations have no native dialogs or wire confirmation directives',()=>{
 for(const file of pages) assert.doesNotMatch(read(file),/\b(?:confirm|alert|prompt)\s*\(|wire:confirm/,file);
});
test('all annotated actions preserve explicit method arguments without inline JavaScript',()=>{
 for(const file of pages) {
  const text=read(file);
  for(const match of text.matchAll(/data-admin-method="([^"]+)"/g)) {
   const surrounding=text.slice(Math.max(0,match.index-250),match.index+800);
   assert.match(surrounding,/data-admin-args=/,`${file}: ${match[1]}`);
   assert.match(surrounding,/data-admin-impact=/,`${file}: ${match[1]}`);
  }
 }
});
