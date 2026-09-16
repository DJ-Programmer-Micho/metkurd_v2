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
use Illuminate\Support\Str;

beforeEach(function () {
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
