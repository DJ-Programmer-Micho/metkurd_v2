// Laravel file limits are KiB. Pass integer bytes to FilePond; its "MB" parser
// uses decimal units independently of the fileSizeBase display option.
export function uploadSizeOptions(input) {
    const kib = Number(input.dataset.maxUploadKib);
    const bytes = kib * 1024;
    if (!Number.isSafeInteger(kib) || kib <= 0 || !Number.isSafeInteger(bytes)) {
        throw new Error('Missing or invalid V2 upload size contract');
    }
    return {
        allowFileSizeValidation: true,
        maxFileSize: bytes,
        maxTotalFileSize: null,
        fileSizeBase: 1024,
        labelFileSizeKilobytes: 'KiB',
        labelFileSizeMegabytes: 'MiB',
        labelFileSizeGigabytes: 'GiB',
        labelMaxFileSizeExceeded: input.dataset.sizeExceeded,
        labelMaxFileSize: input.dataset.maxSizeLabel,
    };
}
