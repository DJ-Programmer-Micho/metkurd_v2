<?php

namespace App\Services\Youtube;

use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\Security\JobExecutionLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Process\InvokedProcess;
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

        if (! $this->jobCanRun($job)) {
            return;
        }

        $job->update([
            'status' => 'running',
            'started_at' => $job->started_at ?: now(),
            'expires_at' => null,
        ]);

        $locks->refreshLock((string) $job->id, self::LOCK_MINUTES);

        $process = null;
        $uploaded = null;
        $finalized = false;

        try {
            $process = $cli->startDownload(
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

            $this->mirrorProgressWhileRunning($job, $process, $cli, $locks);

            $result = $process->wait();

            $this->persistProgressState($job, $cli->progressState((string) $job->id), true);

            $payload = $cli->decodeDownloadResult($result);

            $job->refresh();

            if (! $this->jobCanRun($job)) {
                $this->stopIfRunning($process);

                return;
            }

            $job->update([
                'status' => 'saving',
                'output' => array_replace((array) $job->output, [
                    'worker_meta' => $payload['meta'] ?? [],
                    'file_name' => (string) ($payload['file_name'] ?? 'download.bin'),
                    'download_name' => (string) ($payload['file_name'] ?? 'download.bin'),
                    'mime' => (string) ($payload['mime'] ?? 'application/octet-stream'),
                    'delivery' => 'browser_direct',
                ]),
            ]);

            $locks->refreshLock((string) $job->id, self::LOCK_MINUTES);

            $localFilePath = (string) ($payload['file_path'] ?? '');

            if (! $storage->outputExists($localFilePath)) {
                throw new \RuntimeException('Finished download file was not found.');
            }

            $uploaded = $storage->uploadLocalOutput(
                job: $job->fresh(['customer']) ?? $job,
                localPath: $localFilePath,
                downloadName: (string) ($payload['file_name'] ?? 'download.bin'),
                mime: (string) ($payload['mime'] ?? 'application/octet-stream'),
            );

            $finalized = DB::transaction(function () use ($job, $payload, $uploaded) {
                $fresh = MlJob::query()->lockForUpdate()->find($job->id);

                if (! $fresh) {
                    throw new \RuntimeException('Job not found during YouTube finalize.');
                }

                if (! $this->jobCanRun($fresh)) {
                    return false;
                }

                $output = array_replace((array) $fresh->output, [
                    'worker_meta' => $payload['meta'] ?? [],
                    'disk' => (string) $uploaded['disk'],
                    'path' => (string) $uploaded['path'],
                    'mime' => (string) $uploaded['mime'],
                    'file_name' => (string) ($payload['file_name'] ?? basename((string) $uploaded['path'])),
                    'download_name' => (string) $uploaded['download_name'],
                    'delivery' => 'browser_direct',
                    'ready_at' => now()->toDateTimeString(),
                    'expires_at' => $uploaded['expires_at']->toDateTimeString(),
                    'browser_download_opened_at' => data_get($fresh->output, 'browser_download_opened_at'),
                ]);

                unset($output['local_file_path']);

                $fresh->update([
                    'status' => 'done',
                    'finished_at' => now(),
                    'expires_at' => $uploaded['expires_at'],
                    'storage_out_bytes' => (int) $uploaded['bytes'],
                    'output' => $output,
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

            if (! $finalized) {
                $storage->deleteStoredPath($uploaded['disk'] ?? null, $uploaded['path'] ?? null);
            }
        } catch (\Throwable $e) {
            $this->stopIfRunning($process);

            if (is_array($uploaded) && ! $finalized) {
                $storage->deleteStoredPath($uploaded['disk'] ?? null, $uploaded['path'] ?? null);
            }

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
                    'expires_at' => null,
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
            try {
                $storage->cleanupLocalTempDir((string) $job->id);
            } catch (\Throwable $cleanup) {
                Log::warning('YOUTUBE_JOB_CLEANUP_FAIL', [
                    'job_id' => $this->jobId,
                    'error' => $cleanup->getMessage(),
                ]);
            }

            $locks->releaseLock((string) $job->id);
        }
    }

    protected function mirrorProgressWhileRunning(
        MlJob $job,
        InvokedProcess $process,
        YoutubeDownloadCliService $cli,
        JobExecutionLockService $locks
    ): void {
        $lastProgressHash = null;
        $lastProgressWriteAt = 0.0;
        $lastLockRefreshAt = microtime(true);

        while ($process->running()) {
            $job->refresh();

            if (! $this->jobCanRun($job)) {
                $this->stopIfRunning($process);

                throw new \RuntimeException('Download was cancelled before completion.');
            }

            $progress = $cli->progressState((string) $job->id);
            $progressHash = md5(json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $now = microtime(true);

            if ($progressHash !== $lastProgressHash || ($now - $lastProgressWriteAt) >= 5.0) {
                $this->persistProgressState($job, $progress);
                $lastProgressHash = $progressHash;
                $lastProgressWriteAt = $now;
            }

            if (($now - $lastLockRefreshAt) >= 20.0) {
                $locks->refreshLock((string) $job->id, self::LOCK_MINUTES);
                $lastLockRefreshAt = $now;
            }

            usleep(500000);
        }
    }

    protected function persistProgressState(MlJob $job, array $progressState, bool $force = false): void
    {
        $snapshot = is_array($progressState['snapshot'] ?? null)
            ? $progressState['snapshot']
            : null;
        $logLines = is_array($progressState['log_lines'] ?? null)
            ? array_values(array_slice($progressState['log_lines'], -10))
            : [];

        if (! $force && $snapshot === null && $logLines === []) {
            return;
        }

        $output = array_replace((array) $job->output, [
            'progress_snapshot' => $snapshot,
            'progress_log' => $logLines,
            'progress_updated_at' => $snapshot['updated_at'] ?? now()->toDateTimeString(),
        ]);

        MlJob::query()
            ->where('id', $job->id)
            ->update([
                'output' => $output,
                'updated_at' => now(),
            ]);

        $job->setAttribute('output', $output);
    }

    protected function stopIfRunning(?InvokedProcess $process): void
    {
        if (! $process instanceof InvokedProcess || ! $process->running()) {
            return;
        }

        try {
            $process->stop(3);
        } catch (\Throwable $e) {
            Log::warning('YOUTUBE_WORKER_STOP_FAIL', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function jobCanRun(MlJob $job): bool
    {
        return in_array((string) $job->status, ['queued', 'running', 'saving'], true);
    }
}
