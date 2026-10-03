<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\MlJob;
use App\Services\Billing\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Applies the single financial correction associated with a failed MlJob. */
class MlJobRefundService
{
    public function __construct(private readonly CreditService $credits) {}

    public function refundFailedJob(MlJob|string $job, string $reason): bool
    {
        $jobId = $job instanceof MlJob ? (string) $job->id : $job;

        return DB::transaction(function () use ($jobId, $reason): bool {
            $fresh = MlJob::query()->lockForUpdate()->find($jobId);

            if (! $fresh || (int) $fresh->credits_charged <= 0) {
                return false;
            }

            if ($fresh->refunded_at) {
                return true;
            }
            if ($fresh->status !== 'failed' || $fresh->failure_stage === 'provider_submission_unknown'
                || data_get($fresh->error, 'type') === 'eliminated_by_customer'
                || data_get($fresh->input, 'api_job_id')) {
                return false;
            }
            $reference = (string) ($fresh->refund_reference ?: "ml-job:{$fresh->id}:refund");
            try {
                $this->credits->refundFromCharge(
                    customerId: (int) $fresh->customer_id,
                    credits: (int) $fresh->credits_charged,
                    chargeReference: (string) ($fresh->charge_reference ?: "ml-job:{$fresh->id}:charge"),
                    type: 'ml_job_refund',
                    meta: [
                        'reference_code' => $reference,
                        'related_type' => 'ml_job',
                        'related_id' => (string) $fresh->id,
                        'ml_job_id' => (string) $fresh->id,
                        'tool_action' => (string) $fresh->toolAction?->full_code,
                        'reason' => $reason,
                    ],
                );
            } catch (\DomainException $exception) {
                Log::warning('ML_JOB_REFUND_EVIDENCE_INVALID', [
                    'job_id' => (string) $fresh->id,
                    'code' => $exception->getMessage(),
                ]);

                return false;
            }

            $fresh->refund_reference = $reference;
            $fresh->refunded_at = now();
            $fresh->save();

            return true;
        }, 3);
    }
}
