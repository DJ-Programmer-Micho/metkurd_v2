<?php

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Exceptions\FibApiException;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\FibCallbackNotification;
use App\Domain\Payments\Support\FibStatusEvidence;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Domain\Payments\Support\ProviderObservation;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\ProviderSubscriptionCancellation;
use App\Services\Payments\PaymentSyncFailureService;
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
    $this->travelTo(now()->startOfSecond());
    $this->seed();
    $customer = Customer::create(['username' => 'frequency_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $this->payment = Payment::create([
        'uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
        'provider_status' => 'DRAFT', 'provider_subscription_status' => 'DRAFT', 'fib_subscription_id' => Str::uuid(),
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 12000, 'currency' => 'IQD',
        'original_amount_iqd' => 12000, 'discounted_amount_iqd' => 12000, 'discount_amount_iqd' => 0,
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => $plan->id,
        'valid_until' => now()->addDay(), 'meta' => ['persistence_version' => 2, 'checkout_ui' => 'v2'],
        'purchase_snapshot' => ['name' => $plan->name, 'code' => $plan->code, 'billing_cycle' => 'monthly'],
    ])->fresh();
    foreach (['local_payment_created', 'provider_subscription_created'] as $type) {
        app(PaymentEventRecorder::class)->record($this->payment, ['event_type' => $type, 'source' => 'customer_checkout']);
    }
    $this->raw = ['id' => $this->payment->fib_subscription_id, 'status' => 'DRAFT',
        'monetaryValue' => ['amount' => 12000, 'currency' => 'IQD']];
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->andReturnUsing(function () {
        return FibSubscriptionStatusData::fromArray($this->raw);
    });
});

afterEach(function () {
    Http::assertNothingSent();
});

function frequencyPoll($test, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $test->travel(10)->seconds();
        app(SyncFibCheckoutStatus::class)->handle($test->payment, 'manual_status_refresh');
    }
    $test->payment->refresh();
}

it('keeps exactly the creation evidence through 100 identical DRAFT and awaiting checks', function () {
    $created = $this->payment->events()->orderBy('id')->get()->toJson();
    $started = now()->copy();
    frequencyPoll($this, 100);
    expect($this->payment->events()->orderBy('id')->get()->toJson())->toBe($created)
        ->and($this->payment->last_status_checked_at->timestamp)->toBe($started->timestamp + 1000)
        ->and($this->payment->status->value)->toBe('awaiting_customer_action')
        ->and(app(FibStatusEvidence::class)->persistedSubscriptionObservation($this->payment))->not->toBeNull();
});

it('records only two transitions and one fulfillment for DRAFT ACTIVE ACTIVE x100 PAID PAID x100', function () {
    $this->raw['status'] = 'ACTIVE';
    frequencyPoll($this, 1);
    expect($this->payment->events()->count())->toBe(3)->and($this->payment->fulfilled_at)->toBeNull();
    $activeEvents = $this->payment->events()->orderBy('id')->get()->toJson();
    frequencyPoll($this, 100);
    expect($this->payment->events()->orderBy('id')->get()->toJson())->toBe($activeEvents);
    $this->raw += ['lastPaymentAt' => now()->subMinute()->toIso8601String(), 'activeUntil' => now()->addMonth()->toIso8601String()];
    $this->raw['status'] = 'PAID';
    frequencyPoll($this, 1);
    expect($this->payment->fulfilled_at)->not->toBeNull();
    $paidEvents = $this->payment->events()->orderBy('id')->get()->toJson();
    $wallets = DB::table('credit_wallets')->orderBy('id')->get()->toJson();
    $ledgers = DB::table('credit_ledgers')->orderBy('id')->get()->toJson();
    frequencyPoll($this, 100);
    expect($this->payment->events()->orderBy('id')->get()->toJson())->toBe($paidEvents)
        ->and($this->payment->events()->count())->toBe(5)
        ->and($this->payment->events()->where('event_type', 'provider_status_changed')->count())->toBe(2)
        ->and($this->payment->events()->where('event_type', 'payment_fulfilled')->count())->toBe(1)
        ->and(DB::table('credit_wallets')->orderBy('id')->get()->toJson())->toBe($wallets)
        ->and(DB::table('credit_ledgers')->orderBy('id')->get()->toJson())->toBe($ledgers);
});

it('does not create callback echo or lifecycle events for repeated known DRAFT notifications', function () {
    frequencyPoll($this, 1);
    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('payments.fib.subscription.callback'), ['id' => $this->payment->fib_subscription_id, 'status' => 'DRAFT'])->assertStatus(202);
    }
    $this->payment->refresh();
    expect($this->payment->events()->count())->toBe(2)
        ->and(data_get($this->payment->meta, 'provider_callback_version'))->toBe(10)
        ->and(ProviderObservation::pending($this->payment))->toBeFalse()
        ->and($this->payment->fulfilled_at)->toBeNull();
});

it('deduplicates identical failures across time buckets but retains first last and total count', function (string $method, string $type) {
    $this->payment->update(['valid_until' => now()->addYear()]);
    if ($method === 'captureRenewalFailure') {
        $this->payment->update(['status' => 'paid', 'internal_status' => 'applied', 'paid_at' => now(), 'fulfilled_at' => now(),
            'provider_status' => 'ACTIVE', 'provider_subscription_status' => 'ACTIVE', 'active_until' => now()->addYear()]);
    }
    $first = now()->toIso8601String();
    for ($i = 0; $i < 100; $i++) {
        $exception = new FibApiException('Lookup failed.', ['traceId' => 'different-trace-'.$i,
            'errors' => [['code' => 'SERVICE_UNAVAILABLE', 'title' => 'Unavailable']]], code: 503);
        app(PaymentSyncFailureService::class)->{$method}($this->payment->fresh(), $exception, 'manual_status_refresh');
        if ($i < 99) {
            $this->travel(25)->hours();
        }
    }
    $failure = $this->payment->events()->where('event_type', $type)->sole();
    expect($failure->response_code)->toBe(503)->and($failure->meta['failure_count'])->toBe(100)
        ->and($failure->meta['first_seen_at'])->toBe($first)
        ->and($failure->meta['last_seen_at'])->toBe(now()->toIso8601String())
        ->and($this->payment->events()->where('event_type', 'payment_requires_review')->count())->toBeLessThanOrEqual(1)
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
})->with([
    ['capture', 'provider_status_sync_failed'], ['captureRenewalFailure', 'provider_renewal_sync_failed'], ['captureCallbackFailure', 'callback_failed'],
]);

it('invalidates old cancellation proof on callbacks and failures and restores it without duplicate transitions', function () {
    $this->raw['status'] = 'CANCELLED';
    frequencyPoll($this, 1);
    $count = $this->payment->events()->count();
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment)['confirmed'])->toBeTrue();
    FibCallbackNotification::received($this->payment, ['id' => $this->payment->fib_subscription_id, 'status' => 'CANCELLED']);
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
    frequencyPoll($this, 1);
    expect($this->payment->events()->count())->toBe($count)
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment)['confirmed'])->toBeTrue();
    app(PaymentSyncFailureService::class)->captureCallbackFailure($this->payment, new FibApiException('GET failed', [], code: 503), 'callback');
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
    frequencyPoll($this, 1);
    expect($this->payment->events()->count())->toBe($count + 1)
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment)['confirmed'])->toBeTrue();
    $meta = $this->payment->meta;
    $meta['provider_observation']['payload_hash'] = 'tampered';
    $this->payment->update(['meta' => $meta]);
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
});

it('keeps repeated genuine transitions distinct while suppressing identical intermediate checks', function () {
    foreach (['ACTIVE', 'DRAFT', 'ACTIVE', 'DRAFT', 'ACTIVE'] as $status) {
        $this->raw['status'] = $status;
        frequencyPoll($this, 2);
    }
    expect($this->payment->events()->where('event_type', 'provider_status_changed')->count())->toBe(5)
        ->and($this->payment->events()->where('event_type', 'provider_status_changed')->pluck('event_key')->unique()->count())->toBe(5);
});

it('suppresses one-time UNPAID and PAID polling and duplicate paid callbacks', function () {
    $this->payment->update(['provider_object_type' => 'payment', 'payment_mode' => 'one_time', 'fib_subscription_id' => null,
        'fib_payment_id' => Str::uuid(), 'provider_status' => 'UNPAID', 'provider_payment_status' => 'UNPAID', 'provider_subscription_status' => null]);
    $this->payment->refresh();
    $raw = ['paymentId' => $this->payment->fib_payment_id, 'status' => 'UNPAID', 'amount' => ['amount' => 12000, 'currency' => 'IQD'],
        'validUntil' => now()->addDay()->toIso8601String()];
    $this->partialMock(\App\Domain\Payments\Fib\FibOneTimePaymentService::class)->shouldReceive('getStatus')->andReturnUsing(function () use (&$raw) {
        return \App\Domain\Payments\Data\FibPaymentStatusData::fromArray($raw);
    });
    frequencyPoll($this, 100);
    expect($this->payment->events()->count())->toBe(2, $this->payment->events()->get()->toJson());
    $raw['status'] = 'PAID';
    $raw['paidAt'] = now()->toIso8601String();
    frequencyPoll($this, 1);
    expect($this->payment->fulfilled_at)->not->toBeNull();
    $events = $this->payment->events()->orderBy('id')->get()->toJson();
    frequencyPoll($this, 100);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('payments.fib.callback'), ['paymentId' => $this->payment->fib_payment_id, 'status' => 'PAID'])->assertStatus(202);
    }
    expect($this->payment->events()->orderBy('id')->get()->toJson())->toBe($events)
        ->and($this->payment->events()->count())->toBe(4);
});

it('retains new collection evidence even when the recurring provider status stays ACTIVE', function () {
    $this->raw['status'] = 'ACTIVE';
    $this->raw += ['lastPaymentAt' => now()->subMinute()->toIso8601String(), 'activeUntil' => now()->addMonth()->toIso8601String()];
    frequencyPoll($this, 1);
    $this->travel(32)->days();
    $this->raw['lastPaymentAt'] = now()->subMinute()->toIso8601String();
    $this->raw['activeUntil'] = now()->addMonth()->toIso8601String();
    frequencyPoll($this, 1);
    $events = $this->payment->events()->orderBy('id')->get()->toJson();
    $ledgers = DB::table('credit_ledgers')->orderBy('id')->get()->toJson();
    frequencyPoll($this, 100);
    expect($this->payment->events()->where('event_type', 'provider_collection_verified')->count())->toBe(1)
        ->and($this->payment->events()->where('event_type', 'service_subscription_renewed')->count())->toBe(1)
        ->and($this->payment->events()->orderBy('id')->get()->toJson())->toBe($events)
        ->and(DB::table('credit_ledgers')->orderBy('id')->get()->toJson())->toBe($ledgers);
});

it('discards a GET overtaken by a callback and waits for a fresh authenticated observation', function () {
    frequencyPoll($this, 1);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturnUsing(function () {
        FibCallbackNotification::received($this->payment, ['id' => $this->payment->fib_subscription_id, 'status' => 'ACTIVE']);

        return FibSubscriptionStatusData::fromArray(array_replace($this->raw, ['status' => 'ACTIVE']));
    });
    frequencyPoll($this, 1);
    expect($this->payment->provider_subscription_status)->toBe('DRAFT')->and($this->payment->events()->count())->toBe(2)
        ->and(app(FibStatusEvidence::class)->persistedSubscriptionObservation($this->payment))->toBeNull();
});

it('restores evidence after a legacy callback without appending another terminal event', function () {
    $this->raw['status'] = 'CANCELLED';
    frequencyPoll($this, 1);
    app(PaymentEventRecorder::class)->record($this->payment, ['event_type' => 'callback_received', 'source' => 'legacy_callback', 'payload' => ['status' => 'CANCELLED']]);
    expect(app(FibStatusEvidence::class)->persistedSubscriptionObservation($this->payment->fresh()))->toBeNull();
    $count = $this->payment->events()->count();
    frequencyPoll($this, 1);
    $snapshot = \App\Services\Billing\ProviderReviewSnapshot::capture();
    expect($this->payment->events()->count())->toBe($count)
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment, $snapshot)['confirmed'])->toBeTrue()
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment)['confirmed'])->toBeTrue();
});

it('persists authenticated 404 retry evidence as unresolved without a cancellation request', function () {
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->times(10)
        ->andThrow(new FibApiException('Lookup failed', ['errors' => [['code' => 'NOT_FOUND']]], code: 404));
    $job = new \App\Jobs\Payments\ProcessFibPaymentStatus('subscription', $this->payment->fib_subscription_id, ['status' => 'DRAFT'], 'fib_subscription_callback');
    for ($i = 0; $i < 10; $i++) {
        expect(fn () => $job->handle(app(\App\Domain\Payments\Actions\ConfirmFibPayment::class), app(PaymentEventRecorder::class)))->toThrow(FibApiException::class);
        $this->travel(25)->hours();
    }
    $failure = $this->payment->events()->where('event_type', 'callback_failed')->sole();
    expect($failure->response_code)->toBe(404)->and($failure->meta['failure_count'])->toBe(10)
        ->and($this->payment->events()->whereIn('event_type', ProviderObservation::EVENTS)->count())->toBe(0)
        ->and($this->payment->fresh()->fulfilled_at)->toBeNull()
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
});

it('preserves conservative collection blockers through unchanged status and later empty GETs', function () {
    $this->raw['providerExtension'] = ['paymentStatus' => 'REFUNDED'];
    frequencyPoll($this, 2);
    expect($this->payment->events()->where('event_type', 'provider_evidence_changed')->count())->toBe(1)
        ->and($this->payment->fulfilled_at)->toBeNull();
    unset($this->raw['providerExtension']);
    frequencyPoll($this, 2);
    expect($this->payment->events()->where('event_type', 'provider_evidence_changed')->count())->toBe(2)
        ->and(app(\App\Services\Admin\AdminProviderEvidence::class)->preventsCheckoutInvalidation($this->payment))->toBeTrue()
        ->and(\App\Services\Billing\ProviderReviewSnapshot::capture()->unpaidUnbound($this->payment))->toBeFalse();
});
