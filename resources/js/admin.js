import '../css/admin.css';

// A single delegated bridge survives Livewire page swaps; all labels come from
// the current Admin shell, never a locale captured on the first page.
export function installAdminUi(win = window, doc = document) {
    if (win.__metkurdAdminUi) return win.__metkurdAdminUi;
    const pending = new Set();
    const modalTriggers = new WeakMap();
    const drawerBackground = new Map();
    let epoch = 0;
    let requestFailures = 0;
    const settings = () => doc.querySelector('[data-admin-ui]')?.dataset;
    const current = () => Boolean(settings());
    const message = (key) => settings()?.[key] || '';
    const mobile = () => win.matchMedia?.('(max-width: 991.98px)').matches ?? false;
    const sidebarState = () => {
        if (!current()) return;
        const open = mobile() ? doc.body.classList.contains('admin-sidebar-open') : !doc.body.classList.contains('admin-sidebar-hidden');
        doc.querySelector('[data-admin-sidebar-toggle]')?.setAttribute?.('aria-expanded', String(open));
        const sidebar = doc.getElementById?.('admin-sidebar');
        if (sidebar) {
            sidebar.inert = !open;
            if (mobile() && open) {
                sidebar.setAttribute?.('role', 'dialog');
                sidebar.setAttribute?.('aria-modal', 'true');
            } else {
                sidebar.removeAttribute?.('role');
                sidebar.removeAttribute?.('aria-modal');
            }
        }
        if (mobile() && open) {
            doc.querySelectorAll('[data-admin-drawer-background]').forEach(element => {
                if (!drawerBackground.has(element)) drawerBackground.set(element, element.inert);
                element.inert = true;
            });
        } else {
            drawerBackground.forEach((inert, element) => { element.inert = inert; });
            drawerBackground.clear();
        }
    };
    const closeSidebar = (focus = false) => {
        doc.body.classList.remove('admin-sidebar-open');
        sidebarState();
        if (focus) doc.querySelector('[data-admin-sidebar-toggle]')?.focus?.();
    };
    const syncNavigation = () => {
        if (!win.location) return;
        const url = new URL(win.location.href);
        doc.querySelectorAll('[data-admin-nav]').forEach(link => {
            const target = new URL(link.href);
            const active = target.pathname === url.pathname && (!target.searchParams.has('section') || target.searchParams.get('section') === (url.searchParams.get('section') || 'jobs'));
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'page');
                const system = link.closest('details');
                if (system) system.open = true;
                doc.querySelectorAll('[data-admin-page-context]').forEach(el => el.textContent = link.textContent.trim());
                doc.querySelectorAll('[data-admin-page-group]').forEach(el => el.textContent = link.dataset.adminGroup);
            } else link.removeAttribute('aria-current');
        });
    };
    const shellClick = event => {
        if (!current()) return;
        if (event.target.closest('[data-admin-sidebar-toggle]')) {
            doc.body.classList.toggle(mobile() ? 'admin-sidebar-open' : 'admin-sidebar-hidden');
            sidebarState();
            if (mobile() && doc.body.classList.contains('admin-sidebar-open')) doc.getElementById('admin-sidebar')?.querySelector('button, a')?.focus();
        }
        if (event.target.closest('[data-admin-sidebar-close]')) closeSidebar(true);
        const locale = event.target.closest('[data-admin-locale]')?.dataset.adminLocale;
        if (['en', 'ar', 'ku'].includes(locale)) win.changeLanguage(locale);
    };
    doc.addEventListener('keydown', event => {
        if (!current() || !mobile() || !doc.body.classList.contains('admin-sidebar-open')) return;
        if (event.key === 'Escape') closeSidebar(true);
        if (event.key === 'Tab') {
            const elements = [...doc.getElementById('admin-sidebar').querySelectorAll('a, button, summary, [tabindex="0"]')].filter(el => el.getClientRects().length);
            const first = elements[0], last = elements.at(-1);
            if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    win.matchMedia?.('(max-width: 991.98px)').addEventListener('change', () => closeSidebar());
    win.addEventListener('popstate', () => { if (current()) syncNavigation(); });
    const popup = async (options, restoreFocus = true) => {
        const version = epoch;
        const trigger = doc.activeElement;
        const modal = doc.querySelector('.modal.show');
        const trap = modal && win.bootstrap?.Modal.getInstance?.(modal)?._focustrap;
        trap?.deactivate();
        try {
            return await win.Swal?.fire({
                ...options, customClass: { popup: 'admin-confirm-popup' },
                didOpen: (element) => element.setAttribute('dir', doc.documentElement.dir || 'ltr'),
                returnFocus: false, allowOutsideClick: false,
            });
        } finally {
            if (restoreFocus && version === epoch && current()) {
                if (modal?.classList?.contains('show')) trap?.activate();
                if (trigger?.isConnected) trigger.focus?.();
            }
        }
    };
    const confirm = async (element) => {
        const owner = element.closest('[wire\\:id]');
        const id = owner?.getAttribute('wire:id');
        if (!id || pending.has(id) || !current() || element.disabled) return;
        if (element.tagName === 'FORM' && !element.reportValidity()) return;
        const version = epoch;
        const failureVersion = requestFailures;
        const trigger = doc.activeElement;
        let validationFailed = false;
        let validationSummary;
        const modal = element.closest('.modal');
        const trap = modal && win.bootstrap?.Modal.getInstance(modal)?._focustrap;
        trap?.deactivate();
        pending.add(id);
        element.setAttribute('aria-busy', 'true');
        try {
            const context = element.closest('[data-admin-context]') || owner.querySelector('[data-admin-context]');
            const row = element.closest('tr');
            const target = element.dataset.adminTarget || context?.dataset.adminContext || row?.querySelector('td')?.textContent?.trim() || '';
            const review = element.tagName === 'FORM' ? element : (modal || element.closest('.card'));
            const fields = review ? [...review.querySelectorAll('[data-admin-review]')].map(input => input.tagName === 'SELECT' ? input.selectedOptions[0]?.textContent : input.value).filter(Boolean).join(' · ') : '';
            const action = element.dataset.adminLabel || (element.tagName === 'FORM' ? element.querySelector('[type="submit"]')?.textContent : element.textContent)?.trim() || message('confirm');
            const reasonField = element.dataset.adminReasonField || owner.querySelector('span[data-admin-reason-field]')?.dataset.adminReasonField;
            const result = await popup({ icon: 'warning', titleText: action,
                text: [target, fields, element.dataset.adminImpact].filter(Boolean).join('\n\n'),
                showCancelButton: true, confirmButtonText: message('confirm'), cancelButtonText: message('cancel'),
                focusCancel: true,
                ...(reasonField ? {input: 'textarea', inputLabel: message('reason'), inputValue: '',
                    inputAttributes: {minlength: '10', maxlength: '500', dir: 'auto'},
                    inputValidator: value => value.trim().length >= 10 && value.trim().length <= 500 ? undefined : message('reasonRequired'),
                } : {}),
            }, false);
            if (!result?.isConfirmed || version !== epoch || !element.isConnected || !current()) return;
            const component = win.Livewire?.find(id);
            if (!component) return;
            if (reasonField) {
                if (typeof result.value !== 'string' || result.value.trim().length < 10 || result.value.trim().length > 500) return;
                component.$set(reasonField, result.value.trim(), false);
            }
            // Existing form state (including the locked P0 operation ID) is sent
            // unchanged. Only the explicit existing method/arguments are called.
            await component.$call(element.dataset.adminMethod, ...JSON.parse(element.dataset.adminArgs || '[]'));
            const errors = (modal || owner).querySelector('.admin-validation-summary');
            if (errors) {
                validationFailed = true;
                validationSummary = errors;
                errors.scrollIntoView?.({block: 'nearest'});
            }
        } catch (_) {
            if (version === epoch && current() && failureVersion === requestFailures) await popup({icon: 'error', titleText: message('failed'), confirmButtonText: message('close')}, false);
        } finally {
            pending.delete(id);
            element.removeAttribute('aria-busy');
            if (version === epoch && !win.Swal?.isVisible?.()) {
                if (modal?.classList.contains('show')) trap?.activate();
                if (validationFailed) validationSummary?.focus?.();
                else if (trigger?.isConnected) trigger.focus();
            }
        }
    };
    const intercept = (event) => {
        if (!current()) return;
        if (event.type === 'click') shellClick(event);
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
    // Existing service/plan events share the shell lifecycle instead of installing
    // persistent listeners and navigation cleanup in each page.
    for (const prefix of ['admin', 'services-tools', 'services-entitlements', 'services-pricing', 'services-voices', 'payments-plans', 'customers-list', 'payments-addons', 'payments-storage', 'payments-coupons', 'payments-methods', 'payments-currencies', 'landing-contact', 'landing-tools']) {
        for (const action of ['show', 'hide']) {
            win.addEventListener(`${prefix}:modal-${action}`, event => {
                if (!current()) return;
                const modal = doc.getElementById(event.detail?.id);
                if (modal?.classList.contains('modal')) {
                    if (action === 'show' && !modal.classList.contains('show')) modalTriggers.set(modal, {trigger: doc.activeElement, version: epoch});
                    win.bootstrap?.Modal.getOrCreateInstance(modal)?.[action]();
                }
            });
        }
    }
    doc.addEventListener('hidden.bs.modal', event => {
        const state = modalTriggers.get(event.target);
        modalTriggers.delete(event.target);
        if (state?.version !== epoch || !current()) return;
        if (state.trigger?.isConnected) state.trigger.focus?.();
        else doc.getElementById?.('admin-main')?.focus?.();
    });
    const dispose = () => {
        if (!current()) return;
        epoch++;
        closeSidebar();
        if (current()) win.Swal?.close();
        doc.querySelectorAll('[data-bs-toggle="dropdown"], [data-bs-toggle="tooltip"], .modal').forEach(el => {
            win.bootstrap?.Dropdown.getInstance(el)?.dispose();
            win.bootstrap?.Tooltip.getInstance(el)?.dispose();
            win.bootstrap?.Modal.getInstance(el)?.dispose();
            if (el.classList.contains('modal')) {
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.removeAttribute('aria-modal');
                el.removeAttribute('role');
            }
        });
        doc.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        doc.body.classList.remove('modal-open');
        doc.body.style.removeProperty('padding-right');
        doc.body.style.removeProperty('overflow');
    };
    const initialize = () => {
        if (!current()) return;
        sidebarState();
        syncNavigation();
        win.requestAnimationFrame?.(() => { if (current()) syncNavigation(); });
        doc.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => win.bootstrap?.Dropdown.getOrCreateInstance(el));
        doc.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => win.bootstrap?.Tooltip.getOrCreateInstance(el));
        doc.querySelectorAll('.modal-dialog').forEach(el => el.classList.add('modal-dialog-scrollable'));
        // wire:ignore.self retains dialog attributes while Livewire replaces its title.
        doc.querySelectorAll('.modal[data-admin-title-label], .modal:not([aria-label]):not([aria-labelledby])').forEach((modal, index) => {
            const title = modal.querySelector('.modal-title');
            if (!title) return;
            title.id ||= `${modal.id || `admin-modal-${epoch}-${index}`}-title`;
            modal.setAttribute('aria-labelledby', title.id);
            modal.setAttribute('data-admin-title-label', '');
        });
        // Associate existing Bootstrap labels and controls, including modal forms.
        doc.querySelectorAll('.page-content label.form-label:not([for])').forEach((label, index) => {
            const field = label.parentElement.querySelector('input:not([type="hidden"]), select, textarea');
            if (!field) return;
            field.id ||= `admin-field-${epoch}-${index}`;
            label.htmlFor = field.id;
        });
        doc.querySelectorAll('.page-content .pagination').forEach(list => list.closest('nav')?.setAttribute('aria-label', message('pagination')));
        doc.querySelectorAll('.page-content .table-responsive').forEach(region => {
            if (!region.hasAttribute('tabindex')) region.setAttribute('tabindex', '0');
            if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
            if (!region.hasAttribute('aria-label') && !region.hasAttribute('aria-labelledby')) {
                region.setAttribute('aria-label', region.querySelector('caption')?.textContent.trim() || message('tableRegion'));
            }
        });
        win.feather?.replace();
    };
    doc.addEventListener('livewire:navigating', dispose);
    doc.addEventListener('livewire:navigated', initialize);
    doc.addEventListener('DOMContentLoaded', initialize);
    doc.addEventListener('livewire:initialized', () => {
        win.Livewire.hook('morphed', initialize);
        win.Livewire.hook('request', ({fail}) => fail(({status, preventDefault}) => {
            if (!current()) return;
            requestFailures++;
            preventDefault();
            void popup({icon: 'error', titleText: status === 403 ? message('forbidden') : message('failed'), confirmButtonText: message('close')});
        }));
    });
    win.changeLanguage = (locale) => {
        doc.getElementById('selectedLocale').value = locale;
        doc.getElementById('languageForm').submit();
    };
    const api = {confirm, syncNavigation, sidebarState};
    win.__metkurdAdminUi = api;
    initialize();
    return api;
}
if (typeof window !== 'undefined') installAdminUi();
