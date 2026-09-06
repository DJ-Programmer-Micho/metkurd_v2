<?php

namespace App\Services\OCR;

use App\Models\MlJob;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OcrJobSyncService
{
    public function cancel(MlJob $job): bool
    {
        if (! $job->isActive() || ! $job->provider_job_id) {
            return false;
        }
        $endpointKey = $job->endpoint_key ?: (data_get($job->input, 'v2') ? 'kocr_v2' : 'kocr');
        $endpointId = trim((string) config("runpod.endpoints.{$endpointKey}"));
        if ($endpointId === '') {
            return false;
        }
        $job->update(['input' => array_merge((array) $job->input, ['customer_cancel_requested' => true])]);
        $response = $this->runpod->cancel($endpointId, (string) $job->provider_job_id);
        if (strtoupper((string) data_get($response, 'status')) !== 'CANCELLED') {
            return false;
        }
        $changed = MlJob::query()->whereKey($job->id)->active()->update(['status' => 'cancelled',
            'failure_stage' => 'customer_cancelled', 'finished_at' => now(), 'error' => ['message' => __('Cancelled by customer.')]]);
        if ($changed) {
            $this->locks->releaseLock((string) $job->id);
        }

        return (bool) $changed;
    }

    public function __construct(
        protected RunPodProvider $runpod,
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
        protected OcrV2ArtifactService $v2Artifacts,
    ) {}

    public function buildRunpodInput(
        MlJob $job,
        string $inputDisk,
        string $inputPath,
        string $fileName = 'input.pdf',
        string $fileMime = 'application/pdf',
        string $lang = 'ckb',
        string $pageRange = '',
        int $dpi = 200,
        int $psm = 6,
        int $oem = 3,
        bool $normalize = false,
        bool $grayscale = true,
        bool $autocontrast = true,
        bool $sharpen = true,
        bool $binarize = false
    ): array {
        $fileUrl = $this->storage->temporaryUrlForDisk(
            disk: $inputDisk,
            path: $inputPath,
            expiresAt: now()->addHours(8),
            options: [
                'ResponseContentType' => $fileMime !== '' ? $fileMime : 'application/pdf',
                'ResponseContentDisposition' => 'inline; filename="'.($fileName !== '' ? $fileName : basename($inputPath)).'"',
            ]
        );

        $upload = $this->storage->makeOcrUploadTargets($job, $inputPath);

        return [
            'job_id' => (string) $job->id,
            'file_url' => $fileUrl,
            'file_name' => $fileName !== '' ? $fileName : 'input.pdf',
            'file_mime' => $fileMime !== '' ? $fileMime : 'application/pdf',
            'lang' => $lang,
            'page_range' => trim($pageRange),
            'dpi' => $dpi,
            'psm' => $psm,
            'oem' => $oem,
            'normalize' => $normalize,
            'grayscale' => $grayscale,
            'autocontrast' => $autocontrast,
            'sharpen' => $sharpen,
            'binarize' => $binarize,
            'upload' => [
                'text' => $upload['text'],
                'json' => $upload['json'],
            ],
        ];
    }

    public function sync(MlJob $job): array
    {
        return app(\App\Services\MetKurd\Jobs\JobPollCoordinator::class)->sync(
            $job, fn ($fresh) => $this->syncProvider($fresh), fn ($fresh) => $this->payloadFromJob($fresh)
        );
    }

    protected function syncProvider(MlJob $job): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed'], true)) {
            return $this->payloadFromJob($job);
        }

        $endpointKey = $job->endpoint_key ?: (data_get($job->input, 'v2') ? 'kocr_v2' : 'kocr');
        $endpointId = trim((string) config("runpod.endpoints.{$endpointKey}"));
        if ($endpointId === '') {
            if ($endpointKey === 'kocr_v2') {
                throw new \RuntimeException('The configured GPU endpoint is unavailable.');
            }

            return $this->failJob($job, 'Missing RunPod OCR endpoint id.');
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.');
        }

        try {
            $this->locks->refreshLock((string) $job->id, 60);

            $statusPayload = $this->runpod->status($endpointId, $providerJobId);
            $rawStatus = strtoupper((string) data_get($statusPayload, 'status', ''));
            if (in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true) && (data_get($statusPayload, 'output.success') === false || data_get($statusPayload, 'output.ok') === false)) {
                $rawStatus = 'FAILED';
            }
            $error = (string) (data_get($statusPayload, 'error') ?: data_get($statusPayload, 'output.error') ?: '');

            MlJob::query()->where('id', $job->id)->active()->update([
                ...$this->providerMetrics($statusPayload),
                'updated_at' => now(),
            ]);

            return match ($rawStatus) {
                'IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED' => $this->markStatus($job, 'queued', 10, 'Job queued.'),
                'IN_PROGRESS', 'PROCESSING', 'RUNNING' => $this->markStatus($job, 'running', 65, 'Extracting text...'),
                'COMPLETED', 'SUCCESS' => (data_get($statusPayload, 'output.ok') === false)
                    ? $this->failJob($job, $error !== '' ? $error : 'RunPod OCR job failed.', $statusPayload)
                    : $this->finalizeSuccess($job, $statusPayload),
                'FAILED', 'ERROR', 'CANCELLED', 'TIMED_OUT' => $this->failJob($job, $error !== '' ? $error : 'RunPod OCR job failed.', $statusPayload),
                default => $this->payloadFromJob($job),
            };
        } catch (\Throwable $e) {
            Log::warning('OCR_SYNC_EXCEPTION', [
                'job_id' => (string) $job->id,
                'error' => $e->getMessage(),
            ]);

            return $this->payloadFromJob($job, message: 'Temporary sync issue. Retrying...');
        }
    }

    protected function markStatus(MlJob $job, string $status, int $progress, string $message): array
    {
        MlJob::query()->where('id', $job->id)->active()->update([
            'status' => $status,
            'updated_at' => now(),
        ]);

        $job->refresh();

        return [
            'status' => $status,
            'progress' => $progress,
            'done' => false,
            'failed' => false,
            'message' => $message,
        ];
    }

    protected function finalizeSuccess(MlJob $job, array $response): array
    {
        return DB::transaction(function () use ($job, $response) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);
            if (! $fresh) {
                throw new \RuntimeException('OCR job not found during finalize.');
            }

            if (! $fresh->isActive()) {
                return $this->payloadFromJob($fresh);
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'text.path')) {
                return $this->payloadFromJob($fresh, 'OCR completed.');
            }

            $disk = $this->storage->ocrDisk();
            $paths = $this->storage->ocrPaths($fresh);

            $isV2 = (bool) data_get($fresh->input, 'v2');
            $textPath = (string) (data_get($response, 'output.uploaded_keys.text') ?: $paths['text']);
            $jsonPath = (string) (data_get($response, 'output.uploaded_keys.json') ?: $paths['json']);
            foreach ([$textPath, $jsonPath] as $returnedPath) {
                if (! $this->ownedArtifactPath($returnedPath, dirname($paths['text']))) {
                    return $this->failJob($fresh, 'Processing returned an invalid result location.');
                }
            }
            $workerOutput = (array) data_get($response, 'output.output', []);
            $layoutJson = $isV2 ? $this->v2LayoutJson($workerOutput['json'] ?? null) : null;

            // Both supported V2 worker revisions return final text inline. The
            // upgraded layout worker returns it as page Markdown inside JSON.
            $inlineText = $isV2 ? (string) (data_get($response, 'output.text_corrected')
                ?: data_get($response, 'output.corrected_text')
                ?: data_get($response, 'output.text')
                ?: data_get($response, 'output.result.text')
                ?: data_get($response, 'output.text_raw')
                ?: $this->v2LayoutText($layoutJson)
                ?: $this->v2HtmlText($workerOutput['html'] ?? null)
                ?: '') : '';
            if ($isV2 && ! Storage::disk($disk)->exists($textPath)) {
                if ($inlineText !== '') {
                    if (Storage::disk($disk)->put($textPath, $inlineText, ['ContentType' => 'text/plain; charset=UTF-8']) !== true) {
                        throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
                    }
                }
            }
            if ($isV2 && $layoutJson !== null && ! Storage::disk($disk)->exists($jsonPath)) {
                if (Storage::disk($disk)->put($jsonPath, $layoutJson, ['ContentType' => 'application/json']) !== true) {
                    throw new \App\Services\Storage\StorageObjectUnavailable('The customer result could not be persisted.');
                }
            }

            $textObject = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, $textPath, array_merge(
                $this->storage->apiOutputMeta($fresh, 'ocr', 'render', 'text'),
                ['mime' => 'text/plain; charset=UTF-8']
            ));

            if (! $textObject) {
                return $this->failJob($fresh, 'OCR text output missing after completion.', $response);
            }

            $jsonObject = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, $jsonPath, array_merge(
                $this->storage->apiOutputMeta($fresh, 'ocr', 'render', 'json'),
                ['mime' => 'application/json']
            ));

            $artifacts = $isV2
                ? $this->v2Artifacts->persistWorkerArtifacts($fresh, $disk, $workerOutput)
                : [];
            $basePath = trim(str_replace('\\', '/', dirname((string) data_get($fresh->input, 'file_path', ''))), '/');
            foreach (['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'markdown' => 'text/markdown; charset=UTF-8', 'html' => 'text/html; charset=UTF-8', 'zip' => 'application/zip'] as $format => $mime) {
                if (! data_get($fresh->input, "exports.export_{$format}")) {
                    continue;
                }
                $path = (string) (data_get($response, "output.uploaded_keys.{$format}") ?: data_get($response, "output.files.{$format}.path") ?: data_get($response, "output.{$format}_path"));
                if ($path === '' || ! $this->ownedArtifactPath($path, dirname($paths['text'])) || ! Storage::disk($disk)->exists($path)) {
                    continue;
                }
                $saved = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, $path, array_merge($this->storage->apiOutputMeta($fresh, 'ocr', 'render', $format), ['mime' => $mime]));
                if ($saved) {
                    $artifacts[$format] = ['path' => (string) $saved['path'], 'bytes' => (int) $saved['bytes'], 'mime' => (string) $saved['mime']];
                }
            }

            // The V2 worker intentionally responds in summary-and-text mode.
            // When it has not uploaded requested binaries, create safe export
            // files from the completed corrected text for the customer.
            if ($isV2) {
                $artifacts = $this->v2Artifacts->persistSelectedArtifacts($fresh, $disk, $inlineText, $artifacts);
                foreach ($artifacts as $format => $artifact) {
                    $saved = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, (string) $artifact['path'], array_merge(
                        $this->storage->apiOutputMeta($fresh, 'ocr', 'render', $format),
                        ['mime' => (string) $artifact['mime']]
                    ));
                    if ($saved) {
                        $artifacts[$format] = ['path' => (string) $saved['path'], 'bytes' => (int) $saved['bytes'], 'mime' => (string) $saved['mime']];
                    }
                }
            }

            $output = [
                'disk' => $disk,
                'text' => [
                    'path' => (string) $textObject['path'],
                    'bytes' => (int) $textObject['bytes'],
                    'mime' => (string) $textObject['mime'],
                    'inline' => $inlineText,
                ],
                'json' => $jsonObject ? [
                    'path' => (string) $jsonObject['path'],
                    'bytes' => (int) $jsonObject['bytes'],
                    'mime' => (string) $jsonObject['mime'],
                ] : null,
                'artifacts' => $artifacts,
                'runpod' => $this->storedRunpodOutput((array) data_get($response, 'output', []), $isV2),
            ];

            $storageOut = $this->storage->registerOcrArtifacts($fresh, $output, 'ocr');

            $fresh->status = 'done';
            $fresh->output = $output;
            $fresh->storage_out_bytes = $storageOut;
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->cold_start_ms = data_get($response, 'delayTime');
            $fresh->runtime_ms = data_get($response, 'executionTime');
            $fresh->save();

            $this->locks->releaseLock((string) $fresh->id);

            return $this->payloadFromJob($fresh, 'OCR completed.');
        }, 3);
    }

    private function ownedArtifactPath(string $path, string $base): bool
    {
        return $base !== '' && $base !== '.' && str_starts_with($path, rtrim($base, '/').'/')
            && ! str_contains($path, '\\') && ! preg_match('~(^|/)(\.\.?)(/|$)|[\x00-\x1f]|%[0-9a-f]{2}~i', $path);
    }

    private function v2LayoutJson(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $json === false ? null : $json;
        }

        return null;
    }

    private function v2LayoutText(?string $layoutJson): string
    {
        if ($layoutJson === null) {
            return '';
        }

        $layout = json_decode($layoutJson, true);
        if (! is_array($layout)) {
            return '';
        }

        $pages = data_get($layout, 'pages', []);
        if (! is_array($pages)) {
            return '';
        }

        return collect($pages)
            ->map(fn ($page) => is_array($page) ? trim((string) data_get($page, 'markdown', '')) : '')
            ->filter()
            ->implode("\n\n");
    }

    private function v2HtmlText(mixed $html): string
    {
        if (! is_string($html) || trim($html) === '') {
            return '';
        }

        $withBreaks = preg_replace('/<\/?(?:p|div|section|article|h[1-6]|tr|li|br)\b[^>]*>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @param array<string, mixed> $output @return array<string, mixed> */
    private function storedRunpodOutput(array $output, bool $isV2): array
    {
        if (! $isV2) {
            return $output;
        }

        $returned = array_keys((array) data_get($output, 'output', []));
        data_forget($output, 'output.docx_base64');
        data_forget($output, 'output.html');
        data_forget($output, 'output.json');
        $output['returned_formats'] = $returned;

        return $output;
    }

    protected function failJob(MlJob $job, string $message, array $response = []): array
    {
        MlJob::query()->where('id', $job->id)->active()->update([
            'status' => 'failed',
            'error' => ['message' => $message],
            ...$this->providerMetrics($response),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locks->releaseLock((string) $job->id);
        $job->refresh();

        return $this->payloadFromJob($job, $message);
    }

    protected function payloadFromJob(MlJob $job, ?string $message = null): array
    {
        $status = (string) $job->status;

        return [
            'status' => $status,
            'progress' => match ($status) {
                'queued' => 10,
                'running' => 65,
                'saving' => 90,
                'done', 'failed', 'deleted' => 100,
                default => 0,
            },
            'done' => $status === 'done',
            'failed' => in_array($status, ['failed', 'delete_failed'], true),
            'message' => $message ?: (string) data_get($job->error, 'message', ''),
        ];
    }

    protected function providerMetrics(array $response): array
    {
        return array_filter([
            'cold_start_ms' => ($delay = data_get($response, 'delayTime')) !== null ? (int) $delay : null,
            'runtime_ms' => ($runtime = data_get($response, 'executionTime')) !== null ? (int) $runtime : null,
        ], static fn ($value) => $value !== null);
    }
}
