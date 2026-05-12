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

function initToolDemoAudio(scope = document) {
    const root = scope instanceof HTMLElement || scope === document ? scope : document;
    const players = Array.from(root.querySelectorAll('[data-demo-wave]'));

    if (!players.length) {
        return;
    }

    const formatWaveTime = (seconds) => {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return '0:00';
        }

        const whole = Math.floor(seconds);
        const minutes = Math.floor(whole / 60);
        const remainder = String(whole % 60).padStart(2, '0');
        return `${minutes}:${remainder}`;
    };

    const setTime = (container, current = 0, duration = 0) => {
        const timeEl = container.querySelector('[data-wave-time]');
        if (!(timeEl instanceof HTMLElement)) {
            return;
        }

        timeEl.textContent = `${formatWaveTime(current)} / ${formatWaveTime(duration)}`;
    };

    const warnWave = (container, message, error = null) => {
        if (!(container instanceof HTMLElement)) {
            return;
        }

        const audioRef = container.dataset.audioUrl || 'unknown-audio';
        const warnedKey = `waveWarned${message.replace(/[^a-z0-9]+/gi, '').slice(0, 24)}`;
        if (container.dataset[warnedKey] === '1') {
            return;
        }

        container.dataset[warnedKey] = '1';
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            if (error) {
                console.warn(`[landing-demo] ${message}: ${audioRef}`, error);
            } else {
                console.warn(`[landing-demo] ${message}: ${audioRef}`);
            }
        }
    };

    const setPlayingState = (container, playing) => {
        container.classList.toggle('is-playing', Boolean(playing));

        const icon = container.querySelector('[data-wave-icon]');
        if (icon instanceof HTMLElement) {
            icon.className = playing ? 'bi bi-pause-fill' : 'bi bi-play-fill';
        }

        const voiceCard = container.closest('[data-voice-card], .demo-voice-card');
        if (voiceCard instanceof HTMLElement) {
            voiceCard.classList.toggle('is-playing', Boolean(playing));
        }
    };

    const waveInstances = window.__landingWaveInstances || new Map();
    window.__landingWaveInstances = waveInstances;

    waveInstances.forEach((entry, key) => {
        if (!entry || !(entry.container instanceof HTMLElement)) {
            waveInstances.delete(key);
            return;
        }

        if (entry.container.isConnected) {
            return;
        }

        try {
            entry.ws?.destroy?.();
        } catch (error) {
            // Ignore destroy failures during morph cleanup.
        }

        waveInstances.delete(key);
    });

    const pauseAllExcept = (activeContainer) => {
        document.querySelectorAll('[data-demo-wave]').forEach((node) => {
            if (!(node instanceof HTMLElement) || node === activeContainer) {
                return;
            }

            const activeWave = node._demoWaveInstance;
            if (activeWave && typeof activeWave.isPlaying === 'function' && activeWave.isPlaying()) {
                try {
                    activeWave.pause();
                } catch (error) {
                    // Keep other players safe even if one instance throws.
                }
            }

            const fallback = node.querySelector('[data-wave-fallback]');
            if (fallback instanceof HTMLAudioElement && !fallback.paused) {
                fallback.pause();
            }

            setPlayingState(node, false);
        });
    };

    players.forEach((container, index) => {
        if (!(container instanceof HTMLElement)) {
            return;
        }

        const fallback = container.querySelector('[data-wave-fallback]');
        const fallbackSrc = fallback instanceof HTMLAudioElement ? String(fallback.getAttribute('src') || '') : '';
        const audioUrl = String(container.dataset.audioUrl || fallbackSrc || '').trim();
        const waveId = String(container.dataset.waveId || `landing-wave-${index}`);
        const playButton = container.querySelector('[data-wave-play]');
        const waveform = container.querySelector('[data-waveform]');

        const toggleFallback = () => {
            if (!(fallback instanceof HTMLAudioElement)) {
                return;
            }

            pauseAllExcept(container);

            if (fallback.paused) {
                fallback.play().catch(() => {
                    setPlayingState(container, false);
                });
            } else {
                fallback.pause();
            }
        };

        if (playButton instanceof HTMLButtonElement && playButton.dataset.bound !== 'true') {
            playButton.dataset.bound = 'true';
            playButton.addEventListener('click', () => {
                const instance = container._demoWaveInstance;
                const isWaveReady = container.dataset.waveReady === 'true'
                    && instance
                    && typeof instance.playPause === 'function';

                if (isWaveReady) {
                    pauseAllExcept(container);
                    try {
                        instance.playPause();
                    } catch (error) {
                        toggleFallback();
                    }
                    return;
                }

                toggleFallback();
            });
        }

        if (fallback instanceof HTMLAudioElement && fallback.dataset.bound !== 'true') {
            fallback.dataset.bound = 'true';

            fallback.addEventListener('loadedmetadata', () => {
                setTime(container, fallback.currentTime, fallback.duration);
            });
            fallback.addEventListener('timeupdate', () => {
                setTime(container, fallback.currentTime, fallback.duration);
            });
            fallback.addEventListener('play', () => {
                pauseAllExcept(container);
                setPlayingState(container, true);
            });
            fallback.addEventListener('pause', () => {
                setPlayingState(container, false);
            });
            fallback.addEventListener('ended', () => {
                try {
                    fallback.currentTime = 0;
                } catch (error) {
                    // Ignore seek errors on ended fallback.
                }
                setPlayingState(container, false);
                setTime(container, 0, fallback.duration);
            });
            fallback.addEventListener('error', () => {
                setPlayingState(container, false);
            });
        }

        if (audioUrl === '') {
            const activeInstance = container._demoWaveInstance;
            if (activeInstance && typeof activeInstance.destroy === 'function') {
                try {
                    activeInstance.destroy();
                } catch (error) {
                    // Ignore destroy errors for empty audio players.
                }
            }
            container._demoWaveInstance = null;
            container.dataset.waveReady = 'false';
            container.classList.remove('is-wave-ready');
            container.classList.add('is-fallback-only');

            if (playButton instanceof HTMLButtonElement) {
                playButton.disabled = true;
            }
            setTime(container, 0, 0);
            return;
        }

        if (playButton instanceof HTMLButtonElement) {
            playButton.disabled = false;
        }

        container.classList.remove('is-fallback-only');
        container.classList.remove('is-wave-error');

        const existing = waveInstances.get(waveId);
        if (existing?.ws) {
            const sameContainer = existing.container === container;
            const sameUrl = String(existing.url || '') === audioUrl;

            if (sameContainer && sameUrl) {
                if (container.dataset.waveReady === 'true' && fallback instanceof HTMLAudioElement) {
                    fallback.classList.add('is-hidden');
                }
                return;
            }

            try {
                existing.ws.destroy();
            } catch (error) {
                // Ignore stale instance errors before reinit.
            }
            waveInstances.delete(waveId);
        }

        container.dataset.waveReady = 'false';
        container.classList.remove('is-wave-ready');

        if (typeof window.WaveSurfer === 'undefined' || !(waveform instanceof HTMLElement)) {
            container.classList.add('is-fallback-only');
            warnWave(container, 'WaveSurfer unavailable, using native fallback');
            return;
        }

        try {
            const rootStyles = getComputedStyle(document.documentElement);
            const waveColor = (rootStyles.getPropertyValue('--muted') || '').trim() || '#9cb0d3';
            const progressColor = (rootStyles.getPropertyValue('--secondary') || '').trim() || '#06d6ff';
            const cursorColor = (rootStyles.getPropertyValue('--primary') || '').trim() || '#7c5cff';

            const ws = window.WaveSurfer.create({
                container: waveform,
                height: 52,
                barWidth: 2,
                barGap: 2,
                barRadius: 2,
                waveColor,
                progressColor,
                cursorColor,
                cursorWidth: 2,
                normalize: true,
                hideScrollbar: true,
                interact: true,
            });

            container._demoWaveInstance = ws;
            waveInstances.set(waveId, { ws, container, url: audioUrl });

            ws.on('ready', () => {
                container.dataset.waveReady = 'true';
                container.classList.add('is-wave-ready');
                container.classList.remove('is-fallback-only');
                if (fallback instanceof HTMLAudioElement) {
                    fallback.classList.add('is-hidden');
                }
                setTime(container, 0, ws.getDuration());
            });

            ws.on('play', () => {
                pauseAllExcept(container);
                setPlayingState(container, true);
            });

            ws.on('pause', () => {
                setPlayingState(container, false);
            });

            ws.on('timeupdate', () => {
                setTime(container, ws.getCurrentTime(), ws.getDuration());
            });

            ws.on('finish', () => {
                setPlayingState(container, false);
                try {
                    ws.setTime(0);
                } catch (error) {
                    // Ignore seek errors at finish.
                }
                setTime(container, 0, ws.getDuration());
            });

            ws.on('error', () => {
                container.classList.add('is-wave-error');
                container.classList.add('is-fallback-only');
                container.dataset.waveReady = 'false';
                if (fallback instanceof HTMLAudioElement) {
                    fallback.classList.remove('is-hidden');
                }
                setPlayingState(container, false);
                warnWave(container, 'WaveSurfer failed to decode audio, fallback kept visible');
            });

            ws.load(audioUrl);
        } catch (error) {
            container.classList.add('is-wave-error');
            container.classList.add('is-fallback-only');
            container.dataset.waveReady = 'false';
            if (fallback instanceof HTMLAudioElement) {
                fallback.classList.remove('is-hidden');
            }
            warnWave(container, 'WaveSurfer init failed, fallback kept visible', error);
        }
    });
}

function bootLanding(scope = document) {
    initTheme();
    initReveal(scope);
    initPricingToggle(scope);
    initToolDemoAudio(scope);
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

        window.Livewire.hook('morphed', ({ el } = {}) => {
            requestAnimationFrame(() => bootLanding(el instanceof HTMLElement ? el : document));
        });
    }

    if (!window.__landingWaveSurferReadyBound) {
        window.__landingWaveSurferReadyBound = true;
        window.addEventListener('landing:wavesurfer-ready', () => {
            requestAnimationFrame(() => initToolDemoAudio(document));
        });
    }
}
