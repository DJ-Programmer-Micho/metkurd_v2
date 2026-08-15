const players = new Map();
const pendingAudioBlobs = new Map();
const audioCacheName = 'xomni-audio-v4';
const audioCacheMax = 30;
let speakerPreview = null;
let speakerPreviewCode = null;

const formatTime = (seconds) => {
    const value = Math.max(0, Math.floor(Number(seconds) || 0));
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
};

const destroy = (jobId) => {
    const player = players.get(jobId);
    if (!player) return;

    try { player.waveSurfer.destroy(); } catch (_) {}
    if (player.blobUrl) URL.revokeObjectURL(player.blobUrl);
    players.delete(jobId);
};

const cachedAudioBlob = async (url) => {
    if (pendingAudioBlobs.has(url)) return pendingAudioBlobs.get(url);

    const request = (async () => {
        const cache = await caches.open(audioCacheName);
        const cached = await cache.match(url);
        if (cached) return cached.blob();

        const response = await fetch(url, { method: 'GET', cache: 'no-cache', credentials: 'same-origin' });
        if (!response.ok) throw new Error(`Audio fetch failed: ${response.status}`);

        await cache.put(url, response.clone());
        const keys = await cache.keys();
        await Promise.all(keys.slice(0, Math.max(0, keys.length - audioCacheMax)).map((key) => cache.delete(key)));

        return response.blob();
    })();

    pendingAudioBlobs.set(url, request);
    request.finally(() => pendingAudioBlobs.delete(url));
    return request;
};

const loadAudio = async (jobId, player) => {
    try {
        const blob = await cachedAudioBlob(player.url);
        if (players.get(jobId) !== player) return;
        player.blobUrl = URL.createObjectURL(blob);
        player.waveSurfer.load(player.blobUrl);
    } catch (_) {
        if (players.get(jobId) === player) player.waveSurfer.load(player.url);
    }
};

const destroyDetached = () => {
    players.forEach((player, jobId) => {
        if (!player.root.isConnected || !player.canvas.isConnected) destroy(jobId);
    });
};

const setPlayingState = (player, isPlaying) => {
    const icon = player.root.querySelector('[data-metkurd-waveform-icon]');
    if (!icon) return;

    icon.className = isPlaying ? 'ri-pause-fill' : 'ri-play-fill';
};

const mount = (scope = document) => {
    if (!window.WaveSurfer) return;

    scope.querySelectorAll('[data-metkurd-waveform]').forEach((root) => {
        const jobId = String(root.dataset.job || '').trim();
        const url = String(root.dataset.url || '').trim();
        const canvas = root.querySelector('[data-metkurd-waveform-canvas]');
        const toggle = root.querySelector('[data-metkurd-waveform-toggle]');
        const time = root.querySelector('[data-metkurd-waveform-time]');

        if (!jobId || !url || !canvas || !toggle || !time) return;

        const existing = players.get(jobId);
        if (existing?.root === root && existing.canvas === canvas && existing.url === url) return;
        if (existing) destroy(jobId);

        const waveSurfer = window.WaveSurfer.create({
            container: canvas,
            height: 42,
            normalize: true,
            responsive: true,
            backend: 'MediaElement',
            waveColor: '#60a5fa',
            progressColor: '#2563eb',
            cursorColor: '#bfdbfe',
        });
        const player = { root, canvas, url, waveSurfer };
        players.set(jobId, player);

        waveSurfer.on('ready', () => {
            time.textContent = `00:00 / ${formatTime(waveSurfer.getDuration())}`;
        });
        waveSurfer.on('timeupdate', () => {
            time.textContent = `${formatTime(waveSurfer.getCurrentTime())} / ${formatTime(waveSurfer.getDuration())}`;
        });
        waveSurfer.on('play', () => {
            players.forEach((other, otherJobId) => {
                if (otherJobId !== jobId) other.waveSurfer.pause();
            });
            setPlayingState(player, true);
        });
        waveSurfer.on('pause', () => setPlayingState(player, false));
        waveSurfer.on('finish', () => {
            waveSurfer.setTime(0);
            setPlayingState(player, false);
        });
        waveSurfer.on('error', () => {
            time.textContent = '--:-- / --:--';
            setPlayingState(player, false);
        });

        toggle.addEventListener('click', () => waveSurfer.playPause());
        loadAudio(jobId, player);
    });
};

const refresh = () => {
    destroyDetached();
    mount();
};

window.MetKurdWaveform = {
    mount: refresh,
    destroyAll: () => Array.from(players.keys()).forEach(destroy),
};

window.MetKurdSpeakerPreview = {
    toggle(code, url) {
        if (speakerPreviewCode === code && speakerPreview && !speakerPreview.paused) {
            speakerPreview.pause();
            speakerPreviewCode = null;
            window.dispatchEvent(new CustomEvent('metkurd-v2-speaker-preview', { detail: null }));
            return;
        }

        if (!speakerPreview || speakerPreview.src !== new URL(url, window.location.href).href) {
            if (speakerPreview) speakerPreview.pause();
            speakerPreview = new Audio(url);
            speakerPreview.addEventListener('ended', () => {
                speakerPreviewCode = null;
                window.dispatchEvent(new CustomEvent('metkurd-v2-speaker-preview', { detail: null }));
            });
        }

        speakerPreviewCode = code;
        speakerPreview.play().then(
            () => window.dispatchEvent(new CustomEvent('metkurd-v2-speaker-preview', { detail: code })),
            () => window.dispatchEvent(new CustomEvent('metkurd-v2-speaker-preview', { detail: null }))
        );
    },
    stop() {
        if (speakerPreview) speakerPreview.pause();
        speakerPreviewCode = null;
        window.dispatchEvent(new CustomEvent('metkurd-v2-speaker-preview', { detail: null }));
    },
};

document.addEventListener('metkurd:wavesurfer-ready', refresh);
document.addEventListener('livewire:navigated', refresh);
document.addEventListener('livewire:navigating', () => {
    window.MetKurdWaveform.destroyAll();
    window.MetKurdSpeakerPreview.stop();
});
document.addEventListener('click', (event) => {
    if (event.target.closest('[wire\\:click="previousRecentRendersPage"], [wire\\:click="nextRecentRendersPage"]')) {
        window.MetKurdWaveform.destroyAll();
    }
});
document.addEventListener('livewire:init', () => {
    if (typeof window.Livewire?.hook !== 'function') return;

    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => requestAnimationFrame(refresh));
    });
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh, { once: true });
} else {
    refresh();
}
