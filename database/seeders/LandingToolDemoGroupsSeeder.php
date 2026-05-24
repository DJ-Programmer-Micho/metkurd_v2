<?php

namespace Database\Seeders;

use App\Models\LandingToolPage;
use App\Models\Voice;
use App\Support\Landing\LandingDemoSampleSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LandingToolDemoGroupsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('landing_tool_pages')) {
            return;
        }

        $hasDemoTypeColumn = Schema::hasColumn('landing_tool_pages', 'demo_type');
        $hasDemoConfigColumn = Schema::hasColumn('landing_tool_pages', 'demo_config');
        $schema = app(LandingDemoSampleSchema::class);
        $voiceEngineMap = $this->voiceEngineMap();

        DB::transaction(function () use ($hasDemoTypeColumn, $hasDemoConfigColumn, $schema, $voiceEngineMap): void {
            $pages = LandingToolPage::query()
                ->orderBy('id')
                ->get();

            foreach ($pages as $page) {
                $content = is_array($page->content) ? $page->content : [];
                $shadowDemo = is_array(data_get($content, '_demo')) ? (array) data_get($content, '_demo') : [];

                $rawType = $hasDemoTypeColumn
                    ? (string) ($page->demo_type ?: data_get($shadowDemo, 'type', ''))
                    : (string) data_get($shadowDemo, 'type', '');

                $type = $schema->normalizeType($rawType, (string) $page->slug);
                if (! in_array($type, ['tts', 'ctts'], true)) {
                    continue;
                }

                $rawConfig = $hasDemoConfigColumn
                    ? ((is_array($page->demo_config) && $page->demo_config !== []) ? $page->demo_config : data_get($shadowDemo, 'config', []))
                    : data_get($shadowDemo, 'config', []);

                $normalized = $schema->normalizeConfig($type, $rawConfig, (string) $page->slug);
                if ($normalized === []) {
                    $normalized = [
                        'type' => $type,
                        'version' => 1,
                        'meta' => [],
                        'items' => [],
                        'groups' => [],
                    ];
                }

                $updatedConfig = $this->buildGroupedConfig($type, $normalized, $voiceEngineMap);

                if ($hasDemoTypeColumn) {
                    $page->demo_type = $type;
                }

                if ($hasDemoConfigColumn) {
                    $page->demo_config = $updatedConfig;
                }

                $content['_demo'] = [
                    'type' => $type,
                    'config' => $updatedConfig,
                ];
                $page->content = $content;
                $page->save();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function voiceEngineMap(): array
    {
        return Voice::query()
            ->get(['code', 'meta'])
            ->mapWithKeys(function (Voice $voice): array {
                $engine = $this->normalizeEngineForType(
                    'tts',
                    (string) data_get((array) ($voice->meta ?? []), 'engine', '')
                );

                return [(string) $voice->code => $engine];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $voiceEngineMap
     * @return array<string, mixed>
     */
    protected function buildGroupedConfig(string $type, array $config, array $voiceEngineMap): array
    {
        $items = is_array(data_get($config, 'items')) ? array_values((array) data_get($config, 'items')) : [];
        $groups = $this->mergeGroupsWithDefaults(
            $type,
            is_array(data_get($config, 'groups')) ? array_values((array) data_get($config, 'groups')) : []
        );

        $sampleSignaturesByGroup = [];
        foreach ($groups as $groupIndex => $group) {
            $groupKey = $this->normalizeGroupKey((string) data_get($group, 'key', ''));
            if ($groupKey === '') {
                continue;
            }

            $samples = is_array(data_get($group, 'samples'))
                ? array_values((array) data_get($group, 'samples'))
                : (is_array(data_get($group, 'items')) ? array_values((array) data_get($group, 'items')) : []);

            $groups[$groupIndex]['samples'] = $samples;
            $groups[$groupIndex]['items'] = $samples;
            $sampleSignaturesByGroup[$groupKey] = collect($samples)
                ->map(fn ($sample): string => $this->sampleSignature($type, is_array($sample) ? $sample : []))
                ->filter()
                ->values()
                ->all();
        }

        foreach ($items as $item) {
            $row = is_array($item) ? $item : [];
            $groupKey = $this->resolveGroupKeyForItem($type, $row, $voiceEngineMap);

            if ($groupKey === '') {
                continue;
            }

            $targetIndex = collect($groups)->search(
                fn (array $group): bool => $this->normalizeGroupKey((string) data_get($group, 'key', '')) === $groupKey
            );

            if ($targetIndex === false) {
                $blueprint = $this->groupBlueprintByKey($type, $groupKey);
                $groups[] = [
                    'key' => $groupKey,
                    'label' => (string) data_get($blueprint, 'label', Str::headline(str_replace('_', ' ', $groupKey))),
                    'engine' => (string) data_get($blueprint, 'engine', $this->normalizeEngineForType($type, (string) data_get($row, 'engine', ''))),
                    'description' => (string) data_get($blueprint, 'description', ''),
                    'limit' => (int) data_get($blueprint, 'limit', $type === 'tts' ? 6 : 2),
                    'random' => (bool) data_get($blueprint, 'random', $type === 'tts'),
                    'samples' => [],
                    'items' => [],
                ];
                $targetIndex = count($groups) - 1;
                $sampleSignaturesByGroup[$groupKey] = [];
            }

            $signature = $this->sampleSignature($type, $row);
            if ($signature === '' || in_array($signature, $sampleSignaturesByGroup[$groupKey] ?? [], true)) {
                continue;
            }

            $groups[$targetIndex]['samples'][] = $row;
            $groups[$targetIndex]['items'][] = $row;
            $sampleSignaturesByGroup[$groupKey][] = $signature;
        }

        return [
            'type' => $type,
            'version' => 1,
            'meta' => is_array(data_get($config, 'meta')) ? (array) data_get($config, 'meta') : [],
            'items' => $items,
            'groups' => array_values($groups),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function mergeGroupsWithDefaults(string $type, array $groups): array
    {
        $defaults = collect($this->defaultGroupsByType($type))
            ->keyBy(fn (array $group): string => $this->normalizeGroupKey((string) data_get($group, 'key', '')));
        $merged = [];

        foreach ($groups as $group) {
            $row = is_array($group) ? $group : [];
            $groupKey = $this->normalizeGroupKey((string) data_get($row, 'key', ''));
            $engine = $this->normalizeEngineForType($type, (string) data_get($row, 'engine', ''));

            if ($groupKey === '' && $engine !== '') {
                $groupKey = $this->groupKeyFromEngine($type, $engine);
            }
            if ($groupKey === '') {
                continue;
            }

            /** @var array<string, mixed> $base */
            $base = (array) ($defaults->get($groupKey, []) ?: []);

            $merged[] = [
                'key' => $groupKey,
                'label' => trim((string) data_get($row, 'label', (string) data_get($base, 'label', Str::headline(str_replace('_', ' ', $groupKey))))),
                'engine' => $engine !== '' ? $engine : (string) data_get($base, 'engine', ''),
                'description' => trim((string) data_get($row, 'description', (string) data_get($base, 'description', ''))),
                'limit' => (int) data_get($row, 'limit', data_get($base, 'limit', $type === 'tts' ? 6 : 2)),
                'random' => $this->toBool(data_get($row, 'random', data_get($base, 'random', $type === 'tts'))),
                'samples' => is_array(data_get($row, 'samples'))
                    ? array_values((array) data_get($row, 'samples'))
                    : (is_array(data_get($row, 'items')) ? array_values((array) data_get($row, 'items')) : []),
                'items' => is_array(data_get($row, 'items'))
                    ? array_values((array) data_get($row, 'items'))
                    : (is_array(data_get($row, 'samples')) ? array_values((array) data_get($row, 'samples')) : []),
            ];
        }

        $existingKeys = collect($merged)
            ->map(fn (array $group): string => $this->normalizeGroupKey((string) data_get($group, 'key', '')))
            ->filter()
            ->values()
            ->all();

        foreach ($this->defaultGroupsByType($type) as $default) {
            $groupKey = $this->normalizeGroupKey((string) data_get($default, 'key', ''));
            if ($groupKey === '' || in_array($groupKey, $existingKeys, true)) {
                continue;
            }

            $merged[] = $default;
        }

        return $merged;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defaultGroupsByType(string $type): array
    {
        return match ($type) {
            'tts' => [
                [
                    'key' => 'apollo_1_0v',
                    'label' => 'Apollo 1.0v',
                    'engine' => 'xtts',
                    'description' => 'Classic Kurdish TTS voices',
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'delta',
                    'label' => 'Delta',
                    'engine' => 'ftts',
                    'description' => 'Fast expressive TTS voices',
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'apollo_1_5v',
                    'label' => 'Apollo 1.5v',
                    'engine' => 'xomni',
                    'description' => 'OmniVoice multilingual TTS',
                    'limit' => 6,
                    'random' => true,
                    'samples' => [],
                    'items' => [],
                ],
            ],
            'ctts' => [
                [
                    'key' => 'vector_1_0v',
                    'label' => 'Vector 1.0v',
                    'engine' => 'clone_xtts',
                    'description' => 'Classic voice cloning',
                    'limit' => 2,
                    'random' => false,
                    'samples' => [],
                    'items' => [],
                ],
                [
                    'key' => 'vector_1_5v',
                    'label' => 'Vector 1.5v',
                    'engine' => 'clone_xomni',
                    'description' => 'OmniVoice voice cloning',
                    'limit' => 2,
                    'random' => false,
                    'samples' => [],
                    'items' => [],
                ],
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $voiceEngineMap
     */
    protected function resolveGroupKeyForItem(string $type, array $item, array $voiceEngineMap): string
    {
        $groupKey = $this->normalizeGroupKey((string) data_get($item, 'group_key', ''));
        if ($groupKey !== '') {
            return $groupKey;
        }

        $engine = $this->normalizeEngineForType($type, (string) data_get($item, 'engine', ''));

        if ($type === 'tts' && $engine === '') {
            $voiceId = trim((string) data_get($item, 'voice_id', ''));
            if ($voiceId !== '') {
                $engine = $this->normalizeEngineForType('tts', (string) ($voiceEngineMap[$voiceId] ?? ''));
            }
            if ($engine === '') {
                $engine = $this->inferTtsEngineFromVoiceId($voiceId);
            }
        }

        if ($type === 'ctts' && $engine === '') {
            $title = Str::lower(trim((string) data_get($item, 'title', '')));
            $notes = Str::lower(trim((string) data_get($item, 'notes', '')));
            $haystack = $title . ' ' . $notes;

            if (str_contains($haystack, '1.5') || str_contains($haystack, 'xomni') || str_contains($haystack, 'omni')) {
                $engine = 'clone_xomni';
            } else {
                $engine = 'clone_xtts';
            }
        }

        return $this->groupKeyFromEngine($type, $engine);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    protected function groupBlueprintByKey(string $type, string $groupKey): array
    {
        $needle = $this->normalizeGroupKey($groupKey);

        foreach ($this->defaultGroupsByType($type) as $group) {
            if ($this->normalizeGroupKey((string) data_get($group, 'key', '')) === $needle) {
                return $group;
            }
        }

        return [];
    }

    protected function groupKeyFromEngine(string $type, string $engine): string
    {
        $normalizedEngine = $this->normalizeEngineForType($type, $engine);

        if ($type === 'tts') {
            return match ($normalizedEngine) {
                'xtts' => 'apollo_1_0v',
                'ftts' => 'delta',
                'xomni' => 'apollo_1_5v',
                default => '',
            };
        }

        if ($type === 'ctts') {
            return match ($normalizedEngine) {
                'clone_xtts' => 'vector_1_0v',
                'clone_xomni' => 'vector_1_5v',
                default => '',
            };
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function sampleSignature(string $type, array $item): string
    {
        $signatureParts = match ($type) {
            'tts' => [
                'engine' => (string) data_get($item, 'engine', ''),
                'voice_id' => (string) data_get($item, 'voice_id', ''),
                'audio' => (string) data_get($item, 'audio', ''),
                'label' => (string) data_get($item, 'label', ''),
            ],
            'ctts' => [
                'engine' => (string) data_get($item, 'engine', ''),
                'source_audio' => (string) data_get($item, 'source_audio', ''),
                'cloned_audio' => (string) data_get($item, 'cloned_audio', ''),
                'title' => (string) data_get($item, 'title', ''),
                'source_label' => (string) data_get($item, 'source_label', ''),
                'cloned_label' => (string) data_get($item, 'cloned_label', ''),
            ],
            default => [],
        };

        $json = json_encode($signatureParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? md5($json) : '';
    }

    protected function normalizeGroupKey(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->value();
    }

    protected function normalizeEngineForType(string $type, string $engine): string
    {
        $value = Str::of($engine)
            ->lower()
            ->replace(['\\', '/', '.'], ['_', '_', '_'])
            ->replace('-', '_')
            ->trim()
            ->value();

        if ($value === '') {
            return '';
        }

        if ($type === 'tts') {
            return match ($value) {
                'apollo', 'apollo_classic', 'apollo_1_0v', 'tts', 'xtts' => 'xtts',
                'delta', 'ftts', 'f5tts' => 'ftts',
                'xomni', 'apollo_1_5v', 'omni', 'omnivoice' => 'xomni',
                default => $value,
            };
        }

        if ($type === 'ctts') {
            return match ($value) {
                'clone_tts', 'clone_xtts', 'vector', 'vector_classic', 'vector_1_0v', 'ctts' => 'clone_xtts',
                'clone_xomni', 'vector_1_5v', 'xomni', 'omni', 'omnivoice' => 'clone_xomni',
                default => $value,
            };
        }

        return $value;
    }

    protected function inferTtsEngineFromVoiceId(string $voiceId): string
    {
        $value = Str::lower(trim($voiceId));

        return match (true) {
            str_contains($value, 'xomni') => 'xomni',
            str_contains($value, 'delta'), str_contains($value, 'ftts') => 'ftts',
            str_contains($value, 'apollo'), str_contains($value, 'xtts') => 'xtts',
            default => '',
        };
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        if (is_string($value)) {
            return in_array(Str::lower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
