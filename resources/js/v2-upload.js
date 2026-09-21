import {filePond, disposePond} from './v2-assets.js';

export function mountUpload(ctx, options, win = window) {
    let pond, field, uploading = false;
    const destroy = () => {
        if (uploading) ctx.component()?.cancelUpload(options.property);
        uploading = false;
        const old = pond;
        pond = null; field = null;
        if (old) disposePond(old, win, ctx.root.ownerDocument);
    };
    const update = () => {
        let input = ctx.root.querySelector(options.input);
        // FilePond replaces the input in the document with pond.element (same ID).
        if (pond && (!pond.element.isConnected || ![field, pond.element].includes(input))) {
            destroy();
            input = ctx.root.querySelector(options.input);
        }
        if (!input || pond || !ctx.component()) return;
        field = input;
        const existing = win.FilePond.find(input);
        if (existing) disposePond(existing, win, ctx.root.ownerDocument);
        pond = win.FilePond.create(input, {
            allowMultiple: false, credits: false,
            acceptedFileTypes: ['audio/wav','audio/x-wav','audio/mpeg','audio/mp3','audio/mp4','audio/x-m4a','audio/aac','audio/ogg','audio/webm', ...(options.flac ? ['audio/flac','audio/x-flac'] : [])],
            maxFileSize: options.maxSize,
            ...(input.dataset.labelIdle ? {labelIdle: input.dataset.labelIdle} : {}),
            onaddfile: (error, item) => { if (!error && ctx.alive()) options.preview?.set(item.file); },
            onremovefile: () => { if (ctx.alive()) options.preview?.destroy(); },
            server: {
                process: (_, file, __, load, error, progress, abort) => {
                    const lw = ctx.component();
                    if (!lw) { error(input.dataset.uploadError || 'Upload failed'); return {abort}; }
                    uploading = true;
                    lw.upload(options.property, file,
                        token => { uploading = false; if (ctx.alive()) load(token); },
                        () => { uploading = false; if (ctx.alive()) error(input.dataset.uploadError || 'Upload failed'); },
                        event => { if (ctx.alive()) progress(event.lengthComputable, event.loaded, event.total); });
                    return {abort: () => { uploading = false; lw.cancelUpload(options.property); abort(); }};
                },
                revert: (_, load, error) => {
                    const lw = ctx.component();
                    if (!lw) { error(input.dataset.uploadError || 'Upload failed'); return; }
                    Promise.resolve(lw.call(options.remove)).then(() => { if (ctx.alive()) load(); }, () => { if (ctx.alive()) error(input.dataset.uploadError || 'Upload failed'); });
                },
            },
        });
    };
    ctx.on(options.clear, () => { options.preview?.destroy(); pond?.removeFiles({revert: false}); });
    // Capture the owner before navigation invalidates the context.
    const owner = ctx.component();
    ctx.cleanup(() => { if (uploading) owner?.cancelUpload(options.property); uploading = false; });
    update();
    return {update: () => { update(); options.preview?.sync(); }, destroy: () => { destroy(); options.preview?.destroy(); }};
}

export function mountThetaUpload(ctx, win = window) {
    return mountUpload(ctx, {input: '#v2-theta-reference-pond', property: 'referenceAudio', maxSize: '20MB', remove: 'removeReferenceAudio', clear: 'theta-reference-audio-cleared'}, win);
}

if (typeof window !== 'undefined') {
    const register = definition => (window.MetKurdV2Pages ||= []).push(definition);
    register({key: 'theta-upload', selector: '.v2-theta-workspace', prepare: filePond,
        boot: ctx => mountThetaUpload(ctx)});
    register({key: 'vector-upload', selector: '.v2-ctts-workspace', prepare: filePond,
        boot: ctx => mountUpload(ctx, {input: '#v2-ctts-reference-pond', property: 'referenceAudio', maxSize: '20MB', remove: 'removeReferenceAudio', clear: 'ctts-reference-audio-cleared'})});
    for (const kind of ['leo', 'caption']) {
        register({key: `${kind}-upload`, selector: `.v2-${kind}-page`, prepare: filePond, boot(ctx) {
            let objectUrl = null;
            const preview = {
                set(file) { this.destroy(); objectUrl = URL.createObjectURL(file); this.sync(); },
                destroy() { window.MetKurdWaveform?.destroy(`${kind}-upload`); if (objectUrl) URL.revokeObjectURL(objectUrl); objectUrl = null; },
                sync() { const wave = ctx.root.querySelector(`[data-${kind}-upload-waveform]`); if (ctx.alive() && wave && objectUrl) { wave.dataset.url = objectUrl; window.MetKurdWaveform?.mount(); } },
            };
            ctx.on(kind === 'leo' ? 'leo-copy-transcript' : 'caption-copy-result', event => navigator.clipboard?.writeText(event.text || ''));
            return mountUpload(ctx, {input: `#v2-${kind}-audio-pond`, property: 'audioFile', maxSize: '100MB', flac: true, remove: 'removeAudio', clear: `${kind}-audio-cleared`, preview});
        }});
    }
}
