const LANDING_BOOT_FLAG = '__METKURD_LANDING_BOOTED__';

function updateThemeIcon(theme) {
    document.querySelectorAll('#themeToggle i').forEach((icon) => {
        icon.className = theme === 'dark' ? 'bi bi-moon-stars' : 'bi bi-sun';
    });
}

function applyTheme(theme) {
    const nextTheme = theme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nextTheme);
    updateThemeIcon(nextTheme);
}

function initTheme() {
    let savedTheme = document.documentElement.getAttribute('data-theme') || 'dark';

    try {
        savedTheme = localStorage.getItem('theme') || savedTheme;
    } catch (error) {
        // Ignore storage access issues and keep the current theme.
    }

    applyTheme(savedTheme);

    if (window.__landingThemeBound) {
        return;
    }

    window.__landingThemeBound = true;

    document.addEventListener('click', (event) => {
        const button = event.target.closest('#themeToggle');

        if (!button) {
            return;
        }

        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

        applyTheme(nextTheme);

        try {
            localStorage.setItem('theme', nextTheme);
        } catch (error) {
            // Ignore storage access issues and keep the visual state.
        }
    });
}

function initReveal(scope = document) {
    const items = scope.querySelectorAll('.reveal');

    if (!items.length) {
        return;
    }

    const revealNow = (item) => {
        item.classList.remove('reveal-pending');
        item.classList.add('in-view');
    };

    const shouldRevealImmediately = (item) => {
        const rect = item.getBoundingClientRect();

        return rect.top <= window.innerHeight * 0.9 && rect.bottom >= 0;
    };

    if (
        !('IntersectionObserver' in window)
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        items.forEach((item) => revealNow(item));
        return;
    }

    if (!window.__landingRevealObserver) {
        window.__landingRevealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                revealNow(entry.target);
                window.__landingRevealObserver.unobserve(entry.target);
            });
        }, {
            threshold: 0.14,
        });
    }

    items.forEach((item) => {
        if (item.classList.contains('in-view')) {
            return;
        }

        if (shouldRevealImmediately(item)) {
            revealNow(item);
            return;
        }

        item.classList.add('reveal-pending');

        if (item.dataset.revealObserved === 'true') {
            return;
        }

        item.dataset.revealObserved = 'true';
        window.__landingRevealObserver.observe(item);
    });
}

function forceRevealVisible(scope = document) {
    scope.querySelectorAll('.reveal').forEach((item) => {
        item.classList.remove('reveal-pending');
        item.classList.add('in-view');
    });
}

function syncPricing(root) {
    const toggle = root.querySelector('[data-billing-checkbox]');

    if (!toggle) {
        return;
    }

    const yearly = toggle.checked;

    root.querySelectorAll('[data-monthly][data-yearly]').forEach((element) => {
        element.textContent = yearly
            ? element.dataset.yearly || element.dataset.monthly || ''
            : element.dataset.monthly || '';
    });

    root.querySelectorAll('[data-period]').forEach((element) => {
        const monthlyLabel = element.dataset.periodMonthly || '/month';
        const yearlyLabel = element.dataset.periodYearly || '/year';

        element.textContent = yearly ? yearlyLabel : monthlyLabel;
    });

    root.querySelectorAll('[data-billing-note]').forEach((element) => {
        const monthlyNote = element.dataset.billingNoteMonthly || '';
        const yearlyNote = element.dataset.billingNoteYearly || monthlyNote;

        element.textContent = yearly ? yearlyNote : monthlyNote;
    });
}

function initPricingToggle(scope = document) {
    scope.querySelectorAll('[data-pricing-root]').forEach((root) => {
        const toggle = root.querySelector('[data-billing-checkbox]');

        if (!toggle) {
            return;
        }

        if (toggle.dataset.billingReady !== 'true') {
            toggle.dataset.billingReady = 'true';
            toggle.addEventListener('change', () => syncPricing(root));
        }

        syncPricing(root);
    });
}

function bootLanding(scope = document) {
    initTheme();
    initReveal(scope);
    initPricingToggle(scope);
}

if (!window[LANDING_BOOT_FLAG]) {
    window[LANDING_BOOT_FLAG] = true;

    document.addEventListener('DOMContentLoaded', () => bootLanding(document));
    document.addEventListener('livewire:navigating', () => {
        forceRevealVisible(document);
    });
    document.addEventListener('livewire:navigated', () => {
        requestAnimationFrame(() => bootLanding(document));
    });

    if (window.Livewire && typeof window.Livewire.hook === 'function' && !window.__landingMorphHookBound) {
        window.__landingMorphHookBound = true;

        window.Livewire.hook('morphed', () => {
            requestAnimationFrame(() => bootLanding(document));
        });
    }
}
