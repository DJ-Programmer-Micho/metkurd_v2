<?php

namespace App\Services\Youtube;

use App\Models\MlJob;
use App\Services\Security\JobExecutionLockService;

class YoutubeJobSyncService
{
    public function __construct(
        protected YoutubeOutputStorage $storage,
        protected JobExecutionLockService $locks,
    ) {}

    public function sync(MlJob $job): array
    {
        $fresh = MlJob::query()->findOrFail($job->id);
        $status = (string) $fresh->status;
        $jobId = (string) $fresh->id;

        $snapshot = in_array($status, ['queued', 'running', 'saving'], true)
            ? $this->storage->readProgressSnapshot($jobId)
            : null;

        $progress = $this->resolveProgress($status, $snapshot);
        $queuedForSec = $status === 'queued' && $fresh->created_at
            ? now()->diffInSeconds($fresh->created_at)
            : null;
        $startedAt = $fresh->started_at;
        $elapsedSec = isset($snapshot['elapsed_sec']) ? (int) $snapshot['elapsed_sec'] : null;

        if ($elapsedSec === null && $startedAt && in_array($status, ['running', 'saving'], true)) {
            $elapsedSec = now()->diffInSeconds($startedAt);
        }

        return [
            'status' => $status,
            'progress' => $progress,
            'done' => $status === 'done',
            'failed' => in_array($status, ['failed', 'delete_failed'], true),
            'message' => $this->resolveMessage($fresh, $status, $snapshot, $queuedForSec),
            'phase' => (string) ($snapshot['phase'] ?? ''),
            'speed_bps' => isset($snapshot['speed_bps']) ? (float) $snapshot['speed_bps'] : null,
            'eta_sec' => isset($snapshot['eta_sec']) ? (int) $snapshot['eta_sec'] : null,
            'downloaded_bytes' => isset($snapshot['downloaded_bytes']) ? (int) $snapshot['downloaded_bytes'] : null,
            'total_bytes' => isset($snapshot['total_bytes']) ? (int) $snapshot['total_bytes'] : null,
            'elapsed_sec' => $elapsedSec,
            'queued_for_sec' => $queuedForSec,
            'file_name' => (string) ($snapshot['file_name'] ?? ''),
            'title' => (string) ($snapshot['title'] ?? ''),
            'playlist_index' => isset($snapshot['playlist_index']) ? (int) $snapshot['playlist_index'] : null,
            'playlist_count' => isset($snapshot['playlist_count']) ? (int) $snapshot['playlist_count'] : null,
            'updated_at' => (string) ($snapshot['updated_at'] ?? ''),
            'log_lines' => $this->normalizeLogLines($this->storage->readProgressLog($jobId, 10)),
        ];
    }

    protected function resolveProgress(string $status, ?array $snapshot): int
    {
        $fallback = match ($status) {
            'queued' => 10,
            'running' => 50,
            'saving' => 90,
            'done' => 100,
            'failed' => 100,
            'deleted' => 100,
            'delete_failed' => 100,
            default => 0,
        };

        if (! is_array($snapshot)) {
            return $fallback;
        }

        $rawPercent = $snapshot['percent'] ?? null;

        if (! is_numeric($rawPercent)) {
            return $fallback;
        }

        $percent = (int) round((float) $rawPercent);

        if ($status === 'saving') {
            return max(90, min(99, $percent));
        }

        if ($status === 'running') {
            return max(1, min(99, $percent));
        }

        return max(0, min(100, $percent));
    }

    protected function resolveMessage(MlJob $job, string $status, ?array $snapshot, ?int $queuedForSec): string
    {
        if (is_array($snapshot) && filled($snapshot['message'] ?? null)) {
            return (string) $snapshot['message'];
        }

        if (in_array($status, ['failed', 'delete_failed'], true)) {
            return (string) data_get($job->error, 'message', 'Download failed.');
        }

        if ($status === 'done') {
            return 'Download is ready for your device.';
        }

        if ($status === 'saving') {
            return 'Saving the finished file.';
        }

        if ($status === 'running') {
            return 'Worker is processing the download.';
        }

        if ($status === 'queued') {
            if (($queuedForSec ?? 0) >= 30) {
                return 'Waiting for the queue worker to pick up this job.';
            }

            return 'Queued and waiting to start.';
        }

        return '';
    }

    protected function normalizeLogLines(array $lines): array
    {
        return array_values(array_map(function (array $line) {
            return [
                'ts' => (string) ($line['ts'] ?? ''),
                'event' => (string) ($line['event'] ?? ''),
                'phase' => (string) ($line['phase'] ?? ''),
                'message' => (string) ($line['message'] ?? ''),
                'percent' => isset($line['percent']) && is_numeric($line['percent'])
                    ? (int) round((float) $line['percent'])
                    : null,
                'speed_bps' => isset($line['speed_bps']) && is_numeric($line['speed_bps'])
                    ? (float) $line['speed_bps']
                    : null,
                'eta_sec' => isset($line['eta_sec']) && is_numeric($line['eta_sec'])
                    ? (int) $line['eta_sec']
                    : null,
                'playlist_index' => isset($line['playlist_index']) && is_numeric($line['playlist_index'])
                    ? (int) $line['playlist_index']
                    : null,
                'playlist_count' => isset($line['playlist_count']) && is_numeric($line['playlist_count'])
                    ? (int) $line['playlist_count']
                    : null,
            ];
        }, $lines));
    }

    public function deleteFinishedDownload(MlJob $job): void
    {
        $fresh = MlJob::query()->findOrFail($job->id);

        if (! in_array((string) $fresh->status, ['done', 'delete_failed'], true)) {
            throw new \RuntimeException('Download not found or already deleted.');
        }

        try {
            $this->storage->cleanupLocalTempDir((string) $fresh->id);

            $fresh->update([
                'status' => 'deleted',
                'error' => null,
                'storage_out_bytes' => 0,
                'output' => array_merge((array) $fresh->output, [
                    'deleted_at' => now()->toDateTimeString(),
                    'local_file_path' => null,
                ]),
            ]);

            $this->locks->releaseLock((string) $fresh->id);
        } catch (\Throwable $e) {
            $fresh->update([
                'status' => 'delete_failed',
                'error' => ['message' => $e->getMessage()],
            ]);

            throw $e;
        }
    }
}
