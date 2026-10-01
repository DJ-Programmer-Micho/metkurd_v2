import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import {uploadSizeOptions} from '../../resources/js/v2-upload-size.js';

// Execute the shipped FilePond size plugin, not a copy of its comparison logic.
const sandbox = {module: {exports: {}}, exports: {}};
vm.runInNewContext(readFileSync(new URL('../../public/app/libs/filepond-plugin-file-validate-size/filepond-plugin-file-validate-size.min.js', import.meta.url), 'utf8'), sandbox);
const filters = {};
sandbox.module.exports({addFilter: (name, fn) => {filters[name] = fn;}, utils: {
    Type: {}, replaceInString: (text, values) => text.replace('{filesize}', values.filesize),
    toNaturalFileSize: (bytes, _, base) => `${bytes / base / base} MiB`,
}});

function contract(kib = 102400) {
    const options = uploadSizeOptions({dataset: {maxUploadKib: String(kib),
        sizeExceeded: 'File is too large', maxSizeLabel: `Maximum file size is ${kib / 1024} MiB`}});
    const query = key => ({GET_ALLOW_FILE_SIZE_VALIDATION: options.allowFileSizeValidation,
        GET_MAX_FILE_SIZE: options.maxFileSize, GET_MIN_FILE_SIZE: null,
        GET_MAX_TOTAL_FILE_SIZE: options.maxTotalFileSize, GET_FILE_SIZE_BASE: options.fileSizeBase,
        GET_LABEL_MAX_FILE_SIZE_EXCEEDED: options.labelMaxFileSizeExceeded,
        GET_LABEL_MAX_FILE_SIZE: options.labelMaxFileSize}[key]);
    return {options, query};
}

for (const mib of [95, 99, 100]) {
    test(`FilePond accepts ${mib} MiB via both drop and load validation`, async () => {
        const file = {size: mib * 1024 * 1024};
        const ctx = contract();
        assert.equal(filters.ALLOW_HOPPER_ITEM(file, ctx), true);
        assert.equal(await filters.LOAD_FILE(file, ctx), file);
    });
}

test('FilePond rejects boundary plus one byte with the actual binary limit in its error', async () => {
    const file = {size: 104857601};
    const ctx = contract();
    assert.equal(filters.ALLOW_HOPPER_ITEM(file, ctx), false);
    await assert.rejects(filters.LOAD_FILE(file, ctx), error =>
        error.status.main === 'File is too large' && error.status.sub === 'Maximum file size is 100 MiB');
});

test('reference uploads retain their separate 20 MiB boundary', async () => {
    const ctx = contract(20480);
    const file = {size: 20971520};
    assert.equal(await filters.LOAD_FILE(file, ctx), file);
    await assert.rejects(filters.LOAD_FILE({size: file.size + 1}, ctx));
});

test('missing or corrupt server size metadata cannot silently disable validation', () => {
    for (const maxUploadKib of [undefined, '', '100MB', '-1', '1.5', 'Infinity']) {
        assert.throws(() => uploadSizeOptions({dataset: {maxUploadKib}}));
    }
});
