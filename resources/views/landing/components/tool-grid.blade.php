<?php

use App\Models\Tool;
use App\Support\LandingContent;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $limit = 0;
    public bool $showButton = true;
    public bool $showMeta = true;
    public string $columnClass = 'col-md-6 col-xl-4';
    public string $buttonStyle = 'link';
    public ?string $buttonLabel = null;

    #[Computed]
    public function tools(): array
    {
        $catalog = collect(LandingContent::section('tool_catalog'));
        $supportedCodes = $catalog->keys()->all();

        $activeTools = Cache::remember('landing.active-marketing-tools', now()->addMinutes(15), function () use ($supportedCodes) {
            return Tool::query()
                ->where('is_active', true)
                ->whereIn('code', $supportedCodes)
                ->orderBy('sort_order')
                ->get(['code', 'name', 'sort_order'])
                ->map(fn (Tool $tool) => [
                    'code' => $tool->code,
                    'name' => $tool->name,
                    'sort_order' => $tool->sort_order,
                ])
                ->all();
        });

        $tools = collect($activeTools)
            ->map(function (array $tool) use ($catalog) {
                $copy = (array) ($catalog->get($tool['code']) ?? []);

                if ($copy === []) {
                    return null;
                }

                $slug = $copy['slug'] ?? $tool['code'];

                return array_merge($tool, $copy, [
                    'href' => route('landing.tools.show', [
                        'locale' => app()->getLocale(),
                        'slug' => $slug,
                    ]),
                ]);
            })
            ->filter()
            ->values();

        if ($this->limit > 0) {
            $tools = $tools->take($this->limit)->values();
        }

        return $tools->all();
    }
};
?>

@if($this->tools)
    <div class="row g-4">
        @foreach($this->tools as $tool)
            <div class="{{ $columnClass }}">
                <article class="tool-card glass-card reveal">
                    <div class="icon-chip mb-3">
                        <i class="{{ $tool['icon'] ?? 'bi bi-grid-1x2' }}"></i>
                    </div>

                    <h3>{{ $tool['title'] ?? $tool['name'] }}</h3>
                    <p class="text-muted-soft mb-0">{{ $tool['summary'] ?? '' }}</p>

                    @if($showMeta && ! empty($tool['capabilities']) && is_array($tool['capabilities']))
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @foreach($tool['capabilities'] as $capability)
                                <span class="mini-pill">{{ $capability }}</span>
                            @endforeach
                        </div>
                    @endif

                    @if($showButton)
                        @if($buttonStyle === 'button')
                            <a class="btn btn-outline-soft mt-3" href="{{ $tool['href'] }}" wire:navigate.hover>
                                {{ $buttonLabel ?: LandingContent::text('common.open_page') }}
                            </a>
                        @else
                            <a class="footer-link fw-semibold mt-3 d-inline-flex align-items-center gap-2" href="{{ $tool['href'] }}" wire:navigate.hover>
                                {{ $buttonLabel ?: LandingContent::text('common.learn_more') }}
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        @endif
                    @endif
                </article>
            </div>
        @endforeach
    </div>
@else
    <div class="glass-card landing-empty reveal">
        <h3>{{ LandingContent::text('tools_page.empty_title') }}</h3>
        <p class="text-muted-soft mb-0">{{ LandingContent::text('tools_page.empty_copy') }}</p>
    </div>
@endif
