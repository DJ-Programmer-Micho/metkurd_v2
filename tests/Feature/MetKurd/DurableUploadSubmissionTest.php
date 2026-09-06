<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Services\Billing\CreditService;
use App\Services\MetKurd\Jobs\DurableUploadSubmission;
use App\Services\MetKurd\Jobs\MlJobRefundService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->owner = Customer::create(['username' => 'durable', 'email' => 'durable@example.com', 'password' => 'Secret123!', 'status' => 1]);
    foreach (['app', 'api'] as $wallet) {
        CreditWallet::updateOrCreate(['customer_id' => $this->owner->id, 'wallet_type' => $wallet], ['balance_credits' => 1000, 'subscription_balance_credits' => 1000, 'addon_balance_credits' => 0]);
    }
    $this->begin = fn (string $action = 'ocr.standard', string $key = 'upload-intent') => app(DurableUploadSubmission::class)->begin(
        $this->owner->id, $key, $action, 10, 'upload_charge', [], fn () => MlJob::create([
            'id' => (string) Str::uuid(), 'customer_id' => $this->owner->id, 'status' => 'queued', 'credits_charged' => 10, 'input' => [],
        ])
    );
});

it('commits one job and one app debit for duplicate submissions', function ($action) {
    [$first, $created] = ($this->begin)($action);
    [$second, $duplicate] = ($this->begin)($action);
    expect($created)->toBeTrue()->and($duplicate)->toBeFalse()->and($second->id)->toBe($first->id);
    expect(CreditLedger::where('direction', 'debit')->where('customer_id', $this->owner->id)->count())->toBe(1);
    expect(CreditWallet::where('customer_id', $this->owner->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe(990);
    expect(CreditWallet::where('customer_id', $this->owner->id)->where('wallet_type', 'api')->value('balance_credits'))->toBe(1000);
})->with(['ocr.standard', 'stem.sep2', 'stem.sep4']);

it('rolls back the job and debit when the local transaction fails after charging', function () {
    $real = app(CreditService::class);
    $credits = Mockery::mock(CreditService::class);
    $credits->shouldReceive('charge')->once()->andReturnUsing(function (...$args) use ($real) {
        $real->charge(...$args);
        throw new RuntimeException('Local database boundary failed');
    });
    app()->instance(CreditService::class, $credits);
    expect(fn () => ($this->begin)())->toThrow(RuntimeException::class);
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('customer_id', $this->owner->id)->where('direction', 'debit')->count())->toBe(0);
    expect(CreditWallet::where('customer_id', $this->owner->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe(1000);
});

it('refunds a definite rejection once and does not resubmit on retry', function () {
    [$job] = ($this->begin)();
    $job->update(['submission_attempted_at' => now()]);
    $failure = new RequestException(new Response(new GuzzleHttp\Psr7\Response(422)));
    $service = app(DurableUploadSubmission::class);
    $failed = $service->failed($job, $failure);
    $service->retryRefund($failed);
    [$retry, $created] = ($this->begin)();
    expect($failed->status)->toBe('failed')->and($failed->refunded_at)->not->toBeNull()->and($created)->toBeFalse()->and($retry->id)->toBe($job->id);
    expect(CreditLedger::where('reference_code', "ml-job:{$job->id}:refund")->count())->toBe(1);
});

it('keeps an unsuccessful refund recoverable for the next server reconciliation', function () {
    [$job] = ($this->begin)();
    $refunds = Mockery::mock(MlJobRefundService::class);
    $refunds->shouldReceive('refundFailedJob')->once()->andThrow(new RuntimeException('Database unavailable'));
    $service = new DurableUploadSubmission(app(CreditService::class), $refunds);
    expect($service->failed($job, new RuntimeException('Local preparation failed'))->failure_stage)->toBe('refund_pending');
    app(DurableUploadSubmission::class)->retryRefund($job->fresh());
    expect($job->fresh()->failure_stage)->toBe('submission_failed')->and($job->fresh()->refunded_at)->not->toBeNull();
});

it('never refunds or resubmits an ambiguous dispatch', function ($failure) {
    [$job] = ($this->begin)();
    $job->update(['submission_attempted_at' => now()]);
    $exception = $failure === 'timeout' ? new RuntimeException('Connection lost') : new RequestException(new Response(new GuzzleHttp\Psr7\Response(503)));
    $result = app(DurableUploadSubmission::class)->failed($job, $exception);
    [$retry, $created] = ($this->begin)();
    expect($result->failure_stage)->toBe('provider_submission_unknown')->and($result->status)->toBe('queued')->and($result->refunded_at)->toBeNull()->and($created)->toBeFalse();
    expect(CreditLedger::where('customer_id', $this->owner->id)->where('direction', 'refund')->count())->toBe(0);
})->with(['timeout', 'server_error']);
