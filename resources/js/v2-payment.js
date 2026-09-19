export function countdownText(seconds) {
    const remaining = Math.max(0, Math.ceil(seconds));
    return `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`;
}

export function installPaymentUi(win = window, doc = document) {
    if (win.__v2PaymentUi) return win.__v2PaymentUi;
    let timer;
    let clock;
    let expiredKey;
    let refreshing = false;
    let generation = 0;
    const root = () => doc.querySelector('[data-v2-payment]');
    const recheck = async () => {
        const page = root();
        const id = page?.closest('[wire\\:id]')?.getAttribute('wire:id');
        if (!id || refreshing) return;
        refreshing = true;
        try {
            // Re-render owned server state only; never infer success or call a provider here.
            await win.Livewire?.find(id)?.$call('$refresh');
        } catch (_) {
            // The existing explicit status control remains available.
        } finally { refreshing = false; }
    };
    const tick = () => {
        const page = root();
        const countdown = page?.querySelector('[data-v2-countdown]');
        if (!countdown) { clock = null; return; }
        const expires = Number(countdown.dataset.expires);
        const serverNow = Number(countdown.dataset.serverNow);
        if (!Number.isFinite(expires) || !Number.isFinite(serverNow)) return;
        const signature = `${expires}:${serverNow}`;
        if (clock?.signature !== signature) clock = {signature, started: win.performance.now()};
        const remaining = expires - serverNow - (win.performance.now() - clock.started) / 1000;
        const time = countdown.querySelector('[data-v2-time]');
        if (time) time.textContent = remaining > 0 ? countdownText(remaining) : countdown.dataset.checking;
        if (remaining <= 0 && expiredKey !== expires) {
            expiredKey = expires;
            void recheck();
        }
    };
    const copy = async (button) => {
        const box = button.closest('.v2-payment-code-box');
        const code = box?.querySelector('[data-v2-payment-code]')?.textContent?.trim();
        const feedback = box?.querySelector('[data-v2-copy-feedback]');
        if (!code || button.disabled) return;
        button.disabled = true;
        const version = generation;
        try {
            await win.navigator.clipboard.writeText(code);
            if (version !== generation || !button.isConnected) return;
            button.textContent = button.dataset.copiedLabel;
            if (feedback) feedback.textContent = button.dataset.copiedLabel;
        } catch (_) {
            if (version === generation && button.isConnected && feedback) feedback.textContent = button.dataset.copyFailed;
        } finally {
            button.disabled = false;
            win.setTimeout(() => {
                if (version !== generation || !button.isConnected) return;
                button.textContent = button.dataset.copyLabel;
                if (feedback) feedback.textContent = '';
            }, 2000);
        }
    };
    const initialize = () => {
        if (timer) win.clearInterval(timer);
        if (root()) { tick(); timer = win.setInterval(tick, 1000); }
    };
    doc.addEventListener('click', event => {
        const button = event.target.closest('[data-v2-copy-code]');
        if (!button || !button.closest('[data-v2-payment]')) return;
        event.preventDefault();
        void copy(button);
    });
    const cleanup = () => {
        generation++;
        win.clearInterval(timer);
        timer = null; clock = null; expiredKey = null;
    };
    if (win.MetKurdV2Navigation) {
        win.MetKurdV2Navigation.register({key: 'payment-clock', selector: '[data-v2-payment]', boot() {
            initialize(); return {destroy: cleanup};
        }});
    } else {
        // Standalone embedding/tests also support the normal Livewire events.
        doc.addEventListener('livewire:navigating', cleanup);
        doc.addEventListener('livewire:navigated', initialize);
        doc.addEventListener('DOMContentLoaded', initialize);
    }
    doc.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'visible' && root()) { tick(); void recheck(); }
    });
    win.addEventListener('pageshow', event => { if (event.persisted && root()) { initialize(); void recheck(); } });
    const api = {tick, copy};
    win.__v2PaymentUi = api;
    initialize();
    return api;
}
if (typeof document !== 'undefined') installPaymentUi();
