<div class="demo-sample-board">
    <div class="demo-translation-grid">
        @forelse($items as $item)
            <article class="demo-sample-card">
                <header class="demo-card-head">
                    <h3>{{ !empty($item['title']) ? $item['title'] : __('Translation Example') }}</h3>
                    <span class="demo-chip">{{ $item['source_lang_label'] ?? 'SRC' }} -> {{ $item['target_lang_label'] ?? 'TGT' }}</span>
                </header>

                <div class="demo-text-pair">
                    <div class="demo-text-pair__block">
                        <label>{{ __('Source') }} ({{ $item['source_lang_label'] ?? 'SRC' }})</label>
                        <pre class="demo-pre" dir="{{ $item['source_dir'] ?? 'auto' }}">{{ $item['source_text'] ?? '' }}</pre>
                    </div>

                    <div class="demo-text-pair__block">
                        <label>{{ __('Target') }} ({{ $item['target_lang_label'] ?? 'TGT' }})</label>
                        <pre class="demo-pre" dir="{{ $item['target_dir'] ?? 'auto' }}">{{ $item['target_text'] ?? '' }}</pre>
                    </div>
                </div>
            </article>
        @empty
            <div class="demo-empty">{{ __('No translation examples configured yet.') }}</div>
        @endforelse
    </div>
</div>
