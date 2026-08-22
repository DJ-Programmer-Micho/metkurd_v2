<?php

namespace App\Services\MetKurd\V2;

use App\Services\Providers\RunPodProvider;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\Log;

/**
 * Owns V2 RunPod payload contracts. It intentionally does not create jobs,
 * debit credits, or write storage; those operations must be added together in
 * a transactional submission service after the new worker contracts are live.
 */
class RunPodV2Adapter
{
    public function __construct(
        private readonly RunPodProvider $runpod,
        private readonly MetKurdV2ToolCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $options */
    public function omni(string $service, string $tool, array $options): array
    {
        $definition = $this->toolForKind($service, $tool, ['omni_tts', 'omni_clone']);

        if (($definition['kind'] ?? null) === 'omni_clone') {
            return $this->run($definition, [
                'mode' => 'audio_url',
                'text' => $this->requiredString($options, 'text'),
                'audio_url' => $this->requiredTrustedUrl($options, 'audio_url'),
                'ref_text' => $this->stringOption($options, 'ref_text'),
                'language' => $this->stringOption($options, 'language', 'ckb'),
                'text_language' => $this->stringOption($options, 'text_language', 'ckb'),
                'output_format' => $this->stringOption($options, 'output_format', 'wav'),
                'return_base64' => true,
                'ref_max_sec' => max(1, min(20, (int) ($options['ref_max_sec'] ?? 20))),
                'model' => (string) $definition['provider_model'],
            ]);
        }

        return $this->run($definition, [
            'mode' => $this->stringOption($options, 'mode', 'builtin_ref'),
            'text' => $this->requiredString($options, 'text'),
            'ref_audio' => $this->stringOption($options, 'ref_audio'),
            'ref_text' => $this->stringOption($options, 'ref_text'),
            'language' => $this->stringOption($options, 'language', 'ckb'),
            'output_format' => $this->stringOption($options, 'output_format', 'wav'),
            'return_base64' => true,
            'model' => (string) $definition['provider_model'],
            'text_language' => $this->stringOption($options, 'text_language', 'ckb'),
        ]);
    }

    /** @param array<string, mixed> $options */
    public function qasr(string $service, string $tool, array $options): array
    {
        $definition = $this->toolForKind($service, $tool, ['qasr', 'caption']);

        $input = [
            'audio_url' => $this->requiredTrustedUrl($options, 'audio_url'),
            'model_variant' => $this->stringOption($options, 'model_variant', 'fine_tuned'),
            'language' => $this->stringOption($options, 'language', 'ckb'),
            'type' => $definition['kind'] === 'caption' ? 'caption' : 'asr',
            'intelligent' => $this->booleanOption($options, 'intelligent') ? 1 : 0,
        ];

        // Caption deliberately retains the established V1 worker contract.
        // Its MetKurd tool identity is separate from the worker's `type`.
        if (($definition['kind'] ?? null) === 'caption') {
            $input = array_merge($input, [
                'output_format' => $this->stringOption($options, 'output_format', 'srt'),
                'return_srt' => $this->booleanOption($options, 'return_srt', true),
                'return_segments' => $this->booleanOption($options, 'return_segments', true),
                'max_words_per_caption' => max(1, min(20, (int) ($options['max_words_per_caption'] ?? 8))),
                'max_caption_seconds' => max(1, min(20, (int) ($options['max_caption_seconds'] ?? 6))),
                'min_caption_seconds' => max(1, min(10, (int) ($options['min_caption_seconds'] ?? 1))),
            ]);
        }

        return $this->run($definition, $input);
    }

    /** @param array<string, mixed> $options */
    public function kocr(string $service, string $tool, array $options): array
    {
        $definition = $this->toolForKind($service, $tool, ['kocr']);
        $intelligent = $this->booleanOption($options, 'intelligent');

        $providerOptions = [
            'source_mode' => 'image_file',
            'ocr_model' => 'metocr',
            'run_llm_corrector' => $intelligent,
            'pages' => $this->stringOption($options, 'pages', 'all'),
            'max_pages' => max(1, min(100, (int) ($options['max_pages'] ?? 1))),
            'digit_normalization' => 'preserve',
            'export_docx' => true,
            'export_json' => true,
            'export_txt' => true,
            'export_markdown' => true,
            'export_html' => true,
            'export_zip' => true,
            'return_mode' => $intelligent ? 'summary_and_base64_zip' : 'summary_only',
            'max_return_zip_mb' => max(1, min(20, (int) ($options['max_return_zip_mb'] ?? 20))),
        ];

        if ($intelligent) {
            $providerOptions['llm_corrector_model'] = 'Qwen3-4B-Instruct-2507.Q4_K_M.gguf';
        }

        return $this->run($definition, [
            'job_id' => $this->requiredString($options, 'job_id'),
            'input_path' => $this->requiredSafeStoragePath($options, 'input_path'),
            'file_name' => basename($this->requiredString($options, 'file_name')),
            'options' => $providerOptions,
            'ocr' => [
                'lang' => 'metocr',
                'dpi' => max(72, min(600, (int) ($options['dpi'] ?? 300))),
                'psm' => max(0, min(13, (int) ($options['psm'] ?? 3))),
                'oem' => max(0, min(3, (int) ($options['oem'] ?? 1))),
                'grayscale' => true,
                'autocontrast' => true,
                'sharpen' => true,
                'binarize' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $input */
    private function run(array $definition, array $input): array
    {
        $endpointKey = (string) ($definition['endpoint'] ?? '');
        $endpointId = trim((string) config("runpod.endpoints.{$endpointKey}"));

        if ($endpointId === '') {
            throw new \RuntimeException("RunPod endpoint [{$endpointKey}] is not configured.");
        }

        Log::info('METKURD_V2_RUNPOD_SUBMIT', [
            'endpoint_key' => $endpointKey,
            'kind' => $definition['kind'] ?? null,
            'model' => $definition['provider_model'] ?? null,
        ]);

        return $this->runpod->run($endpointId, $input, (int) config('runpod.v2_timeout', 60));
    }

    /** @return array<string, mixed> */
    private function toolForKind(string $service, string $tool, array $kinds): array
    {
        $definition = $this->catalog->tool($service, $tool);

        if (! is_array($definition) || ! in_array($definition['kind'] ?? null, $kinds, true)) {
            throw new \InvalidArgumentException('Unknown V2 tool contract.');
        }

        return $definition;
    }

    /** @param array<string, mixed> $options */
    private function requiredString(array $options, string $key): string
    {
        $value = trim((string) ($options[$key] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException("{$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private function stringOption(array $options, string $key, string $default = ''): string
    {
        return trim((string) ($options[$key] ?? $default));
    }

    /** @param array<string, mixed> $options */
    private function booleanOption(array $options, string $key, bool $default = false): bool
    {
        return filter_var($options[$key] ?? $default, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $options */
    private function requiredTrustedUrl(array $options, string $key): string
    {
        $url = $this->requiredString($options, $key);
        $parts = parse_url($url);
        $host = strtolower((string) data_get($parts, 'host', ''));
        $allowedHosts = config('runpod.v2_input_hosts', []);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || $host === ''
            || ! in_array($host, $allowedHosts, true)
        ) {
            throw new \InvalidArgumentException("{$key} must be a trusted HTTPS storage URL.");
        }

        return $url;
    }

    /** @param array<string, mixed> $options */
    private function requiredSafeStoragePath(array $options, string $key): string
    {
        $path = str_replace('\\', '/', $this->requiredString($options, $key));
        if (str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException("{$key} must be an application-owned relative storage path.");
        }

        return $path;
    }
}
