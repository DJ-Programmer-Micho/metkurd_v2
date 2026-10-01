<?php

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Customer;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
});

function pruneNoiseCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    return Customer::create([
        'username' => 'prune_'.$suffix,
        'email' => 'prune-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function pruneNoisePayment(Customer $customer): Payment
{
    return Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::FAILED,
        'local_reference' => 'PRUNE-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'fib-prune-pay-123',
        'amount' => 25000,
        'currency' => 'IQD',
    ]);
}

function pruneNoiseEvent(Payment $payment, string $eventType, string $suffix, string $source = 'scheduled_payment_reconciliation'): PaymentEvent
{
    return PaymentEvent::create([
        'payment_id' => $payment->id,
        'provider' => 'fib',
        'event_type' => $eventType,
        'source' => $source,
        'provider_object_type' => $payment->provider_object_type?->value ?? 'payment',
        'event_key' => sprintf('%s:%d:%s', $eventType, $payment->id, $suffix),
        'local_reference' => $payment->local_reference,
        'fib_payment_id' => $payment->fib_payment_id,
        'before_status' => $payment->status->value,
        'after_status' => $payment->status->value,
        'payload' => [
            'safe_message' => 'Synthetic prune test event',
        ],
        'processed_at' => now()->addSeconds((int) $suffix),
    ]);
}

it('shows prune counts in dry-run mode without deleting rows', function () {
    $payment = pruneNoisePayment(pruneNoiseCustomer());

    foreach (range(1, 5) as $index) {
        pruneNoiseEvent($payment, 'provider_status_sync_failed', (string) $index);
    }

    $this->artisan('payments:prune-event-noise', [
        '--event' => 'provider_status_sync_failed',
        '--keep-latest' => 2,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('PAYMENT EVENT NOISE PRUNE')
        ->expectsOutputToContain('Dry-run only; no rows were deleted.')
        ->assertSuccessful();

    expect(PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->count())->toBe(5);
});

it('keeps only the newest noisy rows per group when forced', function () {
    $payment = pruneNoisePayment(pruneNoiseCustomer());

    foreach (range(1, 5) as $index) {
        pruneNoiseEvent($payment, 'provider_status_sync_failed', (string) $index);
    }

    $expectedRemainingIds = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->latest('id')
        ->limit(2)
        ->pluck('id')
        ->all();

    $this->artisan('payments:prune-event-noise', [
        '--event' => 'provider_status_sync_failed',
        '--keep-latest' => 2,
        '--force' => true,
    ])
        ->expectsOutputToContain('Deleted 3 payment_events rows.')
        ->assertSuccessful();

    expect(PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->pluck('id')
        ->sort()
        ->values()
        ->all())->toBe(collect($expectedRemainingIds)->sort()->values()->all());
});

it('refuses to prune protected audit event types', function (string $type) {
    $payment = pruneNoisePayment(pruneNoiseCustomer());
    pruneNoiseEvent($payment, $type, '1', 'fib_callback');

    $this->artisan('payments:prune-event-noise', [
        '--event' => $type,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Refusing to prune protected audit event type ['.$type.'].')
        ->assertExitCode(1);

    expect(PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', $type)
        ->count())->toBe(1);
})->with(['callback_received', 'provider_status_changed', 'provider_collection_verified', 'provider_evidence_changed']);
