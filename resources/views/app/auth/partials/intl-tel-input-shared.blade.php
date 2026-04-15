@once
    @push('styles')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/css/intlTelInput.css">
        <style>
            .iti {
                width: 100%;
                display: block;
            }

            .iti input {
                width: 100% !important;
            }

            .iti.iti--container {
                z-index: 20000 !important;
                pointer-events: auto !important;
            }

            .iti.iti--container * {
                pointer-events: auto !important;
            }

            .iti__dropdown-content {
                background: #111827 !important;
                border: 1px solid rgba(255, 255, 255, 0.08) !important;
                border-radius: 14px !important;
                box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45) !important;
                color: #e5e7eb !important;
            }

            .iti .iti__selected-dial-code {
                margin-right: 4px;
            }

            .iti__country-list {
                background: #111827 !important;
                color: #e5e7eb !important;
                max-height: min(320px, calc(100vh - 120px)) !important;
                overflow-y: auto !important;
                overscroll-behavior: contain;
            }

            .iti__country {
                padding: 10px 12px !important;
                transition: background-color .18s ease, color .18s ease;
            }

            .iti__country:hover {
                background: rgba(255, 255, 255, 0.06) !important;
            }

            .iti__country.iti__highlight,
            .iti__country.iti__active {
                background: rgba(204, 0, 34, 0.18) !important;
                color: #ffffff !important;
            }

            .iti__country-name {
                color: #f3f4f6 !important;
            }

            .iti__dial-code {
                color: #9ca3af !important;
            }

            .iti__country.iti__highlight .iti__dial-code,
            .iti__country:hover .iti__dial-code {
                color: #d1d5db !important;
            }

            .iti__search-input {
                background: #0f172a !important;
                border: 1px solid rgba(255, 255, 255, 0.08) !important;
                color: #f9fafb !important;
                border-radius: 10px !important;
                padding: 10px 12px !important;
                outline: none !important;
                box-shadow: none !important;
            }

            .iti__search-input::placeholder {
                color: #6b7280 !important;
            }

            .iti__search-input:focus {
                border-color: rgba(204, 0, 34, 0.55) !important;
                box-shadow: 0 0 0 3px rgba(204, 0, 34, 0.15) !important;
            }

            .iti__selected-country,
            .iti__selected-country-primary {
                background: #1f2937 !important;
                border-right: 1px solid rgba(255, 255, 255, 0.06);
            }

            .iti__selected-country:hover,
            .iti__selected-country-primary:hover {
                background: #243041 !important;
            }

            .iti__arrow {
                border-top-color: #d1d5db !important;
            }

            .iti__country-list::-webkit-scrollbar {
                width: 10px;
            }

            .iti__country-list::-webkit-scrollbar-track {
                background: #0b1220;
            }

            .iti__country-list::-webkit-scrollbar-thumb {
                background: #374151;
                border-radius: 999px;
            }

            .iti__country-list::-webkit-scrollbar-thumb:hover {
                background: #4b5563;
            }
        </style>
    @endpush
@endonce

@once
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/js/intlTelInput.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/js/utils.js"></script>
        <script>
            (() => {
                if (window.MetIntlTelInput) {
                    document.dispatchEvent(new Event('met:intl-tel-input-ready'));
                    return;
                }

                const states = new Map();
                const initPromises = new Map();

                function normalizeIso2(value) {
                    const normalized = String(value || '').trim().toLowerCase();
                    return /^[a-z]{2}$/.test(normalized) ? normalized : '';
                }

                function normalizeDialCode(value) {
                    return String(value || '').replace(/\D+/g, '');
                }

                function pluginLoaded() {
                    return typeof window.intlTelInput === 'function';
                }

                function isValidPhone(state) {
                    if (!state?.iti || typeof state.iti.isValidNumber !== 'function') {
                        return true;
                    }

                    try {
                        return state.iti.isValidNumber();
                    } catch (error) {
                        return true;
                    }
                }

                function setHiddenValue(element, value) {
                    if (!element || element.value === value) {
                        return;
                    }

                    element.value = value;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                    element.dispatchEvent(new Event('change', { bubbles: true }));
                }

                function showError(state, message = '') {
                    const errorEl = state.errorElement;
                    if (!errorEl) {
                        return;
                    }

                    if (!message) {
                        errorEl.textContent = '';
                        errorEl.style.display = 'none';
                        return;
                    }

                    errorEl.textContent = message;
                    errorEl.style.display = 'block';
                }

                function fallbackPhone(rawValue, dialCode) {
                    const digits = String(rawValue || '').replace(/\D+/g, '');
                    if (!digits) {
                        return '';
                    }

                    if (!dialCode) {
                        return `+${digits}`;
                    }

                    return digits.startsWith(dialCode)
                        ? `+${digits}`
                        : `+${dialCode}${digits}`;
                }

                function sync(state, { validate = false } = {}) {
                    if (!state.input || !state.iti) {
                        return false;
                    }

                    const selectedCountry = state.iti.getSelectedCountryData() || {};
                    const iso2 = normalizeIso2(selectedCountry.iso2 || state.initialCountry);
                    const dialCode = normalizeDialCode(selectedCountry.dialCode || '');

                    setHiddenValue(state.hiddenCountry, iso2);
                    setHiddenValue(state.hiddenDialCode, dialCode);

                    const rawValue = state.input.value.trim();

                    if (!rawValue) {
                        setHiddenValue(state.hiddenPhone, '');
                        showError(state, '');
                        return false;
                    }

                    if (validate && !isValidPhone(state)) {
                        setHiddenValue(state.hiddenPhone, '');
                        showError(state, state.invalidMessage);
                        return false;
                    }

                    let fullNumber = '';

                    try {
                        fullNumber = state.iti.getNumber() || '';
                    } catch (error) {
                        fullNumber = '';
                    }

                    if (!fullNumber) {
                        fullNumber = fallbackPhone(rawValue, dialCode);
                    }

                    setHiddenValue(state.hiddenPhone, fullNumber);
                    showError(state, '');

                    return true;
                }

                function destroy(key) {
                    const state = states.get(key);
                    if (!state) {
                        return;
                    }

                    if (state.abortController) {
                        state.abortController.abort();
                    }

                    if (state.iti && typeof state.iti.destroy === 'function') {
                        state.iti.destroy();
                    }

                    states.delete(key);
                }

                async function init(config) {
                    const key = String(config?.key || '').trim();
                    if (!key) {
                        return null;
                    }

                    if (initPromises.has(key)) {
                        return initPromises.get(key);
                    }

                    const input = document.querySelector(config.inputSelector);
                    const hiddenPhone = document.querySelector(config.hiddenPhoneSelector);

                    if (!input || !hiddenPhone) {
                        destroy(key);
                        return null;
                    }

                    if (!pluginLoaded()) {
                        const fallbackError = document.querySelector(config.errorSelector);
                        if (fallbackError) {
                            fallbackError.textContent = config.assetErrorMessage || 'Phone input failed to load. Please refresh and try again.';
                            fallbackError.style.display = 'block';
                        }

                        return null;
                    }

                    const initTask = Promise.resolve().then(() => {
                        const existing = states.get(key);
                        if (existing && existing.input !== input) {
                            destroy(key);
                        }

                        if (states.has(key)) {
                            const current = states.get(key);
                            current.hiddenPhone = hiddenPhone;
                            current.hiddenCountry = document.querySelector(config.hiddenCountrySelector);
                            current.hiddenDialCode = document.querySelector(config.hiddenDialCodeSelector);
                            current.errorElement = document.querySelector(config.errorSelector);
                            current.invalidMessage = config.invalidMessage || current.invalidMessage;
                            sync(current);
                            return current;
                        }

                        const onlyCountries = Array.isArray(config.onlyCountries)
                            ? config.onlyCountries.map((item) => normalizeIso2(item)).filter(Boolean)
                            : [];
                        const preferredCountries = Array.isArray(config.preferredCountries)
                            ? config.preferredCountries.map((item) => normalizeIso2(item)).filter(Boolean)
                            : [];

                        const initialCountry = normalizeIso2(config.initialCountry) || preferredCountries[0] || onlyCountries[0] || 'iq';

                        const options = {
                            initialCountry,
                            nationalMode: false,
                            separateDialCode: true,
                            autoPlaceholder: 'polite',
                            formatAsYouType: true,
                            strictMode: false,
                            dropdownContainer: document.body,
                        };

                        if (onlyCountries.length > 0) {
                            options.onlyCountries = onlyCountries;
                        }

                        if (preferredCountries.length > 0) {
                            options.preferredCountries = preferredCountries;
                        }

                        const iti = window.intlTelInput(input, options);
                        const abortController = new AbortController();

                        const state = {
                            key,
                            iti,
                            input,
                            hiddenPhone,
                            hiddenCountry: document.querySelector(config.hiddenCountrySelector),
                            hiddenDialCode: document.querySelector(config.hiddenDialCodeSelector),
                            errorElement: document.querySelector(config.errorSelector),
                            invalidMessage: config.invalidMessage || 'Please enter a valid phone number.',
                            initialCountry,
                            abortController,
                        };

                        states.set(key, state);

                        const initialPhone = hiddenPhone.value || '';
                        const initialCountryFromHidden = normalizeIso2(state.hiddenCountry?.value || '') || initialCountry;

                        if (initialPhone) {
                            try {
                                iti.setNumber(initialPhone);
                            } catch (error) {
                            }
                        } else if (initialCountryFromHidden) {
                            try {
                                iti.setCountry(initialCountryFromHidden);
                            } catch (error) {
                            }
                        }

                        const form = document.querySelector(config.formSelector);
                        if (form) {
                            form.addEventListener('submit', (event) => {
                                const valid = sync(state, { validate: true });

                                if (!valid) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    event.stopImmediatePropagation();
                                }
                            }, { capture: true, signal: abortController.signal });
                        }

                        input.addEventListener('input', () => sync(state), { signal: abortController.signal });
                        input.addEventListener('blur', () => sync(state, { validate: true }), { signal: abortController.signal });
                        input.addEventListener('countrychange', () => sync(state, { validate: true }), { signal: abortController.signal });

                        sync(state);
                        return state;
                    }).finally(() => {
                        initPromises.delete(key);
                    });

                    initPromises.set(key, initTask);
                    return initTask;
                }

                window.MetIntlTelInput = {
                    init,
                    destroy,
                    sync(key, options = {}) {
                        const state = states.get(String(key || ''));
                        return state ? sync(state, options) : false;
                    },
                };

                document.dispatchEvent(new Event('met:intl-tel-input-ready'));
            })();
        </script>
    @endpush
@endonce
