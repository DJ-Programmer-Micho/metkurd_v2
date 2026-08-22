@props(['references' => [], 'selectedId' => null])

<aside class="v2-workspace-panel v2-speaker-panel v2-ctts-history-panel">
    <div class="v2-panel-heading"><span>{{ __('Reference voices') }}</span><small>{{ __('Reuse a saved voice') }}</small></div>
    <div class="v2-ctts-reference-list">
        @forelse($references as $reference)
            @php($selected = (int) $selectedId === (int) $reference['id'])
            <article class="v2-ctts-reference {{ $selected ? 'is-selected' : '' }}" wire:key="v2-ctts-reference-{{ $reference['id'] }}" wire:loading.class="is-pending" wire:target="selectReference({{ $reference['id'] }})">
                <button type="button" wire:click="selectReference({{ $reference['id'] }})" x-on:click="window.MetKurdSpeakerPreview?.stop(); window.MetKurdWaveform?.stopAll()" aria-pressed="{{ $selected ? 'true' : 'false' }}">
                    <i class="ri-mic-2-line" aria-hidden="true"></i><span><strong>{{ $reference['name'] }}</strong><small>@if($reference['duration']){{ $reference['duration'] }} · @endif{{ number_format($reference['bytes'] / 1024, 1) }} KB · {{ $reference['when'] }}</small></span>
                    @if($selected)<i class="ri-checkbox-circle-fill" aria-label="{{ __('Selected') }}"></i>@endif
                </button>
                @if($reference['preview_url'])
                    <div class="v2-ctts-reference-preview" x-data="{ code: @js('ctts-reference-'.$reference['id']), url: @js($reference['preview_url']), playing: false, toggle() { window.MetKurdSpeakerPreview?.toggle(this.code, this.url) } }" x-on:metkurd-v2-speaker-preview.window="playing = $event.detail === code">
                        <button type="button" class="v2-speaker-preview-btn v2-ctts-preview-btn" x-on:click.stop="toggle" x-bind:aria-pressed="playing" x-bind:title="playing ? @js(__('Pause reference preview')) : @js(__('Play reference preview'))" aria-label="{{ __('Play reference preview') }}">
                            <i :class="playing ? 'ri-pause-fill' : 'ri-play-fill'" aria-hidden="true"></i><span x-text="playing ? @js(__('Pause')) : @js(__('Preview'))"></span>
                        </button>
                    </div>
                @endif
            </article>
        @empty
            <div class="v2-empty-state">{{ __('No saved reference voices yet. Upload a reference voice to create your first cloned speech.') }}</div>
        @endforelse
    </div>
    @if($references->hasPages())
        <nav class="v2-ctts-reference-pagination" aria-label="{{ __('Reference voices pagination') }}">
            <button type="button" wire:click="previousReferencePage" @disabled($references->onFirstPage())>{{ __('Previous') }}</button>
            <span>{{ $references->currentPage() }} / {{ $references->lastPage() }}</span>
            <button type="button" wire:click="nextReferencePage" @disabled(! $references->hasMorePages())>{{ __('Next') }}</button>
        </nav>
    @endif
</aside>
