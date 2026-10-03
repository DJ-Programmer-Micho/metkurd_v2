<?php

use App\Support\LandingContent;
use App\Support\Landing\LandingToolPageCatalog;
use Illuminate\Support\Str;
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
        $tools = collect(app(LandingToolPageCatalog::class)->listForLocale(app()->getLocale()))
            ->map(function (array $tool) {
                $slug = trim((string) ($tool['slug'] ?? ''));

                if ($slug === '') {
                    return null;
                }

                return [
                    'slug' => $slug,
                    'name' => (string) ($tool['title'] ?? strtoupper($slug)),
                    'title' => (string) ($tool['title'] ?? strtoupper($slug)),
                    'summary' => (string) ($tool['summary'] ?? ''),
                    'square_image_url' => data_get($tool, 'square_image_url'),
                    'card_image_url' => data_get($tool, 'card_image_url'),
                    'capabilities' => $this->normalizeStringList($tool['capabilities'] ?? []),
                    'href' => route('landing.tools.show', [
                        'locale' => app()->getLocale(),
                        'slug' => $slug,
                    ]),
                ];
            })
            ->filter()
            ->values();

        $catalog = app(\App\Support\Landing\PublicProductCatalog::class);
        if ($catalog->apiEnabled() || $catalog->mcpEnabled()) {
            $copy = app(\App\Support\Landing\PublicWebsiteContent::class);
            $tools->push([
                'slug' => 'developers', 'title' => $copy->text('developer'),
                'summary' => $copy->text($catalog->apiEnabled() ? 'api_available' : 'mcp_available'),
                'capabilities' => array_values(array_filter([$catalog->apiEnabled() ? 'API' : null, $catalog->mcpEnabled() ? 'MCP' : null])),
                'href' => config('metkurd_v2.enabled')
                    ? route($catalog->apiEnabled() ? 'app.v2.api' : 'app.v2.mcp', ['locale' => app()->getLocale()])
                    : route('landing.pricing', ['locale' => app()->getLocale()]),
            ]);
        }

        if ($this->limit > 0) {
            $tools = $tools->take($this->limit)->values();
        }

        return $tools->all();
    }

    /**
     * @return string[]
     */
    protected function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $value),
            fn (string $item) => $item !== ''
        ));
    }
};
?>

@if($this->tools)
    <div class="row g-4">
        @foreach($this->tools as $tool)
            <div class="{{ $columnClass }}">
                <article class="tool-card glass-card reveal">
                    <div class="tool-card-media mb-3">
                    @if(! empty($tool['square_image_url']))
                        <div class="icon-chip tool-square-chip">
                            <img
                                src="{{ $tool['square_image_url'] }}"
                                alt="{{ $tool['title'] ?? $tool['name'] }}"
                                class="tool-square-image" width="64" height="64"
                                loading="lazy"
                            >
                        </div>
                    @elseif(! empty($tool['card_image_url']))
                        <img
                            src="{{ $tool['card_image_url'] }}"
                            alt="{{ $tool['title'] ?? $tool['name'] }}"
                            class="img-fluid rounded-3 w-100 tool-card-cover" width="640" height="360" style="height:auto"
                            loading="lazy"
                        >
                    @else
                        <div class="icon-chip">
                            <span class="tool-fallback-letter">{{ Str::upper(Str::substr((string) ($tool['title'] ?? $tool['name']), 0, 1)) }}</span>
                        </div>
                    @endif
                    </div>

                    <h3>{{ $tool['title'] ?? $tool['name'] }}</h3>
                    <p class="text-muted-soft mb-0">{{ $tool['summary'] ?? '' }}</p>

                    @if($showMeta && ! empty($tool['capabilities']) && is_array($tool['capabilities']))
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            @foreach($tool['capabilities'] as $capability)
                                <span class="mini-pill"><bdi dir="ltr">{{ $capability }}</bdi></span>
                            @endforeach
                        </div>
                    @endif

                    @if($showButton)
                        @if($buttonStyle === 'button')
                            <a class="btn btn-outline-soft mt-3" href="{{ $tool['href'] }}" wire:navigate>
                                {{ $buttonLabel ?: LandingContent::text('common.open_page') }}
                            </a>
                        @else
                            <a class="footer-link fw-semibold mt-3 d-inline-flex align-items-center gap-2" href="{{ $tool['href'] }}" wire:navigate>
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
