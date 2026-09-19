export function installPurchaseRefresh(win = window, doc = document) {
    if (win.__v2PurchaseRefresh) return win.__v2PurchaseRefresh;
    let refreshing = false;
    const refresh = async () => {
        const page = doc.querySelector('[data-v2-purchase]');
        const id = page?.closest('[wire\\:id]')?.getAttribute('wire:id');
        if (!id || refreshing) return;
        refreshing = true;
        try {
            // A notification is only a hint: re-read the server's shared checkout guard.
            await win.Livewire?.find(id)?.$call('$refresh');
        } catch (_) {
            // The final purchase action always checks the guard again.
        } finally { refreshing = false; }
    };
    const key = 'metkurd:v2:checkout-changed';
    win.addEventListener('v2-checkout-changed', () => {
        try { win.localStorage.setItem(key, `${Date.now()}:${Math.random()}`); } catch (_) { /* Storage may be disabled. */ }
        void refresh();
    });
    win.addEventListener('storage', event => { if (event.key === key) void refresh(); });
    win.addEventListener('focus', () => void refresh());
    win.addEventListener('pageshow', event => { if (event.persisted) void refresh(); });
    doc.addEventListener('visibilitychange', () => { if (doc.visibilityState === 'visible') void refresh(); });
    win.__v2PurchaseRefresh = { refresh };
    return win.__v2PurchaseRefresh;
}

export async function initializeAccountPhone(root, phoneLibrary) {
    if (!root || !phoneLibrary) return;
    const config = JSON.parse(root.dataset.phoneConfig || '{}');
    await phoneLibrary.init({
        key: 'v2-profile-phone',
        inputSelector: '#profile_phone_number',
        hiddenPhoneSelector: '#profile_phone_number_hidden',
        hiddenCountrySelector: '#profile_phone_country_hidden',
        hiddenDialCodeSelector: '#profile_phone_dial_code_hidden',
        formSelector: '#profile-details-form',
        errorSelector: '#profile_phone_number_client_error',
        initialCountry: root.querySelector('#profile_phone_country_hidden')?.value || config.preferredCountries?.[0] || 'iq',
        onlyCountries: config.allowedCountries || [],
        preferredCountries: config.preferredCountries || [],
        invalidMessage: config.invalidMessage,
        assetErrorMessage: config.assetErrorMessage,
    });
}

if (typeof document !== 'undefined') {
    installPurchaseRefresh();
    (window.MetKurdV2Pages ||= []).push({key: 'account-phone', selector: '[data-v2-profile]', boot(ctx) {
        const initialize = () => initializeAccountPhone(ctx.root, window.MetIntlTelInput).catch(() => {
            if (!ctx.alive()) return;
            const error = ctx.root.querySelector('#profile_phone_number_client_error');
            if (error) { error.textContent = JSON.parse(ctx.root.dataset.phoneConfig || '{}').assetErrorMessage || ''; error.style.display = 'block'; }
        });
        ctx.listen(document, 'met:intl-tel-input-ready', initialize);
        initialize();
        return {update: initialize, destroy: () => window.MetIntlTelInput?.destroy('v2-profile-phone')};
    }});
}
