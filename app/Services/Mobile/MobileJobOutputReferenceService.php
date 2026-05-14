<?php

namespace App\Services\Mobile;

use App\Models\CustomerFile;
use App\Models\MlJob;
use Illuminate\Support\Collection;

class MobileJobOutputReferenceService
{
    protected const INPUT_PURPOSES = [
        'reference',
        'input_audio',
        'input_document',
        'source_text',
    ];

    public function __construct(
        protected MobileAppCatalog $catalog,
    ) {
    }

    /**
     * @return array{outputs: array<int, array<string, mixed>>, primary_output: array<string, mixed>|null}
     */
    public function referencesForJob(MlJob $job, string $app): array
    {
        $descriptors = $this->outputDescriptors($job);
        $files = $this->resolveFiles($job, $app, $descriptors);

        if ($files->isEmpty()) {
            return [
                'outputs' => [],
                'primary_output' => null,
            ];
        }

        /** @var Collection<string, CustomerFile> $filesByPath */
        $filesByPath = $files->keyBy(fn (CustomerFile $file) => (string) $file->path);
        $outputs = [];
        $usedIds = [];

        foreach ($descriptors as $descriptor) {
            $path = (string) ($descriptor['path'] ?? '');

            if ($path === '' || ! $filesByPath->has($path)) {
                continue;
            }

            $file = $filesByPath->get($path);

            if (! $file instanceof CustomerFile) {
                continue;
            }

            $usedIds[(int) $file->id] = true;
            $outputs[] = $this->referenceForFile(
                $file,
                $app,
                (string) ($descriptor['role'] ?? data_get($file->meta, 'role', 'output')),
                (bool) ($descriptor['is_primary'] ?? false),
            );
        }

        foreach ($files as $file) {
            if (isset($usedIds[(int) $file->id])) {
                continue;
            }

            $outputs[] = $this->referenceForFile(
                $file,
                $app,
                (string) data_get($file->meta, 'role', 'output'),
                false,
            );
        }

        $primary = collect($outputs)->first(fn (array $output) => (bool) ($output['is_primary'] ?? false))
            ?? ($outputs[0] ?? null);

        return [
            'outputs' => array_values($outputs),
            'primary_output' => $primary,
        ];
    }

    /**
     * @param  array<int, array{path: string, role: string, is_primary: bool}>  $descriptors
     * @return Collection<int, CustomerFile>
     */
    protected function resolveFiles(MlJob $job, string $app, array $descriptors): Collection
    {
        $toolCodes = array_values((array) data_get($this->catalog->for($app), 'file_tool_codes', []));
        $paths = collect($descriptors)
            ->pluck('path')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $baseQuery = CustomerFile::query()
            ->where('customer_id', (int) $job->customer_id)
            ->where('status', 'active');

        if ($toolCodes !== []) {
            $baseQuery->whereIn('tool_code', $toolCodes);
        }

        $files = $paths !== []
            ? (clone $baseQuery)->whereIn('path', $paths)->orderBy('id')->get()
            : collect();

        if ($files->isNotEmpty()) {
            return $files;
        }

        return (clone $baseQuery)
            ->where('meta->job_id', (string) $job->id)
            ->whereNotIn('purpose', self::INPUT_PURPOSES)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{path: string, role: string, is_primary: bool}>
     */
    protected function outputDescriptors(MlJob $job): array
    {
        $jobKind = strtolower(trim((string) ($job->job_kind ?? $job->tool?->code ?? '')));
        $descriptors = [];

        match ($jobKind) {
            'tts', 'ftts', 'clone_tts' => $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'audio', true),
            'wasr', 'asr' => [
                $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'transcript', true),
                $this->pushDescriptor($descriptors, data_get($job->output, 'json_path'), 'transcript_json', false),
            ],
            'qasr' => $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'transcript', true),
            'caption' => [
                $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'transcript', true),
                $this->pushDescriptor(
                    $descriptors,
                    data_get($job->output, 'srt.path') ?: data_get($job->output, 'srt_file.path') ?: data_get($job->output, 'srt_path'),
                    'srt',
                    false
                ),
            ],
            'stem' => [
                $this->pushDescriptor($descriptors, data_get($job->output, 'stems.vocals.path'), 'vocals', true),
                $this->pushDescriptor($descriptors, data_get($job->output, 'stems.instrumental.path'), 'instrumental', false),
                $this->pushDescriptor($descriptors, data_get($job->output, 'stems.drums.path'), 'drums', false),
                $this->pushDescriptor($descriptors, data_get($job->output, 'stems.bass.path'), 'bass', false),
                $this->pushDescriptor($descriptors, data_get($job->output, 'stems.other.path'), 'other', false),
                $this->pushDescriptor($descriptors, data_get($job->output, 'original.path'), 'original', false),
                $this->pushDescriptor($descriptors, data_get($job->output, 'result_json.path'), 'result_json', false),
            ],
            'ocr' => [
                $this->pushDescriptor($descriptors, data_get($job->output, 'text.path'), 'text', true),
                $this->pushDescriptor($descriptors, data_get($job->output, 'json.path'), 'json', false),
            ],
            'tran' => $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'translation', true),
            default => $this->pushDescriptor($descriptors, data_get($job->output, 'path'), 'output', true),
        };

        return array_values($descriptors);
    }

    /**
     * @param  array<int, array{path: string, role: string, is_primary: bool}>  $descriptors
     */
    protected function pushDescriptor(array &$descriptors, mixed $path, string $role, bool $isPrimary): void
    {
        $normalizedPath = trim((string) $path);

        if ($normalizedPath === '') {
            return;
        }

        $descriptors[] = [
            'path' => $normalizedPath,
            'role' => $role,
            'is_primary' => $isPrimary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function referenceForFile(CustomerFile $file, string $app, string $role, bool $isPrimary): array
    {
        return [
            'id' => (int) $file->id,
            'app' => $app,
            'tool_code' => (string) ($file->tool_code ?? ''),
            'purpose' => (string) ($file->purpose ?? ''),
            'role' => $role,
            'name' => basename((string) $file->path),
            'mime' => (string) ($file->mime ?? 'application/octet-stream'),
            'size_bytes' => (int) ($file->size_bytes ?? 0),
            'is_primary' => $isPrimary,
            'file_endpoint' => route('api.mobile.apps.files.show', [
                'app' => $app,
                'fileId' => (int) $file->id,
            ]),
            'download_endpoint' => route('api.mobile.apps.files.download', [
                'app' => $app,
                'fileId' => (int) $file->id,
            ]),
        ];
    }
}
