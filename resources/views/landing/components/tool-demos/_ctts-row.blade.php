<article class="demo-clone-card" dir="ltr">
    @if(!empty($example['title']))
        <h4>{{ $example['title'] }}</h4>
    @endif

    <div class="demo-clone-flow">
        <section class="demo-clone-person">
            <div class="demo-clone-person__head">
                <div class="demo-clone-avatar">
                    @if(!empty($example['source_avatar']))
                        <img
                            src="{{ $example['source_avatar'] }}"
                            alt="{{ $example['source_label'] ?? __('Original voice') }}"
                            loading="lazy"
                            onload="this.nextElementSibling && (this.nextElementSibling.style.display='none');"
                            onerror="this.style.display='none'; this.nextElementSibling && (this.nextElementSibling.style.display='grid');"
                        >
                    @endif
                    <span>{{ $example['source_initials'] ?? 'OR' }}</span>
                </div>
                <div class="demo-clone-person__meta">
                    <strong>{{ $example['source_label'] ?? __('Original voice') }}</strong>
                </div>
            </div>

            @if(!empty($example['source_audio']))
                @include('landing.components.tool-demos._wave-player', [
                    'audioUrl' => (string) $example['source_audio'],
                    'title' => (string) ($example['source_label'] ?? ''),
                    'uid' => $uidPrefix . '-source',
                ])
            @else
                <div class="demo-inline-note">{{ __('Original sample not configured.') }}</div>
            @endif
        </section>

        <div class="demo-clone-arrow" aria-hidden="true">
            <i class="bi bi-arrow-right"></i>
        </div>

        <section class="demo-clone-person">
            <div class="demo-clone-person__head">
                <div class="demo-clone-avatar">
                    @if(!empty($example['cloned_avatar']))
                        <img
                            src="{{ $example['cloned_avatar'] }}"
                            alt="{{ $example['cloned_label'] ?? __('Cloned voice') }}"
                            loading="lazy"
                            onload="this.nextElementSibling && (this.nextElementSibling.style.display='none');"
                            onerror="this.style.display='none'; this.nextElementSibling && (this.nextElementSibling.style.display='grid');"
                        >
                    @endif
                    <span>{{ $example['cloned_initials'] ?? 'CL' }}</span>
                </div>
                <div class="demo-clone-person__meta">
                    <strong>{{ $example['cloned_label'] ?? __('Cloned voice') }}</strong>
                </div>
            </div>

            @if(!empty($example['cloned_audio']))
                @include('landing.components.tool-demos._wave-player', [
                    'audioUrl' => (string) $example['cloned_audio'],
                    'title' => (string) ($example['cloned_label'] ?? ''),
                    'uid' => $uidPrefix . '-cloned',
                ])
            @else
                <div class="demo-inline-note">{{ __('Cloned sample not configured.') }}</div>
            @endif
        </section>
    </div>

    @if(!empty($example['notes']))
        <p class="demo-clone-notes">{{ $example['notes'] }}</p>
    @endif
</article>
