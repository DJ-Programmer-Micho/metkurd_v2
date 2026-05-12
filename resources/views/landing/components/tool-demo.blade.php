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

        $normalized = $this->demoSchema()->normalizeConfig($type, $rawConfig, $slug);
        if ($normalized !== []) {
            return $normalized;
        }

        return [
            'type' => $type,
            'version' => 1,
            'meta' => [],
            'items' => [],
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
    public function ttsItems(): array
    {
        if ($this->demoType !== 'tts') {
            return [];
        }

        $items = $this->demoItems;
        if ($items === []) {
            return $this->fallbackTtsItemsFromVoices();
        }

        $voiceIds = collect($items)
            ->map(fn ($item): string => trim((string) data_get($item, 'voice_id', '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $voiceMap = Voice::query()
            ->where('is_active', true)
            ->when($voiceIds !== [], fn ($query) => $query->whereIn('code', $voiceIds))
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

                $engine = Str::lower(trim((string) data_get($row, 'engine', '')));
                if ($engine === '' && $voice instanceof Voice) {
                    $engine = Str::lower(trim((string) data_get((array) ($voice->meta ?? []), 'engine', '')));
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
                $hasContent = $label !== '' || $audio !== null || $voiceId !== '';

                if (! $hasContent) {
                    return null;
                }

                return [
                    'label' => $label,
                    'engine' => $engine,
                    'engine_label' => $this->engineLabel($engine),
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
     * @return array<int, array<string, mixed>>
     */
    protected function fallbackTtsItemsFromVoices(): array
    {
        return Voice::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['code', 'name', 'meta'])
            ->map(function (Voice $voice): array {
                $meta = is_array($voice->meta) ? $voice->meta : [];
                $engine = Str::lower(trim((string) data_get($meta, 'engine', $this->inferEngineFromVoiceId((string) $voice->code))));
                $label = trim((string) $voice->name);
                if ($label === '') {
                    $label = (string) $voice->code;
                }

                return [
                    'label' => $label,
                    'engine' => $engine,
                    'engine_label' => $this->engineLabel($engine),
                    'voice_id' => (string) $voice->code,
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

        return collect($this->demoItems)
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

                if ($source === null && $cloned === null && trim((string) data_get($row, 'title', '')) === '') {
                    return null;
                }

                return [
                    'title' => trim((string) data_get($row, 'title', '')),
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

    protected function inferEngineFromVoiceId(string $voiceId): string
    {
        $value = Str::lower(trim($voiceId));

        return match (true) {
            str_contains($value, 'apollo'), str_contains($value, 'xtts') => 'apollo',
            str_contains($value, 'delta'), str_contains($value, 'ftts') => 'delta',
            default => '',
        };
    }

    protected function engineLabel(string $engine): string
    {
        return match (Str::lower(trim($engine))) {
            'apollo' => 'Apollo',
            'delta' => 'Delta',
            'xtts' => 'XTTS',
            'ftts' => 'FTTS',
            default => $engine !== '' ? Str::headline($engine) : __('TTS'),
        };
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
                    ])
                @elseif($demoType === 'ctts')
                    @include('landing.components.tool-demos.ctts', [
                        'items' => $this->cttsItems,
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
