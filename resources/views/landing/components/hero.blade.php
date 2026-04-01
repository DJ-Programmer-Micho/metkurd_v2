<?php

use Livewire\Component;

new class extends Component
{
    public string $icon = 'bi bi-stars';
    public string $badge = '';
    public string $title = '';
    public string $lead = '';
    public bool $compact = false;
    public array $actions = [];
    public array $pills = [];
};
?>

<section class="hero {{ $compact ? 'py-5' : '' }}">
    <span class="hero-orb orb-1"></span>
    <span class="hero-orb orb-2"></span>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 text-center">
                @if($badge !== '')
                    <span class="hero-badge reveal mb-4">
                        <i class="{{ $icon }}"></i>
                        {{ $badge }}
                    </span>
                @endif

                <h1 class="display-hero reveal mb-3">{{ $title }}</h1>

                @if($lead !== '')
                    <p class="lead-soft reveal mx-auto mt-3">{{ $lead }}</p>
                @endif

                @if(! empty($actions))
                    <div class="d-flex justify-content-center flex-wrap gap-3 mt-4 reveal">
                        @foreach($actions as $action)
                            <a
                                class="btn {{ ($action['style'] ?? 'primary') === 'primary' ? 'btn-glow' : 'btn-outline-soft' }} btn-lg rounded-pill px-4"
                                href="{{ $action['href'] ?? 'javascript:void(0)' }}"
                                @if(($action['navigate'] ?? true) === true)
                                    wire:navigate
                                @endif
                            >
                                @if(! empty($action['icon']))
                                    <i class="{{ $action['icon'] }}"></i>
                                @endif
                                {{ $action['label'] ?? '' }}
                            </a>
                        @endforeach
                    </div>
                @endif

                @if(! empty($pills))
                    <div class="d-flex justify-content-center flex-wrap gap-2 mt-4 reveal">
                        @foreach($pills as $pill)
                            <span class="mini-pill">{{ $pill }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
