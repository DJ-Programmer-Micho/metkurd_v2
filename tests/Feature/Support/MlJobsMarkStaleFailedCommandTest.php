<?php

use App\Models\Customer;
use App\Models\MlJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $this->seed();
});

it('uses stable lifecycle age despite fresh poll bookkeeping for every stale status', function (string $status, string $clock) {
    $this->freezeTime();
    $job = makeMlJob(staleJobsCustomer()->id, $status);
    $job->forceFill(['provider_job_id' => 'acknowledged', 'created_at' => now()->subHours(3),
        'started_at' => $clock === 'started_at' ? now()->subMinutes(61) : null,
        'submission_attempted_at' => $clock === 'submission_attempted_at' ? now()->subMinutes(61) : null,
        'updated_at' => now(), 'next_poll_at' => now()->addMinute(), 'poll_attempts' => 100,
        'poll_token' => 'in-flight', 'poll_locked_until' => now()->addMinutes(5)])->save();
    $before = $job->fresh()->getAttributes();
    $options = ['--status' => [$status], '--customer-id' => $job->customer_id, '--job-kind' => 'wasr', '--limit' => 1];
    $this->artisan('ml-jobs:mark-stale-failed', $options + ['--dry-run' => true])->expectsOutput('Would Update: 1')->assertSuccessful();
    expect($job->fresh()->getAttributes())->toBe($before);
    $this->artisan('ml-jobs:mark-stale-failed', $options)->expectsOutput('Updated: 1')->assertSuccessful();
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->poll_token)->toBeNull()
        ->and($job->fresh()->poll_locked_until)->toBeNull()->and(data_get($job->fresh()->error, 'code'))->toBe('stale_timeout');
})->with(['queue', 'queued', 'processing', 'running', 'saving'])->with(['started_at', 'submission_attempted_at', 'created_at']);

it('gives started_at precedence and preserves both configured timeout boundaries', function () {
    $this->freezeTime();
    $owner = staleJobsCustomer();
    foreach (['queued' => 29, 'running' => 59] as $status => $age) {
        $job = makeMlJob($owner->id, $status);
        $job->forceFill(['provider_job_id' => 'remote-'.$status, 'created_at' => now()->subMinutes($status === 'queued' ? $age : 180),
            'submission_attempted_at' => now()->subHours(2), 'started_at' => now()->subMinutes($age)])->save();
    }
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('No stale ML jobs matched the current filters.')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('Updated: 2')->assertSuccessful();
});

it('never expires unknown submission or interrupted paid preparation as a proven accepted job', function (array $attributes) {
    $job = makeMlJob(staleJobsCustomer()->id, 'running');
    $job->forceFill($attributes + ['created_at' => now()->subHours(3), 'started_at' => now()->subHours(2)])->save();
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('No stale ML jobs matched the current filters.')->assertSuccessful();
    expect($job->fresh()->status)->toBe('running')->and($job->fresh()->refunded_at)->toBeNull();
})->with([
    [['submission_attempted_at' => '2026-01-01 00:00:00']],
    [['charge_reference' => 'paid-preparation']],
    [['provider_job_id' => 'remote', 'failure_stage' => 'provider_submission_unknown']],
]);

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
