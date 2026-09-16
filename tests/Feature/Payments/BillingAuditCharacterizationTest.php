<?php

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditMonthlyGrant;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\CreditService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\SyncProviderSubscriptionLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Phase 1/2 cases assert safe behavior. Event-volume characterization remains deferred to Phase 3.
beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Storage::fake('s3');
    Carbon::setTestNow('2026-05-01 10:00:00');
    $this->seed(); // Isolated fixtures only; never the application database.
});

afterEach(function () {
    Carbon::setTestNow();
});

function billingAuditFixture(bool $fulfilled = true): array
{
    $customer = Customer::create([
        'username' => 'audit_'.Str::lower(Str::random(12)),
        'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => 'fib',
        'purchase_type' => 'plan_subscription',
        'payment_mode' => 'recurring',
        'provider_object_type' => 'subscription',
        'status' => $fulfilled ? 'paid' : 'awaiting_customer_action',
        'internal_status' => $fulfilled ? 'applied' : 'awaiting_customer_action',
        'fulfilled_at' => $fulfilled ? now() : null,
        'local_reference' => (string) Str::uuid(),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => (string) Str::uuid(),
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $plan->id,
        'amount' => 26500,
        'currency' => 'IQD',
        'paid_at' => $fulfilled ? now() : null,
        'last_payment_at' => $fulfilled ? now() : null,
        'active_until' => '2026-06-01 10:00:00',
        'provider_subscription_status' => 'ACTIVE',
        'purchase_snapshot' => ['billing_cycle' => 'monthly', 'name' => $plan->name, 'code' => $plan->code],
    ]);
    $subscription = $fulfilled ? app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fib',
        'provider_ref' => $payment->fib_subscription_id,
        'payment_id' => $payment->id,
        'renewal_strategy' => 'provider_schedule',
        'active_until' => $payment->active_until,
        'provider_last_payment_at' => $payment->last_payment_at->toIso8601String(),
        'billing_cycle' => 'monthly',
    ]) : null;

    billingAuditStoredCollection($payment);

    return [$customer, $plan, $payment, $subscription];
}

function billingAuditStoredCollection(Payment $payment): void
{
    $payment->refresh();
    $payment->update(['status_response' => [
        'id' => $payment->fib_subscription_id, 'status' => $payment->provider_subscription_status,
        'lastPaymentAt' => $payment->last_payment_at?->toIso8601String(),
        'activeUntil' => $payment->active_until?->toIso8601String(),
    ]]);
}

it('parses documented millisecond subscription timestamps as exact UTC instants', function () {
    $data = FibSubscriptionStatusData::fromArray([
        'id' => 'fixture', 'status' => 'ACTIVE',
        'activeUntil' => 1755588900190, 'lastPaymentAt' => 1755502500190,
    ]);
    expect($data->activeUntil?->utc()->format('Y-m-d H:i:s.vP'))->toBe('2025-08-19 07:35:00.190+00:00')
        ->and($data->lastPaymentAt?->getTimestamp())->toBe(1755502500);
});

it('rejects current and stored callback paid claims without authenticated payment evidence', function () {
    [$customer, $plan, $payment] = billingAuditFixture(false);
    $payment->update(['callback_payload' => ['paymentStatus' => 'PAID', 'paidAt' => '2026-05-01T10:00:00Z']]);
    $before = $customer->fresh()->wallet->balance_credits;
    $beforeApi = $customer->fresh()->apiWallet->balance_credits;
    config(['fib.callback_secret' => '']);
    $status = FibSubscriptionStatusData::fromArray([
        'id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'activeUntil' => '2026-06-01T10:00:00Z', 'lastPaymentAt' => null,
    ]);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);

    foreach ([1, 2] as $attempt) {
        $this->postJson(route('payments.fib.subscription.callback'), [
            'id' => $payment->fib_subscription_id, 'paymentStatus' => 'PAID',
        ])->assertAccepted();
    }

    expect($payment->fresh()->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->fresh()->fulfilled_at)->toBeNull()
        ->and($payment->fresh()->hasProviderPaidSubscriptionEvidence())->toBeFalse()
        ->and($customer->fresh()->currentServicePlan()->id)->not->toBe($plan->id)
        ->and(\App\Models\CreditOrder::where('payment_id', $payment->id)->count())->toBe(0)
        ->and($customer->fresh()->wallet->balance_credits)->toBe($before)
        ->and($customer->fresh()->apiWallet->balance_credits)->toBe($beforeApi);
});

it('does not refill paid monthly credits without fresh collection evidence', function () {
    [$customer, $plan, $payment] = billingAuditFixture();
    Carbon::setTestNow('2026-06-01 00:15:00');
    app(CreditService::class)->charge($customer->id, 100, 'audit_spend');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits() - 100)
        ->and($payment->fresh()->last_payment_at->toDateString())->toBe('2026-05-01');
    Http::assertNothingSent();
});

it('uses verified provider renewal instead of an independent paid calendar refill', function () {
    [$customer, $plan, $payment, $subscription] = billingAuditFixture();
    Carbon::setTestNow('2026-06-01 10:05:00');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    app(CreditService::class)->charge($customer->id, 100, 'audit_spend');
    $payment->update(['last_payment_at' => '2026-06-01 10:00:00', 'active_until' => '2026-07-01 10:00:00']);
    billingAuditStoredCollection($payment);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'audit');
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits())
        ->and(CreditMonthlyGrant::where('customer_id', $customer->id)->where('year_month', '2026-06')->count())->toBe(0);
});

it('characterizes unchanged subscription status polls creating separate events', function () {
    [, , $payment] = billingAuditFixture();
    $status = FibSubscriptionStatusData::fromArray([
        'id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'activeUntil' => '2026-06-01T10:00:00Z', 'lastPaymentAt' => '2026-05-01T10:00:00Z',
    ]);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);
    foreach ([1, 2] as $attempt) {
        app(SyncFibCheckoutStatus::class)->handle($payment, 'audit', null, false, false);
    }
    expect(PaymentEvent::where('payment_id', $payment->id)->where('event_type', 'provider_status_checked')->count())->toBe(2);
});

it('rejects subscription response identity mismatch before financial writes', function () {
    [, , $payment] = billingAuditFixture(false);
    $status = FibSubscriptionStatusData::fromArray([
        'id' => 'different-subscription', 'status' => 'ACTIVE',
        'monetaryValue' => ['amount' => 1, 'currency' => 'USD'],
        'lastPaymentAt' => '2026-05-01T10:00:00Z',
    ]);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    $result = app(SyncFibCheckoutStatus::class)->handle($payment, 'audit', null, false, false);
    expect($result->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($result->fulfilled_at)->toBeNull()
        ->and($result->review_required_at)->not->toBeNull()
        ->and($result->fib_subscription_id)->toBe($payment->fib_subscription_id)
        ->and((int) $result->amount)->toBe(26500);
});

it('preserves a locally scheduled cancellation despite ACTIVE evidence', function () {
    [, , $payment, $subscription] = billingAuditFixture();
    $subscription->update(['auto_renew' => false, 'canceled_at' => now(), 'ends_at' => $payment->active_until]);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'audit');
    expect($subscription->fresh()->auto_renew)->toBeFalse()
        ->and($subscription->fresh()->ends_at->equalTo($payment->active_until))->toBeTrue()
        ->and($subscription->fresh()->canceled_at)->not->toBeNull();
});

it('keeps the scheduled renewal event source within its declared column length', function () {
    $source = 'scheduled_sub_renewal';
    $migration = file_get_contents(database_path('migrations/2026_04_16_100100_create_payment_events_table.php'));
    expect($migration)->toContain("string('source', 40)")
        ->and(strlen($source))->toBeLessThanOrEqual(40)
        ->and(file_get_contents(app_path('Console/Commands/ReconcileFibSubscriptionRenewals.php')))->toContain($source);
});

it('never reactivates a superseded subscription or overwrites the new allowance', function () {
    [$customer, $plan, $payment, $subscription] = billingAuditFixture();
    $nextPlan = ServicePlan::where('code', 'student')->firstOrFail();
    app(PlanSwitcher::class)->switchServicePlan($customer->fresh(), $nextPlan->id, ['provider' => 'fake']);
    expect($subscription->fresh()->status)->toBe('ended');

    $payment->update(['last_payment_at' => '2026-05-02 10:00:00', 'active_until' => '2026-06-02 10:00:00']);
    billingAuditStoredCollection($payment);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'audit');
    expect($subscription->fresh()->status)->toBe('ended')
        ->and($customer->fresh()->wallet->subscription_balance_credits)->toBe($nextPlan->appMonthlyCredits())
        ->and($customer->fresh()->currentServicePlan()->id)->toBe($nextPlan->id);
});

it('does not replay June after June July May observations', function () {
    [$customer, $plan, $payment] = billingAuditFixture();
    $lifecycle = app(SyncProviderSubscriptionLifecycle::class);
    foreach (['2026-06-01', '2026-07-01', '2026-05-01'] as $date) {
        $payment->update(['last_payment_at' => $date.' 10:00:00', 'active_until' => '2026-08-01 10:00:00']);
        billingAuditStoredCollection($payment);
        $lifecycle->handle($payment, 'audit');
    }
    app(CreditService::class)->charge($customer->id, 100, 'audit_spend');
    $payment->update(['last_payment_at' => '2026-06-01 10:00:00']);
    billingAuditStoredCollection($payment);
    $lifecycle->handle($payment, 'audit');
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits() - 100);
});

it('retries the same collection after an inactive plan prevented allocation', function () {
    [$customer, $plan, $payment, $subscription] = billingAuditFixture();
    app(CreditService::class)->charge($customer->id, 100, 'audit_spend');
    $plan->update(['is_active' => false]);
    $payment->update(['last_payment_at' => '2026-06-01 10:00:00', 'active_until' => '2026-07-01 10:00:00']);
    billingAuditStoredCollection($payment);
    $lifecycle = app(SyncProviderSubscriptionLifecycle::class);
    $lifecycle->handle($payment, 'audit');
    $plan->update(['is_active' => true]);
    $lifecycle->handle($payment, 'audit');
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits())
        ->and(data_get($subscription->fresh()->meta, 'last_applied_renewal_cycle_key'))->toBe($payment->providerRecurringCycleKey());
});

it('rejects disabled unsigned areeba callbacks targeting historical intents', function () {
    [$customer] = billingAuditFixture();
    config(['payments.providers.areeba.enabled' => false, 'payments.providers.areeba.callback_secret' => null,
        'payments.providers.areeba.webhook_secret' => null]);
    $product = \App\Models\CreditProduct::where('code', 'addon_10000')->firstOrFail();
    $intent = \App\Models\PaymentIntent::create([
        'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id,
        'provider' => 'fib', 'payment_method' => 'card', 'purpose_type' => 'credit_product',
        'purpose_id' => $product->id, 'base_amount_iqd' => $product->priceIqdAmount(),
        'gross_amount_iqd' => $product->priceIqdAmount(), 'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(), 'merchant_transaction_id' => (string) Str::uuid(),
    ]);
    $before = $customer->fresh()->wallet->addon_balance_credits;
    $this->postJson(route('payments.webhooks.areeba'), [
        'merchantTransactionId' => $intent->merchant_transaction_id,
        'transactionStatus' => 'PAID', 'uuid' => 'audit-transaction',
    ])->assertForbidden();
    expect($intent->fresh()->status)->toBe('pending')
        ->and($intent->fresh()->fulfilled_at)->toBeNull()
        ->and($customer->fresh()->wallet->addon_balance_credits)->toBe($before);
    Http::assertNothingSent();
});

it('fulfills a verified subscription callback once despite replay', function () {
    [$customer, $plan, $payment] = billingAuditFixture(false);
    config(['fib.callback_secret' => '']);
    $status = FibSubscriptionStatusData::fromArray([
        'id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'monetaryValue' => ['amount' => $payment->amount, 'currency' => $payment->currency],
        'activeUntil' => '2026-06-01T10:00:00Z', 'lastPaymentAt' => '2026-05-01T10:00:00Z',
    ]);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);
    $payload = ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'];
    $this->postJson(route('payments.fib.subscription.callback'), $payload)->assertAccepted();
    $ledgerCount = \App\Models\CreditLedger::where('customer_id', $customer->id)->count();
    $this->postJson(route('payments.fib.subscription.callback'), $payload)->assertAccepted();
    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fresh()->fulfilled_at)->not->toBeNull()
        ->and(\App\Models\CreditOrder::where('payment_id', $payment->id)->count())->toBe(1)
        ->and(\App\Models\CreditLedger::where('customer_id', $customer->id)->count())->toBe($ledgerCount)
        ->and($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits())
        ->and($customer->fresh()->apiWallet->subscription_balance_credits)->toBe($plan->apiMonthlyCredits());
});

it('rejects mismatched or malformed optional subscription evidence', function (array $overrides, string $reason) {
    [$customer, , $payment] = billingAuditFixture(false);
    $before = $customer->fresh()->wallet->balance_credits;
    $status = FibSubscriptionStatusData::fromArray(array_replace([
        'id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => '2026-05-01T10:00:00Z',
    ], $overrides));
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->fulfilled_at)->toBeNull()
        ->and($payment->fresh()->status)->not->toBe(PaymentStatus::PAID)
        ->and($customer->fresh()->wallet->balance_credits)->toBe($before)
        ->and(\App\Models\CreditOrder::where('payment_id', $payment->id)->count())->toBe(0)
        ->and(PaymentEvent::where('payment_id', $payment->id)->where('event_type', 'provider_evidence_rejected')->first()->meta['reason'])->toBe($reason);
})->with([
    [['monetaryValue' => ['amount' => 1, 'currency' => 'IQD']], 'monetary_value_mismatch'],
    [['monetaryValue' => ['amount' => 26500, 'currency' => 'USD']], 'monetary_value_mismatch'],
    [['monetaryValue' => ['amount' => '26500oops', 'currency' => 'IQD']], 'monetary_value_mismatch'],
    [['monetaryValue' => ['amount' => 26500]], 'monetary_value_mismatch'],
    [['lastPaymentAt' => 'tomorrow', 'paymentStatus' => 'PAID'], 'invalid_subscription_timestamp'],
    [['activeUntil' => 999999999999999], 'invalid_subscription_timestamp'],
    [['merchantTransactionId' => 'another-checkout'], 'merchant_reference_mismatch'],
]);

it('rejects wrong one-time identity or returned money before fulfillment', function (string $case) {
    [$customer, , $payment] = billingAuditFixture(false);
    $payment->update(['provider_object_type' => 'payment', 'payment_mode' => 'one_time', 'fib_payment_id' => 'fixture-payment']);
    $status = \App\Domain\Payments\Data\FibPaymentStatusData::fromArray([
        'paymentId' => $case === 'id' ? 'another-payment' : 'fixture-payment', 'status' => 'PAID',
        'amount' => ['amount' => $case === 'amount' ? 1 : 26500, 'currency' => $case === 'currency' ? 'USD' : 'IQD'],
    ]);
    $this->partialMock(\App\Domain\Payments\Fib\FibOneTimePaymentService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    $before = $customer->fresh()->wallet->balance_credits;
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->status)->not->toBe(PaymentStatus::PAID)
        ->and($payment->fresh()->fulfilled_at)->toBeNull()
        ->and($payment->fresh()->fib_payment_id)->toBe('fixture-payment')
        ->and($customer->fresh()->wallet->balance_credits)->toBe($before)
        ->and($payment->fresh()->review_required_at)->not->toBeNull();
})->with(['id', 'amount', 'currency']);

it('keeps verified evidence monotonic and does not apply rejected observations to wallets or lifecycle', function () {
    [$customer, , $payment, $subscription] = billingAuditFixture();
    $states = [];
    foreach (['2026-06-01', '2026-05-01'] as $day) {
        $states[] = FibSubscriptionStatusData::fromArray([
            'id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
            'lastPaymentAt' => $day.'T10:00:00Z', 'activeUntil' => '2026-07-01T10:00:00Z',
        ]);
    }
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn(...$states);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'audit', null, false, false);
    $wallet = $customer->fresh()->wallet->getAttributes();
    $local = $subscription->fresh()->getAttributes();
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->last_payment_at->utc()->toIso8601String())->toBe('2026-06-01T10:00:00+00:00')
        ->and($customer->fresh()->wallet->getAttributes())->toBe($wallet)
        ->and($subscription->fresh()->getAttributes())->toBe($local);
});

it('preserves paid time and coverage while recording a terminal provider transition', function () {
    [, , $payment] = billingAuditFixture();
    $payment->update(['last_payment_at' => '2026-06-01 10:00:00', 'active_until' => '2026-07-01 10:00:00']);
    billingAuditStoredCollection($payment);
    $last = $payment->fresh()->last_payment_at->timestamp;
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'CANCELLED',
        'lastPaymentAt' => '2026-05-01T10:00:00Z', 'activeUntil' => '2026-06-01T10:00:00Z']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'audit', null, false, false);
    expect($payment->fresh()->last_payment_at->timestamp)->toBe($last)
        ->and($payment->fresh()->active_until->format('Y-m-d H:i:s'))->toBe('2026-07-01 10:00:00')
        ->and($payment->fresh()->provider_subscription_status)->toBe('CANCELLED');
});

it('rejects malformed or conflicting callback identifiers before provider requests', function ($payload) {
    config(['fib.callback_secret' => '']);
    $this->postJson(route('payments.fib.subscription.callback'), $payload)->assertStatus(406);
    Http::assertNothingSent();
})->with([[['id' => ['bad']]], [['id' => str_repeat('a', 129)]], [['id' => 'one', 'subscriptionId' => 'two']]]);

it('persists scheduled checkout and renewal events within a strict source width guard', function () {
    [, , $payment] = billingAuditFixture(false);
    PaymentEvent::creating(function (PaymentEvent $event) {
        if (strlen($event->source) > 40) {
            throw new \RuntimeException('Event source exceeds strict schema width.');
        }
    });
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => '2026-05-01T10:00:00Z', 'activeUntil' => '2026-06-01T10:00:00Z']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'scheduled_sub_checkout');
    app(SyncFibCheckoutStatus::class)->handle($payment, 'scheduled_sub_renewal');
    expect($payment->fresh()->fulfilled_at)->not->toBeNull();
    foreach (['scheduled_sub_checkout', 'scheduled_sub_renewal'] as $source) {
        expect(PaymentEvent::where('payment_id', $payment->id)->where('source', $source)->exists())->toBeTrue();
    }
});

it('preserves the exact UTC second when documented millisecond evidence is stored and read back', function () {
    [, , $payment] = billingAuditFixture(false);
    $payment->update(['active_until' => null]);
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => 1755502500190, 'activeUntil' => 1755588900190]);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'audit', null, false, false);
    expect($payment->fresh()->last_payment_at->timestamp)->toBe(1755502500)
        ->and($payment->fresh()->active_until->timestamp)->toBe(1755588900);
});

it('rejects active coverage regression even when the reported payment timestamp increases', function () {
    [$customer, , $payment, $subscription] = billingAuditFixture();
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => '2026-05-02T10:00:00Z', 'activeUntil' => '2026-05-15T10:00:00Z']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    $period = $payment->fresh()->active_until->timestamp;
    $last = $payment->fresh()->last_payment_at->timestamp;
    $wallet = $customer->fresh()->wallet->getAttributes();
    $local = $subscription->fresh()->getAttributes();
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->active_until->timestamp)->toBe($period)
        ->and($payment->fresh()->last_payment_at->timestamp)->toBe($last)
        ->and($customer->fresh()->wallet->getAttributes())->toBe($wallet)
        ->and($subscription->fresh()->getAttributes())->toBe($local);
});

it('fulfills one-time payment from matched authenticated money and identity', function () {
    [, , $payment] = billingAuditFixture(false);
    $payment->update(['provider_object_type' => 'payment', 'payment_mode' => 'one_time', 'fib_payment_id' => 'verified-one-time']);
    $status = \App\Domain\Payments\Data\FibPaymentStatusData::fromArray(['paymentId' => 'verified-one-time', 'status' => 'PAID',
        'amount' => ['amount' => $payment->amount, 'currency' => $payment->currency], 'paidAt' => '2026-05-01T10:00:00Z']);
    $this->partialMock(\App\Domain\Payments\Fib\FibOneTimePaymentService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)->and($payment->fresh()->fulfilled_at)->not->toBeNull()
        ->and(\App\Models\CreditOrder::where('payment_id', $payment->id)->count())->toBe(1);
});

it('does not replay an unverified paid application from historical callback claims', function () {
    [$customer, , $payment] = billingAuditFixture(false);
    config(['fib.callback_secret' => '']);
    $payment->update(['status' => 'paid', 'internal_status' => 'paid_pending_application',
        'callback_payload' => ['paymentStatus' => 'PAID', 'isPaid' => true],
        'status_response' => ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE', 'lastPaymentAt' => null]]);
    $status = FibSubscriptionStatusData::fromArray($payment->status_response);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn($status);
    $before = $customer->fresh()->wallet->balance_credits;
    $this->postJson(route('payments.fib.subscription.callback'), ['id' => $payment->fib_subscription_id, 'paymentStatus' => 'PAID'])->assertAccepted();
    expect($payment->fresh()->fulfilled_at)->toBeNull()
        ->and($payment->fresh()->internal_status->value)->toBe('requires_review')
        ->and($customer->fresh()->wallet->balance_credits)->toBe($before)
        ->and(\App\Models\CreditOrder::where('payment_id', $payment->id)->count())->toBe(0);
});
