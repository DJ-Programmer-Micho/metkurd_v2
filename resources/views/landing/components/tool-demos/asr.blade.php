<div class="demo-sample-board">
    @forelse($items as $item)
        <article class="demo-sample-card">
            <header class="demo-card-head">
                <h3>{{ $item['title'] ?? __('ASR Example') }}</h3>
                <div class="d-flex gap-2 flex-wrap">
                    @if(!empty($item['language_label']))
                        <span class="demo-chip">{{ $item['language_label'] }}</span>
                    @endif
                    @if(!empty($item['confidence']))
                        <span class="demo-chip">{{ $item['confidence'] }}</span>
                    @endif
                </div>
            </header>

            @if(!empty($item['audio']))
                <div class="mb-3">
                    @include('landing.components.tool-demos._wave-player', [
                        'audioUrl' => (string) $item['audio'],
                        'title' => (string) ($item['title'] ?? ''),
                        'uid' => 'asr-' . $loop->index,
                    ])
                </div>
            @endif

            <pre class="demo-pre demo-pre--transcript" dir="{{ $item['dir'] ?? 'auto' }}">{{ $item['transcript'] ?? '' }}</pre>

            @if(!empty($item['notes']))
                <p class="demo-inline-note mb-0 mt-2">{{ $item['notes'] }}</p>
            @endif
        </article>
    @empty
        <div class="demo-empty">{{ __('No ASR examples configured yet.') }}</div>
    @endforelse
</div>
