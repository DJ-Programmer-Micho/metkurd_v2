<?php

namespace App\Services\XTTS;

use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;

class XttsJobSyncService
{
    public function __construct(
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
    ) {}

    public function sync(MlJob $job, Tool $tool): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting'], true)) {
            return $this->payload($job);
        }

        $endpointId = (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: config('runpod.endpoints.xtts'));
        if ($endpointId === '') {
            return $this->failJob($job, 'Missing RunPod endpoint id.');
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.');
        }

        /** @var RunPodProvider $runpod */
        $runpod = app(RunPodProvider::class);
        $st = $runpod->status($endpointId, $providerJobId);

        $rawStatus = strtoupper((string) data_get($st, 'status', ''));
        $out = (array) data_get($st, 'output', []);
        $wavB64 = (string) (
            data_get($out, 'wav_b64', '')
            ?: data_get($out, 'wav_base64', '')
            ?: data_get($out, 'audio_b64', '')
            ?: data_get($out, 'audio_base64', '')
            ?: data_get($st, 'output.wav_b64', '')
            ?: data_get($st, 'output.audio_b64', '')
        );

        $progress = (int) (data_get($out, 'progress', 0) ?: data_get($st, 'output.progress', 0));

        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED'                => 'queued',
            'IN_PROGRESS', 'RUNNING'            => 'running',
            'COMPLETED'                         => ($wavB64 !== '' ? 'saving' : 'running'),
            'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default                             => 'running',
        };

        if ((string) $tool->code === 'clone_tts') {
            $this->locks->refreshLock((string) $job->id);
        }

        if ($mapped === 'failed') {
            $err = (string) (data_get($st, 'error') ?: data_get($out, 'error') ?: 'RunPod failed.');
            return $this->failJob($job, $err);
        }

        if ($wavB64 !== '') {
            return $this->finalizeSuccess($job, $tool, $wavB64);
        }

        MlJob::query()->where('id', $job->id)->update([
            'status' => $mapped,
            'updated_at' => now(),
        ]);

        $job->refresh();

        return $this->payload($job, $progress);
    }

    protected function finalizeSuccess(MlJob $job, Tool $tool, string $wavB64): array
    {
        return DB::transaction(function () use ($job, $tool, $wavB64) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh) {
                throw new \RuntimeException('Job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'path')) {
                return $this->payload($fresh, 100);
            }

            $folder = \App\Support\CustomerFolder::make(
                (int) $fresh->customer_id,
                data_get($fresh, 'customer.profile.first_name') ?? data_get($fresh, 'customer.first_name'),
                data_get($fresh, 'customer.profile.last_name') ?? data_get($fresh, 'customer.last_name'),
                data_get($fresh, 'customer.username')
            );

            $subFolder = (string) $tool->code === 'clone_tts' ? 'clone-tts' : 'tts';
            $fileKey = "renders/{$folder}/{$subFolder}/{$fresh->id}/out.wav";

            $saved = $this->storage->saveWavB64ToS3((int) $fresh->customer_id, $fileKey, $wavB64, [
                'job_id' => (string) $fresh->id,
                'tool' => (string) $tool->code,
                'purpose' => 'render',
                'mime' => 'audio/wav',
            ]);

            $fresh->status = 'done';
            $fresh->output = [
                'disk' => $saved['disk'],
                'path' => $saved['path'],
                'bytes' => $saved['bytes'],
                'mime' => $saved['mime'],
            ];
            $fresh->storage_out_bytes = (int) $saved['bytes'];
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->save();

            if ((string) $tool->code === 'clone_tts') {
                $this->locks->releaseLock((string) $fresh->id);
            }

            return $this->payload($fresh, 100);
        }, 3);
    }

    protected function failJob(MlJob $job, string $message): array
    {
        MlJob::query()->where('id', $job->id)->update([
            'status' => 'failed',
            'error' => ['message' => $message],
            'finished_at' => now(),
        ]);

    if (
        (string) data_get($job, 'tool.code') === 'clone_tts'
        || (string) $job->job_kind === 'clone_tts'
    ) {
        $this->locks->releaseLock((string) $job->id);
    }

        $job->refresh();

        return $this->payload($job, 100);
    }

    public function deleteFinishedRender(MlJob $job): void
    {
        DB::transaction(function () use ($job) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh || (string) $fresh->status !== 'done') {
                throw new \RuntimeException('Render not found or already deleted.');
            }

            $fresh->status = 'deleting';
            $fresh->error = null;
            $fresh->save();
        }, 3);

        try {
            $customerId = (int) $job->customer_id;

            $outPath  = (string) data_get($job->output, 'path', '');
            $outBytes = (int) data_get($job->output, 'bytes', 0);

            if ($outPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $outPath, $outBytes);
            }

            if ((string) $job->tool?->code === 'clone_tts') {
                $refPath  = (string) data_get($job->input, 'reference_audio_path', '');
                $refBytes = (int) ((int) $job->storage_in_bytes ?: data_get($job->input, 'reference_audio_bytes', 0));

                if ($refPath !== '') {
                    $this->storage->deleteFromS3AndUncount($customerId, $refPath, $refBytes);
                }
            }

            MlJob::query()->where('id', $job->id)->update([
                'status' => 'deleted',
                'error' => null,
                'updated_at' => now(),
            ]);

            $this->locks->releaseLock((string) $job->id);
        } catch (\Throwable $e) {
            MlJob::query()->where('id', $job->id)->update([
                'status' => 'delete_failed',
                'error' => ['message' => $e->getMessage()],
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    protected function payload(MlJob $job, int $progress = 0): array
    {
        $status = (string) $job->status;

        return [
            'job_id' => (string) $job->id,
            'status' => $status,
            'progress' => $progress ?: match ($status) {
                'queued' => 10,
                'running' => 40,
                'saving' => 90,
                'done', 'failed' => 100,
                default => 0,
            },
            'done' => $status === 'done',
            'failed' => $status === 'failed',
            'message' => (string) data_get($job->error, 'message', ''),
        ];
    }
}
