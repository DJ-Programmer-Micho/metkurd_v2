<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileMlJob;
use App\Models\ApiJob;
use App\Models\MlJob;
use Illuminate\Console\Command;

class ReconcileMlJobs extends Command
{
    protected $signature = 'ml-jobs:reconcile {--limit=200}';

    protected $description = 'Queue due GPU status checks and recover pending financial corrections.';

    public function handle(): int
    {
        $limit = max(1, min(2000, (int) $this->option('limit')));
        // Unlinked claims cannot have charged or dispatched: context attachment and reservation are atomic.
        // Expiry prevents a delayed request from starting after this correction.
        ApiJob::query()->where('meta->api_version', 2)->where('status', 'accepted')
            ->whereNull('ml_job_id')->where('created_at', '<', now()->subMinutes(15))
            ->update(['status' => 'failed', 'error_code' => 'processing_failed', 'completed_at' => now()]);
        $ids = MlJob::query()->where(function ($query) {
            $query->where(function ($active) {
                $active->active()->where('provider', 'runpod')->whereNotNull('provider_job_id')->where('provider_job_id', '!=', '')
                    ->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
                    ->where(fn ($lease) => $lease->whereNull('poll_locked_until')->orWhere('poll_locked_until', '<=', now()));
            })->orWhere(fn ($refund) => $refund->where('status', 'failed')->where('failure_stage', 'refund_pending')->where(fn ($due) => $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now())));
        })->orderBy('next_poll_at')->orderBy('updated_at')->limit($limit)->pluck('id');
        $apiIds = ApiJob::query()->whereIn('status', ['queued', 'processing', 'accepted'])
            ->whereHas('mlJob', fn ($job) => $job->whereIn('status', ['done', 'failed']))->limit($limit)->pluck('ml_job_id');
        foreach ($ids->merge($apiIds)->unique() as $id) {
            ReconcileMlJob::dispatch((string) $id);
        }
        $this->info('Due job checks queued.');

        return self::SUCCESS;
    }
}
