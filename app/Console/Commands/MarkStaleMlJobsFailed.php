<?php

namespace App\Console\Commands;

use App\Models\MlJob;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkStaleMlJobsFailed extends Command
{
    protected $signature = 'ml-jobs:mark-stale-failed
        {--status=* : Limit to specific statuses (queue,queued,processing,running,saving)}
        {--minutes= : Use one timeout (in minutes) for all targeted statuses}
        {--queued-minutes=30 : Timeout for queue/queued statuses}
        {--processing-minutes=60 : Timeout for processing/running/saving statuses}
        {--chunk=200 : Number of rows per chunk}
        {--limit=0 : Maximum rows to process (0 means no limit)}
        {--customer-id= : Limit to a single customer id}
        {--job-kind= : Limit to a specific job_kind}
        {--dry-run : Show what would be updated without writing changes}';

    protected $description = 'Mark stale ML jobs as failed so pending queues do not get stuck forever.';

    /**
     * @var array<int, string>
     */
    protected array $allowedStatuses = ['queue', 'queued', 'processing', 'running', 'saving'];

    public function handle(): int
    {
        $chunk = max(10, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $customerId = (int) $this->option('customer-id');
        $jobKind = strtolower(trim((string) $this->option('job-kind')));
        $dryRun = (bool) $this->option('dry-run');

        $statuses = $this->resolveStatuses($this->option('status'));

        if ($statuses === []) {
            $this->error('No valid statuses were selected. Allowed: '.implode(', ', $this->allowedStatuses));

            return self::FAILURE;
        }

        $timeouts = $this->resolveTimeouts($statuses);
        $query = MlJob::query()
            ->whereNull('provider_job_id')
            ->whereNull('charge_reference')
            ->whereNull('submission_attempted_at')
            ->where(function ($builder) use ($statuses, $timeouts) {
                foreach ($statuses as $status) {
                    $timeout = $timeouts[$status] ?? null;

                    if ($timeout === null) {
                        continue;
                    }

                    $threshold = now()->subMinutes($timeout);

                    $builder->orWhere(function ($statusQuery) use ($status, $threshold) {
                        $statusQuery->where('status', $status);

                        if (in_array($status, ['queue', 'queued'], true)) {
                            $statusQuery->where('created_at', '<=', $threshold);

                            return;
                        }

                        $statusQuery->where(function ($timeQuery) use ($threshold) {
                            $timeQuery
                                ->where(function ($started) use ($threshold) {
                                    $started->whereNotNull('started_at')->where('started_at', '<=', $threshold);
                                })
                                ->orWhere(function ($fallback) use ($threshold) {
                                    $fallback
                                        ->whereNull('started_at')
                                        ->where('updated_at', '<=', $threshold);
                                });
                        });
                    });
                }
            });

        if ($customerId > 0) {
            $query->where('customer_id', $customerId);
        }

        if ($jobKind !== '') {
            $query->where('job_kind', $jobKind);
        }

        $candidateCount = (clone $query)->count();

        if ($candidateCount === 0) {
            $this->info('No stale ML jobs matched the current filters.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $previewPrinted = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunk, function ($jobs) use (
                $limit,
                $dryRun,
                $timeouts,
                &$processed,
                &$updated,
                &$previewPrinted
            ) {
                foreach ($jobs as $job) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    if (! $this->isJobStale($job, $timeouts)) {
                        continue;
                    }

                    if ($dryRun) {
                        $updated++;

                        if ($previewPrinted < 20) {
                            $previewPrinted++;
                            $this->line(sprintf(
                                '[dry-run] job_id=%s customer_id=%d status=%s updated_at=%s started_at=%s',
                                (string) $job->id,
                                (int) $job->customer_id,
                                (string) $job->status,
                                (string) optional($job->updated_at)?->toIso8601String(),
                                (string) optional($job->started_at)?->toIso8601String(),
                            ));
                        }

                        continue;
                    }

                    if ($this->markFailed($job, $timeouts)) {
                        $updated++;
                    }
                }

                return true;
            });

        $this->info($dryRun
            ? 'Stale ML job cleanup dry-run completed.'
            : 'Stale ML job cleanup completed.');
        $this->line('Candidates: '.number_format($candidateCount));
        $this->line('Processed: '.number_format($processed));
        $this->line($dryRun ? 'Would Update: '.number_format($updated) : 'Updated: '.number_format($updated));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveStatuses(mixed $rawStatuses): array
    {
        if (! is_array($rawStatuses) || $rawStatuses === []) {
            return $this->allowedStatuses;
        }

        return collect($rawStatuses)
            ->flatMap(function ($value) {
                if (! is_scalar($value)) {
                    return [];
                }

                return explode(',', strtolower(trim((string) $value)));
            })
            ->map(static fn (string $status): string => trim($status))
            ->filter(fn (string $status): bool => in_array($status, $this->allowedStatuses, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<string, int>
     */
    protected function resolveTimeouts(array $statuses): array
    {
        $global = max(0, (int) $this->option('minutes'));
        $queuedMinutes = max(1, (int) $this->option('queued-minutes'));
        $processingMinutes = max(1, (int) $this->option('processing-minutes'));

        if ($global > 0) {
            $queuedMinutes = $global;
            $processingMinutes = $global;
        }

        $timeouts = [];

        foreach ($statuses as $status) {
            $timeouts[$status] = in_array($status, ['queue', 'queued'], true)
                ? $queuedMinutes
                : $processingMinutes;
        }

        return $timeouts;
    }

    /**
     * @param  array<string, int>  $timeouts
     */
    protected function isJobStale(MlJob $job, array $timeouts): bool
    {
        $status = strtolower((string) $job->status);
        $timeoutMinutes = $timeouts[$status] ?? null;

        if ($timeoutMinutes === null) {
            return false;
        }

        $threshold = now()->subMinutes($timeoutMinutes);
        $reference = $this->referenceTimeForStatus($job, $status);

        return $reference !== null && $reference->lessThanOrEqualTo($threshold);
    }

    protected function referenceTimeForStatus(MlJob $job, string $status): ?CarbonInterface
    {
        if (in_array($status, ['queue', 'queued'], true)) {
            return $job->created_at instanceof CarbonInterface ? $job->created_at : null;
        }

        if ($job->started_at instanceof CarbonInterface) {
            return $job->started_at;
        }

        if ($job->updated_at instanceof CarbonInterface) {
            return $job->updated_at;
        }

        return $job->created_at instanceof CarbonInterface ? $job->created_at : null;
    }

    /**
     * @param  array<string, int>  $timeouts
     */
    protected function markFailed(MlJob $job, array $timeouts): bool
    {
        return (bool) DB::transaction(function () use ($job, $timeouts) {
            /** @var MlJob|null $locked */
            $locked = MlJob::query()->lockForUpdate()->find($job->id);

            if (! $locked instanceof MlJob) {
                return false;
            }

            // Dispatch may have begun after the command selected its candidates.
            if ($locked->provider_job_id || $locked->charge_reference || $locked->submission_attempted_at) {
                return false;
            }

            if (! $this->isJobStale($locked, $timeouts)) {
                return false;
            }

            $previousStatus = strtolower((string) $locked->status);

            if (! in_array($previousStatus, $this->allowedStatuses, true)) {
                return false;
            }

            $error = is_array($locked->error) ? $locked->error : [];
            $error['message'] = 'Job timed out and was automatically marked as failed.';
            $error['code'] = 'stale_timeout';
            $error['source'] = 'ml-jobs:mark-stale-failed';
            $error['previous_status'] = $previousStatus;
            $error['timed_out_at'] = now()->toIso8601String();

            $locked->forceFill([
                'status' => 'failed',
                'error' => $error,
                'finished_at' => $locked->finished_at ?? now(),
                'execution_scope' => null,
                'locked_by_session_id' => null,
                'locked_by_fingerprint' => null,
                'lock_expires_at' => null,
            ])->save();

            return true;
        }, 3);
    }
}
