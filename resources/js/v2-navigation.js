export const serviceColors = Object.freeze({
    'text-to-speech': '#93c5fd', 'clone-text-to-speech': '#fd9393',
    'speech-to-text': '#86efac', ocr: '#7dd3fc', stem: '#fdba74',
    storage: '#93c5fd', profile: '#93c5fd', 'my-billing': '#93c5fd', payments: '#93c5fd',
    'subscription-plans': '#93c5fd', 'storage-plans': '#93c5fd', 'addon-credits': '#93c5fd', api: '#93c5fd',
});

export function progressColor(url, base = 'http://localhost') {
    const parts = new URL(url, base).pathname.split('/');
    const index = parts.indexOf('app-v2');
    return index < 0 ? '#2299dd' : serviceColors[parts[index + 1]] || '#2299dd';
}

// One owner for navigation, morph reconciliation, and component-scoped cleanup.
export function installNavigation(win = window, doc = document) {
    if (win.MetKurdV2Navigation) return win.MetKurdV2Navigation;
    const definitions = new Map(), mounted = new Map();
    let navigating = false, frame = null, hooked = false;
    const color = url => doc.documentElement.style.setProperty('--livewire-progress-bar-color', progressColor(url, win.location.href));
    const dispose = key => {
        const entry = mounted.get(key);
        if (!entry) return;
        mounted.delete(key);
        entry.active = false;
        entry.cleanups.reverse().forEach(cleanup => cleanup());
        entry.controller?.destroy?.();
    };
    const reconcile = () => {
        if (navigating) return;
        for (const [key, definition] of definitions) {
            const root = doc.querySelector(definition.selector);
            let entry = mounted.get(key);
            if (entry && (entry.root !== root || !entry.root.isConnected)) { dispose(key); entry = null; }
            if (!root || !win.Livewire) continue;
            if (entry) { entry.controller?.update?.(); continue; }
            const owner = root.closest('[wire\\:id]');
            if (owner && !win.Livewire.find(owner.getAttribute('wire:id'))) continue;
            entry = {root, active: true, cleanups: []};
            mounted.set(key, entry);
            const alive = () => entry.active && root.isConnected;
            const context = {
                root, alive,
                component: () => alive() && owner ? win.Livewire.find(owner.getAttribute('wire:id')) : null,
                cleanup: callback => entry.cleanups.push(callback),
                listen(target, event, callback, options) {
                    const scoped = (...args) => { if (alive()) return callback(...args); };
                    target?.addEventListener(event, scoped, options);
                    entry.cleanups.push(() => target?.removeEventListener(event, scoped, options));
                },
                on(event, callback) {
                    const off = win.Livewire.on(event, (...args) => { if (alive()) return callback(...args); });
                    if (typeof off === 'function') entry.cleanups.push(off);
                },
            };
            Promise.resolve(definition.prepare?.()).then(() => {
                if (!alive()) return;
                entry.controller = definition.boot(context);
                delete root.dataset.v2BootError;
            }).catch(error => {
                if (alive()) {
                    root.dataset.v2BootError = key;
                    entry.cleanups.splice(0).reverse().forEach(cleanup => cleanup());
                }
                console.error(`V2 page setup failed (${key})`, error);
            });
        }
    };
    const schedule = () => {
        if (frame !== null || navigating) return;
        frame = win.requestAnimationFrame(() => { frame = null; reconcile(); });
    };
    const bind = () => {
        if (!win.Livewire || hooked) return;
        hooked = true;
        win.Livewire.hook('morphed', schedule);
        win.Livewire.hook('morph.removed', schedule);
        schedule();
    };
    const register = definition => {
        if (!definitions.has(definition.key)) definitions.set(definition.key, definition);
        bind(); schedule();
    };
    doc.addEventListener('livewire:navigate', event => color(event.detail?.url || win.location.href));
    doc.addEventListener('livewire:navigating', () => {
        navigating = true;
        if (frame !== null) win.cancelAnimationFrame(frame);
        frame = null;
        [...mounted.keys()].forEach(dispose);
    });
    doc.addEventListener('livewire:navigated', () => { navigating = false; color(win.location.href); bind(); schedule(); });
    doc.addEventListener('DOMContentLoaded', () => { bind(); schedule(); }, {once: true});
    doc.addEventListener('livewire:initialized', () => { bind(); schedule(); });
    const api = {register, refresh: schedule};
    win.MetKurdV2Navigation = api;
    const queued = win.MetKurdV2Pages || [];
    win.MetKurdV2Pages = {push: register};
    queued.forEach(register);
    color(win.location.href); bind(); schedule();
    return api;
}

if (typeof document !== 'undefined') installNavigation();
