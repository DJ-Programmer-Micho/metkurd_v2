import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const agreement = read('resources/views/admin/pages/customers/service-agreements.blade.php');
const expression = field => agreement.match(new RegExp('x-text="([^"]*\\$wire\\.' + field + '[^"]*)"'))[1];
test('agreement review uses plan allowances only for blank overrides and preserves explicit zero', () => {
    const context = {plans: {1: {app: 2000, api: 5000}, 2: {app: 4000, api: 8000}},
        $wire: {agreementPlanId: '1', agreementAppCredits: '', agreementApiCredits: ''}};
    assert.equal(vm.runInNewContext(expression('agreementAppCredits'), context), 2000);
    assert.equal(vm.runInNewContext(expression('agreementApiCredits'), context), 5000);
    context.$wire.agreementAppCredits = '0';
    context.$wire.agreementApiCredits = '75000';
    assert.equal(vm.runInNewContext(expression('agreementAppCredits'), context), '0');
    assert.equal(vm.runInNewContext(expression('agreementApiCredits'), context), '75000');
    context.$wire.agreementPlanId = '2';
    context.$wire.agreementAppCredits = '';
    assert.equal(vm.runInNewContext(expression('agreementAppCredits'), context), 4000);
    assert.equal(context.plans[2].api, 8000);
});
test('agreement review uses configured concurrency fallback without mutating action state', () => {
    const source = expression('agreementConcurrency').replace(/\{\{.*?\}\}/, '5');
    const $wire = {agreementConcurrency: ''};
    assert.equal(vm.runInNewContext(source, {$wire}), 5);
    assert.equal($wire.agreementConcurrency, '');
    $wire.agreementConcurrency = '2';
    assert.equal(vm.runInNewContext(source, {$wire}), '2');
});
test('customer views add no global listeners and keep review content bound to local text nodes', () => {
    for (const file of ['resources/views/components/admin-customer-overview.blade.php',
        'resources/views/admin/pages/customers/⚡adm-customers-register.blade.php',
        'resources/views/admin/pages/customers/service-agreements.blade.php']) {
        assert.doesNotMatch(read(file), /addEventListener|x-html|<script\b/);
    }
    assert.match(agreement, /x-text="\$wire\.agreementReference/);
    assert.match(agreement, /data-admin-method="recordServiceAgreement"/);
    assert.match(agreement, /data-admin-method="adjustServiceAgreement"/);
    const css = read('resources/css/admin.css');
    assert.match(css, /\.admin-customer-facts\s*\{[^}]*minmax/);
    assert.match(css, /\.admin-customer-review\s*\{[^}]*border-inline-start/);
    assert.match(css, /@media\(max-width:575\.98px\)[\s\S]*\.admin-customer-workspace/);
});
