<?php

namespace App\Support\Landing;

use App\Support\AreaJsonTranslations;

class PublicWebsiteContent
{
    public const GITHUB_ORGANIZATION = 'https://github.com/MetKurdAI';

    public const API_REPOSITORY = 'https://github.com/MetKurdAI/metkurd-api';

    public const TERMINOLOGY_SOURCE_URL = 'https://www.whitehouse.gov/presidential-actions/2026/09/inaugurating-the-era-of-super-intelligence/';

    public function text(string $key, array $replace = [], ?string $locale = null): string
    {
        $value = AreaJsonTranslations::get('public.'.$key, 'landing', $locale) ?? '';
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, (string) $replacement, $value);
        }

        return $value;
    }

    public function homeDescription(): string
    {
        $catalog = app(PublicProductCatalog::class);
        $complete = $catalog->family('tts') !== [] && $catalog->family('ctts') !== []
            && $catalog->family('asr') !== [] && $catalog->has('scanner');

        return $this->text($complete ? 'home_description' : 'home_description_limited');
    }

    public function entity(?string $locale = null): string
    {
        $catalog = app(PublicProductCatalog::class);
        $families = collect($catalog->products())->pluck('family')->unique()
            ->filter(fn ($family) => $family !== 'ocr' || $catalog->has('scanner'))
            ->map(fn ($family) => $this->text('tools.'.$family.'.title', [], $locale))->all();
        if ($catalog->has('harakat-1')) {
            $families[] = $this->text('harakat_name', [], $locale);
        }
        if ($catalog->apiEnabled() || $catalog->mcpEnabled()) {
            $integrations = array_filter([$catalog->apiEnabled() ? 'API' : null, $catalog->mcpEnabled() ? 'MCP' : null]);
            $families[] = $this->text('developer', [], $locale).' ('.implode(', ', $integrations).')';
        }

        return $this->text('entity', ['services' => implode(' · ', $families)], $locale);
    }

    public function tool(string $slug, array $products, string $locale): array
    {
        $copySlug = $slug === 'ocr' && ! in_array('scanner', array_column($products, 'key'), true) ? 'harakat' : $slug;
        $copy = AreaJsonTranslations::group('public.tools.'.$copySlug, 'landing', $locale);
        $cards = [];
        foreach ($products as $product) {
            $cards[] = ['icon' => 'bi bi-stars', 'title' => $product['name'],
                'copy' => $this->text('products.'.$product['key'], [], $locale)];
        }

        return $copy + [
            'slug' => $slug, 'badge' => $copy['title'], 'icon' => 'bi bi-stars',
            'hero_text' => $copy['summary'], 'about_title' => $copy['title'],
            'about_copy' => $copy['summary'], 'use_cases_title' => $this->text('use_cases', [], $locale),
            'capabilities' => array_column($products, 'name'), 'feature_cards' => $cards,
            'meta_description' => $copy['summary'], 'is_active' => true,
        ];
    }

    public function faqs(string $section): array
    {
        $catalog = app(PublicProductCatalog::class);
        $rows = AreaJsonTranslations::group('public.'.$section.'_faq', 'landing');

        return collect($rows)->filter(function ($row, $key) use ($catalog): bool {
            return match ($key) {
                'tts', 'ctts', 'asr', 'stem' => $catalog->family($key) !== [],
                'ocr' => $catalog->has('scanner'),
                'caption' => $catalog->has('caption'), 'harakat' => $catalog->has('harakat-1'),
                'api' => $catalog->apiEnabled(), 'mcp' => $catalog->mcpEnabled(),
                default => true,
            };
        })->map(function ($row, $key) use ($catalog): array {
            $row['copy'] = str_replace(':products', implode(', ', $catalog->names($key)), $row['copy']);
            $row['copy'] = str_replace(':entity', $this->entity(), $row['copy']);

            return $row;
        })->values()->all();
    }
}
