<?php

namespace App\Console\Commands;

use App\Models\CustomerFile;
use App\Services\Storage\StorageFileDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredApiFiles extends Command
{
    protected $signature = 'api:cleanup-expired-files {--limit=200}';

    protected $description = 'Delete expired temporary public API files.';

    public function __construct(
        protected StorageFileDeletionService $deletions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $files = CustomerFile::query()
            ->where('status', 'active')
            ->where('retention_mode', 'temporary')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $deleted = 0;

        foreach ($files as $file) {
            try {
                $this->deletions->delete($file, 'expired');
                $deleted++;
            } catch (\Throwable $e) {
                Log::warning('API_EXPIRED_FILE_DELETE_FAILED', [
                    'customer_file_id' => (int) $file->id,
                    'path' => (string) $file->path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired API files deleted: {$deleted}");

        return self::SUCCESS;
    }
}
