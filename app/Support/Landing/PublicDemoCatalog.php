<?php

namespace App\Support\Landing;

/** Filter configured examples by their recorded product, never relabel legacy audio. */
class PublicDemoCatalog
{
    private const LEGACY_ENGINES = [
        'xomni' => 'apollo-1', 'clone_xomni' => 'vector-1',
    ];

    public function filter(string $family, mixed $config): array
    {
        $schema = app(LandingDemoSampleSchema::class)->normalizeConfig($family, $config, $family);
        if (($schema['type'] ?? null) !== $family) {
            return ['type' => $family, 'version' => 2, 'meta' => [], 'items' => [], 'groups' => []];
        }
        $products = collect(app(PublicProductCatalog::class)->family($family))->keyBy('key');
        $groups = [];
        $items = [];
        $accept = function (array $row, ?string $parent = null) use ($products, $family): ?array {
            if (! ($row['is_active'] ?? true)) {
                return null;
            }
            $key = $row['product'] ?? $parent ?? (self::LEGACY_ENGINES[$row['engine'] ?? ''] ?? null);
            if (isset($row['action'])) {
                $key = $products->firstWhere('action', $row['action'])['key'] ?? null;
                if (isset($row['product']) && $row['product'] !== $key) {
                    return null;
                }
            }
            $product = $products->get($key);
            if (! $product || ($family === 'ocr' && $key !== 'scanner')) {
                return null;
            }
            // Public payloads contain curated product names, never action codes.
            unset($row['action']);
            $row['product'] = $key;
            $row['engine'] = $key;
            $row['group_key'] = str_replace('-', '_', $key);
            $row['product_name'] = $product['name'];

            return $row;
        };
        foreach ($schema['items'] ?? [] as $row) {
            if ($row = $accept($row)) {
                $items[] = $row;
            }
        }
        foreach ($schema['groups'] ?? [] as $group) {
            if (! ($resolved = $accept($group))) {
                continue;
            }
            foreach ($group['samples'] ?? [] as $sample) {
                if ($sample = $accept($sample, $resolved['product'])) {
                    $items[] = $sample;
                }
            }
        }
        $voiceIds = array_values(array_filter(array_column($items, 'voice_id')));
        $publicVoices = $voiceIds === [] ? [] : \App\Models\Voice::query()
            ->where('is_active', true)->where('is_public', true)->where('meta->engine', 'xomni')
            ->whereIn('code', $voiceIds)->pluck('code')->all();
        $items = collect($items)->filter(function ($row) use ($publicVoices) {
            if (empty($row['voice_id'])) {
                return true;
            }

            return $row['product'] === 'apollo-1' && in_array($row['voice_id'], $publicVoices, true);
        })->sortBy('sort_order')->take(max(1, min(200, (int) config('landing.public_demo_limit', 60))))->values()->all();
        foreach ($products as $key => $product) {
            $samples = array_values(array_filter($items, fn ($row) => $row['product'] === $key));
            if ($samples === []) {
                continue;
            }
            $groups[] = ['key' => str_replace('-', '_', $key), 'label' => $product['name'],
                'engine' => $key, 'samples' => $samples, 'limit' => 0, 'random' => false];
        }
        if (! in_array($family, ['tts', 'ctts'], true)) {
            foreach ($items as &$item) {
                $title = (string) ($item['title'] ?? '');
                if ($title !== $item['product_name'] && ! str_starts_with($title, $item['product_name'].' — ')) {
                    $item['title'] = $item['product_name'].($title === '' ? '' : ' — '.$title);
                }
            }
        }

        return ['type' => $family, 'version' => 2, 'meta' => [], 'items' => $items, 'groups' => $groups];
    }
}
