<?php

namespace App\Services\OCR;

use App\Models\MlJob;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OcrJobSyncService
{
    public function __construct(
        protected RunPodProvider $runpod,
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
    ) {
    }

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
                'ResponseContentDisposition' => 'inline; filename="' . ($fileName !== '' ? $fileName : basename($inputPath)) . '"',
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
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed'], true)) {
            return $this->payloadFromJob($job);
        }

        $endpointId = (string) (config('runpod.endpoints.kocr') ?: env('RUNPOD_ENDPOINT_ID_KOCR'));
        if ($endpointId === '') {
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
            $error = (string) (data_get($statusPayload, 'error') ?: data_get($statusPayload, 'output.error') ?: '');

            MlJob::query()->where('id', $job->id)->update([
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
        MlJob::query()->where('id', $job->id)->update([
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
            if (!$fresh) {
                throw new \RuntimeException('OCR job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'text.path')) {
                return $this->payloadFromJob($fresh, 'OCR completed.');
            }

            $disk = $this->storage->ocrDisk();
            $paths = $this->storage->ocrPaths($fresh);

            $textPath = (string) (data_get($response, 'output.uploaded_keys.text') ?: $paths['text']);
            $jsonPath = (string) (data_get($response, 'output.uploaded_keys.json') ?: $paths['json']);

            $textObject = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, $textPath, [
                'job_id' => (string) $fresh->id,
                'tool' => 'ocr',
                'purpose' => 'render',
                'role' => 'text',
                'mime' => 'text/plain; charset=UTF-8',
            ]);

            if (!$textObject) {
                return $this->failJob($fresh, 'OCR text output missing after completion.', $response);
            }

            $jsonObject = $this->storage->registerExistingObject((int) $fresh->customer_id, $disk, $jsonPath, [
                'job_id' => (string) $fresh->id,
                'tool' => 'ocr',
                'purpose' => 'render',
                'role' => 'json',
                'mime' => 'application/json',
            ]);

            $output = [
                'disk' => $disk,
                'text' => [
                    'path' => (string) $textObject['path'],
                    'bytes' => (int) $textObject['bytes'],
                    'mime' => (string) $textObject['mime'],
                ],
                'json' => $jsonObject ? [
                    'path' => (string) $jsonObject['path'],
                    'bytes' => (int) $jsonObject['bytes'],
                    'mime' => (string) $jsonObject['mime'],
                ] : null,
                'runpod' => (array) data_get($response, 'output', []),
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

    protected function failJob(MlJob $job, string $message, array $response = []): array
    {
        MlJob::query()->where('id', $job->id)->update([
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
