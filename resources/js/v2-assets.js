import {uploadSizeOptions} from './v2-upload-size.js';

const loading = new Map();
const registered = new WeakMap();
const assets = {
    FilePond: '/app/libs/filepond/filepond.min.js',
    FilePondPluginFileValidateType: 'https://unpkg.com/filepond-plugin-file-validate-type@1/dist/filepond-plugin-file-validate-type.min.js',
    FilePondPluginFileValidateSize: '/app/libs/filepond-plugin-file-validate-size/filepond-plugin-file-validate-size.min.js',
    FilePondPluginImagePreview: '/app/libs/filepond-plugin-image-preview/filepond-plugin-image-preview.min.js',
    WaveSurfer: 'https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js',
};

export function loadAsset(name, win = window, doc = document) {
    if (win[name]) return Promise.resolve(win[name]);
    if (loading.has(name)) return loading.get(name);
    const promise = new Promise((resolve, reject) => {
        const script = doc.createElement('script');
        script.src = assets[name];
        script.dataset.navigateOnce = '';
        script.onload = () => win[name] ? resolve(win[name]) : reject(new Error(`Missing library: ${name}`));
        script.onerror = () => { script.remove(); loading.delete(name); reject(new Error(`Unable to load library: ${name}`)); };
        doc.head.append(script);
    });
    loading.set(name, promise);
    return promise;
}

export async function filePond(plugins = ['FilePondPluginFileValidateType', 'FilePondPluginFileValidateSize']) {
    const [pond, ...loaded] = await Promise.all(['FilePond', ...plugins].map(name => loadAsset(name)));
    let seen = registered.get(pond);
    if (!seen) { seen = new Set(); registered.set(pond, seen); }
    loaded.forEach(plugin => { if (!seen.has(plugin)) { pond.registerPlugin(plugin); seen.add(plugin); } });
    return pond;
}

export function disposePond(pond, win = window, doc = globalThis.document) {
    if (!pond) return;
    // FilePond's instance destroy event is deferred. Restore/unregister synchronously
    // before Livewire snapshots/removes its DOM, then release the instance resources.
    if (pond.element?.parentNode === null) doc.createDocumentFragment().append(pond.element);
    win.FilePond.destroy?.(pond.element);
    pond.destroy();
}

if (typeof window !== 'undefined') window.MetKurdV2Assets = {load: loadAsset, filePond, disposePond, uploadSizeOptions};
