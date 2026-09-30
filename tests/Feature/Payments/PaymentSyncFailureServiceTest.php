<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Exceptions\FibApiException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Customer;
use App\Services\Payments\PaymentSyncFailureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    $this->seed();
});

function syncFailureCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    return Customer::create([
        'username' => 'sync_failure_'.$suffix,
        'email' => 'sync-failure-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function syncFailurePayment(Customer $customer, array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'SYNC-FAIL-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-sync-failure-'.Str::lower(Str::random(8)),
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'DRAFT',
    ], $overrides));
}

function syncFailureException(int $status = 404, string $fibErrorCode = 'NOT_FOUND_ERROR'): FibApiException
{
    return new FibApiException(
        message: 'FIB lookup failed.',
        payload: [
            'traceId' => 'trace-sync-failure-123',
            'errors' => [[
                'code' => $fibErrorCode,
                'title' => 'Lookup failed',
            ]],
        ],
        code: $status,
    );
}

it('skips provider status sync failure events for protected payment states', function (array $overrides) {
    $payment = syncFailurePayment(syncFailureCustomer(), $overrides);

    $result = app(PaymentSyncFailureService::class)->capture(
        $payment,
        syncFailureException(),
        'scheduled_reconciliation',
    );

    expect($result['skipped'] ?? false)->toBeTrue()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_status_sync_failed')
            ->count())->toBe(0)
        ->and(data_get($payment->fresh()->meta, 'latest_sync_failure'))->toBeNull();
})->with([
    'requires_review draft' => [[
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'provider_subscription_status' => 'DRAFT',
        'review_required_at' => now(),
        'paid_at' => now(),
    ]],
    'paid applied active' => [[
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'provider_subscription_status' => 'ACTIVE',
        'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(),
        'active_until' => now()->addDay(),
    ]],
    'paid applied rejected' => [[
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'provider_subscription_status' => 'REJECTED',
        'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(),
    ]],
    'fulfilled payment' => [[
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'payment_mode' => PaymentMode::ONE_TIME,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::PAID_PENDING_APPLICATION,
        'fib_payment_id' => 'fib-sync-failure-payment-123',
        'fib_subscription_id' => null,
        'provider_subscription_status' => null,
        'provider_payment_status' => 'PAID',
        'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(),
    ]],
    'terminal payment' => [[
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'payment_mode' => PaymentMode::ONE_TIME,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'status' => PaymentStatus::FAILED,
        'internal_status' => PaymentInternalStatus::FAILED,
        'fib_payment_id' => 'fib-sync-failure-terminal-123',
        'fib_subscription_id' => null,
        'provider_subscription_status' => null,
        'provider_payment_status' => 'FAILED',
        'failed_at' => now()->subHour(),
    ]],
]);

it('normalizes legacy scheduled reconciliation failure events for unresolved rows', function () {
    $payment = syncFailurePayment(syncFailureCustomer(), [
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'provider_subscription_status' => 'DRAFT',
        'review_required_at' => null,
        'fulfilled_at' => null,
    ]);

    $result = app(PaymentSyncFailureService::class)->capture(
        $payment,
        syncFailureException(),
        'scheduled_reconciliation',
    );

    $payment = $payment->fresh();
    $event = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->latest('id')
        ->firstOrFail();

    expect($result['skipped'] ?? false)->toBeFalse()
        ->and((string) ($result['source'] ?? ''))->toBe('scheduled_sub_checkout')
        ->and((string) $event->source)->toBe('scheduled_sub_checkout')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_status_sync_failed')
            ->where('source', 'scheduled_reconciliation')
            ->count())->toBe(0)
        ->and((string) data_get($payment->meta, 'latest_sync_failure.source'))->toBe('scheduled_sub_checkout')
        ->and((int) data_get($payment->meta, 'latest_sync_failure_count'))->toBe(1);
});

it('persists the exact overlength legacy sources as distinct canonical failure identities', function ($legacy, $canonical, $renewal) {
    $payment = syncFailurePayment(syncFailureCustomer(), $renewal ? [
        'status' => PaymentStatus::PAID, 'internal_status' => PaymentInternalStatus::APPLIED,
        'provider_subscription_status' => 'ACTIVE', 'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(), 'active_until' => now()->addDay(),
    ] : []);
    // SQLite does not enforce VARCHAR widths; emulate the production constraint.
    PaymentEvent::creating(function (PaymentEvent $event) {
        expect(strlen($event->source))->toBeLessThanOrEqual(40);
    });
    $method = $renewal ? 'captureRenewalFailure' : 'capture';
    $eventType = $renewal ? 'provider_renewal_sync_failed' : 'provider_status_sync_failed';
    $prefix = $renewal ? 'latest_renewal_sync_failure' : 'latest_sync_failure';
    $service = app(PaymentSyncFailureService::class);
    $first = $service->$method($payment, syncFailureException(404, 'NOT_FOUND'), $legacy);
    $second = $service->$method($payment->fresh(), syncFailureException(404, 'NOT_FOUND'), $canonical);
    $events = $payment->events()->where('event_type', $eventType)->get();
    expect($first['source'])->toBe($canonical)->and($second['failure_count'])->toBe(2)
        ->and($events)->toHaveCount(1)->and($events[0]->source)->toBe($canonical)
        ->and($events[0]->response_code)->toBe(404)->and($events[0]->payload['fib_error_code'])->toBe('NOT_FOUND')
        ->and($events[0]->payload['source'])->toBe($canonical)
        ->and($events[0]->event_key)->toContain(':'.$canonical.':')
        ->and(data_get($payment->fresh()->meta, $prefix.'.source'))->toBe($canonical)
        ->and($payment->fresh()->provider_subscription_status)->toBe($renewal ? 'ACTIVE' : 'DRAFT');
    Http::assertNothingSent();
})->with([
    ['scheduled_subscription_checkout_reconciliation', 'scheduled_sub_checkout', false],
    ['scheduled_subscription_renewal_reconciliation', 'scheduled_sub_renewal', true],
]);

it('records authenticated subscription 404 safely through legacy sync and the reconciliation command without cancel POST', function ($legacyCaller) {
    $payment = syncFailurePayment(syncFailureCustomer());
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true,
        'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client', 'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 1, 'fib.http.retry_sleep_ms' => 1]);
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$payment->fib_subscription_id => Http::response([
            'errors' => [['code' => 'NOT_FOUND', 'title' => 'Not found']], 'private_provider_field' => 'MUST-NOT-BE-PERSISTED',
        ], 404)]);
    if ($legacyCaller) {
        $source = 'scheduled_subscription_checkout_reconciliation';
        try {
            app(\App\Domain\Payments\Actions\SyncFibCheckoutStatus::class)->handle($payment, $source);
            $this->fail('Expected authenticated GET failure.');
        } catch (FibApiException $exception) {
            expect($exception->getCode())->toBe(404);
            app(PaymentSyncFailureService::class)->capture($payment, $exception, $source);
        }
    } else {
        $this->artisan('subscriptions:reconcile', ['--customer' => $payment->customer_id, '--limit' => 1, '--stale-minutes' => 0])->assertSuccessful();
    }
    $event = $payment->events()->where('event_type', 'provider_status_sync_failed')->sole();
    expect($event->source)->toBe('scheduled_sub_checkout')->and($event->response_code)->toBe(404)
        ->and($event->payload['fib_error_code'])->toBe('NOT_FOUND')
        ->and(json_encode($event->payload))->not->toContain('MUST-NOT-BE-PERSISTED', 'fixture-token', 'fixture-secret')
        ->and($payment->fresh()->provider_subscription_status)->toBe('DRAFT')
        ->and(app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->confirmation($payment->fresh())['confirmed'])->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer fixture-token'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && ! str_ends_with($request->url(), '/openid-connect/token'));
})->with([true, false]);

it('canonicalizes only the two known recorder aliases and refuses unknown overlength sources without truncation', function () {
    $payment = syncFailurePayment(syncFailureCustomer());
    $recorder = app(\App\Domain\Payments\Support\PaymentEventRecorder::class);
    foreach (['scheduled_subscription_checkout_reconciliation' => 'scheduled_sub_checkout',
        'scheduled_subscription_renewal_reconciliation' => 'scheduled_sub_renewal', 'fib_subscription_callback' => 'fib_subscription_callback'] as $source => $expected) {
        expect($recorder->record($payment, ['event_type' => 'fixture', 'source' => $source])->source)->toBe($expected);
    }
    foreach ([str_repeat('x', 40).'a', str_repeat('x', 40).'b'] as $source) {
        expect(fn () => $recorder->record($payment, ['event_type' => 'fixture', 'source' => $source]))->toThrow(InvalidArgumentException::class);
    }
    expect($payment->events()->count())->toBe(3);
});
