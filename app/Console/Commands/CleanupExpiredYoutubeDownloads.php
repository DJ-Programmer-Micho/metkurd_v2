<?php

namespace App\Console\Commands;

use App\Models\MlJob;
use App\Services\Youtube\YoutubeOutputStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredYoutubeDownloads extends Command
{
    protected $signature = 'youtube:cleanup-expired {--limit=200 : Maximum expired jobs to process in one run}';

    protected $description = 'Delete expired prepared YouTube downloads from shared storage and mark the jobs as deleted';

    public function handle(YoutubeOutputStorage $storage): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $jobs = MlJob::query()
            ->where('job_kind', 'youtube_download')
            ->where('status', 'done')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('No expired YouTube downloads found.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($jobs as $job) {
            try {
                $storage->deleteStoredOutput($job);
                $storage->cleanupLocalTempDir((string) $job->id);

                $job->update([
                    'status' => 'deleted',
                    'storage_out_bytes' => 0,
                    'expires_at' => null,
                    'output' => array_replace((array) $job->output, [
                        'expired_at' => now()->toDateTimeString(),
                        'disk' => null,
                        'path' => null,
                        'delivery' => null,
                    ]),
                ]);

                $deleted++;
            } catch (\Throwable $e) {
                Log::warning('YOUTUBE_CLEANUP_FAIL', [
                    'job_id' => (string) $job->id,
                    'error' => $e->getMessage(),
                ]);

                $this->warn("Failed to clean job {$job->id}: {$e->getMessage()}");
            }
        }

        $this->info("Cleaned {$deleted} expired YouTube download(s).");

        return self::SUCCESS;
    }
}
