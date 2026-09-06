<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\MlJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Coordinates browser, API and queue polling across application servers. */
class JobPollCoordinator
{
    public function sync(MlJob $job, callable $poll, callable $payload): array
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($job, $token) {
            $fresh = MlJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! $fresh->isActive() || ! $fresh->provider_job_id
                || ($fresh->next_poll_at && $fresh->next_poll_at->isFuture())
                || ($fresh->poll_locked_until && $fresh->poll_locked_until->isFuture())) {
                return false;
            }
            $fresh->forceFill(['poll_token' => $token, 'poll_locked_until' => now()->addMinutes(5),
                'next_poll_at' => now()->addSeconds(10), 'poll_attempts' => $fresh->poll_attempts + 1])->save();

            return true;
        }, 3);
        $job->refresh();
        if (! $claimed) {
            return $payload($job);
        }
        try {
            return $poll($job);
        } catch (\Throwable $exception) {
            Log::warning('ML_JOB_SYNC_RETRY', ['job_id' => $job->id, 'exception' => $exception::class]);

            return $payload($job->refresh());
        } finally {
            $fresh = $job->fresh();
            if ($fresh->status === 'failed' && $fresh->charge_reference && ! $fresh->refunded_at
                && data_get($fresh->error, 'type') !== 'eliminated_by_customer'
                && ! data_get($fresh->input, 'api_job_id') && ! data_get($fresh->input, 'customer_cancel_requested')
                && $fresh->failure_stage !== 'provider_submission_unknown') {
                $fresh->update(['failure_stage' => 'refund_pending']);
                app(DurableUploadSubmission::class)->retryRefund($fresh);
            }
            // Progressively back off long-running and unavailable jobs (10–60 seconds).
            $delay = min(60, 10 * (1 + intdiv((int) $job->poll_attempts, 6)));
            MlJob::query()->whereKey($job->id)->where('poll_token', $token)->update([
                'poll_token' => null, 'poll_locked_until' => null, 'next_poll_at' => now()->addSeconds($delay),
            ]);
        }
    }
}
