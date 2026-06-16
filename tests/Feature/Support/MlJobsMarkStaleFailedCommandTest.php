<?php

use App\Models\Customer;
use App\Models\MlJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function staleJobsCustomer(): Customer
{
    return Customer::create([
        'username' => 'stale_jobs_'.Str::lower(Str::random(8)),
        'email' => 'stale-jobs-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function makeMlJob(int $customerId, string $status): MlJob
{
    return MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => $customerId,
        'status' => $status,
        'provider' => 'runpod',
        'job_kind' => 'wasr',
    ]);
}

it('marks stale queued and processing jobs as failed while keeping fresh jobs untouched', function () {
    Carbon::setTestNow('2026-06-01 10:00:00');
    $customer = staleJobsCustomer();

    $staleQueue = makeMlJob($customer->id, 'queue');
    $staleProcessing = makeMlJob($customer->id, 'processing');
    $freshQueued = makeMlJob($customer->id, 'queued');

    MlJob::query()->whereKey($staleQueue->id)->update([
        'created_at' => now()->subMinutes(120),
        'updated_at' => now()->subMinutes(120),
    ]);

    MlJob::query()->whereKey($staleProcessing->id)->update([
        'started_at' => now()->subMinutes(120),
        'updated_at' => now()->subMinutes(120),
        'lock_expires_at' => now()->subMinutes(30),
        'execution_scope' => 'customer',
        'locked_by_session_id' => 'session-a',
        'locked_by_fingerprint' => 'fingerprint-a',
    ]);

    MlJob::query()->whereKey($freshQueued->id)->update([
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('ml-jobs:mark-stale-failed', [
        '--queued-minutes' => 30,
        '--processing-minutes' => 60,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(MlJob::query()->findOrFail($staleQueue->id)->status)->toBe('queue')
        ->and(MlJob::query()->findOrFail($staleProcessing->id)->status)->toBe('processing');

    $this->artisan('ml-jobs:mark-stale-failed', [
        '--queued-minutes' => 30,
        '--processing-minutes' => 60,
    ])->assertSuccessful();

    $staleQueue = MlJob::query()->findOrFail($staleQueue->id);
    $staleProcessing = MlJob::query()->findOrFail($staleProcessing->id);
    $freshQueued = MlJob::query()->findOrFail($freshQueued->id);

    expect($staleQueue->status)->toBe('failed')
        ->and(data_get($staleQueue->error, 'code'))->toBe('stale_timeout')
        ->and($staleQueue->finished_at)->not->toBeNull();

    expect($staleProcessing->status)->toBe('failed')
        ->and(data_get($staleProcessing->error, 'code'))->toBe('stale_timeout')
        ->and($staleProcessing->lock_expires_at)->toBeNull()
        ->and($staleProcessing->execution_scope)->toBeNull()
        ->and($staleProcessing->locked_by_session_id)->toBeNull()
        ->and($staleProcessing->locked_by_fingerprint)->toBeNull();

    expect($freshQueued->status)->toBe('queued');
});
