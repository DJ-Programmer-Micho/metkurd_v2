@props(['renders' => [], 'locale' => 'en'])

<aside class="v2-workspace-panel v2-renders-panel">
    <div class="v2-panel-heading"><span>{{ __('Recent Renders') }}</span><small>{{ __('Your Apollo history') }}</small></div>
    <div class="v2-render-list">
        @forelse($renders as $render)
            <article class="v2-render-item is-{{ $render['status_semantic'] }}" wire:key="v2-render-{{ $render['id'] }}">
                <div class="d-flex justify-content-between gap-2"><strong>{{ $render['label'] }}</strong><span class="v2-render-status is-{{ $render['status_semantic'] }}">{{ $render['status_label'] }}</span></div>
                <p>{{ $render['text'] }}</p>
                <small class="v2-muted">{{ $render['speaker'] }} · {{ $render['when'] }}</small>
                @if($render['status'] === 'failed')
                    <small class="v2-render-outcome">{{ __('Generation could not be completed.') }}</small>
                @elseif(in_array($render['status'], ['cancelled', 'canceled'], true))
                    <small class="v2-render-outcome">{{ __('Generation was cancelled.') }}</small>
                @endif
                @if($render['output_unavailable'] ?? false)
                    <small class="v2-render-outcome">{{ __('This file is no longer available in storage.') }}</small>
                @endif
                @if($render['stream_url'])
                    <div class="v2-render-player" wire:ignore data-metkurd-waveform data-job="{{ $render['id'] }}" data-url="{{ $render['stream_url'] }}">
                        <div class="v2-render-player-controls">
                            <button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause audio') }}">
                                <i class="ri-play-fill" data-metkurd-waveform-icon></i>
                            </button>
                            <span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span>
                        </div>
                        <div id="v2-waveform-{{ $render['id'] }}" class="v2-waveform-canvas" data-metkurd-waveform-canvas></div>
                    </div>
                    <a class="btn btn-sm btn-outline-light mt-2" href="{{ $render['download_url'] }}">{{ __('Download') }}</a>
                @endif
            </article>
        @empty
            <div class="v2-empty-state">{{ __('Your generated audio will appear here.') }}</div>
        @endforelse
    </div>
    @if($renders->hasPages())
        <nav class="v2-render-pagination" aria-label="{{ __('Recent renders pagination') }}">
            <button type="button" wire:click="previousRecentRendersPage" @disabled($renders->onFirstPage())>{{ __('Previous') }}</button>
            <span>{{ $renders->currentPage() }} / {{ $renders->lastPage() }}</span>
            <button type="button" wire:click="nextRecentRendersPage" @disabled(! $renders->hasMorePages())>{{ __('Next') }}</button>
        </nav>
    @endif
    <a wire:navigate class="v2-history-link" href="{{ route('app.v2.storage', ['locale' => $locale]) }}">{{ __('View all history') }} <i class="ri-arrow-right-line"></i></a>
</aside>
