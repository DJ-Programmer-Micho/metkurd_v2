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

        $toolCode = strtolower(trim((string) $tool->code));
        $endpointId = (string) (
            data_get($tool->meta, 'runpod_endpoint_id')
            ?: $this->fallbackEndpointForTool($toolCode)
        );

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
            'IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED' => 'queued',
            'IN_PROGRESS', 'RUNNING'            => 'running',
            'COMPLETED'                         => ($wavB64 !== '' ? 'saving' : 'running'),
            'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default                             => 'running',
        };

        if ($toolCode === 'clone_tts') {
            $this->locks->refreshLock((string) $job->id);
        }

        if ($mapped === 'failed') {
            $err = $this->normalizeProviderFailureMessage(
                (string) (data_get($st, 'error') ?: data_get($out, 'error') ?: ''),
                $toolCode
            );
            return $this->failJob($job, $err);
        }

        if ($rawStatus === 'COMPLETED' && $toolCode === 'ftts' && $wavB64 === '') {
            return $this->failJob($job, $this->normalizeProviderFailureMessage(
                'F5TTS completed without output.wav_b64.',
                $toolCode
            ));
        }

        if ($wavB64 !== '') {
            return $this->finalizeSuccess($job, $tool, $wavB64, $out);
        }

        MlJob::query()->where('id', $job->id)->update([
            'status' => $mapped,
            'updated_at' => now(),
        ]);

        $job->refresh();

        return $this->payload($job, $progress);
    }

    protected function finalizeSuccess(MlJob $job, Tool $tool, string $wavB64, array $providerOutput = []): array
    {
        return DB::transaction(function () use ($job, $tool, $wavB64, $providerOutput) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (!$fresh) {
                throw new \RuntimeException('Job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'path')) {
                return $this->payload($fresh, 100);
            }

            $subFolder = $this->outputFolderForTool((string) $tool->code);
            $fileKey = $this->storage->renderBaseDir($fresh, $subFolder).'/out.wav';

            $saved = $this->storage->saveWavB64ToS3((int) $fresh->customer_id, $fileKey, $wavB64, [
                'job_id' => (string) $fresh->id,
                'tool' => (string) $tool->code,
                'purpose' => 'render',
                'mime' => 'audio/wav',
            ]);

            $fresh->status = 'done';
            $output = [
                'disk' => $saved['disk'],
                'path' => $saved['path'],
                'bytes' => $saved['bytes'],
                'mime' => $saved['mime'],
            ];

            if ((string) $tool->code === 'ftts') {
                $output['audio_file'] = (string) data_get($providerOutput, 'audio_file', '');
                $output['audio_path'] = (string) data_get($providerOutput, 'audio_path', '');
                $output['normalized_text'] = (string) data_get($providerOutput, 'normalized_text', '');
                $output['sample_rate'] = (int) data_get($providerOutput, 'sample_rate', 0);
                $output['info'] = data_get($providerOutput, 'info');
            }

            $fresh->output = $output;
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

    protected function fallbackEndpointForTool(string $toolCode): string
    {
        return match ($toolCode) {
            'ftts' => (string) (config('runpod.endpoints.ftts') ?: env('RUNPOD_ENDPOINT_ID_FTTS')),
            default => (string) (config('runpod.endpoints.xtts') ?: env('RUNPOD_ENDPOINT_ID_XTTS')),
        };
    }

    protected function outputFolderForTool(string $toolCode): string
    {
        return match (strtolower(trim($toolCode))) {
            'clone_tts' => 'clone-tts',
            'ftts' => 'ftts',
            default => 'tts',
        };
    }

    protected function normalizeProviderFailureMessage(string $rawMessage, string $toolCode = ''): string
    {
        $toolCode = strtolower(trim($toolCode));
        $message = trim($rawMessage);

        if ($message !== '' && str_starts_with($message, '{')) {
            $decoded = json_decode($message, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $jsonMessage = trim((string) data_get($decoded, 'message', ''));

                if ($jsonMessage !== '') {
                    $message = $jsonMessage;
                }
            }
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        if ($message === '') {
            return $this->genericProviderFailureMessage($toolCode);
        }

        if (str_contains(strtolower($message), 't must be strictly increasing or decreasing')) {
            return 'We could not generate stable audio timing for this request. Please try again. If it repeats, shorten the text or change the selected voice/settings.';
        }

        if (
            str_contains(strtolower($message), 'completed without output.wav_b64')
            || str_contains(strtolower($message), 'output.wav_b64')
        ) {
            return 'Generation finished without a valid audio file. Please retry.';
        }

        return $message;
    }

    protected function genericProviderFailureMessage(string $toolCode = ''): string
    {
        return $toolCode === 'ftts'
            ? 'The F5TTS generation failed. Please try again.'
            : 'The audio generation failed. Please try again.';
    }
}
