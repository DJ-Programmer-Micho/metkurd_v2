<?php

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibStatusEvidence;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Customer;
use App\Models\ServicePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    $this->seed();
    $this->customer = Customer::create(['username' => 'compact_'.Str::random(9), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->plan = ServicePlan::where('code', 'pro')->firstOrFail();
});

function compactPayment($test, array $overrides = []): Payment
{
    return Payment::create(array_replace([
        'uuid' => Str::uuid(), 'customer_id' => $test->customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
        'provider_subscription_status' => 'DRAFT', 'fib_subscription_id' => Str::uuid(),
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 12000, 'currency' => 'IQD',
        'original_amount_iqd' => 12000, 'discounted_amount_iqd' => 12000, 'discount_amount_iqd' => 0,
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => $test->plan->id,
        'valid_until' => now()->addHour(), 'meta' => ['persistence_version' => 2, 'checkout_ui' => 'v2'],
        'purchase_snapshot' => ['name' => $test->plan->name, 'code' => $test->plan->code, 'billing_cycle' => 'monthly'],
    ], $overrides))->fresh();
}

it('never writes QR or raw callbacks and keeps compact corroborated creation provenance', function () {
    $payment = compactPayment($this);
    $raw = ['subscriptionId' => $payment->fib_subscription_id, 'appLink' => 'https://p-stage.fib.iq/fixture-checkout',
        'qrCode' => 'data:image/png;base64,'.str_repeat('A', 60000), 'debug' => str_repeat('private', 10000)];
    $request = ['description' => $payment->local_reference, 'monetaryValue' => ['amount' => 12000, 'currency' => 'IQD'],
        'statusCallbackUrl' => 'https://merchant.example.test/callback', 'title' => 'checkout'];
    $payment->update(['qr_code' => $raw['qrCode'], 'create_response' => $raw, 'create_payload' => $request,
        'provider_links' => ['app' => $raw['appLink']], 'callback_payload' => ['status' => 'PAID', 'body' => str_repeat('X', 50000)]]);
    $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_subscription_created',
        'source' => 'customer_checkout', 'payload' => $raw, 'meta' => ['create_payload' => $request]]);
    $payment->refresh();
    expect($payment->qr_code)->toBeNull()->and($payment->callback_payload)->toBeNull()
        ->and($payment->create_response)->toBe(['subscriptionId' => $payment->fib_subscription_id, 'appLinkHost' => 'p-stage.fib.iq'])
        ->and($event->payload)->toBe($payment->create_response)
        ->and(json_encode($event->getAttributes()))->not->toContain('base64', 'private', 'statusCallbackUrl');
    expect((new \App\Services\Billing\FibProviderProvenance)->classify($payment, [$event])['classification'])->toBe('confirmed_test_or_staging');
    Http::assertNothingSent();
});

it('stores commercial structures once and rejects mutation of their immutable snapshot', function () {
    $quote = ['gross_amount_iqd' => 12000, 'base_amount_iqd' => 12000, 'net_amount_iqd' => 11500, 'provider_fee_amount_iqd' => 500];
    $payment = compactPayment($this, ['meta' => ['persistence_version' => 2, 'fee_quote' => $quote, 'coupon' => ['code' => 'fixture'],
        'payment_method_code' => 'fib', 'payment_driver' => 'fib', 'provider_object_type' => 'subscription'],
        'purchase_snapshot' => ['name' => 'Pro', 'code' => 'pro', 'fee_quote' => $quote, 'coupon' => ['code' => 'fixture'],
            'intended_plan' => ['id' => $this->plan->id, 'name' => 'Pro', 'code' => 'pro'], 'gross_amount_iqd' => 12000]]);
    expect($payment->meta)->toBe(['persistence_version' => 2])->and($payment->feeQuote())->toEqual($quote)
        ->and($payment->purchase_snapshot)->not->toHaveKeys(['intended_plan', 'gross_amount_iqd'])
        ->and($payment->snapshot()['gross_amount_iqd'])->toBe('12000')
        ->and($payment->snapshot()['intended_plan']['id'])->toBe($this->plan->id);
    expect(fn () => $payment->update(['purchase_snapshot' => ['name' => 'Changed']]))->toThrow(LogicException::class);
});

it('keeps GET evidence bounded through repeated sync fulfillment and cancellation without duplicate credits', function () {
    $payment = compactPayment($this);
    $raw = ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE', 'monetaryValue' => ['amount' => 12000, 'currency' => 'IQD'],
        'lastPaymentAt' => now()->subMinute()->toIso8601String(), 'activeUntil' => now()->addMonth()->toIso8601String(),
        'paidBy' => ['name' => 'PRIVATE_BANK_NAME', 'iban' => 'PRIVATE_IBAN'], 'qrCode' => 'data:image/png;base64,'.str_repeat('A', 30000),
        'debug' => str_repeat('PRIVATE_DEBUG', 5000)];
    $service = $this->partialMock(FibSubscriptionService::class);
    $service->shouldReceive('getStatus')->times(3)->andReturnUsing(function () use (&$raw) {
        return FibSubscriptionStatusData::fromArray($raw);
    });
    app(SyncFibCheckoutStatus::class)->handle($payment, 'compact_test');
    $payment->refresh();
    expect($payment->fulfilled_at)->not->toBeNull()->and($payment->status->value)->toBe('paid');
    $wallets = DB::table('credit_wallets')->orderBy('id')->get()->toJson();
    $ledgers = DB::table('credit_ledgers')->orderBy('id')->get()->toJson();
    $snapshot = $payment->getRawOriginal('purchase_snapshot');
    $size = strlen($payment->getRawOriginal('status_response'));
    app(SyncFibCheckoutStatus::class)->handle($payment, 'compact_test');
    expect(strlen($payment->fresh()->getRawOriginal('status_response')))->toBe($size)->toBeLessThan(1024);
    expect(app(FibStatusEvidence::class)->persistedSubscriptionObservation($payment->fresh()))->not->toBeNull();
    $raw['status'] = 'CANCELLED';
    app(SyncFibCheckoutStatus::class)->handle($payment, 'compact_test');
    $payment->refresh();
    expect(app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->confirmation($payment)['confirmed'])->toBeTrue()
        ->and($payment->status->value)->toBe('paid')->and($payment->getRawOriginal('purchase_snapshot'))->toBe($snapshot)
        ->and(DB::table('credit_wallets')->orderBy('id')->get()->toJson())->toBe($wallets)
        ->and(DB::table('credit_ledgers')->orderBy('id')->get()->toJson())->toBe($ledgers);
    expect(json_encode($payment->getAttributes()).$payment->events()->get()->toJson())->not->toContain('PRIVATE_', 'base64');
});

it('stores bounded callback wakeups without accepting callback paid claims', function () {
    $payment = compactPayment($this);
    $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'callback_received', 'source' => 'fib_subscription_callback',
        'payload' => ['subscriptionId' => $payment->fib_subscription_id, 'status' => 'PAID', 'paidAt' => now()->toIso8601String(),
            'isPaid' => true, 'qrCode' => 'data:image/png;base64,'.str_repeat('A', 10000), 'body' => ['customer' => 'PRIVATE']]]);
    expect($event->payload)->toBe(['subscriptionId' => $payment->fib_subscription_id, 'status' => 'PAID'])
        ->and($payment->fresh()->paid_at)->toBeNull()->and($payment->fresh()->fulfilled_at)->toBeNull();
    Http::assertNothingSent();
});

it('uses one bounded review code while preserving financial review context', function () {
    $payment = compactPayment($this);
    app(\App\Services\Payments\PaymentApplicationService::class)->markRequiresReview($payment, str_repeat('Provider explanation ', 40), ['current_plan_id' => 1, 'intended_plan_id' => 2]);
    $payment->refresh();
    expect($payment->mismatch_reason)->toBe('application_state_mismatch')->and($payment->status_reason)->toBeNull()
        ->and(data_get($payment->meta, 'application_review.reason'))->toBeNull()
        ->and(data_get($payment->meta, 'application_review.context.intended_plan_id'))->toBe(2);
    $payment->update(['status_reason' => str_repeat('raw diagnostic ', 300)]);
    expect($payment->fresh()->mismatch_reason)->toBe('application_state_mismatch')->and($payment->fresh()->status_reason)->toBeNull();
});

it('does not rewrite unversioned historical evidence', function () {
    $raw = ['qrCode' => 'data:image/png;base64,fixture', 'debug' => 'historical'];
    $payment = compactPayment($this, ['meta' => [], 'qr_code' => $raw['qrCode'], 'create_response' => $raw, 'status_reason' => 'Historical text']);
    $before = $payment->getRawOriginal();
    $payment->save();
    expect($payment->fresh()->getRawOriginal())->toBe($before);
});

it('rejects oversized operational metadata without silently truncating financial state', function () {
    $payment = compactPayment($this);
    $before = $payment->getRawOriginal();
    expect(fn () => $payment->update(['meta' => ['persistence_version' => 2, 'unbounded' => str_repeat('X', 20000)]]))->toThrow(LengthException::class);
    expect($payment->fresh()->getRawOriginal())->toBe($before);
});

it('retains unmapped collection blockers without converting them into payment truth', function () {
    $payment = compactPayment($this, ['status_response' => ['status' => 'DRAFT',
        'unknownProviderExtension' => ['paymentStatus' => 'REFUNDED', 'bankingPii' => 'PRIVATE']]]);
    expect($payment->status_response)->toBe(['status' => 'DRAFT', 'unmapped_collection_evidence' => true])
        ->and(app(\App\Services\Admin\AdminProviderEvidence::class)->preventsCheckoutInvalidation($payment))->toBeTrue()
        ->and($payment->paid_at)->toBeNull()->and($payment->fulfilled_at)->toBeNull();
    $payment->save();
    expect($payment->fresh()->status_response['unmapped_collection_evidence'])->toBeTrue();
});

it('keeps the compact contract after attempts to clear its metadata marker', function () {
    $payment = compactPayment($this);
    $payment->update(['meta' => [], 'qr_code' => 'data:image/png;base64,fixture']);
    expect($payment->fresh()->usesCompactPersistence())->toBeTrue()->and($payment->fresh()->qr_code)->toBeNull();
});

it('does not convert a conflicting invalid checkout URL into trusted environment evidence', function () {
    $payment = compactPayment($this);
    $raw = ['subscriptionId' => $payment->fib_subscription_id, 'appLink' => 'http://p-stage.fib.iq/invalid', 'appLinkHost' => 'p-stage.fib.iq'];
    $request = ['description' => $payment->local_reference];
    $payment->update(['create_response' => $raw, 'create_payload' => $request]);
    $event = app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_subscription_created',
        'source' => 'customer_checkout', 'payload' => $raw, 'meta' => ['create_payload' => $request]]);
    expect(app(\App\Services\Billing\FibProviderProvenance::class)->classify($payment->fresh(), [$event])['classification'])->toBe('unknown_environment');
});

it('records unresolved 404 with one reason and no duplicated failure structures or cancellation', function () {
    $payment = compactPayment($this);
    $exception = new \App\Domain\Payments\Exceptions\FibApiException('Lookup failed.', [
        'traceId' => 'fixture-trace', 'errors' => [['code' => 'NOT_FOUND', 'title' => 'Not found']],
    ], code: 404);
    foreach (range(1, 3) as $attempt) {
        app(\App\Services\Payments\PaymentSyncFailureService::class)->capture($payment->fresh(), $exception, 'scheduled_subscription_checkout_reconciliation');
    }
    $payment->refresh();
    $event = $payment->events()->where('event_type', 'provider_status_sync_failed')->sole();
    expect($event->source)->toBe('scheduled_sub_checkout')->and($event->response_code)->toBe(404)
        ->and($payment->status_reason)->toBeNull()->and($payment->mismatch_reason)->toBe('provider_not_found')
        ->and(data_get($payment->meta, 'latest_sync_failure.http_status'))->toBe(404)
        ->and($payment->meta)->not->toHaveKeys(['latest_sync_failure_http_status', 'latest_sync_failure_error_code', 'latest_sync_failure_trace_id'])
        ->and(data_get($payment->meta, 'latest_sync_failure.safe_message'))->toBeNull()
        ->and(app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->confirmation($payment)['confirmed'])->toBeFalse();
    Http::assertNothingSent();
});

it('expires cached QR without touching financial facts or using a SQL cache', function () {
    $payment = compactPayment($this);
    $before = $payment->getRawOriginal();
    $qr = 'data:image/png;base64,aGVsbG8=';
    \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, $qr, now()->addMinute());
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBe($qr);
    $this->travel(61)->seconds();
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBeNull()
        ->and($payment->fresh()->getRawOriginal())->toBe($before);
    config(['cache.default' => 'database']);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('put')->once()->with('payment-checkout-qr:'.$payment->customer_id.':'.$payment->uuid, $qr, 60);
    \Illuminate\Support\Facades\Cache::shouldReceive('store')->once()->with('file')->andReturn($cache);
    \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, $qr, now()->addMinute());
});

it('keeps cached QR until the actual checkout deadline including checkouts longer than one day', function (bool $responseDeadline) {
    $this->travelTo(now()->startOfSecond());
    $expires = now()->addHours(36);
    $payment = compactPayment($this, ['valid_until' => $expires]);
    $qr = 'data:image/png;base64,aGVsbG8=';
    \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, $qr, $responseDeadline ? $expires : null);
    $this->travel(35)->hours();
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBe($qr);
    $this->travel(3599)->seconds();
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBe($qr);
    $this->travel(1)->seconds();
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBeNull();
})->with([true, false]);

it('does not invent a cache lifetime when the checkout has no deadline', function () {
    $payment = compactPayment($this, ['valid_until' => null]);
    \App\Domain\Payments\Support\CheckoutQrCache::remember($payment, 'data:image/png;base64,aGVsbG8=', null);
    expect(\App\Domain\Payments\Support\CheckoutQrCache::read($payment))->toBeNull();
    Http::assertNothingSent();
});
