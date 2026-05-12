@php
    $audioUrl = trim((string) ($audioUrl ?? ''));
    $title = trim((string) ($title ?? ''));
    $uid = trim((string) ($uid ?? uniqid('wave_', true)));
@endphp

@if($audioUrl !== '')
    <div class="demo-wave-player" data-demo-wave data-audio-url="{{ $audioUrl }}" data-wave-id="{{ $uid }}" data-wave-ready="0">
        <div class="demo-wave-main">
            <button type="button" class="demo-wave-play" data-wave-play aria-label="{{ __('Play audio') }}">
                <i class="bi bi-play-fill" data-wave-icon></i>
            </button>

            <div class="demo-wave-content">
                @if($title !== '')
                    <div class="demo-wave-title">{{ $title }}</div>
                @endif
                <div class="demo-waveform" data-waveform></div>
            </div>

            <span class="demo-wave-time" data-wave-time>0:00 / 0:00</span>
        </div>

        <audio controls preload="none" src="{{ $audioUrl }}" class="demo-wave-fallback" data-wave-fallback></audio>
    </div>
@endif
