<div class="demo-sample-board">
    @forelse($items as $item)
        @php
            $stemCount = max(1, count($item['stems'] ?? []));
            $markerId = 'demo-stem-arrow-' . $loop->index;
            $targetStart = 14;
            $targetRange = 92;
        @endphp
        <article class="demo-sample-card">
            <header class="demo-card-head">
                <h3>{{ $item['title'] ?? __('Stem Separation Example') }}</h3>
            </header>

            <div class="demo-stem-board">
                <section class="demo-stem-source-wrap">
                    <div class="demo-audio-card demo-stem-source">
                        <div class="demo-audio-card__meta">
                            <strong>{{ __('Source Song') }}</strong>
                        </div>
                        @if(!empty($item['original_audio']))
                            @include('landing.components.tool-demos._wave-player', [
                                'audioUrl' => (string) $item['original_audio'],
                                'title' => __('Source Song'),
                                'uid' => 'stem-source-' . $loop->index,
                            ])
                        @else
                            <div class="demo-inline-note">{{ __('Original mix is not configured yet.') }}</div>
                        @endif
                    </div>
                </section>

                <div class="demo-stem-connector" aria-hidden="true">
                    <svg class="demo-stem-connector-svg" viewBox="0 0 88 120" preserveAspectRatio="none" role="presentation">
                        <defs>
                            <marker id="{{ $markerId }}" markerWidth="8" markerHeight="8" refX="6.5" refY="4" orient="auto">
                                <path d="M0,0 L8,4 L0,8 Z" class="demo-stem-arrow-head"></path>
                            </marker>
                        </defs>
                        <path class="demo-stem-connector-trunk" d="M6 60 H24"></path>
                        @for($lineIndex = 0; $lineIndex < $stemCount; $lineIndex++)
                            @php
                                $targetY = $stemCount === 1
                                    ? 60
                                    : $targetStart + ($lineIndex * ($targetRange / max(1, $stemCount - 1)));
                                $targetYValue = number_format($targetY, 2, '.', '');
                            @endphp
                            <path
                                class="demo-stem-connector-branch"
                                d="M24 60 C42 60, 50 {{ $targetYValue }}, 82 {{ $targetYValue }}"
                                marker-end="url(#{{ $markerId }})"
                            ></path>
                        @endfor
                    </svg>
                    <span class="demo-stem-mobile-arrow">
                        <i class="bi bi-arrow-down"></i>
                    </span>
                </div>

                <section class="demo-stem-tracks">
                    @forelse($item['stems'] ?? [] as $stem)
                        <article class="demo-stem-track-card">
                            <div class="demo-audio-card">
                                <div class="demo-audio-card__meta">
                                    <strong>{{ $stem['label'] ?? __('Stem') }}</strong>
                                </div>
                                @if(!empty($stem['audio']))
                                    @include('landing.components.tool-demos._wave-player', [
                                        'audioUrl' => (string) $stem['audio'],
                                        'title' => (string) ($stem['label'] ?? ''),
                                        'uid' => 'stem-track-' . $loop->parent->index . '-' . $loop->index,
                                    ])
                                @else
                                    <div class="demo-inline-note">{{ __('Stem audio missing.') }}</div>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="demo-inline-note">{{ __('No stem channels are configured yet.') }}</div>
                    @endforelse
                </section>
            </div>

            @if(!empty($item['notes']))
                <p class="demo-inline-note mb-0 mt-2">{{ $item['notes'] }}</p>
            @endif
        </article>
    @empty
        <div class="demo-empty">{{ __('No STEM examples configured yet.') }}</div>
    @endforelse
</div>
