<?php

use Livewire\Component;

new class extends Component
{
    public array $items = [];
    public string $columns = 'col-md-6 col-xl-4';
    public string $cardClass = '';
};
?>

<div class="row g-4">
    @foreach($items as $item)
        <div class="{{ $columns }}">
            <div class="feature-card glass-card reveal {{ $cardClass }}">
                @if(! empty($item['icon']))
                    <div class="icon-chip mb-3">
                        <i class="{{ $item['icon'] }}"></i>
                    </div>
                @endif

                <h3>{{ $item['title'] ?? '' }}</h3>

                @if(! empty($item['copy']))
                    <p class="text-muted-soft mb-0">{{ $item['copy'] }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
