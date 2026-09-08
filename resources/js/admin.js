import '../css/admin.css';

// A single delegated bridge survives Livewire page swaps; all labels come from
// the current Admin shell, never a locale captured on the first page.
export function installAdminUi(win = window, doc = document) {
    if (win.__metkurdAdminUi) return win.__metkurdAdminUi;
    const pending = new Set();
    let epoch = 0;
    const settings = () => doc.querySelector('[data-admin-ui]')?.dataset;
    const current = () => Boolean(settings());
    const message = (key) => settings()?.[key] || '';
    const popup = (options) => win.Swal?.fire({
        ...options, customClass: { popup: 'admin-confirm-popup' },
        didOpen: (element) => element.setAttribute('dir', doc.documentElement.dir || 'ltr'),
        returnFocus: false, allowOutsideClick: false,
    });
    const confirm = async (element) => {
        const owner = element.closest('[wire\\:id]');
        const id = owner?.getAttribute('wire:id');
        if (!id || pending.has(id) || !current() || element.disabled) return;
        if (element.tagName === 'FORM' && !element.reportValidity()) return;
        const version = epoch;
        const trigger = doc.activeElement;
        const modal = element.closest('.modal');
        const trap = modal && win.bootstrap?.Modal.getInstance(modal)?._focustrap;
        trap?.deactivate();
        pending.add(id);
        element.setAttribute('aria-busy', 'true');
        try {
            const context = element.closest('[data-admin-context]') || owner.querySelector('[data-admin-context]');
            const row = element.closest('tr');
            const target = element.dataset.adminTarget || context?.dataset.adminContext || row?.querySelector('td')?.textContent?.trim() || '';
            const review = element.tagName === 'FORM' ? element : element.closest('.card');
            const fields = review ? [...review.querySelectorAll('[data-admin-review]')].map(input => input.tagName === 'SELECT' ? input.selectedOptions[0]?.textContent : input.value).filter(Boolean).join(' · ') : '';
            const action = element.dataset.adminLabel || (element.tagName === 'FORM' ? element.querySelector('[type="submit"]')?.textContent : element.textContent)?.trim() || message('confirm');
            const result = await popup({ icon: 'warning', titleText: action,
                text: [target, fields, element.dataset.adminImpact].filter(Boolean).join('\n\n'),
                showCancelButton: true, confirmButtonText: message('confirm'), cancelButtonText: message('cancel'),
                focusCancel: true,
            });
            if (!result?.isConfirmed || version !== epoch || !element.isConnected || !current()) return;
            const component = win.Livewire?.find(id);
            if (!component) return;
            // Existing form state (including the locked P0 operation ID) is sent
            // unchanged. Only the explicit existing method/arguments are called.
            await component.$call(element.dataset.adminMethod, ...JSON.parse(element.dataset.adminArgs || '[]'));
        } catch (_) {
            if (version === epoch && current()) await popup({icon: 'error', titleText: message('failed'), confirmButtonText: message('close')});
        } finally {
            pending.delete(id);
            element.removeAttribute('aria-busy');
            if (version === epoch && modal?.classList.contains('show')) trap?.activate();
            if (version === epoch && trigger?.isConnected) trigger.focus();
        }
    };
    const intercept = (event) => {
        if (!current()) return;
        const element = event.type === 'submit' ? event.target.closest('form[data-admin-method]') : event.target.closest('[data-admin-method]:not(form)');
        if (!element) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void confirm(element);
    };
    doc.addEventListener('click', intercept, true);
    doc.addEventListener('submit', intercept, true);
    win.addEventListener('alert', (event) => {
        if (!current()) return;
        const payload = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        const detail = payload || {};
        if (detail.type === 'error') {
            void popup({icon: 'error', titleText: message('failed'), text: detail.message || '', confirmButtonText: message('close')});
        } else if (win.toastr) {
            win.toastr.options = {closeButton: true, progressBar: true, escapeHtml: true, rtl: doc.documentElement.dir === 'rtl', positionClass: doc.documentElement.dir === 'rtl' ? 'toast-top-left' : 'toast-top-right'};
            const type = ['success', 'info', 'warning'].includes(detail.type) ? detail.type : 'info';
            win.toastr[type](detail.message || '', detail.title || '');
        }
    });
    const dispose = () => {
        if (!current()) return;
        epoch++;
        if (current()) win.Swal?.close();
        doc.querySelectorAll('[data-bs-toggle="dropdown"], [data-bs-toggle="tooltip"], .modal').forEach(el => {
            win.bootstrap?.Dropdown.getInstance(el)?.dispose();
            win.bootstrap?.Tooltip.getInstance(el)?.dispose();
            win.bootstrap?.Modal.getInstance(el)?.dispose();
        });
        doc.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        doc.body.classList.remove('modal-open');
        doc.body.style.removeProperty('padding-right');
        doc.body.style.removeProperty('overflow');
    };
    const initialize = () => {
        if (!current()) return;
        doc.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => win.bootstrap?.Dropdown.getOrCreateInstance(el));
        doc.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => win.bootstrap?.Tooltip.getOrCreateInstance(el));
        // Associate existing Bootstrap labels and controls, including modal forms.
        doc.querySelectorAll('.page-content label.form-label:not([for])').forEach((label, index) => {
            const field = label.parentElement.querySelector('input:not([type="hidden"]), select, textarea');
            if (!field) return;
            field.id ||= `admin-field-${epoch}-${index}`;
            label.htmlFor = field.id;
        });
        doc.querySelectorAll('.page-content .pagination').forEach(list => list.closest('nav')?.setAttribute('aria-label', message('pagination')));
        win.feather?.replace();
    };
    doc.addEventListener('livewire:navigating', dispose);
    doc.addEventListener('livewire:navigated', initialize);
    doc.addEventListener('DOMContentLoaded', initialize);
    doc.addEventListener('livewire:initialized', () => {
        win.Livewire.hook('morphed', initialize);
    });
    win.changeLanguage = (locale) => {
        doc.getElementById('selectedLocale').value = locale;
        doc.getElementById('languageForm').submit();
    };
    const api = {confirm};
    win.__metkurdAdminUi = api;
    initialize();
    return api;
}
if (typeof window !== 'undefined') installAdminUi();
