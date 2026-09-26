// WaveSurfer owns waveform loading/drawing. One AudioContext owns the result's
// transport: no independently clocked media playback or corrective audio seeks.
export function mountStemPlayer(root, t, format, win = window) {
    const listeners = new AbortController();
    const listen = (target, name, fn) => target?.addEventListener(name, fn, {signal: listeners.signal});
    const all = root.querySelector('[data-stem-play-all]');
    const stop = root.querySelector('[data-stem-stop-all]');
    const timeline = root.querySelector('[data-stem-timeline]');
    const current = root.querySelector('[data-stem-current]');
    const duration = root.querySelector('[data-stem-duration]');
    const status = root.querySelector('[data-stem-player-status]');
    let context, destroyed = false, running = false, starting = false, generation = 0;
    let offset = 0, startedAt = 0, frame = null, scrubbing = false;
    const tracks = [...root.querySelectorAll('[data-stem-track]')].map(row => ({
        row, wave: row.querySelector('[data-stem-wave]'), audio: row.querySelector('[data-stem-audio]'),
        toggle: row.querySelector('[data-stem-toggle]'), mute: row.querySelector('[data-stem-mute]'),
        solo: row.querySelector('[data-stem-solo]'), time: row.querySelector('[data-stem-track-time]'),
        muted: false, soloed: false, enabled: true, buffer: null, source: null, gain: null, player: null,
    }));
    // The original is a comparison track, not an extra copy of the entire mix.
    tracks.forEach(track => { track.enabled = track.row.dataset.stemTrack !== 'original'; });
    const maxDuration = () => Math.max(0, ...tracks.filter(track => track.enabled).map(track => track.buffer?.duration || 0));
    const time = () => running ? Math.min(maxDuration(), offset + Math.max(0, context.currentTime - startedAt)) : offset;
    const ready = () => tracks.length > 0 && tracks.every(track => track.buffer);
    const message = key => { if (status) { status.textContent = key ? t(key) : ''; status.hidden = !key; } };
    const gains = () => {
        const soloed = tracks.some(track => track.soloed);
        tracks.forEach(track => {
            const audible = track.enabled && !track.muted && (!soloed || track.soloed);
            if (track.gain) {
                // A short ramp avoids a sample discontinuity (click) when toggled.
                const gain = track.gain.gain, now = context.currentTime;
                gain.cancelScheduledValues(now);
                gain.setTargetAtTime(audible ? 1 : 0, now, 0.005);
            }
            track.mute.setAttribute('aria-pressed', String(track.muted));
            track.solo.setAttribute('aria-pressed', String(track.soloed));
            track.row.classList.toggle('is-muted', !audible);
            track.row.classList.toggle('is-solo', track.soloed);
        });
    };
    const draw = () => {
        if (destroyed) return;
        const position = time(), length = maxDuration();
        if (timeline) { timeline.max = String(length); if (!scrubbing) timeline.value = String(position); }
        if (current && !scrubbing) current.textContent = format(position);
        if (duration) duration.textContent = length ? format(length) : '--:--';
        tracks.forEach(track => {
            const playing = running && track.enabled && position < (track.buffer?.duration || 0);
            track.row.classList.toggle('is-playing', playing);
            track.toggle.querySelector('i').className = playing ? 'ri-pause-fill' : 'ri-play-fill';
            if (track.time) track.time.textContent = format(position);
            // This seeks only WaveSurfer's silent display media. The audible
            // AudioBufferSourceNodes keep running on their shared clock.
            if (track.buffer) track.player.setTime(Math.min(position, track.buffer.duration));
            track.toggle.disabled = !ready() || starting;
        });
        if (all) {
            all.disabled = !ready() || starting;
            all.querySelector('span').textContent = running ? t('Pause All') : t('Play All');
            all.querySelector('i').className = running ? 'ri-pause-fill' : 'ri-play-fill';
        }
    };
    const stopSources = () => {
        if (frame !== null) win.cancelAnimationFrame(frame);
        frame = null;
        tracks.forEach(track => {
            if (!track.source) return;
            track.source.onended = null;
            try { track.source.stop(); } catch (_) {}
            track.source.disconnect(); track.source = null;
        });
    };
    const pause = () => {
        offset = time(); running = false; starting = false; generation++;
        stopSources(); draw();
    };
    const tick = () => {
        if (destroyed || !running) return;
        if (time() >= maxDuration()) { pause(); return; }
        draw(); frame = win.requestAnimationFrame(tick);
    };
    const schedule = () => {
        stopSources();
        startedAt = context.currentTime + 0.025;
        tracks.forEach(track => {
            // Even inaudible tracks run, so Mute/Solo never start or seek sources.
            if (offset >= track.buffer.duration) return;
            const source = context.createBufferSource();
            source.buffer = track.buffer; source.connect(track.gain);
            track.source = source; source.start(startedAt, offset);
        });
        running = true; gains(); tick();
    };
    const play = async () => {
        if (!ready() || starting || destroyed) return;
        const attempt = ++generation;
        starting = true; draw();
        try {
            await context.resume();
            if (destroyed || attempt !== generation) return;
            if (offset >= maxDuration()) offset = 0;
            schedule(); message('');
        } catch (_) {
            if (!destroyed) { running = false; stopSources(); message('Unable to play this audio. Please reopen the result.'); }
        } finally {
            if (!destroyed && attempt === generation) { starting = false; draw(); }
        }
    };
    const seek = value => {
        if (!ready() || destroyed) return;
        scrubbing = false;
        const playing = running;
        pause(); offset = Math.min(maxDuration(), Math.max(0, Number(value) || 0));
        if (playing && offset < maxDuration()) schedule(); else draw();
    };
    try {
        const AudioContext = win.AudioContext || win.webkitAudioContext;
        context = new AudioContext();
        message('Loading audio preview…');
        tracks.forEach(track => {
            track.gain = context.createGain(); track.gain.connect(context.destination);
            track.player = win.WaveSurfer.create({
                container: track.wave, media: track.audio, url: track.wave.dataset.url,
                // Waveform defaults decode at 8 kHz. Reuse a full-rate decode for
                // playback instead; the browser converts to its output device rate.
                sampleRate: context.sampleRate, height: 58, waveColor: 'rgba(249,115,22,.36)',
                progressColor: '#f97316', cursorColor: '#fed7aa', cursorWidth: 2,
                barWidth: 2, barGap: 2, barRadius: 2, normalize: true, interact: true, autoScroll: false,
            });
            track.player.on('ready', () => {
                if (destroyed) return;
                track.buffer = track.player.getDecodedData();
                if (ready()) message('');
                draw();
            });
            track.player.on('interaction', seek);
            track.player.on('error', () => {
                if (destroyed) return;
                pause(); track.buffer = null; draw();
                message('Unable to play this audio. Please reopen the result.');
            });
            listen(track.mute, 'click', () => { track.muted = !track.muted; gains(); });
            listen(track.solo, 'click', () => {
                track.soloed = !track.soloed;
                // A comparison track becomes audible only by an explicit action.
                if (track.soloed) track.enabled = true;
                else if (track.row.dataset.stemTrack === 'original') track.enabled = false;
                gains();
            });
            listen(track.toggle, 'click', async () => {
                if (!ready()) return;
                if (running && track.enabled) { pause(); return; }
                pause(); tracks.forEach(item => { item.enabled = item === track; });
                await play();
            });
        });
        listen(all, 'click', async () => {
            if (running) { pause(); return; }
            tracks.forEach(track => { track.enabled = track.row.dataset.stemTrack !== 'original' || track.soloed; });
            await play();
        });
        listen(stop, 'click', () => { scrubbing = false; pause(); offset = 0; draw(); });
        // Preview the thumb while dragging; commit one coordinated seek on release.
        listen(timeline, 'input', () => { scrubbing = true; if (current) current.textContent = format(Number(timeline.value)); });
        listen(timeline, 'change', () => seek(timeline.value));
        gains(); draw();
    } catch (_) {
        message('Unable to play this audio. Please reopen the result.');
        if (all) all.disabled = true;
    }
    return () => {
        if (destroyed) return;
        destroyed = true; generation++; listeners.abort(); stopSources();
        tracks.forEach(track => {
            track.gain?.disconnect(); track.player?.destroy(); track.buffer = null;
            // WaveSurfer does not dispose externally supplied media elements.
            track.audio?.pause(); track.audio?.removeAttribute('src'); track.audio?.load();
            track.wave.replaceChildren();
        });
        if (context) Promise.resolve(context.close()).catch(() => {});
        delete root.__stemDestroy;
    };
}

if (typeof window !== 'undefined') window.MetKurdStemPlayer = {mount: mountStemPlayer};
