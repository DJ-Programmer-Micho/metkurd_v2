<?php

namespace App\Services\XTTS;

use App\Models\MlJob;
use App\Models\Tool;
use App\Services\MetKurd\V2\CttsWorkspaceCache;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;

class XttsJobSyncService
{
    public function __construct(
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
        protected CttsWorkspaceCache $workspaceCache,
    ) {}

    public function sync(MlJob $job, Tool $tool): array
    {
        return app(\App\Services\MetKurd\Jobs\JobPollCoordinator::class)->sync(
            $job, fn ($fresh) => $this->syncProvider($fresh, $tool), fn ($fresh) => $this->payload($fresh)
        );
    }

    protected function syncProvider(MlJob $job, Tool $tool): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed', 'cancelled', 'canceled'], true)) {
            return $this->payload($job);
        }

        $toolCode = strtolower(trim((string) $tool->code));
        $endpointKey = trim((string) $job->endpoint_key);
        if ($endpointKey === '' && (in_array($toolCode, ['xomni-v2', 'vector-v2'], true) || $job->model_key)) {
            $endpointKey = 'omni_v2';
        }
        $endpointId = $endpointKey !== ''
            ? trim((string) config("runpod.endpoints.{$endpointKey}"))
            : (string) (data_get($tool->meta, 'runpod_endpoint_id') ?: $this->fallbackEndpointForTool($toolCode));

        if ($endpointId === '') {
            if ($endpointKey !== '') {
                // Missing local configuration does not prove accepted work failed.
                throw new \RuntimeException('The configured GPU endpoint is unavailable.');
            }

            return $this->failJob($job, 'Missing RunPod endpoint id.', $toolCode);
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.', $toolCode);
        }

        /** @var RunPodProvider $runpod */
        $runpod = app(RunPodProvider::class);
        $st = $runpod->status($endpointId, $providerJobId);

        $rawStatus = strtoupper((string) data_get($st, 'status', ''));
        if (in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true) && (data_get($st, 'output.success') === false || data_get($st, 'output.ok') === false)) {
            $rawStatus = 'FAILED';
        }
        $out = (array) data_get($st, 'output', []);
        $wavB64 = $this->extractAudioBase64($out, $st);

        $progress = (int) (data_get($out, 'progress', 0) ?: data_get($st, 'output.progress', 0));

        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED' => 'queued',
            'IN_PROGRESS', 'RUNNING' => 'running',
            'COMPLETED', 'SUCCESS' => ($wavB64 !== '' ? 'saving' : 'running'),
            'FAILED', 'ERROR', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default => 'running',
        };

        if (in_array($toolCode, ['clone_tts', 'clone_xomni', 'vector-v2'], true)) {
            $this->locks->refreshLock((string) $job->id);
        }

        if ($mapped === 'failed') {
            $err = $this->normalizeProviderFailureMessage(
                (string) (data_get($st, 'error') ?: data_get($out, 'error') ?: ''),
                $toolCode
            );

            return $this->failJob($job, $err, $toolCode);
        }

        if (
            in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true)
            && $wavB64 === ''
        ) {
            return $this->failJob($job, $this->normalizeProviderFailureMessage(
                $toolCode === 'ftts'
                    ? 'F5TTS completed without output.wav_b64.'
                    : 'Omni generation completed without output.audio_base64.',
                $toolCode
            ), $toolCode);
        }

        if (in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true) && $wavB64 !== '') {
            return $this->finalizeSuccess($job, $tool, $wavB64, $out);
        }

        MlJob::query()->where('id', $job->id)->active()->update([
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

            if (! $fresh) {
                throw new \RuntimeException('Job not found during finalize.');
            }

            if (! $fresh->isActive()) {
                return $this->payload($fresh);
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'path')) {
                return $this->payload($fresh, 100);
            }

            $providerMime = strtolower(trim((string) data_get($providerOutput, 'mime_type', 'audio/wav')));
            if (! str_starts_with($providerMime, 'audio/')) {
                $providerMime = 'audio/wav';
            }

            $providerOutputFilename = basename(trim((string) data_get($providerOutput, 'output_filename', '')));
            if (
                $providerOutputFilename === ''
                || $providerOutputFilename === '.'
                || $providerOutputFilename === DIRECTORY_SEPARATOR
            ) {
                $providerOutputFilename = 'out.wav';
            }

            $outputFilename = $providerOutputFilename;
            if (in_array((string) $tool->code, ['xomni', 'xomni-v2', 'clone_xomni', 'vector-v2'], true)) {
                $outputFilename = $this->normalizeOmniOutputFilename(
                    toolCode: (string) $tool->code,
                    workerFilename: $providerOutputFilename,
                    mimeType: $providerMime,
                );
            }

            $subFolder = $this->outputFolderForTool((string) $tool->code);
            $fileKey = $this->storage->renderBaseDir($fresh, $subFolder).'/'.$outputFilename;

            $saved = $this->storage->saveWavB64ToS3((int) $fresh->customer_id, $fileKey, $wavB64, [
                'job_id' => (string) $fresh->id,
                'tool' => (string) $tool->code,
                'purpose' => 'render',
                'mime' => $providerMime,
                'retention_mode' => (string) data_get($fresh->input, 'api_storage_mode') === 'temporary' ? 'temporary' : 'permanent',
                'expires_at' => data_get($fresh->input, 'api_expires_at'),
                'source_type' => data_get($fresh->input, 'api_job_id') ? 'api_job' : 'ml_job',
                'source_id' => (string) (data_get($fresh->input, 'api_job_id') ?: $fresh->id),
                'counts_toward_quota' => (string) data_get($fresh->input, 'api_storage_mode') !== 'temporary',
            ]);

            $fresh->status = 'done';
            $output = [
                'disk' => $saved['disk'],
                'path' => $saved['path'],
                'bytes' => $saved['bytes'],
                'mime' => $saved['mime'],
                'output_filename' => $outputFilename,
                'stored_output_filename' => $outputFilename,
                'provider_output_filename' => (string) data_get($providerOutput, 'output_filename', ''),
                'duration' => (float) data_get($providerOutput, 'duration', 0),
                'provider_success' => (bool) data_get($providerOutput, 'success', true),
                'provider_mode' => (string) data_get($providerOutput, 'mode', ''),
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

            $this->forgetCttsRenderCache($fresh, (string) $tool->code);

            if (in_array((string) $tool->code, ['clone_tts', 'clone_xomni', 'vector-v2'], true)) {
                $this->locks->releaseLock((string) $fresh->id);
            }

            return $this->payload($fresh, 100);
        }, 3);
    }

    protected function failJob(MlJob $job, string $message, string $toolCode = ''): array
    {
        MlJob::query()->where('id', $job->id)->active()->update([
            'status' => 'failed',
            'error' => ['message' => $message],
            'finished_at' => now(),
        ]);

        if (
            in_array((string) data_get($job, 'tool.code'), ['clone_tts', 'clone_xomni', 'vector-v2'], true)
            || in_array((string) $job->job_kind, ['clone_tts', 'clone_xomni', 'vector-v2'], true)
        ) {
            $this->locks->releaseLock((string) $job->id);
        }

        $job->refresh();

        $this->forgetCttsRenderCache($job, $toolCode ?: (string) data_get($job, 'tool.code'));

        return $this->payload($job, 100);
    }

    public function deleteFinishedRender(MlJob $job): void
    {
        DB::transaction(function () use ($job) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (! $fresh || ! in_array((string) $fresh->status, ['done', 'delete_failed'], true)) {
                throw new \RuntimeException('Render not found or already deleted.');
            }

            $fresh->status = 'deleting';
            $fresh->error = null;
            $fresh->save();
        }, 3);

        try {
            $customerId = (int) $job->customer_id;

            $outPath = (string) data_get($job->output, 'path', '');
            $outBytes = (int) data_get($job->output, 'bytes', 0);

            if ($outPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $outPath, $outBytes);
            }

            if (in_array((string) $job->tool?->code, ['clone_tts', 'clone_xomni', 'vector-v2'], true) && ! data_get($job->input, 'reference_is_reusable')) {
                $refPath = (string) data_get($job->input, 'reference_audio_path', '');
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
            $this->forgetCttsRenderCache($job, (string) $job->tool?->code);
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

    private function forgetCttsRenderCache(MlJob $job, string $toolCode): void
    {
        if (in_array($toolCode, ['clone_tts', 'clone_xomni', 'vector-v2'], true)) {
            $this->workspaceCache->forgetRenders((int) $job->customer_id, $toolCode);
        }
    }

    protected function fallbackEndpointForTool(string $toolCode): string
    {
        return match ($toolCode) {
            'ftts' => (string) (config('runpod.endpoints.ftts') ?: env('RUNPOD_ENDPOINT_ID_FTTS')),
            'xomni', 'xomni-v2', 'clone_xomni', 'vector-v2' => (string) (config('runpod.endpoints.omni') ?: env('RUNPOD_ENDPOINT_ID_OMNI')),
            default => (string) (config('runpod.endpoints.xtts') ?: env('RUNPOD_ENDPOINT_ID_XTTS')),
        };
    }

    protected function outputFolderForTool(string $toolCode): string
    {
        return match (strtolower(trim($toolCode))) {
            'clone_tts' => 'clone-tts',
            'xomni' => 'xomni',
            'xomni-v2' => 'xomni-v2',
            'clone_xomni' => 'clone_xomni',
            'vector-v2' => 'vector-v2',
            'ftts' => 'ftts',
            default => 'tts',
        };
    }

    protected function normalizeOmniOutputFilename(string $toolCode, ?string $workerFilename, ?string $mimeType = null): string
    {
        $prefix = match (strtolower(trim($toolCode))) {
            'xomni' => 'xomni',
            'xomni-v2' => 'xomni-v2',
            'clone_xomni' => 'clone_xomni',
            'vector-v2' => 'vector-v2',
            default => 'audio',
        };

        $workerFilename = basename(trim((string) $workerFilename));
        $extension = $this->resolveAudioExtension($mimeType, $workerFilename);

        if (
            $workerFilename !== ''
            && preg_match('/^omnivoice_(\d{8}_\d{6}_\d+)\.[a-z0-9]+$/i', $workerFilename, $matches) === 1
        ) {
            return "{$prefix}_{$matches[1]}.{$extension}";
        }

        if (
            $workerFilename !== ''
            && preg_match('/(\d{8}_\d{6}_\d+)/', $workerFilename, $matches) === 1
        ) {
            return "{$prefix}_{$matches[1]}.{$extension}";
        }

        return sprintf(
            '%s_%s_%06d.%s',
            $prefix,
            now()->format('Ymd_His'),
            (int) now()->format('u'),
            $extension
        );
    }

    protected function resolveAudioExtension(?string $mimeType = null, ?string $filename = null): string
    {
        $filename = trim((string) $filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext !== '') {
            return preg_replace('/[^a-z0-9]+/i', '', $ext) ?: 'wav';
        }

        $mimeType = strtolower(trim((string) $mimeType));

        return match ($mimeType) {
            'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'wav',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            default => 'wav',
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
        return match ($toolCode) {
            'xomni' => 'The Apollo 1.5v generation failed. Please try again.',
            'xomni-v2' => 'The Apollo 2.0v generation failed. Please try again.',
            'clone_xomni' => 'The Vector 1.5v generation failed. Please try again.',
            'vector-v2' => 'The Vector 2.0v generation failed. Please try again.',
            'ftts' => 'The F5TTS generation failed. Please try again.',
            default => 'The audio generation failed. Please try again.',
        };
    }

    protected function extractAudioBase64(array $out, array $statusPayload = []): string
    {
        $b64 = (string) (
            data_get($out, 'wav_b64', '')
            ?: data_get($out, 'wav_base64', '')
            ?: data_get($out, 'audio_b64', '')
            ?: data_get($out, 'audio_base64', '')
            ?: data_get($statusPayload, 'output.wav_b64', '')
            ?: data_get($statusPayload, 'output.wav_base64', '')
            ?: data_get($statusPayload, 'output.audio_b64', '')
            ?: data_get($statusPayload, 'output.audio_base64', '')
        );

        if ($b64 !== '') {
            return $b64;
        }

        $dataUrl = (string) (
            data_get($out, 'audio_data_url', '')
            ?: data_get($statusPayload, 'output.audio_data_url', '')
        );

        if ($dataUrl !== '' && preg_match('/^data:audio\/[^;]+;base64,(.+)$/i', $dataUrl, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }
}
