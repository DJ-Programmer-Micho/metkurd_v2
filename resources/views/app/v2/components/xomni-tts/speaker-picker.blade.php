@props(['groups' => [], 'selected' => '', 'expanded' => ''])

<aside class="v2-workspace-panel v2-speaker-panel xomni-speaker-picker omni-reference-panel">
    <div class="v2-panel-heading">
        <span>{{ __('Speakers') }}</span>
        <small>{{ __('Choose a reference voice') }}</small>
    </div>

    @forelse($groups as $groupKey => $group)
        @php($isExpanded = $expanded === $groupKey)
        <section class="v2-speaker-group {{ $isExpanded ? 'is-expanded' : '' }}" wire:key="v2-speaker-group-{{ $groupKey }}">
            <button type="button" class="v2-speaker-group-toggle" wire:click="toggleSpeakerGroup('{{ $groupKey }}')" aria-expanded="{{ $isExpanded ? 'true' : 'false' }}">
                <span>{{ $group['label'] }}</span><span>{{ $isExpanded ? '−' : '+' }}</span>
            </button>

            @if($isExpanded)
                <div class="v2-speaker-group-list">
                    @foreach($group['speakers'] as $speaker)
                        @php($isSelected = $selected === $speaker['code'])
                        <article class="omni-voice-card v2-speaker-card {{ $isSelected ? 'is-selected' : '' }}" wire:key="v2-speaker-{{ $speaker['code'] }}">
                            <button type="button" class="v2-speaker-select" wire:click="selectSpeaker('{{ $speaker['code'] }}')" aria-pressed="{{ $isSelected ? 'true' : 'false' }}">
                                <span class="omni-voice-avatar">
                                    @if($speaker['avatar_url'])
                                        <img class="omni-voice-avatar-image" src="{{ $speaker['avatar_url'] }}" alt="" loading="lazy">
                                    @else
                                        <span class="omni-voice-avatar-fallback">{{ $speaker['initials'] }}</span>
                                    @endif
                                </span>
                                <span class="v2-speaker-copy"><strong>{{ $speaker['name'] }}</strong>
                                    @if($speaker['style'])<small class="omni-voice-tag omni-voice-tag--style">{{ $speaker['style'] }}</small>@endif
                                    @if($speaker['subtitle'])<small>{{ $speaker['subtitle'] }}</small>@endif
                                </span>
                                @if($isSelected)<i class="ri-checkbox-circle-fill v2-speaker-check" aria-label="{{ __('Selected') }}"></i>@endif
                            </button>

                            @if($speaker['preview_url'])
                                <div class="v2-speaker-preview" x-data="{ code: @js($speaker['code']), url: @js($speaker['preview_url']), playing: false, toggle() { window.MetKurdSpeakerPreview?.toggle(this.code, this.url) } }" x-on:metkurd-v2-speaker-preview.window="playing = $event.detail === code">
                                    <button type="button" class="omni-voice-preview-btn v2-speaker-preview-btn" x-on:click="toggle()" x-bind:aria-label="playing ? '{{ __('Playing') }}' : '{{ __('Preview') }}'"><i class="ri-play-fill" x-show="!playing"></i><i class="ri-pause-fill" x-show="playing"></i><span x-text="playing ? '{{ __('Playing') }}' : '{{ __('Preview') }}'"></span></button>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <div class="omni-voice-empty">{{ __('No voices are available for your current plan.') }}</div>
    @endforelse
</aside>
