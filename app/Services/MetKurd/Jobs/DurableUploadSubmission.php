<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\Customer;
use App\Models\MlJob;
use App\Services\Billing\CreditService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** The local job and its app-wallet debit commit together, before any remote work. */
class DurableUploadSubmission
{
    public function __construct(private CreditService $credits, private MlJobRefundService $refunds) {}

    public function begin(int $customerId, string $key, string $action, int $amount, string $type, array $meta, callable $create, ?SubmissionContext $context = null): array
    {
        $context ??= new SubmissionContext;

        return DB::transaction(function () use ($context, $customerId, $key, $action, $amount, $type, $meta, $create) {
            Customer::query()->lockForUpdate()->findOrFail($customerId);
            $existing = MlJob::query()->where('customer_id', $customerId)->where('submission_key', $key)->first();
            if ($existing) {
                if (data_get($existing->input, 'billing_action') !== $action) {
                    throw new \InvalidArgumentException('Submission identity belongs to another action.');
                }

                return [$existing, false];
            }
            $job = $create();
            $reference = "ml-job:{$job->id}:charge";
            $job->update(['submission_key' => $key, 'charge_reference' => $reference,
                'failure_stage' => 'preparing', 'input' => array_merge($job->input ?? [], ['billing_action' => $action, 'wallet_type' => 'app'])]);
            $context->charge($customerId, $amount, $type, array_merge($meta, [
                'reference_code' => $reference, 'related_type' => 'ml_job', 'related_id' => $job->id,
                'ml_job_id' => $job->id, 'tool_action' => $action,
            ]));

            return [$job->refresh(), true];
        }, 3);
    }

    public function failed(MlJob $job, \Throwable $exception): MlJob
    {
        $job->refresh();
        // A timeout, 5xx response, missing ID, or local failure after dispatch may hide acceptance.
        $rejected = $exception instanceof RequestException
            && in_array($exception->response->status(), [400, 401, 403, 404, 405, 413, 422, 429], true);
        if ($job->submission_attempted_at && ! $rejected) {
            $job->update(['failure_stage' => 'provider_submission_unknown',
                'error' => ['message' => __('Processing status is being reviewed. Please do not resubmit.')]]);
            Log::warning('GPU_SUBMISSION_REQUIRES_REVIEW', ['job_id' => $job->id]);

            return $job->fresh();
        }
        $job->update(['status' => 'failed', 'failure_stage' => 'refund_pending', 'finished_at' => now(),
            'error' => ['code' => $exception instanceof \App\Services\Storage\StorageQuotaExceededException ? 'storage_limit_exceeded' : 'processing_failed', 'message' => \App\Support\CustomerFacingError::message($exception->getMessage())]]);
        $this->retryRefund($job);

        return $job->fresh();
    }

    public function retryRefund(MlJob $job): void
    {
        if ($job->failure_stage !== 'refund_pending' || $job->status !== 'failed' || ! $job->charge_reference) {
            return;
        }
        try {
            if ($apiJobId = data_get($job->input, 'api_job_id')) {
                $reservation = \App\Models\ApiCreditReservation::query()->where('api_job_id', $apiJobId)->where('customer_id', $job->customer_id)->first();
                if ($reservation) {
                    app(\App\Services\CustomerApi\CustomerApiCreditReservationService::class)->release($reservation);
                    $job->update(['refunded_at' => now(), 'failure_stage' => 'submission_failed']);
                }

                return;
            }
            if ($this->refunds->refundFailedJob($job, 'submission_failed')) {
                $job->refresh()->update(['failure_stage' => 'submission_failed']);
            }
        } catch (\Throwable $exception) {
            // The durable pending marker survives a failed correction and is retried by reconciliation.
            Log::warning('GPU_SUBMISSION_REFUND_PENDING', ['job_id' => $job->id, 'exception' => $exception::class]);
        } finally {
            MlJob::query()->whereKey($job->id)->update(['next_poll_at' => now()->addMinute()]);
        }
    }
}
