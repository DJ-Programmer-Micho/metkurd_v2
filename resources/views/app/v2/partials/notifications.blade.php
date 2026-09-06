<script>
    (() => {
    // Refresh translated settings when Livewire navigation changes the locale.
    window.metkurdV2Alerts = {
        fallback: @js(__('Processing could not be completed. Please try again.')),
        close: @js(__('Close')),
        confirm: @js(__('Confirm')),
        cancel: @js(__('Cancel')),
        expired: @js(__('Your session has expired. Reload this page to continue.')),
    };
    window.metkurdV2Confirm = async message => {
        if (!window.Swal) return false;
        const settings = window.metkurdV2Alerts;
        const result = await window.Swal.fire({
            icon: 'warning', text: /runpod/i.test(String(message)) ? settings.fallback : String(message),
            showCancelButton: true, confirmButtonText: settings.confirm, cancelButtonText: settings.cancel,
            focusCancel: true, reverseButtons: document.documentElement.dir === 'rtl',
            didOpen: popup => popup.setAttribute('dir', document.documentElement.dir || 'ltr'),
        });
        return result.isConfirmed;
    };
    if (!window.__METKURD_V2_ALERT_BOUND__) {
        window.__METKURD_V2_ALERT_BOUND__ = true;
        const approved = new WeakSet();
        let confirmationOpen = false;
        document.addEventListener('click', async event => {
            const button = event.target.closest('[data-v2-confirm]');
            if (!button || !document.body.classList.contains('metkurd-v2')) return;
            if (approved.has(button)) { approved.delete(button); return; }
            event.preventDefault();
            event.stopImmediatePropagation();
            if (confirmationOpen) return;
            confirmationOpen = true;
            try {
                if (await window.metkurdV2Confirm(button.dataset.v2Confirm) && button.isConnected) {
                    approved.add(button);
                    button.click();
                }
            } finally { confirmationOpen = false; }
        }, true);
        window.addEventListener('alert', event => {
            if (!document.body.classList.contains('metkurd-v2') || !window.Swal) return;
            const detail = Array.isArray(event.detail) ? (event.detail[0] || {}) : (event.detail || {});
            const rawMessage = String(detail.message || '');
            const settings = window.metkurdV2Alerts;
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: ['success', 'error', 'warning', 'info', 'question'].includes(detail.type) ? detail.type : 'info',
                text: /runpod/i.test(rawMessage) ? settings.fallback : rawMessage,
                showConfirmButton: false,
                showCloseButton: true,
                closeButtonAriaLabel: settings.close,
                timer: 5000,
                timerProgressBar: true,
                didOpen: popup => popup.setAttribute('dir', document.documentElement.dir || 'ltr'),
            });
        });
    }
    const bindV2RequestErrors = () => {
        if (!window.Livewire || window.__METKURD_V2_REQUEST_ERRORS_BOUND__) return;
        window.__METKURD_V2_REQUEST_ERRORS_BOUND__ = true;
        window.Livewire.hook('request', ({ fail }) => fail(({ status, preventDefault }) => {
            if (!document.body.classList.contains('metkurd-v2') || status === 422) return;
            preventDefault();
            if (status === 419) {
                window.metkurdV2Confirm(window.metkurdV2Alerts.expired).then(ok => { if (ok) window.location.reload(); });
            } else {
                window.dispatchEvent(new CustomEvent('alert', { detail: { type: 'error', message: window.metkurdV2Alerts.fallback } }));
            }
        }));
    };
    bindV2RequestErrors();
    document.addEventListener('livewire:init', bindV2RequestErrors, { once: true });
    })();
</script>
