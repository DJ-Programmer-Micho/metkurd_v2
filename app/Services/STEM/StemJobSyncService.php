<?php

namespace App\Services\STEM;

use App\Models\MlJob;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StemJobSyncService
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
        int $stems = 4,
        string $model = 'htdemucs_ft',
        string $stemCodec = 'mp3',
        string $stemBitrate = '192k',
        string $device = 'cuda'
    ): array {
        $audioUrl = $this->storage->temporaryUrlForDisk(
            disk: $inputDisk,
            path: $inputPath,
            expiresAt: now()->addHours(8)
        );

        $audioExt = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION) ?: 'wav');

        $upload = $this->storage->makeStemUploadTargets(
            job: $job,
            codec: $stemCodec,
            stemsMode: $stems
        );

        return [
            'job_id' => (string) $job->id,
            'audio_url' => $audioUrl,
            'audio_ext' => $audioExt,
            'stems' => $stems,
            'model' => $model,
            'device' => $device,
            'stem_codec' => $stemCodec,
            'stem_bitrate' => $stemBitrate,
            'upload' => [
                'original_wav' => $upload['original_wav'],
                'result_json' => $upload['result_json'],
                'stems' => $upload['stems'],
            ],
        ];
    }

    public function sync(MlJob $job): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed'], true)) {
            return $this->payloadFromJob($job);
        }

        $endpointId = (string) (config('runpod.endpoints.stem') ?: env('RUNPOD_ENDPOINT_ID_STEM'));
        if ($endpointId === '') {
            return $this->failJob($job, 'Missing RunPod STEM endpoint id.');
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.');
        }

        try {
            $this->locks->refreshLock((string) $job->id, 60);

            $st = $this->runpod->status($endpointId, $providerJobId);
            $rawStatus = strtoupper((string) data_get($st, 'status', ''));
            $error = (string) (data_get($st, 'error') ?: data_get($st, 'output.error') ?: '');

            MlJob::query()->where('id', $job->id)->update([
                ...$this->providerMetrics($st),
                'updated_at' => now(),
            ]);

            return match ($rawStatus) {
                'IN_QUEUE', 'QUEUED' => $this->markStatus($job, 'queued', 10, 'Job queued.'),
                'IN_PROGRESS', 'PROCESSING', 'RUNNING' => $this->markStatus($job, 'running', 65, 'Separating audio...'),
                'COMPLETED', 'SUCCESS' => (data_get($st, 'output.ok') === false)
                    ? $this->failJob($job, $error !== '' ? $error : 'RunPod STEM job failed.', $st)
                    : $this->finalizeSuccess($job, $st),
                'FAILED', 'ERROR', 'CANCELLED', 'TIMED_OUT' => $this->failJob($job, $error !== '' ? $error : 'RunPod STEM job failed.', $st),
                default => $this->payloadFromJob($job),
            };
        } catch (\Throwable $e) {
            Log::warning('STEM_SYNC_EXCEPTION', [
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
                throw new \RuntimeException('Stem job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && !empty(data_get($fresh->output, 'stems'))) {
                return $this->payloadFromJob($fresh);
            }

            $meta = (array) ($fresh->meta ?? []);
            $codec = (string) ($meta['stem_codec'] ?? data_get($fresh->input, 'stem_codec', 'mp3'));
            $stemsMode = (int) ($meta['separation_mode'] ?? data_get($fresh->input, 'stems', 4));
            $disk = $this->storage->stemDisk();
            $paths = $this->storage->stemPaths($fresh, $codec, $stemsMode);

            $output = [
                'disk' => $disk,
                'original' => null,
                'result_json' => null,
                'stems' => [],
            ];

            if (Storage::disk($disk)->exists($paths['original'])) {
                $output['original'] = [
                    'path' => $paths['original'],
                    'bytes' => (int) Storage::disk($disk)->size($paths['original']),
                    'mime' => (string) (Storage::disk($disk)->mimeType($paths['original']) ?: 'audio/wav'),
                ];
            }

            if (Storage::disk($disk)->exists($paths['result_json'])) {
                $output['result_json'] = [
                    'path' => $paths['result_json'],
                    'bytes' => (int) Storage::disk($disk)->size($paths['result_json']),
                    'mime' => (string) (Storage::disk($disk)->mimeType($paths['result_json']) ?: 'application/json'),
                ];
            }

            foreach ($paths['stems'] as $name => $path) {
                if (Storage::disk($disk)->exists($path)) {
                    $output['stems'][$name] = [
                        'path' => $path,
                        'bytes' => (int) Storage::disk($disk)->size($path),
                        'mime' => (string) (Storage::disk($disk)->mimeType($path) ?: 'audio/mpeg'),
                    ];
                }
            }

            $requiredStemCount = $stemsMode === 2 ? 2 : 4;
            if (count($output['stems']) < $requiredStemCount) {
                return $this->failJob($fresh, "Stem outputs incomplete. Expected {$requiredStemCount}, found " . count($output['stems']) . '.');
            }

            $storageOut = $this->storage->registerStemArtifacts($fresh, $output, 'stem');

            $fresh->status = 'done';
            $fresh->output = $output;
            $fresh->storage_out_bytes = $storageOut;
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->cold_start_ms = data_get($response, 'delayTime');
            $fresh->runtime_ms = data_get($response, 'executionTime');
            $fresh->save();

            $this->locks->releaseLock((string) $fresh->id);

            return $this->payloadFromJob($fresh, 'Stem separation completed.');
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
        $tracks = (int) (data_get($job->meta, 'separation_mode') ?: data_get($job->input, 'stems', 4)) === 2
            ? ['original', 'vocals', 'instrumental']
            : ['original', 'vocals', 'drums', 'bass', 'other'];

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
            'message' => $message,
            'render' => $status === 'done' ? [
                'job_id' => (string) $job->id,
                'tracks' => $tracks,
            ] : null,
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
