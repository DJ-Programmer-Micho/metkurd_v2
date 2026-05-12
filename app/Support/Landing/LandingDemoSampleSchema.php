<?php

namespace App\Support\Landing;

use Illuminate\Support\Str;

class LandingDemoSampleSchema
{
    /**
     * @return array<int, string>
     */
    public function allowedTypes(): array
    {
        return ['tts', 'ctts', 'asr', 'stem', 'ocr', 'translation'];
    }

    /**
     * @return array<string, string>
     */
    public function adminTypeOptions(): array
    {
        return [
            'tts' => __('TTS pre-generated samples'),
            'ctts' => __('CTTS clone comparison samples'),
            'asr' => __('ASR transcription samples'),
            'stem' => __('STEM separation samples'),
            'ocr' => __('OCR extraction samples'),
            'translation' => __('Translation pair samples'),
        ];
    }

    public function normalizeType(?string $rawType, string $slug = ''): ?string
    {
        $normalized = Str::of((string) $rawType)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->value();

        $mapped = match ($normalized) {
            'tts', 'tts-dual' => 'tts',
            'ctts', 'clone-tts', 'ctts-vector' => 'ctts',
            'asr', 'asr-dual', 'wasr', 'qasr' => 'asr',
            'stem', 'stem-split' => 'stem',
            'ocr', 'ocr-flow' => 'ocr',
            'translation', 'tran', 'translation-flow', 'translate', 'trans-ckb' => 'translation',
            default => null,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        $slugNormalized = Str::of($slug)
            ->trim()
            ->lower()
            ->replace('_', '-')
            ->value();

        return match ($slugNormalized) {
            'tts' => 'tts',
            'ctts', 'clone-tts', 'clone-xtts' => 'ctts',
            'asr', 'wasr', 'qasr' => 'asr',
            'stem' => 'stem',
            'ocr' => 'ocr',
            'translation', 'tran', 'translate', 'trans-ckb' => 'translation',
            default => null,
        };
    }

    /**
     * @param  mixed  $rawConfig
     * @return array<string, mixed>
     */
    public function normalizeConfig(?string $rawType, mixed $rawConfig, string $slug = ''): array
    {
        $config = is_array($rawConfig) ? $rawConfig : [];
        $envelopeType = $this->normalizeType(
            is_string(data_get($config, 'type')) ? (string) data_get($config, 'type') : $rawType,
            $slug
        );

        if ($envelopeType === null) {
            return [];
        }

        $meta = $this->normalizeMeta(is_array(data_get($config, 'meta')) ? (array) data_get($config, 'meta') : []);
        $items = is_array(data_get($config, 'items')) ? (array) data_get($config, 'items') : [];

        if ($items === []) {
            [$legacyMeta, $legacyItems] = $this->normalizeLegacyPayload($envelopeType, $config);
            $meta = array_merge($legacyMeta, $meta);
            $items = $legacyItems;
        }

        $normalizedItems = array_values(array_filter(
            array_map(fn ($item) => $this->normalizeItem($envelopeType, $item), $items),
            fn ($item) => is_array($item) && $item !== []
        ));

        return [
            'type' => $envelopeType,
            'version' => 1,
            'meta' => $meta,
            'items' => $normalizedItems,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function normalizeMeta(array $meta): array
    {
        $normalized = [];

        $sampleText = trim((string) data_get($meta, 'sample_text', ''));
        if ($sampleText !== '') {
            $normalized['sample_text'] = $sampleText;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<string, mixed>, 1: array<int, mixed>}
     */
    protected function normalizeLegacyPayload(string $type, array $config): array
    {
        $meta = [];
        $sampleText = trim((string) data_get($config, 'sample_text', ''));
        if ($sampleText !== '') {
            $meta['sample_text'] = $sampleText;
        }

        return match ($type) {
            'tts' => [$meta, $this->legacyTtsItems($config)],
            'ctts' => [$meta, $this->legacyCttsItems($config)],
            'asr' => [$meta, $this->legacyAsrItems($config)],
            'stem' => [$meta, $this->legacyStemItems($config)],
            'ocr' => [$meta, $this->legacyOcrItems($config)],
            'translation' => [$meta, $this->legacyTranslationItems($config)],
            default => [$meta, []],
        };
    }

    /**
     * @param  mixed  $item
     * @return array<string, mixed>
     */
    protected function normalizeItem(string $type, mixed $item): array
    {
        $row = is_array($item) ? $item : [];

        return match ($type) {
            'tts' => $this->normalizeTtsItem($row),
            'ctts' => $this->normalizeCttsItem($row),
            'asr' => $this->normalizeAsrItem($row),
            'stem' => $this->normalizeStemItem($row),
            'ocr' => $this->normalizeOcrItem($row),
            'translation' => $this->normalizeTranslationItem($row),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeTtsItem(array $item): array
    {
        $audio = $this->normalizeMediaValue(data_get($item, 'audio', data_get($item, 'audio_url')));
        $label = trim((string) data_get($item, 'label', data_get($item, 'name', '')));
        $engine = trim((string) data_get($item, 'engine', ''));
        $voiceId = trim((string) data_get($item, 'voice_id', data_get($item, 'voice_code', '')));
        $description = trim((string) data_get($item, 'description', ''));

        if ($label === '' && $voiceId !== '') {
            $label = $voiceId;
        }

        if ($label === '' && $audio === null) {
            return [];
        }

        return array_filter([
            'label' => $label !== '' ? $label : __('Voice Sample'),
            'engine' => $engine,
            'voice_id' => $voiceId,
            'audio' => $audio,
            'description' => $description,
        ], fn ($value) => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeCttsItem(array $item): array
    {
        $sourceAudio = $this->normalizeMediaValue(data_get($item, 'source_audio', data_get($item, 'sample_audio', data_get($item, 'source_url'))));
        $clonedAudio = $this->normalizeMediaValue(data_get($item, 'cloned_audio', data_get($item, 'target_audio', data_get($item, 'cloned_url'))));
        $title = trim((string) data_get($item, 'title', data_get($item, 'label', '')));
        $sourceLabel = trim((string) data_get($item, 'source_label', data_get($item, 'sample_label', '')));
        $clonedLabel = trim((string) data_get($item, 'cloned_label', data_get($item, 'target_label', '')));
        $notes = trim((string) data_get($item, 'notes', data_get($item, 'description', '')));

        $hasContent = $sourceAudio !== null
            || $clonedAudio !== null
            || $title !== ''
            || $sourceLabel !== ''
            || $clonedLabel !== ''
            || $notes !== '';

        if (! $hasContent) {
            return [];
        }

        return array_filter([
            'title' => $title,
            'source_label' => $sourceLabel,
            'source_audio' => $sourceAudio,
            'cloned_label' => $clonedLabel,
            'cloned_audio' => $clonedAudio,
            'notes' => $notes,
        ], fn ($value) => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeAsrItem(array $item): array
    {
        $audio = $this->normalizeMediaValue(data_get($item, 'audio', data_get($item, 'audio_url')));
        $transcript = trim((string) data_get($item, 'transcript', data_get($item, 'text', '')));

        if ($audio === null && $transcript === '') {
            return [];
        }

        return array_filter([
            'title' => trim((string) data_get($item, 'title', data_get($item, 'label', ''))),
            'audio' => $audio,
            'transcript' => $transcript,
            'language' => trim((string) data_get($item, 'language', data_get($item, 'lang', ''))),
            'confidence' => trim((string) data_get($item, 'confidence', '')),
            'notes' => trim((string) data_get($item, 'notes', '')),
        ], fn ($value) => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeStemItem(array $item): array
    {
        $original = $this->normalizeMediaValue(data_get($item, 'original_audio', data_get($item, 'source_audio', data_get($item, 'sample_audio'))));
        $stems = is_array(data_get($item, 'stems')) ? (array) data_get($item, 'stems') : [];

        if ($stems === [] && is_array(data_get($item, 'tracks'))) {
            $tracks = (array) data_get($item, 'tracks');
            foreach ($tracks as $track) {
                $row = is_array($track) ? $track : [];
                $key = $this->normalizeStemKey((string) data_get($row, 'key', data_get($row, 'label', '')));
                $audio = $this->normalizeMediaValue(data_get($row, 'audio', data_get($row, 'url', data_get($row, 'path'))));
                if ($key !== null && $audio !== null) {
                    $stems[$key] = $audio;
                }
            }
        }

        $normalizedStems = [];
        foreach (['vocals', 'drums', 'bass', 'other'] as $stemKey) {
            $audio = $this->normalizeMediaValue(data_get($stems, $stemKey));
            if ($audio !== null) {
                $normalizedStems[$stemKey] = $audio;
            }
        }

        if ($original === null && $normalizedStems === []) {
            return [];
        }

        return array_filter([
            'title' => trim((string) data_get($item, 'title', data_get($item, 'label', ''))),
            'original_audio' => $original,
            'stems' => $normalizedStems,
            'notes' => trim((string) data_get($item, 'notes', '')),
        ], fn ($value) => ! ($value === null || $value === '' || $value === []));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeOcrItem(array $item): array
    {
        $image = $this->normalizeMediaValue(data_get($item, 'image', data_get($item, 'image_url', data_get($item, 'image_path'))));
        $text = trim((string) data_get($item, 'extracted_text', data_get($item, 'output_text', data_get($item, 'text', ''))));

        if ($image === null && $text === '') {
            return [];
        }

        return array_filter([
            'title' => trim((string) data_get($item, 'title', data_get($item, 'label', ''))),
            'image' => $image,
            'extracted_text' => $text,
            'notes' => trim((string) data_get($item, 'notes', '')),
        ], fn ($value) => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeTranslationItem(array $item): array
    {
        $sourceText = trim((string) data_get($item, 'source_text', ''));
        $targetText = trim((string) data_get($item, 'target_text', ''));

        if ($sourceText === '' && $targetText === '') {
            return [];
        }

        return array_filter([
            'title' => trim((string) data_get($item, 'title', data_get($item, 'label', ''))),
            'source_lang' => Str::lower(trim((string) data_get($item, 'source_lang', ''))),
            'target_lang' => Str::lower(trim((string) data_get($item, 'target_lang', ''))),
            'source_text' => $sourceText,
            'target_text' => $targetText,
        ], fn ($value) => ! ($value === null || $value === ''));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTtsItems(array $config): array
    {
        $legacyItems = is_array(data_get($config, 'voices')) ? (array) data_get($config, 'voices') : [];
        if ($legacyItems !== []) {
            return $legacyItems;
        }

        $voiceCodes = collect((array) data_get($config, 'voice_codes', []))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->values()
            ->all();

        return collect($voiceCodes)
            ->map(fn (string $code): array => [
                'label' => $code,
                'voice_id' => $code,
                'engine' => $this->inferEngineFromVoiceCode($code),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyCttsItems(array $config): array
    {
        $samples = is_array(data_get($config, 'samples')) ? (array) data_get($config, 'samples') : [];
        if ($samples !== []) {
            return $samples;
        }

        $examples = is_array(data_get($config, 'examples')) ? (array) data_get($config, 'examples') : [];
        if ($examples !== []) {
            return $examples;
        }

        $sample = data_get($config, 'sample');
        if (is_array($sample) && $sample !== []) {
            return [$sample];
        }

        $source = data_get($config, 'sample_audio', data_get($config, 'source_audio'));
        $cloned = data_get($config, 'cloned_audio', data_get($config, 'target_audio'));

        if ($source === null && $cloned === null) {
            return [];
        }

        return [[
            'source_label' => data_get($config, 'sample_label', 'Source'),
            'cloned_label' => data_get($config, 'cloned_label', 'Cloned'),
            'source_audio' => $source,
            'cloned_audio' => $cloned,
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyAsrItems(array $config): array
    {
        if (is_array(data_get($config, 'items')) && (array) data_get($config, 'items') !== []) {
            return (array) data_get($config, 'items');
        }

        $models = is_array(data_get($config, 'models')) ? (array) data_get($config, 'models') : [];
        $source = data_get($config, 'source_audio');

        if ($models === [] && $source === null) {
            return [];
        }

        if ($models === []) {
            return [[
                'title' => data_get($config, 'source_label', 'ASR example'),
                'audio' => $source,
                'transcript' => data_get($config, 'sample_text', ''),
            ]];
        }

        return collect($models)
            ->map(function ($model) use ($source): array {
                $row = is_array($model) ? $model : [];
                return [
                    'title' => trim((string) data_get($row, 'label', 'ASR Example')),
                    'audio' => data_get($row, 'audio', $source),
                    'transcript' => data_get($row, 'transcript', data_get($row, 'text', '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyStemItems(array $config): array
    {
        if (is_array(data_get($config, 'items')) && (array) data_get($config, 'items') !== []) {
            return (array) data_get($config, 'items');
        }

        $tracks = is_array(data_get($config, 'stems')) ? (array) data_get($config, 'stems') : [];
        if ($tracks === []) {
            return [];
        }

        $stems = [];
        foreach ($tracks as $track) {
            $row = is_array($track) ? $track : [];
            $key = $this->normalizeStemKey((string) data_get($row, 'key', data_get($row, 'label', '')));
            $audio = data_get($row, 'audio', data_get($row, 'path', data_get($row, 'url')));
            if ($key !== null && $audio !== null) {
                $stems[$key] = $audio;
            }
        }

        return [[
            'title' => data_get($config, 'source_label', 'STEM Sample'),
            'original_audio' => data_get($config, 'source_audio', data_get($config, 'sample_audio')),
            'stems' => $stems,
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyOcrItems(array $config): array
    {
        if (is_array(data_get($config, 'items')) && (array) data_get($config, 'items') !== []) {
            return (array) data_get($config, 'items');
        }

        $firstImage = data_get($config, 'input_items.0.image', data_get($config, 'input_items.0.image_path'));
        $secondImage = data_get($config, 'input_items.1.image', data_get($config, 'input_items.1.image_path'));
        $image = $firstImage ?? $secondImage;

        return [[
            'title' => data_get($config, 'input_items.0.label', 'OCR sample'),
            'image' => $image,
            'extracted_text' => data_get($config, 'output_text', data_get($config, 'sample_text', '')),
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTranslationItems(array $config): array
    {
        if (is_array(data_get($config, 'items')) && (array) data_get($config, 'items') !== []) {
            return (array) data_get($config, 'items');
        }

        return [[
            'source_lang' => data_get($config, 'source_lang', 'ku'),
            'target_lang' => data_get($config, 'target_lang', 'en'),
            'source_text' => data_get($config, 'source_text', data_get($config, 'sample_text', '')),
            'target_text' => data_get($config, 'target_text', ''),
        ]];
    }

    protected function normalizeStemKey(string $label): ?string
    {
        $normalized = Str::lower(trim($label));
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'vocal') || str_contains($normalized, 'voice') => 'vocals',
            str_contains($normalized, 'drum') => 'drums',
            str_contains($normalized, 'bass') => 'bass',
            str_contains($normalized, 'music'),
            str_contains($normalized, 'instrument'),
            str_contains($normalized, 'other') => 'other',
            default => null,
        };
    }

    protected function inferEngineFromVoiceCode(string $voiceCode): string
    {
        $code = Str::lower(trim($voiceCode));

        return match (true) {
            str_contains($code, 'apollo'), str_contains($code, 'xtts') => 'apollo',
            str_contains($code, 'delta'), str_contains($code, 'ftts') => 'delta',
            default => '',
        };
    }

    protected function normalizeMediaValue(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
