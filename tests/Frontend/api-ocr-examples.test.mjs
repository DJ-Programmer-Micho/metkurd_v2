import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const php = process.env.PHP_BINARY || 'php';
// Generate actual documentation without booting Laravel or connecting to a database.
const generated = spawnSync(php, ['-r', `
function url($path) { return 'https://metkurd.example.test'.$path; }
require 'vendor/autoload.php';
$docs = new App\\Services\\CustomerApi\\V2\\ApiDocumentation;
echo json_encode($docs->examples($docs->services()['ocr']), JSON_THROW_ON_ERROR);
`], {encoding: 'utf8'});
assert.equal(generated.status, 0, generated.stderr);
const examples = JSON.parse(generated.stdout);

test('generated Node OCR example uploads local bytes and repeated export fields', async () => {
    const source = Buffer.from('%PDF-local-test-document');
    let calls = 0;
    const code = examples.JavaScript.replace("import { readFile } from 'node:fs/promises';", '');
    await vm.runInNewContext(`(async () => {${code}})()`, {
        FormData, Blob, console: {log() {}},
        readFile: async path => { assert.equal(path, './document.pdf'); return source; },
        fetch: async (url, request) => {
            calls++;
            assert.equal(url, 'https://metkurd.example.test/api/v2/ocr');
            assert.equal(request.method, 'POST');
            assert.deepEqual(request.body.getAll('exports[]'), ['txt', 'docx']);
            assert.equal(request.body.get('pages'), '1');
            assert.equal(request.body.get('intelligent'), '1');
            assert.equal(request.body.has('file_url'), false);
            assert.equal(Object.keys(request.headers).some(key => key.toLowerCase() === 'content-type'), false);
            const file = request.body.get('file');
            assert.equal(file.name, 'document.pdf');
            assert.equal(file.type, 'application/pdf');
            assert.deepEqual(Buffer.from(await file.arrayBuffer()), source);
            return {status: 202, json: async () => ({id: 'job_fixture'})};
        },
    });
    assert.equal(calls, 1);
});

test('generated PHP OCR example has valid PHP syntax', () => {
    const lint = spawnSync(php, ['-l'], {input: examples.PHP, encoding: 'utf8'});
    assert.equal(lint.status, 0, lint.stdout + lint.stderr);
});

test('OCR language selection is local to its panel, including Windows PowerShell', () => {
    const view = readFileSync(new URL('../../resources/views/app/v2/partials/api-ocr-examples.blade.php', import.meta.url), 'utf8');
    assert.ok(view.includes('x-data="{ ocrLanguage: \'cURL\' }"'));
    assert.ok(view.includes('x-model="ocrLanguage"'));
    assert.ok(view.includes('<option>PowerShell</option>'));
    assert.ok(view.includes('x-show="ocrLanguage === @js($language)"'));
});
