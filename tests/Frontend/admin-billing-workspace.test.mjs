import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
const read = file => readFileSync(new URL('../../'+file, import.meta.url), 'utf8');

test('wide billing evidence remains scrollable with keyboard access and RTL-neutral wrapping', () => {
 const view=read('resources/views/components/admin-billing-table.blade.php');
 assert.match(view, /table-responsive admin-billing-table/);
 assert.match(view, /tabindex="0" role="region" aria-label=/);
 assert.match(view, /<bdi>/);
 const css=read('resources/css/admin.css');
 assert.match(css, /\.admin-billing-table td[^}]*overflow-wrap: anywhere/);
 assert.match(css, /\.admin-billing-table \.admin-billing-historical[^}]*border-inline-start/);
});

test('billing surfaces use existing navigation and never add direct reconciliation or retry calls', () => {
 const table=read('resources/views/components/admin-billing-table.blade.php');
 assert.match(table, /wire:navigate/);
 assert.match(table, /admin\.customers\.register/);
 assert.doesNotMatch(table, /wire:click|data-admin-method|<script|onclick|fetch\(/);
 const evidence=read('resources/views/components/admin-billing-evidence.blade.php');
 assert.match(evidence, /\$page->links\(\)/);
 assert.doesNotMatch(evidence, /payload|<script|wire:poll/);
});
