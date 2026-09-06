<?php

namespace App\Services\MetKurd\Jobs;

use App\Models\ApiJob;
use App\Models\MlJob;
use App\Services\Billing\CreditService;
use App\Services\CustomerApi\CustomerApiCreditReservationService;

/** Trusted application context, never constructed from customer request fields. */
final readonly class SubmissionContext
{
    public function __construct(public ?ApiJob $apiJob = null) {}

    public function channel(): string
    {
        return $this->apiJob ? 'api' : 'app';
    }

    /** Called inside the core's local job transaction, before any remote work. */
    public function charge(int $customerId, int $credits, string $type, array $meta): void
    {
        if (! $this->apiJob) {
            app(CreditService::class)->charge($customerId, $credits, $type, $meta);

            return;
        }
        $api = ApiJob::query()->lockForUpdate()->findOrFail($this->apiJob->id);
        $job = MlJob::query()->where('customer_id', $customerId)->findOrFail($meta['ml_job_id']);
        if ((int) $api->customer_id !== $customerId || $api->ml_job_id || $api->status !== 'accepted') {
            throw new \LogicException('Invalid submission context.');
        }
        app(CustomerApiCreditReservationService::class)->reserve($customerId, $api->id, $credits, $meta);
        $api->update(['ml_job_id' => $job->id, 'estimated_credits' => $credits, 'reserved_credits' => $credits]);
        $job->update(['input' => array_merge($job->input ?? [], [
            'wallet_type' => 'api', 'api_job_id' => $api->id, 'api_version' => 2,
            'api_storage_mode' => $api->storage_mode, 'api_expires_at' => data_get($api->meta, 'expires_at'),
        ])]);
    }
}
