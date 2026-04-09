@props([
    'model' => 'cfTurnstileResponse',
    'errorKey' => null,
    'theme' => 'dark',
    'size' => 'normal',
    'class' => '',
])

@php
    $errorKey = $errorKey ?: $model;
    $siteKey = trim((string) config('services.turnstile.site_key'));
    $containerId = 'turnstile-container-' . substr(md5($model . '-' . $theme . '-' . $size), 0, 10);
    $inputId = 'turnstile-input-' . substr(md5($model), 0, 10);
@endphp

<div class="{{ trim('turnstile-field ' . $class) }}">
    <input
        id="{{ $inputId }}"
        type="hidden"
        name="cf-turnstile-response"
        wire:model.defer="{{ $model }}"
    >

    @if ($siteKey !== '')
        <div
            id="{{ $containerId }}"
            class="js-turnstile-container"
            data-turnstile-sitekey="{{ $siteKey }}"
            data-turnstile-input-id="{{ $inputId }}"
            data-turnstile-theme="{{ $theme }}"
            data-turnstile-size="{{ $size }}"
            data-turnstile-language="auto"
        >
            <div class="js-turnstile-box" wire:ignore></div>
        </div>
    @else
        <div class="small text-danger">
            {{ __('Human verification is currently unavailable. Please try again later.') }}
        </div>
    @endif

    @error($errorKey)
        <div class="invalid-feedback d-block mt-2">{{ $message }}</div>
    @enderror
</div>

@once
    @push('scripts')
        <script>
            (() => {
                if (window.__turnstileWidgetBooted) {
                    return;
                }

                window.__turnstileWidgetBooted = true;

                function syncTurnstileInput(inputId, token) {
                    const input = document.getElementById(inputId);
                    if (!input) return;

                    input.value = token || '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }

                function renderTurnstileWidgets() {
                    if (!window.turnstile) {
                        return;
                    }

                    document.querySelectorAll('.js-turnstile-container').forEach((container) => {
                        const box = container.querySelector('.js-turnstile-box');

                        if (!box || box.dataset.turnstileRendered === '1') {
                            return;
                        }

                        const widgetId = window.turnstile.render(box, {
                            sitekey: container.dataset.turnstileSitekey,
                            theme: container.dataset.turnstileTheme || 'auto',
                            size: container.dataset.turnstileSize || 'normal',
                            language: container.dataset.turnstileLanguage || 'auto',
                            callback: (token) => syncTurnstileInput(container.dataset.turnstileInputId, token),
                            'expired-callback': () => syncTurnstileInput(container.dataset.turnstileInputId, ''),
                            'error-callback': () => syncTurnstileInput(container.dataset.turnstileInputId, ''),
                        });

                        box.dataset.turnstileRendered = '1';
                        container.dataset.turnstileWidgetId = String(widgetId);
                    });
                }

                function resetTurnstileWidgets() {
                    document.querySelectorAll('.js-turnstile-container').forEach((container) => {
                        syncTurnstileInput(container.dataset.turnstileInputId, '');

                        if (!window.turnstile || !container.dataset.turnstileWidgetId) {
                            return;
                        }

                        try {
                            window.turnstile.reset(container.dataset.turnstileWidgetId);
                        } catch (error) {
                            console.warn('[Turnstile] Reset failed', error);
                        }
                    });
                }

                function bindLivewireHooks() {
                    if (!window.Livewire) {
                        return;
                    }

                    if (typeof window.Livewire.on === 'function' && !window.__turnstileLivewireOnBound) {
                        window.__turnstileLivewireOnBound = true;
                        window.Livewire.on('turnstile-reset', () => {
                            resetTurnstileWidgets();
                            setTimeout(renderTurnstileWidgets, 0);
                        });
                    }

                    if (typeof window.Livewire.hook === 'function' && !window.__turnstileLivewireHookBound) {
                        window.__turnstileLivewireHookBound = true;
                        window.Livewire.hook('morphed', () => {
                            setTimeout(renderTurnstileWidgets, 0);
                        });
                    }
                }

                window.__turnstileOnLoad = function () {
                    renderTurnstileWidgets();
                };

                window.__renderTurnstileWidgets = renderTurnstileWidgets;
                window.__resetTurnstileWidgets = resetTurnstileWidgets;

                document.addEventListener('DOMContentLoaded', renderTurnstileWidgets);
                document.addEventListener('livewire:navigated', () => setTimeout(renderTurnstileWidgets, 0));
                document.addEventListener('livewire:initialized', bindLivewireHooks);

                bindLivewireHooks();
                renderTurnstileWidgets();
            })();
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__turnstileOnLoad" async defer></script>
    @endpush
@endonce
