<div class="demo-sample-board">
    @forelse($items as $item)
        <article class="demo-sample-card">
            <header class="demo-card-head">
                <h3>{{ $item['title'] ?? __('OCR Example') }}</h3>
            </header>

            <div class="demo-ocr-grid">
                <div class="demo-sample-card demo-sample-card--inner">
                    <h4>{{ __('Input') }}</h4>
                    @if(!empty($item['image']))
                        <div class="demo-ocr-image-frame">
                            <img src="{{ $item['image'] }}" alt="{{ $item['title'] ?? __('OCR Input') }}" class="demo-ocr-image" loading="lazy">
                        </div>
                    @else
                        <div class="demo-inline-note">{{ __('Input image/GIF is not configured yet.') }}</div>
                    @endif
                </div>

                <div class="demo-sample-card demo-sample-card--inner">
                    <h4>{{ __('Extracted Text') }}</h4>
                    <pre class="demo-pre" dir="{{ $item['dir'] ?? 'auto' }}">{{ $item['extracted_text'] ?? '' }}</pre>
                </div>
            </div>

            @if(!empty($item['notes']))
                <p class="demo-inline-note mb-0 mt-2">{{ $item['notes'] }}</p>
            @endif
        </article>
    @empty
        <div class="demo-empty">{{ __('No OCR examples configured yet.') }}</div>
    @endforelse
</div>
