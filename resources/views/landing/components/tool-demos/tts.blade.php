<div class="demo-sample-board demo-sample-board--tts" data-tts-board data-engine="mixed">
    <div class="demo-sample-card demo-sample-card--voices">
        <header class="demo-card-head">
            <h3>{{ __('Voice Examples') }}</h3>
            <span class="demo-chip">{{ count($items) }} {{ __('voices') }}</span>
        </header>

        <div class="demo-voice-grid">
            @forelse($items as $voice)
                @php
                    $audioUrl = trim((string) (
                        $voice['audio']
                        ?? $voice['audio_url']
                        ?? $voice['preview_url']
                        ?? $voice['url']
                        ?? $voice['file_url']
                        ?? ''
                    ));
                @endphp
                <article class="demo-voice-card" data-voice-card data-voice-code="{{ $voice['voice_id'] ?? $loop->index }}">
                    <div class="demo-voice-meta">
                        <div class="demo-clone-avatar demo-voice-avatar">
                            @if(!empty($voice['avatar']))
                                <img
                                    src="{{ $voice['avatar'] }}"
                                    alt="{{ $voice['label'] ?? __('Voice') }}"
                                    loading="lazy"
                                    onload="this.nextElementSibling && (this.nextElementSibling.style.display='none');"
                                    onerror="this.style.display='none'; this.nextElementSibling && (this.nextElementSibling.style.display='grid');"
                                >
                            @endif
                            <span>{{ $voice['initials'] ?? 'AI' }}</span>
                        </div>

                        <div class="demo-voice-copy">
                            <strong>{{ $voice['label'] ?? __('Voice sample') }}</strong>
                            @if(!empty($voice['engine_label']))
                                <span class="demo-voice-engine">{{ $voice['engine_label'] }}</span>
                            @endif
                        </div>
                    </div>

                    @if($audioUrl !== '')
                        @include('landing.components.tool-demos._wave-player', [
                            'audioUrl' => $audioUrl,
                            'title' => (string) ($voice['label'] ?? ''),
                            'uid' => 'tts-' . $loop->index . '-' . ($voice['voice_id'] ?? 'voice'),
                        ])
                    @else
                        <div class="demo-inline-note">{{ __('Audio sample is not available yet for this voice.') }}</div>
                    @endif

                    @if(!empty($voice['description']))
                        <p class="demo-voice-desc">{{ $voice['description'] }}</p>
                    @endif
                </article>
            @empty
                <div class="demo-empty">{{ __('No voice examples configured yet.') }}</div>
            @endforelse
        </div>
    </div>
</div>
