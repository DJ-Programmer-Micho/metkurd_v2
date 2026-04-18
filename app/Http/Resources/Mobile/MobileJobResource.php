<?php

namespace App\Http\Resources\Mobile;

use App\Services\Mobile\MobileAppCatalog;
use App\Services\Mobile\MobileJobOutputReferenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class MobileJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'tool:id,code,name',
            'toolAction:id,tool_code,full_code,name',
        ]);

        $appSlug = (string) ($request->route('app') ?: app(MobileAppCatalog::class)->appSlugForJob($this->resource));
        $toolCode = (string) ($this->resource->tool?->code ?? '');
        $jobKind = (string) ($this->resource->job_kind ?? '');
        $includeOutputReferences = $request->routeIs('api.mobile.apps.jobs.show');
        $outputReferences = $includeOutputReferences
            ? app(MobileJobOutputReferenceService::class)->referencesForJob($this->resource, $appSlug)
            : ['outputs' => [], 'primary_output' => null];

        return [
            'id' => (string) $this->resource->id,
            'app' => $appSlug !== '' ? $appSlug : null,
            'status' => (string) $this->resource->status,
            'job_kind' => $jobKind !== '' ? $jobKind : null,
            'tool_code' => $toolCode !== '' ? $toolCode : null,
            'tool_action' => (string) ($this->resource->toolAction?->full_code ?? '') ?: null,
            'summary' => $this->summary(),
            'credits_charged' => (int) ($this->resource->credits_charged ?? 0),
            'storage_in_bytes' => (int) ($this->resource->storage_in_bytes ?? 0),
            'storage_out_bytes' => (int) ($this->resource->storage_out_bytes ?? 0),
            'provider' => (string) ($this->resource->provider ?? ''),
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'updated_at' => optional($this->resource->updated_at)->toIso8601String(),
            'started_at' => optional($this->resource->started_at)->toIso8601String(),
            'finished_at' => optional($this->resource->finished_at)->toIso8601String(),
            'input' => array_filter([
                'text_preview' => $this->inputTextPreview(),
                'audio_name' => data_get($this->resource->input, 'audio_name'),
                'reference_audio_name' => data_get($this->resource->input, 'reference_audio_name'),
                'file_name' => data_get($this->resource->input, 'file_name'),
                'language' => data_get($this->resource->input, 'language'),
                'lang' => data_get($this->resource->input, 'lang'),
                'source_lang' => data_get($this->resource->input, 'source_lang'),
                'target_lang' => data_get($this->resource->input, 'target_lang'),
                'speaker_id' => data_get($this->resource->input, 'speaker_id'),
                'model_variant' => data_get($this->resource->input, 'model_variant'),
                'stems' => data_get($this->resource->input, 'stems'),
                'page_range' => data_get($this->resource->input, 'page_range'),
            ], fn ($value) => $value !== null && $value !== ''),
            'result' => [
                'text_preview' => $this->outputTextPreview(),
                'has_output' => ! empty((array) ($this->resource->output ?? [])),
                'has_error' => ! empty((array) ($this->resource->error ?? [])),
                'outputs' => $outputReferences['outputs'],
                'primary_output' => $outputReferences['primary_output'],
            ],
        ];
    }

    protected function inputTextPreview(): ?string
    {
        $value = trim((string) data_get($this->resource->input, 'text', ''));

        return $value !== '' ? Str::limit($value, 160) : null;
    }

    protected function outputTextPreview(): ?string
    {
        foreach ([
            data_get($this->resource->output, 'text'),
            data_get($this->resource->output, 'translated_text'),
            data_get($this->resource->output, 'transcription'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 240);
            }
        }

        return null;
    }

    protected function summary(): string
    {
        $jobKind = strtolower(trim((string) ($this->resource->job_kind ?? $this->resource->tool?->code ?? '')));

        return match ($jobKind) {
            'tts', 'ftts', 'clone_tts' => Str::limit((string) data_get($this->resource->input, 'text', __('Speech generation job')), 120),
            'wasr', 'qasr', 'asr', 'stem' => (string) data_get($this->resource->input, 'audio_name', __('Uploaded audio')),
            'ocr' => (string) data_get($this->resource->input, 'file_name', __('Uploaded document')),
            'tran' => Str::limit((string) data_get($this->resource->output, 'text', data_get($this->resource->input, 'text', __('Translation job'))), 120),
            default => (string) data_get($this->resource->input, 'text', data_get($this->resource->input, 'file_name', data_get($this->resource->input, 'audio_name', __('ML job')))),
        };
    }
}
