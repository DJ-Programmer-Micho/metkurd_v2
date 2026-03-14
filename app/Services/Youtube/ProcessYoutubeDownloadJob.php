<?php

namespace App\Services\Youtube;

use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\Security\JobExecutionLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessYoutubeDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const LOCK_MINUTES = 130;

    public string $jobId;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(string $jobId)
    {
        $this->jobId = $jobId;
    }

    public function handle(
        YoutubeDownloadCliService $cli,
        YoutubeOutputStorage $storage,
        JobExecutionLockService $locks
    ): void {
        $job = MlJob::query()->findOrFail($this->jobId);

        if (! in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
            return;
        }

        $job->update([
            'status' => 'running',
            'started_at' => $job->started_at ?: now(),
        ]);

        $locks->refreshLock((string) $job->id, self::LOCK_MINUTES);

        $keepLocalOutput = false;

        try {
            $result = $cli->download(
                url: (string) data_get($job->input, 'url', ''),
                mode: (string) data_get(
                    $job->input,
                    'worker_mode',
                    data_get($job->input, 'preview_type') === 'playlist'
                        ? 'playlist'
                        : data_get($job->input, 'mode', 'audio')
                ),
                format: (string) data_get($job->input, 'format', 'mp3'),
                quality: (string) data_get($job->input, 'quality', 'p720'),
                jobId: (string) $job->id,
            );

            $job->refresh();

            if (! in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
                return;
            }

            $job->update([
                'status' => 'saving',
                'output' => array_merge((array) $job->output, [
                    'worker_meta' => $result['meta'] ?? [],
                    'local_file_path' => (string) ($result['file_path'] ?? ''),
                    'mime' => (string) ($result['mime'] ?? 'application/octet-stream'),
                    'file_name' => (string) ($result['file_name'] ?? 'download.bin'),
                    'download_name' => (string) ($result['file_name'] ?? 'download.bin'),
                    'delivery' => 'browser_direct',
                ]),
            ]);

            $locks->refreshLock((string) $job->id, self::LOCK_MINUTES);

            $localFilePath = (string) ($result['file_path'] ?? '');

            if (! $storage->outputExists($localFilePath)) {
                throw new \RuntimeException('Finished download file was not found.');
            }

            $bytes = $storage->outputBytes($localFilePath);

            $job->refresh();

            if (! in_array((string) $job->status, ['queued', 'running', 'saving'], true)) {
                return;
            }

            $finalized = DB::transaction(function () use ($job, $result, $localFilePath, $bytes) {
                $fresh = MlJob::query()->lockForUpdate()->find($job->id);

                if (! $fresh) {
                    throw new \RuntimeException('Job not found during finalize.');
                }

                if (! in_array((string) $fresh->status, ['queued', 'running', 'saving'], true)) {
                    return false;
                }

                $fresh->update([
                    'status' => 'done',
                    'finished_at' => now(),
                    'storage_out_bytes' => $bytes,
                    'output' => array_merge((array) $fresh->output, [
                        'local_file_path' => $localFilePath,
                        'mime' => (string) ($result['mime'] ?? 'application/octet-stream'),
                        'file_name' => (string) ($result['file_name'] ?? 'download.bin'),
                        'download_name' => (string) ($result['file_name'] ?? 'download.bin'),
                        'delivery' => 'browser_direct',
                    ]),
                    'error' => null,
                ]);

                CustomerUsage::query()->firstOrCreate(
                    ['customer_id' => (int) $fresh->customer_id],
                    [
                        'storage_used_bytes' => 0,
                        'jobs_total' => 0,
                        'jobs_succeeded' => 0,
                        'jobs_failed' => 0,
                    ]
                );

                CustomerUsage::query()
                    ->where('customer_id', (int) $fresh->customer_id)
                    ->increment('jobs_succeeded');

                return true;
            });

            if ($finalized === true) {
                $keepLocalOutput = true;
            }
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_JOB_FAIL', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            $job->refresh();

            if (! in_array((string) $job->status, ['failed', 'deleted'], true)) {
                $job->update([
                    'status' => 'failed',
                    'error' => ['message' => $e->getMessage()],
                    'finished_at' => now(),
                ]);

                CustomerUsage::query()->firstOrCreate(
                    ['customer_id' => (int) $job->customer_id],
                    [
                        'storage_used_bytes' => 0,
                        'jobs_total' => 0,
                        'jobs_succeeded' => 0,
                        'jobs_failed' => 0,
                    ]
                );

                CustomerUsage::query()
                    ->where('customer_id', (int) $job->customer_id)
                    ->increment('jobs_failed');
            }

            throw $e;
        } finally {
            if (! $keepLocalOutput) {
                try {
                    $storage->cleanupLocalTempDir((string) $job->id);
                } catch (\Throwable $cleanup) {
                    Log::warning('YOUTUBE_JOB_CLEANUP_FAIL', [
                        'job_id' => $this->jobId,
                        'error' => $cleanup->getMessage(),
                    ]);
                }
            }

            $locks->releaseLock((string) $job->id);
        }
    }
}
