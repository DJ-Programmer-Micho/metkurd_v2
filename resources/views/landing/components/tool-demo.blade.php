<?php

use App\Models\Voice;
use App\Support\Landing\LandingDemoSampleSchema;
use App\Support\Landing\LandingMediaStorage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** @var array<string, mixed> */
    public array $tool = [];

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function demoEnvelope(): array
    {
        $slug = (string) data_get($this->tool, 'slug', '');
        $rawType = data_get($this->tool, 'demo_type');
        $rawConfig = data_get($this->tool, 'demo_config', []);

        $type = $this->demoSchema()->normalizeType(is_string($rawType) ? $rawType : null, $slug);
        if ($type === null && is_array($rawConfig)) {
            $type = $this->demoSchema()->normalizeType((string) data_get($rawConfig, 'type', ''), $slug);
        }
        if ($type === null) {
            return [];
        }

        $normalized = app(\App\Support\Landing\PublicDemoCatalog::class)->filter($slug, $rawConfig);
        if ($normalized !== []) {
            return $normalized;
        }

        return [
            'type' => $type,
            'version' => 1,
            'meta' => [],
            'items' => [],
            'groups' => [],
        ];
    }

    #[Computed]
    public function demoType(): string
    {
        return trim((string) data_get($this->demoEnvelope, 'type', ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function demoItems(): array
    {
        $items = data_get($this->demoEnvelope, 'items', []);
        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function demoGroups(): array
    {
        $groups = data_get($this->demoEnvelope, 'groups', []);
        return is_array($groups) ? array_values($groups) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function ttsItems(): array
    {
        if ($this->demoType !== 'tts') {
            return [];
        }

        $items = $this->resolveTtsDemoEntries($this->demoItems);

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function ttsGroups(): array
    {
        if ($this->demoType !== 'tts') {
            return [];
        }

        $groupRows = $this->demoGroups;
        if ($groupRows === []) {
            return [];
        }

        $flatItems = $this->ttsItems;
        $resolved = [];

        foreach ($this->resolveConfiguredTtsGroups($groupRows) as $group) {
            $engine = (string) data_get($group, 'engine', '');
            $samples = $this->resolveTtsGroupSamples($group, $flatItems);
            $limited = $this->sliceGroupItems(
                $samples,
                (int) data_get($group, 'limit', 6),
                (bool) data_get($group, 'random', true)
            );

            $resolved[] = [
                'key' => (string) data_get($group, 'key', ''),
                'label' => (string) data_get($group, 'label', $this->engineLabel($engine)),
                'description' => (string) data_get($group, 'description', ''),
                'engine' => $engine,
                'engine_label' => $this->engineLabel($engine),
                'limit' => (int) data_get($group, 'limit', 6),
                'random' => (bool) data_get($group, 'random', true),
                'items' => (array) data_get($limited, 'items', []),
                'count' => count((array) data_get($limited, 'items', [])),
                'available_count' => (int) data_get($limited, 'available_count', 0),
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackTtsItemsFromVoices(?string $engine = null): array
    {
        $normalizedEngine = $this->normalizeDemoEngine((string) $engine, 'tts');

        return Voice::query()
            ->where('is_active', true)
            ->when($normalizedEngine !== '', fn ($query) => $query->where('meta->engine', $normalizedEngine))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['code', 'name', 'meta'])
            ->map(function (Voice $voice): array {
                $meta = is_array($voice->meta) ? $voice->meta : [];
                $engine = $this->normalizeDemoEngine(
                    (string) data_get($meta, 'engine', $this->inferEngineFromVoiceId((string) $voice->code)),
                    'tts'
                );
                $label = trim((string) $voice->name);
                if ($label === '') {
                    $label = (string) $voice->code;
                }

                return [
                    'label' => $label,
                    'engine' => $engine,
                    'engine_label' => $this->engineLabel($engine),
                    'voice_id' => (string) $voice->code,
                    'group_key' => $this->defaultTtsGroupKeyByEngine($engine),
                    'audio' => route('landing.tools.demo.voice.preview', [
                        'locale' => app()->getLocale(),
                        'voiceCode' => (string) $voice->code,
                        'proxy' => 1,
                    ]),
                    'avatar' => route('landing.tools.demo.voice.avatar', [
                        'locale' => app()->getLocale(),
                        'voiceCode' => (string) $voice->code,
                    ]),
                    'description' => '',
                    'initials' => $this->initials($label),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function cttsItems(): array
    {
        if ($this->demoType !== 'ctts') {
            return [];
        }

        return $this->resolveCttsDemoEntries($this->demoItems);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function cttsGroups(): array
    {
        if ($this->demoType !== 'ctts') {
            return [];
        }

        $groupRows = $this->demoGroups;
        if ($groupRows === []) {
            return [];
        }

        $flatItems = $this->cttsItems;
        $resolved = [];

        foreach ($this->resolveConfiguredCttsGroups($groupRows) as $group) {
            $engine = (string) data_get($group, 'engine', '');
            $samples = $this->resolveCttsGroupSamples($group, $flatItems);
            $limited = $this->sliceGroupItems(
                $samples,
                (int) data_get($group, 'limit', 0),
                (bool) data_get($group, 'random', false)
            );

            $resolved[] = [
                'key' => (string) data_get($group, 'key', ''),
                'label' => (string) data_get($group, 'label', $this->engineLabel($engine)),
                'description' => (string) data_get($group, 'description', ''),
                'engine' => $engine,
                'engine_label' => $this->engineLabel($engine),
                'limit' => (int) data_get($group, 'limit', 0),
                'random' => (bool) data_get($group, 'random', false),
                'items' => (array) data_get($limited, 'items', []),
                'count' => count((array) data_get($limited, 'items', [])),
                'available_count' => (int) data_get($limited, 'available_count', 0),
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function asrItems(): array
    {
        if ($this->demoType !== 'asr') {
            return [];
        }

        return collect($this->demoItems)
            ->map(function ($item): ?array {
                $row = is_array($item) ? $item : [];
                $audio = $this->resolveMediaUrl((string) data_get($row, 'audio', ''));
                $transcript = trim((string) data_get($row, 'transcript', ''));
                $language = Str::lower(trim((string) data_get($row, 'language', '')));

                if ($audio === null && $transcript === '') {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')) ?: __('ASR Example'),
                    'audio' => $audio,
                    'transcript' => $transcript,
                    'language' => $language,
                    'language_label' => $this->languageLabel($language),
                    'confidence' => trim((string) data_get($row, 'confidence', '')),
                    'notes' => trim((string) data_get($row, 'notes', '')),
                    'dir' => $this->textDirection($language, $transcript),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function stemItems(): array
    {
        if ($this->demoType !== 'stem') {
            return [];
        }

        $stemLabels = [
            'vocals' => __('Vocals'),
            'instrumental' => __('Instrumental'),
            'drums' => __('Drums'),
            'bass' => __('Bass'),
            'other' => __('Other'),
        ];

        return collect($this->demoItems)
            ->map(function ($item) use ($stemLabels): ?array {
                $row = is_array($item) ? $item : [];
                $original = $this->resolveMediaUrl((string) data_get($row, 'original_audio', ''));
                $stems = is_array(data_get($row, 'stems')) ? (array) data_get($row, 'stems') : [];

                $resolvedStems = collect($stemLabels)
                    ->map(function (string $label, string $key) use ($stems): ?array {
                        $audio = $this->resolveMediaUrl((string) data_get($stems, $key, ''));
                        if ($audio === null) {
                            return null;
                        }

                        return [
                            'key' => $key,
                            'label' => $label,
                            'audio' => $audio,
                        ];
                    })
                    ->filter(fn ($item) => is_array($item))
                    ->values()
                    ->all();

                if ($original === null && $resolvedStems === []) {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')) ?: __('Stem Separation Example'),
                    'original_audio' => $original,
                    'stems' => $resolvedStems,
                    'notes' => trim((string) data_get($row, 'notes', '')),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function ocrItems(): array
    {
        if ($this->demoType !== 'ocr') {
            return [];
        }

        return collect($this->demoItems)
            ->map(function ($item): ?array {
                $row = is_array($item) ? $item : [];
                $image = $this->resolveMediaUrl((string) data_get($row, 'image', ''));
                $text = trim((string) data_get($row, 'extracted_text', ''));

                if ($image === null && $text === '') {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')) ?: __('OCR Example'),
                    'image' => $image,
                    'extracted_text' => $text,
                    'notes' => trim((string) data_get($row, 'notes', '')),
                    'dir' => $this->textDirection(null, $text),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function translationItems(): array
    {
        if ($this->demoType !== 'translation') {
            return [];
        }

        return collect($this->demoItems)
            ->map(function ($item): ?array {
                $row = is_array($item) ? $item : [];
                $sourceLang = Str::lower(trim((string) data_get($row, 'source_lang', '')));
                $targetLang = Str::lower(trim((string) data_get($row, 'target_lang', '')));
                $sourceText = trim((string) data_get($row, 'source_text', ''));
                $targetText = trim((string) data_get($row, 'target_text', ''));

                if ($sourceText === '' && $targetText === '') {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')),
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'source_lang_label' => $this->languageLabel($sourceLang),
                    'target_lang_label' => $this->languageLabel($targetLang),
                    'source_text' => $sourceText,
                    'target_text' => $targetText,
                    'source_dir' => $this->textDirection($sourceLang, $sourceText),
                    'target_dir' => $this->textDirection($targetLang, $targetText),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function resolveTtsDemoEntries(array $items): array
    {
        $voiceIds = collect($items)
            ->map(fn ($item): string => trim((string) data_get($item, 'voice_id', '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $voiceMap = Voice::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->whereIn('code', $voiceIds)
            ->get(['code', 'name', 'meta'])
            ->keyBy('code');

        return collect($items)
            ->map(function ($item) use ($voiceMap): ?array {
                $row = is_array($item) ? $item : [];
                $voiceId = trim((string) data_get($row, 'voice_id', ''));
                $voice = $voiceId !== '' ? $voiceMap->get($voiceId) : null;

                $label = trim((string) data_get($row, 'label', ''));
                if ($label === '' && $voice instanceof Voice) {
                    $label = trim((string) $voice->name);
                }
                if ($label === '') {
                    $label = $voiceId !== '' ? $voiceId : __('Voice sample');
                }

                $engine = $this->normalizeDemoEngine((string) data_get($row, 'engine', ''), 'tts');
                if ($engine === '' && $voice instanceof Voice) {
                    $engine = $this->normalizeDemoEngine((string) data_get((array) ($voice->meta ?? []), 'engine', ''), 'tts');
                }
                if ($engine === '') {
                    $engine = $this->inferEngineFromVoiceId($voiceId);
                }

                $audio = $this->resolveTtsAudioUrl($row);
                if ($audio === null && $voiceId !== '') {
                    $audio = route('landing.tools.demo.voice.preview', [
                        'locale' => app()->getLocale(),
                        'voiceCode' => $voiceId,
                        'proxy' => 1,
                    ]);
                }

                $avatar = null;
                $configAvatar = $this->resolveMediaUrl((string) data_get($row, 'avatar', ''));

                if ($configAvatar !== null) {
                    $avatar = $configAvatar;
                } elseif ($voiceId !== '') {
                    $avatar = route('landing.tools.demo.voice.avatar', [
                        'locale' => app()->getLocale(),
                        'voiceCode' => $voiceId,
                    ]);
                }

                $description = trim((string) data_get($row, 'description', ''));
                $groupKey = $this->normalizeGroupKey((string) data_get($row, 'group_key', ''));
                if ($groupKey === '') {
                    $groupKey = $this->defaultTtsGroupKeyByEngine($engine);
                }

                $hasContent = $label !== '' || $audio !== null || $voiceId !== '';

                if (! $hasContent) {
                    return null;
                }

                return [
                    'label' => $label,
                    'engine' => $engine,
                    'engine_label' => $this->engineLabel($engine),
                    'group_key' => $groupKey,
                    'voice_id' => $voiceId,
                    'audio' => $audio,
                    'avatar' => $avatar,
                    'description' => $description,
                    'initials' => $this->initials($label),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function resolveCttsDemoEntries(array $items): array
    {
        return collect($items)
            ->map(function ($item): ?array {
                $row = is_array($item) ? $item : [];
                $source = $this->resolveMediaUrl((string) data_get($row, 'source_audio', ''));
                $cloned = $this->resolveMediaUrl((string) data_get($row, 'cloned_audio', ''));
                $sourceLabel = trim((string) data_get($row, 'source_label', ''));
                $clonedLabel = trim((string) data_get($row, 'cloned_label', ''));

                if ($sourceLabel === '') {
                    $sourceLabel = __('Original voice');
                }
                if ($clonedLabel === '') {
                    $clonedLabel = __('Cloned voice');
                }

                $engine = $this->normalizeDemoEngine((string) data_get($row, 'engine', ''), 'ctts');
                if ($engine === '') {
                    $engine = $this->inferCttsEngineFromRow($row);
                }

                $groupKey = $this->normalizeGroupKey((string) data_get($row, 'group_key', ''));
                if ($groupKey === '') {
                    $groupKey = $this->defaultCttsGroupKeyByEngine($engine);
                }

                if ($source === null && $cloned === null && trim((string) data_get($row, 'title', '')) === '') {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')),
                    'engine' => $engine,
                    'engine_label' => $this->engineLabel($engine),
                    'group_key' => $groupKey,
                    'source_label' => $sourceLabel,
                    'source_audio' => $source,
                    'cloned_label' => $clonedLabel,
                    'cloned_audio' => $cloned,
                    'notes' => trim((string) data_get($row, 'notes', '')),
                    'source_avatar' => $this->resolveMediaUrl((string) data_get($row, 'source_avatar', data_get($row, 'avatar', ''))),
                    'cloned_avatar' => $this->resolveMediaUrl((string) data_get($row, 'cloned_avatar', data_get($row, 'avatar', ''))),
                    'source_initials' => $this->initials($sourceLabel),
                    'cloned_initials' => $this->initials($clonedLabel),
                ];
            })
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function resolveConfiguredTtsGroups(array $groups): array
    {
        $defaults = collect($this->defaultTtsGroupDefinitions())
            ->keyBy('key');
        $resolved = [];

        foreach ($groups as $index => $group) {
            $row = is_array($group) ? $group : [];
            $key = $this->normalizeGroupKey((string) data_get($row, 'key', ''));
            $engine = $this->normalizeDemoEngine((string) data_get($row, 'engine', ''), 'tts');

            if ($key === '' && $engine !== '') {
                $key = $this->defaultTtsGroupKeyByEngine($engine);
            }
            if ($key === '') {
                $key = 'tts_group_' . ($index + 1);
            }

            /** @var array<string, mixed> $base */
            $base = (array) ($defaults->get($key, []) ?: []);
            if ($base === [] && $engine !== '') {
                $base = (array) ($defaults->first(fn (array $item): bool => (string) ($item['engine'] ?? '') === $engine) ?? []);
            }

            $resolved[] = [
                'key' => $key,
                'label' => trim((string) data_get($row, 'label', (string) data_get($base, 'label', Str::headline(str_replace('_', ' ', $key))))),
                'description' => trim((string) data_get($row, 'description', (string) data_get($base, 'description', ''))),
                'engine' => $engine !== '' ? $engine : (string) data_get($base, 'engine', ''),
                'limit' => (int) data_get($row, 'limit', data_get($base, 'limit', 6)),
                'random' => $this->toBool(data_get($row, 'random', data_get($base, 'random', true))),
                'samples' => is_array(data_get($row, 'samples'))
                    ? (array) data_get($row, 'samples')
                    : (is_array(data_get($row, 'items')) ? (array) data_get($row, 'items') : []),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function resolveConfiguredCttsGroups(array $groups): array
    {
        $defaults = collect($this->defaultCttsGroupDefinitions())
            ->keyBy('key');
        $resolved = [];

        foreach ($groups as $index => $group) {
            $row = is_array($group) ? $group : [];
            $key = $this->normalizeGroupKey((string) data_get($row, 'key', ''));
            $engine = $this->normalizeDemoEngine((string) data_get($row, 'engine', ''), 'ctts');

            if ($key === '' && $engine !== '') {
                $key = $this->defaultCttsGroupKeyByEngine($engine);
            }
            if ($key === '') {
                $key = 'ctts_group_' . ($index + 1);
            }

            /** @var array<string, mixed> $base */
            $base = (array) ($defaults->get($key, []) ?: []);
            if ($base === [] && $engine !== '') {
                $base = (array) ($defaults->first(fn (array $item): bool => (string) ($item['engine'] ?? '') === $engine) ?? []);
            }

            $resolved[] = [
                'key' => $key,
                'label' => trim((string) data_get($row, 'label', (string) data_get($base, 'label', Str::headline(str_replace('_', ' ', $key))))),
                'description' => trim((string) data_get($row, 'description', (string) data_get($base, 'description', ''))),
                'engine' => $engine !== '' ? $engine : (string) data_get($base, 'engine', ''),
                'limit' => (int) data_get($row, 'limit', data_get($base, 'limit', 0)),
                'random' => $this->toBool(data_get($row, 'random', data_get($base, 'random', false))),
                'samples' => is_array(data_get($row, 'samples'))
                    ? (array) data_get($row, 'samples')
                    : (is_array(data_get($row, 'items')) ? (array) data_get($row, 'items') : []),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, array<string, mixed>>  $flatItems
     * @return array<int, array<string, mixed>>
     */
    protected function resolveTtsGroupSamples(array $group, array $flatItems): array
    {
        $engine = $this->normalizeDemoEngine((string) data_get($group, 'engine', ''), 'tts');
        $groupKey = $this->normalizeGroupKey((string) data_get($group, 'key', ''));
        $samples = $this->resolveTtsDemoEntries((array) data_get($group, 'samples', []));

        if ($samples === [] && $flatItems !== []) {
            $samples = collect($flatItems)
                ->filter(function (array $item) use ($engine, $groupKey): bool {
                    $itemEngine = $this->normalizeDemoEngine((string) data_get($item, 'engine', ''), 'tts');
                    $itemGroupKey = $this->normalizeGroupKey((string) data_get($item, 'group_key', ''));

                    if ($groupKey !== '' && $itemGroupKey === $groupKey) {
                        return true;
                    }

                    return $engine !== '' && $itemEngine === $engine;
                })
                ->values()
                ->all();
        }

        return $samples;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<int, array<string, mixed>>  $flatItems
     * @return array<int, array<string, mixed>>
     */
    protected function resolveCttsGroupSamples(array $group, array $flatItems): array
    {
        $engine = $this->normalizeDemoEngine((string) data_get($group, 'engine', ''), 'ctts');
        $groupKey = $this->normalizeGroupKey((string) data_get($group, 'key', ''));
        $samples = $this->resolveCttsDemoEntries((array) data_get($group, 'samples', []));

        if ($samples === [] && $flatItems !== []) {
            $samples = collect($flatItems)
                ->filter(function (array $item) use ($engine, $groupKey): bool {
                    $itemEngine = $this->normalizeDemoEngine((string) data_get($item, 'engine', ''), 'ctts');
                    $itemGroupKey = $this->normalizeGroupKey((string) data_get($item, 'group_key', ''));

                    if ($groupKey !== '' && $itemGroupKey === $groupKey) {
                        return true;
                    }

                    if ($engine !== '' && $itemEngine === $engine) {
                        return true;
                    }

                    if ($engine === 'clone_xtts' && $itemEngine === '') {
                        return true;
                    }

                    return false;
                })
                ->values()
                ->all();
        }

        return $samples;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function sliceGroupItems(array $items, int $limit, bool $random): array
    {
        $normalized = array_values($items);
        $available = count($normalized);

        if ($available > 1 && $random) {
            shuffle($normalized);
        }

        if ($limit > 0) {
            $normalized = array_slice($normalized, 0, $limit);
        }

        return [
            'items' => $normalized,
            'available_count' => $available,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defaultTtsGroupDefinitions(): array
    {
        return [
            [
                'key' => 'apollo_1_0v',
                'label' => __('Apollo 1.0v'),
                'engine' => 'xtts',
                'description' => __('Classic Kurdish TTS voices'),
                'limit' => 6,
                'random' => true,
            ],
            [
                'key' => 'delta',
                'label' => __('Delta'),
                'engine' => 'ftts',
                'description' => __('Fast expressive TTS voices'),
                'limit' => 6,
                'random' => true,
            ],
            [
                'key' => 'apollo_1_5v',
                'label' => __('Apollo 1.5v'),
                'engine' => 'xomni',
                'description' => __('OmniVoice multilingual TTS'),
                'limit' => 6,
                'random' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defaultCttsGroupDefinitions(): array
    {
        return [
            [
                'key' => 'vector_1_0v',
                'label' => __('Vector 1.0v'),
                'engine' => 'clone_xtts',
                'description' => __('Classic voice cloning'),
                'limit' => 2,
                'random' => false,
            ],
            [
                'key' => 'vector_1_5v',
                'label' => __('Vector 1.5v'),
                'engine' => 'clone_xomni',
                'description' => __('OmniVoice voice cloning'),
                'limit' => 2,
                'random' => false,
            ],
        ];
    }

    protected function defaultTtsGroupKeyByEngine(string $engine): string
    {
        return match ($this->normalizeDemoEngine($engine, 'tts')) {
            'xtts' => 'apollo_1_0v',
            'ftts' => 'delta',
            'xomni' => 'apollo_1_5v',
            default => '',
        };
    }

    protected function defaultCttsGroupKeyByEngine(string $engine): string
    {
        return match ($this->normalizeDemoEngine($engine, 'ctts')) {
            'clone_xtts' => 'vector_1_0v',
            'clone_xomni' => 'vector_1_5v',
            default => '',
        };
    }

    protected function inferEngineFromVoiceId(string $voiceId): string
    {
        $value = Str::lower(trim($voiceId));

        return match (true) {
            str_contains($value, 'xomni') => 'xomni',
            str_contains($value, 'delta'), str_contains($value, 'ftts') => 'ftts',
            str_contains($value, 'apollo'), str_contains($value, 'xtts') => 'xtts',
            default => '',
        };
    }

    protected function engineLabel(string $engine): string
    {
        $product = collect(app(\App\Support\Landing\PublicProductCatalog::class)->products())
            ->first(fn ($row) => str_replace('-', '_', $row['key']) === $this->normalizeDemoEngine($engine));
        if ($product) {
            return $product['name'];
        }

        return match ($this->normalizeDemoEngine($engine)) {
            'xtts' => __('Apollo 1.0v'),
            'ftts' => __('Delta'),
            'xomni' => __('Apollo 1.5v'),
            'clone_xtts' => __('Vector 1.0v'),
            'clone_xomni' => __('Vector 1.5v'),
            default => $engine !== '' ? Str::headline($engine) : __('TTS'),
        };
    }

    protected function inferCttsEngineFromRow(array $row): string
    {
        $groupKey = $this->normalizeGroupKey((string) data_get($row, 'group_key', ''));
        if ($groupKey === 'vector_1_5v') {
            return 'clone_xomni';
        }
        if ($groupKey === 'vector_1_0v') {
            return 'clone_xtts';
        }

        $title = Str::lower(trim((string) data_get($row, 'title', '')));
        $notes = Str::lower(trim((string) data_get($row, 'notes', '')));
        $haystack = $title . ' ' . $notes;

        if (str_contains($haystack, '1.5') || str_contains($haystack, 'xomni') || str_contains($haystack, 'omni')) {
            return 'clone_xomni';
        }

        if (str_contains($haystack, 'classic') || str_contains($haystack, '1.0')) {
            return 'clone_xtts';
        }

        return '';
    }

    protected function normalizeDemoEngine(string $engine, ?string $type = null): string
    {
        $normalized = Str::of($engine)
            ->lower()
            ->replace(['\\', '/', '.'], ['_', '_', '_'])
            ->replace('-', '_')
            ->trim()
            ->value();

        if ($normalized === '') {
            return '';
        }

        if ($type === 'tts' || $type === null) {
            $ttsMapped = match ($normalized) {
                'apollo', 'apollo_classic', 'apollo_1_0v', 'tts', 'xtts' => 'xtts',
                'delta', 'ftts', 'f5tts' => 'ftts',
                'xomni', 'omni', 'omnivoice', 'apollo_1_5v' => 'xomni',
                default => $normalized,
            };

            if ($type === 'tts' || in_array($ttsMapped, ['xtts', 'ftts', 'xomni'], true)) {
                return $ttsMapped;
            }
        }

        if ($type === 'ctts' || $type === null) {
            return match ($normalized) {
                'clone_tts', 'clone_xtts', 'vector', 'vector_classic', 'vector_1_0v', 'ctts' => 'clone_xtts',
                'clone_xomni', 'vector_1_5v', 'xomni', 'omni', 'omnivoice' => 'clone_xomni',
                default => $normalized,
            };
        }

        return $normalized;
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

    protected function languageLabel(string $lang): string
    {
        return match (Str::lower(trim($lang))) {
            'ku', 'ckb' => 'KU',
            'ar' => 'AR',
            'en' => 'EN',
            'de' => 'DE',
            default => Str::upper(trim($lang)) !== '' ? Str::upper(trim($lang)) : __('N/A'),
        };
    }

    protected function textDirection(?string $lang, string $text): string
    {
        $language = Str::lower(trim((string) $lang));

        if (in_array($language, ['ku', 'ckb', 'ar', 'fa', 'ur'], true)) {
            return 'rtl';
        }

        if (in_array($language, ['en', 'de', 'fr', 'es', 'tr'], true)) {
            return 'ltr';
        }

        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text) === 1) {
            return 'rtl';
        }

        return 'ltr';
    }

    protected function initials(string $value): string
    {
        $parts = collect(preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY))
            ->filter()
            ->values();

        if ($parts->count() >= 2) {
            return mb_strtoupper(mb_substr((string) $parts[0], 0, 1) . mb_substr((string) $parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr((string) ($parts->first() ?? 'V'), 0, 2) ?: 'V');
    }

    protected function resolveMediaUrl(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return $this->mediaStorage()->publicUrl($path);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveTtsAudioUrl(array $row): ?string
    {
        $candidates = [
            data_get($row, 'audio'),
            data_get($row, 'audio_url'),
            data_get($row, 'preview_url'),
            data_get($row, 'url'),
            data_get($row, 'file_url'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            if (Str::startsWith($value, ['http://', 'https://'])) {
                return $this->forceVoicePreviewProxy($value);
            }

            if (Str::startsWith($value, ['/'])) {
                return $this->forceVoicePreviewProxy(url(ltrim($value, '/')));
            }

            if (Str::contains($value, '/tools/demos/voices/') && Str::contains($value, '/preview')) {
                return $this->forceVoicePreviewProxy(url(ltrim($value, '/')));
            }

            $resolved = $this->resolveMediaUrl($value);
            if ($resolved !== null) {
                return $this->forceVoicePreviewProxy($resolved);
            }
        }

        return null;
    }

    protected function forceVoicePreviewProxy(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return $url;
        }

        if (! Str::contains($normalized, '/tools/demos/voices/') || ! Str::contains($normalized, '/preview')) {
            return $normalized;
        }

        if (Str::contains($normalized, 'proxy=')) {
            return $normalized;
        }

        return $normalized . (Str::contains($normalized, '?') ? '&' : '?') . 'proxy=1';
    }

    protected function mediaStorage(): LandingMediaStorage
    {
        return app(LandingMediaStorage::class);
    }

    protected function demoSchema(): LandingDemoSampleSchema
    {
        return app(LandingDemoSampleSchema::class);
    }
};
?>

@php
    $demoType = $this->demoType;
    $toolTitle = (string) data_get($tool, 'title', __('Tool'));
@endphp

<div>
@if($demoType !== '')
    <section class="section pt-0">
        <div class="container">
            <div class="tool-demo-shell glass-card reveal" data-landing-demo data-demo-type="{{ $demoType }}">
                <div class="tool-demo-header">
                    <span class="section-badge">
                        <i class="bi bi-bezier2"></i>
                        {{ __('Pre-generated Demo Samples') }}
                    </span>
                    <h2 class="section-title h2 mt-3 mb-2">{{ __(':tool', ['tool' => $toolTitle]) }}</h2>
                    {{-- <h2 class="section-title h2 mt-3 mb-2">{{ __('How :tool Works', ['tool' => $toolTitle]) }}</h2> --}}
                    <p class="text-muted-soft mb-0">{{ __('Real examples configured by METKURD team so guests can preview outputs before using the tool.') }}</p>
                </div>

                @if($demoType === 'tts')
                    @include('landing.components.tool-demos.tts', [
                        'items' => $this->ttsItems,
                        'groups' => $this->ttsGroups,
                    ])
                @elseif($demoType === 'ctts')
                    @include('landing.components.tool-demos.ctts', [
                        'items' => $this->cttsItems,
                        'groups' => $this->cttsGroups,
                    ])
                @elseif($demoType === 'asr')
                    @include('landing.components.tool-demos.asr', [
                        'items' => $this->asrItems,
                    ])
                @elseif($demoType === 'stem')
                    @include('landing.components.tool-demos.stem', [
                        'items' => $this->stemItems,
                    ])
                @elseif($demoType === 'ocr')
                    @include('landing.components.tool-demos.ocr', [
                        'items' => $this->ocrItems,
                    ])
                @elseif($demoType === 'translation')
                    @include('landing.components.tool-demos.translation', [
                        'items' => $this->translationItems,
                    ])
                @endif
            </div>
        </div>
    </section>

    @once
        @push('scripts')
            <script
                data-navigate-once
                src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"
                onload="window.dispatchEvent(new CustomEvent('landing:wavesurfer-ready'))"
            ></script>
        @endpush
    @endonce
@endif
</div>
