@props(['selectedReference' => null, 'referenceAudioName' => null, 'referenceAudioBytes' => null, 'referenceAudioMime' => null])

<section class="v2-ctts-reference-workflow">
    <div class="v2-panel-heading"><span>{{ __('Reference audio') }}</span><small>{{ __('Upload or reuse a voice') }}</small></div>
    <div class="v2-ctts-reference-loading" wire:loading.flex wire:target="selectReference">
        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>{{ __('Loading reference voice…') }}
    </div>
    @if($selectedReference)
        <div class="v2-ctts-selected-reference"><i class="ri-mic-2-line"></i><div><strong>{{ $selectedReference['name'] }}</strong><small>{{ __('Using a saved reference from your history.') }}</small></div><button type="button" class="btn btn-sm btn-outline-danger" wire:click="useAnotherReference">{{ __('Use another file') }}</button></div>
        @if($selectedReference['preview_url'])
            <div class="v2-ctts-selected-waveform-shell" wire:key="v2-ctts-selected-waveform-{{ $selectedReference['id'] }}">
                <div class="v2-ctts-selected-waveform" wire:ignore data-metkurd-waveform data-accent="danger" data-job="ctts-reference-{{ $selectedReference['id'] }}" data-url="{{ $selectedReference['preview_url'] }}">
                    <div class="v2-render-player-controls">
                        <button type="button" class="v2-waveform-toggle" data-metkurd-waveform-toggle aria-label="{{ __('Play or pause selected reference audio') }}"><i class="ri-play-fill" data-metkurd-waveform-icon></i></button>
                        <span class="v2-waveform-time" data-metkurd-waveform-time>00:00 / --:--</span>
                    </div>
                    <div class="v2-waveform-canvas" data-metkurd-waveform-canvas></div>
                    <small class="v2-waveform-load-state" data-metkurd-waveform-state>{{ __('Loading audio preview…') }}</small>
                </div>
            </div>
        @endif
    @else
        <p class="v2-muted mb-3">{{ __('Upload the voice you want to clone. Clean speech between 10 and 30 seconds works best.') }}</p>
        <div wire:ignore><input type="file" id="v2-ctts-reference-pond" accept=".wav,.mp3,.m4a,.aac,.ogg,.webm,audio/*"></div>
        <div wire:loading wire:target="referenceAudio" class="small text-danger mt-2">{{ __('Uploading reference audio…') }}</div>
        @if($referenceAudioName)<div class="v2-ctts-uploaded-reference"><i class="ri-file-music-line"></i><span><strong>{{ $referenceAudioName }}</strong><small>{{ $referenceAudioMime ?: 'audio/*' }} · {{ number_format(($referenceAudioBytes ?? 0) / 1024, 1) }} KB</small></span><button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeReferenceAudio">{{ __('Remove') }}</button></div>@endif
        @error('referenceAudio')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
    @endif
</section>
