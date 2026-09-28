<?php

namespace App\Support\Landing;

use App\Models\LandingToolPage;
use App\Models\ToolAction;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Public identity and availability only; never an entitlement or pricing authority. */
class PublicProductCatalog
{
    public const CACHE_KEY = 'landing:public-products:v2';

    private const FAMILIES = [
        'text-to-speech' => 'tts',
        'clone-text-to-speech' => 'ctts',
        'speech-to-text' => 'asr',
        'ocr' => 'ocr',
        'stem' => 'stem',
    ];

    public function products(): array
    {
        $request = request();
        if ($request->attributes->has(self::CACHE_KEY)) {
            return $request->attributes->get(self::CACHE_KEY);
        }
        $read = function (): array {
            $definitions = $this->definitions();

            $hidden = LandingToolPage::query()->where('is_active', false)->pluck('slug')->all();

            return ToolAction::query()
                ->where('is_active', true)
                ->whereIn('full_code', array_keys($definitions))
                ->whereHas('tool', fn ($query) => $query->where('is_active', true))
                ->get(['id', 'full_code', 'tool_code', 'default_metric_code'])
                ->map(function (ToolAction $action) use ($definitions, $hidden): ?array {
                    $product = $definitions[$action->full_code];
                    if ($product['tool_code'] !== $action->tool_code || in_array($product['family'], $hidden, true)) {
                        return null;
                    }

                    return $product + ['action_id' => $action->id, 'action' => $action->full_code, 'metric' => $action->default_metric_code];
                })->filter()->sortBy(fn ($product) => array_search($product['action'], array_keys($definitions), true))->values()->all();
        };

        // Never publish transaction-local catalog edits into the shared cache.
        $products = DB::transactionLevel() > 0 ? $read() : Cache::remember(self::CACHE_KEY, 300, $read);
        $request->attributes->set(self::CACHE_KEY, $products);

        return $products;
    }

    private function definitions(): array
    {
        $definitions = [];
        foreach (app(MetKurdV2ToolCatalog::class)->services() as $service => $family) {
            foreach ($family['tools'] ?? [] as $key => $definition) {
                if (! isset(self::FAMILIES[$service], $definition['legacy_action']) || ($definition['coming_soon'] ?? false)) {
                    continue;
                }
                $definitions[$definition['legacy_action']] = [
                    'key' => $key,
                    'family' => self::FAMILIES[$service],
                    'name' => match ($key) {
                        '2-stem' => 'STEM 2', '4-stem' => 'STEM 4',
                        default => preg_replace('/v$/', '', $definition['name']),
                    },
                    'tool_code' => $definition['legacy_tool'],
                ];
            }
        }

        return $definitions;
    }

    public function currentFamilySlugs(): array
    {
        return array_values(array_unique(array_column($this->definitions(), 'family')));
    }

    public function publicFamilySlugs(): array
    {
        return array_values(array_unique(array_column($this->products(), 'family')));
    }

    public function visibilityStatus(LandingToolPage $page): string
    {
        if (! in_array($page->slug, $this->currentFamilySlugs(), true)) {
            return 'legacy';
        }

        return $page->is_active && in_array($page->slug, $this->publicFamilySlugs(), true) ? 'active' : 'inactive';
    }

    public function family(string $slug): array
    {
        return array_values(array_filter($this->products(), fn ($product) => $product['family'] === $slug));
    }

    public function names(?string $slug = null): array
    {
        return array_column($slug === null ? $this->products() : $this->family($slug), 'name');
    }

    public function has(string $key): bool
    {
        return in_array($key, array_column($this->products(), 'key'), true);
    }

    public function apiEnabled(): bool
    {
        return (bool) config('customer_api.v2_enabled', false);
    }

    public function mcpEnabled(): bool
    {
        return (bool) config('mcp.enabled', false);
    }

    public function forget(): void
    {
        request()->attributes->remove(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY);
        foreach (['en', 'ar', 'ku'] as $locale) {
            Cache::forget('landing:public-pages:v2:'.$locale);
        }
        app(\App\Support\LandingPricingCatalog::class)->flushServicePlanCache();
    }
}
