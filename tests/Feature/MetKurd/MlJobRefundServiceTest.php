<?php

use App\Jobs\ReconcileMlJob;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\Jobs\DurableUploadSubmission;
use App\Services\MetKurd\Jobs\MlJobRefundService;
use App\Services\Providers\RunPodProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->seed();
    $this->owner = Customer::create(['username' => 'refund', 'email' => 'refund@example.com', 'password' => 'Secret123!', 'status' => 1]);
    $this->apiWallet = CreditWallet::where('customer_id', $this->owner->id)->where('wallet_type', 'api')->firstOrFail();
    $this->makeCharge = function (int $subscription = 700, int $addon = 300, int $amount = 1000): MlJob {
        $this->wallet = CreditWallet::updateOrCreate(['customer_id' => $this->owner->id, 'wallet_type' => 'app'], array_replace(CreditWallet::defaultAttributes($this->owner->id), [
            'subscription_balance_credits' => $subscription, 'addon_balance_credits' => $addon,
            'balance_credits' => $subscription + $addon,
        ]));
        [$job] = app(DurableUploadSubmission::class)->begin(
            $this->owner->id, 'refund-intent', 'xomni.standard', $amount, 'omni_charge', [],
            fn () => MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $this->owner->id, 'status' => 'queued', 'credits_charged' => $amount, 'input' => []]),
        );
        $job->update(['status' => 'failed', 'failure_stage' => 'refund_pending']);

        return $job;
    };
    $this->refunds = fn (MlJob $job) => CreditLedger::where('reference_code', "ml-job:{$job->id}:refund")->where('customer_id', $job->customer_id)->where('wallet_type', 'app');
});

it('restores exactly the persisted App debit buckets once', function (int $subscription, int $addon, int $amount, array $expected) {
    $job = ($this->makeCharge)($subscription, $addon, $amount);
    $apiBefore = $this->apiWallet->getAttributes();
    $service = app(MlJobRefundService::class);
    expect($service->refundFailedJob($job, 'provider_failed'))->toBeTrue();
    $stamp = $job->fresh()->refunded_at->getTimestamp();
    $ledgerBefore = ($this->refunds)($job)->get()->toArray();
    $walletBefore = $this->wallet->fresh()->getAttributes();
    $this->travel(5)->minutes();
    expect($service->refundFailedJob($job, 'retry'))->toBeTrue()
        ->and(($this->refunds)($job)->pluck('amount', 'bucket')->all())->toBe($expected)
        ->and(($this->refunds)($job)->get()->toArray())->toBe($ledgerBefore)
        ->and($job->fresh()->refunded_at->getTimestamp())->toBe($stamp)
        ->and($this->wallet->fresh()->getAttributes())->toBe($walletBefore)
        ->and($this->wallet->fresh()->subscription_balance_credits)->toBe($subscription)
        ->and($this->wallet->fresh()->addon_balance_credits)->toBe($addon)
        ->and($this->wallet->fresh()->balance_credits)->toBe($subscription + $addon)
        ->and($this->wallet->fresh()->lifetime_refunded)->toBe($amount)
        ->and($this->wallet->fresh()->lifetime_spent)->toBe($amount)
        ->and($this->apiWallet->fresh()->getAttributes())->toBe($apiBefore);
})->with([
    'subscription' => [1160, 0, 1160, ['subscription' => 1160]],
    'addon' => [0, 900, 900, ['addon' => 900]],
    'split' => [700, 300, 1000, ['subscription' => 700, 'addon' => 300]],
]);

it('recognizes both existing split refund rows without applying money twice when the job marker is missing', function () {
    $job = ($this->makeCharge)();
    $service = app(MlJobRefundService::class);
    $service->refundFailedJob($job, 'failed');
    $before = $this->wallet->fresh()->getAttributes();
    $rows = ($this->refunds)($job)->get()->toArray();
    $job->refresh()->update(['refunded_at' => null]);
    expect($service->refundFailedJob($job, 'retry'))->toBeTrue()
        ->and($job->fresh()->refunded_at)->not->toBeNull()
        ->and(($this->refunds)($job)->count())->toBe(2)
        ->and(($this->refunds)($job)->get()->toArray())->toBe($rows)
        ->and($this->wallet->fresh()->getAttributes())->toBe($before);
});

it('sums multiple debit rows within each bucket and scopes nonunique references to the owned App wallet', function () {
    $job = ($this->makeCharge)();
    $debit = CreditLedger::where('reference_code', $job->charge_reference)->where('bucket', 'subscription')->firstOrFail();
    $debit->update(['amount' => 400, 'credits_delta' => -400]);
    $part = $debit->replicate();
    $part->fill(['amount' => 300, 'credits_delta' => -300])->save();
    $unrelated = $debit->replicate();
    $unrelated->fill(['wallet_type' => 'api', 'ml_job_id' => null, 'related_type' => null, 'related_id' => null])->save();
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'failed'))->toBeTrue()
        ->and(($this->refunds)($job)->pluck('amount', 'bucket')->all())->toBe(['subscription' => 700, 'addon' => 300]);
});

it('fails closed and reports a bounded code for inconsistent debit evidence', function (string $defect) {
    $job = ($this->makeCharge)();
    $query = CreditLedger::where('reference_code', $job->charge_reference);
    match ($defect) {
        'missing' => $query->delete(),
        'total' => $job->update(['credits_charged' => 999]),
        'bucket' => $query->update(['bucket' => 'unknown']),
        'wallet' => $query->update(['wallet_type' => 'api']),
        'customer' => $query->update(['customer_id' => Customer::create(['username' => 'other', 'email' => 'other@example.com', 'password' => 'Secret123!', 'status' => 1])->id]),
        'job' => $query->update(['ml_job_id' => (string) Str::uuid()]),
        'delta' => $query->update(['credits_delta' => 0]),
        'negative' => $query->update(['amount' => 0]),
    };
    $before = $this->wallet->fresh()->getAttributes();
    Log::spy();
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'failed'))->toBeFalse()
        ->and($job->fresh()->refunded_at)->toBeNull()
        ->and($job->fresh()->failure_stage)->toBe('refund_pending')
        ->and(($this->refunds)($job)->count())->toBe(0)
        ->and($this->wallet->fresh()->getAttributes())->toBe($before);
    Log::shouldHaveReceived('warning')->once()->with('ML_JOB_REFUND_EVIDENCE_INVALID', Mockery::on(fn ($context) => array_keys($context) === ['job_id', 'code']));
})->with(['missing', 'total', 'bucket', 'wallet', 'customer', 'job', 'delta', 'negative']);

it('does not complete or top up conflicting existing refund evidence', function (string $defect) {
    $job = ($this->makeCharge)();
    $service = app(MlJobRefundService::class);
    $service->refundFailedJob($job, 'failed');
    $job->refresh()->update(['refunded_at' => null]);
    $query = ($this->refunds)($job);
    match ($defect) {
        'allocation' => $query->update(['bucket' => 'addon']),
        'partial' => (clone $query)->where('bucket', 'addon')->delete(),
        'type' => $query->update(['type' => 'unrelated_refund']),
        'direction' => $query->update(['direction' => 'credit']),
        'wallet' => $query->update(['wallet_type' => 'api']),
    };
    $before = $this->wallet->fresh()->getAttributes();
    $rows = CreditLedger::get()->toArray();
    expect($service->refundFailedJob($job, 'retry'))->toBeFalse()
        ->and($job->fresh()->refunded_at)->toBeNull()
        ->and($this->wallet->fresh()->getAttributes())->toBe($before)
        ->and(CreditLedger::get()->toArray())->toBe($rows);
})->with(['allocation', 'partial', 'type', 'direction', 'wallet']);

it('preserves nonrefundable job guards', function (array $change) {
    $job = ($this->makeCharge)();
    $job->update($change);
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'failed'))->toBeFalse()
        ->and(($this->refunds)($job)->count())->toBe(0)
        ->and($this->wallet->fresh()->balance_credits)->toBe(0);
})->with([
    'unknown submission' => [['failure_stage' => 'provider_submission_unknown']],
    'eliminated' => [['error' => ['type' => 'eliminated_by_customer']]],
    'API' => [['input' => ['api_job_id' => 'api-job']]],
    'running' => [['status' => 'running']],
    'zero' => [['credits_charged' => 0]],
]);

it('rolls back both buckets and the job marker if the second ledger write fails', function () {
    $job = ($this->makeCharge)();
    $before = $this->wallet->fresh()->getAttributes();
    DB::unprepared("CREATE TRIGGER reject_refund BEFORE INSERT ON credit_ledgers WHEN NEW.direction = 'refund' AND NEW.bucket = 'addon' BEGIN SELECT RAISE(ABORT, 'isolated ledger failure'); END");
    try {
        expect(fn () => app(MlJobRefundService::class)->refundFailedJob($job, 'failed'))->toThrow(Illuminate\Database\QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER reject_refund');
    }
    expect(($this->refunds)($job)->count())->toBe(0)
        ->and($this->wallet->fresh()->getAttributes())->toBe($before)
        ->and($job->fresh()->refunded_at)->toBeNull();
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'retry'))->toBeTrue();
});

it('uses exact split restoration for stale timeout and ordinary provider failure', function (string $failure) {
    $job = ($this->makeCharge)();
    $tool = Tool::firstOrCreate(['code' => 'xomni'], ['name' => 'Apollo']);
    $job->update(['status' => 'running', 'failure_stage' => null, 'tool_id' => $tool->id, 'provider' => 'runpod', 'provider_job_id' => 'accepted', 'endpoint_key' => 'omni_v2', 'started_at' => now()->subMinutes(61)]);
    $provider = Mockery::mock(RunPodProvider::class);
    if ($failure === 'stale') {
        $provider->shouldNotReceive('status');
    } else {
        config()->set('runpod.endpoints.omni_v2', 'fake-endpoint');
        $provider->shouldReceive('status')->once()->andReturn(['status' => 'FAILED', 'error' => 'Synthetic provider failure']);
    }
    app()->instance(RunPodProvider::class, $provider);
    if ($failure === 'stale') {
        $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('Updated: 1')->assertSuccessful();
    }
    (new ReconcileMlJob($job->id))->handle();
    (new ReconcileMlJob($job->id))->handle();
    expect($job->fresh()->status)->toBe('failed')
        ->and($job->fresh()->refunded_at)->not->toBeNull()
        ->and(($this->refunds)($job)->pluck('amount', 'bucket')->all())->toBe(['subscription' => 700, 'addon' => 300])
        ->and($this->wallet->fresh()->subscription_balance_credits)->toBe(700)
        ->and($this->wallet->fresh()->addon_balance_credits)->toBe(300)
        ->and($this->wallet->fresh()->lifetime_refunded)->toBe(1000);
})->with(['stale', 'provider']);

it('leaves unrelated CreditService refund defaults and idempotency unchanged', function () {
    ($this->makeCharge)();
    $service = app(CreditService::class);
    expect($service->refund($this->owner->id, 20, 'legacy_refund', ['reference_code' => 'unrelated']))->toBeTrue()
        ->and($service->refund($this->owner->id, 20, 'legacy_refund', ['reference_code' => 'unrelated']))->toBeFalse()
        ->and($this->wallet->fresh()->addon_balance_credits)->toBe(20)
        ->and($this->wallet->fresh()->subscription_balance_credits)->toBe(0)
        ->and($this->wallet->fresh()->lifetime_refunded)->toBe(20);
});

it('uses the persisted charge and refund references rather than assuming generated identities', function () {
    $job = ($this->makeCharge)();
    CreditLedger::where('reference_code', $job->charge_reference)->update(['reference_code' => 'custom-charge']);
    $job->update(['charge_reference' => 'custom-charge', 'refund_reference' => 'custom-refund']);
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'failed'))->toBeTrue()
        ->and(CreditLedger::where('reference_code', 'custom-refund')->count())->toBe(2)
        ->and($job->fresh()->refund_reference)->toBe('custom-refund');
});

it('leaves invalid evidence pending through durable recovery and never creates a missing wallet', function () {
    $job = ($this->makeCharge)();
    $this->wallet->delete();
    app(DurableUploadSubmission::class)->retryRefund($job);
    expect($job->fresh()->failure_stage)->toBe('refund_pending')
        ->and($job->fresh()->refunded_at)->toBeNull()
        ->and(($this->refunds)($job)->count())->toBe(0)
        ->and(CreditWallet::where('customer_id', $job->customer_id)->where('wallet_type', 'app')->exists())->toBeFalse();
});

it('does not repair or reapply already-marked historical refunds', function () {
    $job = ($this->makeCharge)();
    app(CreditService::class)->refund($job->customer_id, 1000, 'ml_job_refund', ['reference_code' => "ml-job:{$job->id}:refund"]);
    $job->update(['refunded_at' => now(), 'refund_reference' => "ml-job:{$job->id}:refund"]);
    $before = $this->wallet->fresh()->getAttributes();
    $rows = CreditLedger::get()->toArray();
    expect(app(MlJobRefundService::class)->refundFailedJob($job, 'retry'))->toBeTrue()
        ->and($this->wallet->fresh()->getAttributes())->toBe($before)
        ->and(CreditLedger::get()->toArray())->toBe($rows);
});
