import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const template = readFileSync('resources/views/errors/503.blade.php', 'utf8');
const translations = JSON.parse(readFileSync('resources/lang/maintenance.json', 'utf8'));
const scripts = [...template.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1].replace('@json($copy)', JSON.stringify(translations)));

test('one prerendered document selects locale, direction and exact landing/app/admin path segments', () => {
    for (const [path, locale, mode] of [
        ['/', 'en', 'landing'], ['/en/pricing', 'en', 'landing'], ['/ar', 'ar', 'landing'], ['/ku/tools', 'ku', 'landing'],
        ['/en/app-v2/ocr/scanner', 'en', 'app'], ['/ar/app-v2', 'ar', 'app'], ['/ku/app/home', 'ku', 'app'],
        ['/en/adm/home', 'en', 'app'], ['/adm/signin', 'en', 'app'], ['/app/home', 'en', 'app'],
        ['/en/app-v2-other', 'en', 'landing'], ['/fr/pricing', 'en', 'landing'],
    ]) {
        let reloads = 0, retry;
        const elements = Object.fromEntries(['maintenance-title', 'maintenance-message', 'maintenance-retry'].map(id => [id, {addEventListener: (_, fn) => {retry = fn;}}]));
        const copyNodes = Object.keys(translations.en).map(key => ({dataset: {copy: key}}));
        const document = {documentElement: {}, querySelectorAll: () => copyNodes, getElementById: id => elements[id]};
        const window = {location: {pathname: path, href: 'https://example.test'+path+'?keep=1', reload: () => reloads++}};
        const context = vm.createContext({document, window});
        scripts.forEach(script => vm.runInContext(script, context));
        assert.equal(document.documentElement.lang, locale);
        assert.equal(document.documentElement.dir, locale === 'en' ? 'ltr' : 'rtl');
        assert.equal(document.documentElement.className, mode+'-maintenance');
        assert.equal(elements['maintenance-title'].textContent, translations[locale][mode+'Title']);
        assert.equal(elements['maintenance-message'].textContent, translations[locale][mode+'Message']);
        assert.ok(copyNodes.every(node => typeof node.textContent === 'string' && node.textContent.length > 0));
        retry({preventDefault() {}});
        assert.equal(reloads, 1);
        assert.equal(window.location.href, 'https://example.test'+path+'?keep=1');
    }
});

test('maintenance copy has complete EN/AR/KU parity and scripts have no polling or application dependencies', () => {
    for (const locale of ['ar', 'ku']) assert.deepEqual(Object.keys(translations[locale]), Object.keys(translations.en));
    assert.doesNotMatch(scripts.join('\n'), /fetch\(|XMLHttpRequest|setInterval|Livewire|Alpine|bootstrap|localStorage/);
    assert.match(template, /name="robots" content="noindex,nofollow"/);
});
