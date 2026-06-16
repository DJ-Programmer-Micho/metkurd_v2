<?php

namespace App\Services\Translation;

use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use Illuminate\Support\Facades\DB;

class TranJobSyncService
{
    public function __construct(
        protected CustomerOutputStorage $storage,
        protected JobExecutionLockService $locks,
    ) {}

    public function sync(MlJob $job, Tool $tool): array
    {
        if (in_array((string) $job->status, ['done', 'failed', 'deleted', 'deleting', 'delete_failed'], true)) {
            return $this->payload($job);
        }

        $endpointId = (string) (
            data_get($tool->meta, 'runpod_endpoint_id')
            ?: config('runpod.endpoints.tran')
            ?: env('RUNPOD_ENDPOINT_ID_TRAN')
        );

        if ($endpointId === '') {
            return $this->failJob($job, 'Missing RunPod Translation endpoint id.');
        }

        $providerJobId = (string) $job->provider_job_id;
        if ($providerJobId === '') {
            return $this->failJob($job, 'Missing provider job id.');
        }

        /** @var RunPodProvider $runpod */
        $runpod = app(RunPodProvider::class);
        $statusPayload = $runpod->status($endpointId, $providerJobId);

        $rawStatus = strtoupper((string) data_get($statusPayload, 'status', ''));
        $output = data_get($statusPayload, 'output');
        $errorMessage = (string) (data_get($statusPayload, 'error') ?: data_get($output, 'error') ?: '');

        $translatedText = $this->extractTranslatedText($statusPayload);
        $mapped = match ($rawStatus) {
            'IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED' => 'queued',
            'IN_PROGRESS', 'RUNNING' => 'running',
            'COMPLETED', 'SUCCESS' => ($translatedText !== '' ? 'saving' : 'failed'),
            'FAILED', 'CANCELLED', 'TIMED_OUT' => 'failed',
            default => 'running',
        };

        $this->locks->refreshLock((string) $job->id, 60);

        if ($mapped === 'failed' && $translatedText === '') {
            $message = $errorMessage !== '' ? $errorMessage : (
                in_array($rawStatus, ['COMPLETED', 'SUCCESS'], true)
                    ? 'RunPod completed but returned no translated text.'
                    : 'RunPod Translation failed.'
            );

            return $this->failJob($job, $message);
        }

        if ($translatedText !== '') {
            return $this->finalizeSuccess($job, $tool, $translatedText, $statusPayload);
        }

        MlJob::query()->where('id', $job->id)->update([
            'status' => $mapped,
            'updated_at' => now(),
        ]);

        $job->refresh();

        return $this->payload($job, $this->extractProgress($rawStatus, $statusPayload));
    }

    protected function finalizeSuccess(MlJob $job, Tool $tool, string $translatedText, array $providerPayload = []): array
    {
        return DB::transaction(function () use ($job, $tool, $translatedText, $providerPayload) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (! $fresh) {
                throw new \RuntimeException('Translation job not found during finalize.');
            }

            if ((string) $fresh->status === 'done' && data_get($fresh->output, 'path')) {
                return $this->payload($fresh, 100);
            }

            $base = $this->storage->renderBaseDir($fresh, 'tran');
            $targetKey = "{$base}/target.txt";

            $savedTarget = $this->storage->saveTextToS3(
                (int) $fresh->customer_id,
                $targetKey,
                $translatedText,
                array_merge(
                    $this->storage->apiOutputMeta($fresh, (string) ($tool->code ?: 'tran'), 'target_text'),
                    ['mime' => 'text/plain; charset=UTF-8']
                )
            );

            $fresh->status = 'done';
            $fresh->output = [
                'disk' => $savedTarget['disk'],
                'path' => $savedTarget['path'],
                'bytes' => $savedTarget['bytes'],
                'mime' => $savedTarget['mime'],
                'text' => $translatedText,
                'source_lang' => (string) data_get($providerPayload, 'output.source_lang', data_get($fresh->input, 'source_lang', '')),
                'target_lang' => (string) data_get($providerPayload, 'output.target_lang', data_get($fresh->input, 'target_lang', '')),
                'char_count' => mb_strlen($translatedText),
                'word_count' => $this->unicodeWordCount($translatedText),
                'provider_output' => data_get($providerPayload, 'output', []),
            ];
            $fresh->storage_out_bytes = (int) $savedTarget['bytes'];
            $fresh->finished_at = now();
            $fresh->error = null;
            $fresh->save();

            $this->locks->releaseLock((string) $fresh->id);

            return $this->payload($fresh, 100);
        }, 3);
    }

    protected function failJob(MlJob $job, string $message): array
    {
        MlJob::query()->where('id', $job->id)->update([
            'status' => 'failed',
            'error' => ['message' => $message],
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        $this->locks->releaseLock((string) $job->id);

        $job->refresh();

        return $this->payload($job, 100);
    }

    public function deleteFinishedTranslation(MlJob $job): void
    {
        DB::transaction(function () use ($job) {
            $fresh = MlJob::query()->lockForUpdate()->find($job->id);

            if (! $fresh || ! in_array((string) $fresh->status, ['done', 'delete_failed'], true)) {
                throw new \RuntimeException('Translation not found or already deleted.');
            }

            $fresh->status = 'deleting';
            $fresh->error = null;
            $fresh->save();
        }, 3);

        try {
            $fresh = MlJob::query()->findOrFail($job->id);
            $customerId = (int) $fresh->customer_id;

            $targetPath = (string) data_get($fresh->output, 'path', '');
            $targetBytes = (int) data_get($fresh->output, 'bytes', 0);
            $sourcePath = (string) data_get($fresh->input, 'source_path', '');
            $sourceBytes = (int) ((int) $fresh->storage_in_bytes ?: data_get($fresh->input, 'source_bytes', 0));

            if ($targetPath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $targetPath, $targetBytes);
            }

            if ($sourcePath !== '') {
                $this->storage->deleteFromS3AndUncount($customerId, $sourcePath, $sourceBytes);
            }

            MlJob::query()->where('id', $fresh->id)->update([
                'status' => 'deleted',
                'output' => null,
                'storage_in_bytes' => 0,
                'storage_out_bytes' => 0,
                'error' => null,
                'updated_at' => now(),
            ]);

            $this->locks->releaseLock((string) $fresh->id);
        } catch (\Throwable $e) {
            MlJob::query()->where('id', $job->id)->update([
                'status' => 'delete_failed',
                'error' => ['message' => $e->getMessage()],
                'updated_at' => now(),
            ]);

            throw $e;
        }
    }

    protected function extractTranslatedText(array $statusPayload): string
    {
        foreach ([
            data_get($statusPayload, 'output.translated_text'),
            data_get($statusPayload, 'output.translation'),
            data_get($statusPayload, 'output.text'),
            data_get($statusPayload, 'output.result.translated_text'),
            data_get($statusPayload, 'text'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $output = data_get($statusPayload, 'output');

        if (is_string($output) && trim($output) !== '') {
            return trim($output);
        }

        return '';
    }

    protected function extractProgress(string $rawStatus, array $statusPayload): int
    {
        $progress = data_get($statusPayload, 'progress');

        if (is_numeric($progress)) {
            return max(0, min(100, (int) round((float) $progress)));
        }

        return match ($rawStatus) {
            'IN_QUEUE', 'QUEUED', 'PENDING', 'THROTTLED', 'THROTTLING', 'NO_CAPACITY', 'NO_WORKERS', 'RATE_LIMITED' => 10,
            'IN_PROGRESS', 'RUNNING' => 55,
            'COMPLETED', 'SUCCESS' => 95,
            'FAILED', 'TIMED_OUT', 'CANCELLED' => 100,
            default => 0,
        };
    }

    protected function unicodeWordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}\']+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    protected function payload(MlJob $job, int $progress = 0): array
    {
        $status = (string) $job->status;

        return [
            'job_id' => (string) $job->id,
            'status' => $status,
            'progress' => $progress ?: match ($status) {
                'queued' => 10,
                'running' => 45,
                'saving' => 90,
                'done', 'failed' => 100,
                default => 0,
            },
            'done' => $status === 'done',
            'failed' => $status === 'failed',
            'message' => (string) data_get($job->error, 'message', ''),
            'text' => (string) data_get($job->output, 'text', ''),
        ];
    }
}
