<?php

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
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

function recurringCycleFixture(bool $fulfilled = true): array
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
        'provider_cycle_key' => $payment->providerRecurringCycleKey(),
    ]) : null;

    recurringCycleStoredCollection($payment);

    return [$customer, $plan, $payment, $subscription];
}

function recurringCycleStoredCollection(Payment $payment): void
{
    $payment->refresh();
    $payment->update(['status_response' => [
        'id' => $payment->fib_subscription_id, 'status' => $payment->provider_subscription_status,
        'lastPaymentAt' => $payment->last_payment_at?->toIso8601String(),
        'activeUntil' => $payment->active_until?->toIso8601String(),
    ]]);
}

function recurringCycleReceipt(Payment $payment, string $start = '2026-06-01 10:00:00', string $end = '2026-07-01 10:00:00', string $status = 'ACTIVE'): void
{
    $payment->update(['last_payment_at' => $start, 'active_until' => $end, 'provider_subscription_status' => $status]);
    recurringCycleStoredCollection($payment);
}

function recurringCycleFinancialState(Customer $customer): array
{
    return [
        $customer->fresh()->wallet->getAttributes(), $customer->fresh()->apiWallet->getAttributes(),
        \App\Models\CreditLedger::where('customer_id', $customer->id)->count(),
        \App\Models\SubscriptionCreditAllocation::where('customer_id', $customer->id)->count(),
    ];
}

it('allocates one verified monthly cycle across callback scheduler and a fresh service instance', function () {
    [$customer, $plan, $payment] = recurringCycleFixture();
    $customer->wallet->update(['addon_balance_credits' => 77, 'balance_credits' => $plan->appMonthlyCredits() + 77]);
    $customer->apiWallet->update(['addon_balance_credits' => 91, 'balance_credits' => $plan->apiMonthlyCredits() + 91]);
    Carbon::setTestNow('2026-06-01 10:05:00');
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => '2026-06-01T10:00:00+03:00', 'activeUntil' => '2026-07-01T10:00:00+03:00']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);
    config(['fib.callback_secret' => '']);
    $this->postJson(route('payments.fib.subscription.callback'), ['id' => $payment->fib_subscription_id])->assertAccepted();
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits())
        ->and($customer->fresh()->apiWallet->subscription_balance_credits)->toBe($plan->apiMonthlyCredits())
        ->and($customer->fresh()->wallet->addon_balance_credits)->toBe(77)
        ->and($customer->fresh()->apiWallet->addon_balance_credits)->toBe(91);
    app(CreditService::class)->charge($customer->id, 100, 'cycle_spend');
    app(CreditService::class)->charge($customer->id, 50, 'cycle_spend', [], 'api');
    $state = recurringCycleFinancialState($customer);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'scheduled_sub_renewal');
    app()->forgetInstance(SyncProviderSubscriptionLifecycle::class);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'restart');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect(recurringCycleFinancialState($customer))->toBe($state)
        ->and(\App\Models\SubscriptionCreditAllocation::where('payment_id', $payment->id)->where('allocation_type', 'provider_renewal')->count())->toBe(1);
});

it('rolls back both wallets claim lifecycle and ledgers when a renewal ledger fails then retries the same cycle', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    app(CreditService::class)->charge($customer->id, 100, 'cycle_spend');
    app(CreditService::class)->charge($customer->id, 50, 'cycle_spend', [], 'api');
    recurringCycleReceipt($payment);
    $state = recurringCycleFinancialState($customer);
    $subscriptionState = $subscription->fresh()->getAttributes();
    $fail = true;
    \App\Models\CreditLedger::creating(function ($ledger) use (&$fail) {
        if ($fail && $ledger->type === 'provider_subscription_renewal' && $ledger->wallet_type === 'api') {
            throw new RuntimeException('Fixture ledger failure');
        }
    });
    expect(fn () => app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test'))->toThrow(RuntimeException::class, 'Fixture ledger failure');
    expect(recurringCycleFinancialState($customer))->toBe($state)
        ->and($subscription->fresh()->getAttributes())->toBe($subscriptionState);
    $fail = false;
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits())
        ->and($customer->fresh()->apiWallet->subscription_balance_credits)->toBe($plan->apiMonthlyCredits());
    $state = recurringCycleFinancialState($customer);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect(recurringCycleFinancialState($customer))->toBe($state);
});

it('allows annual monthly allocations only inside the verified prepaid term without another charge', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $payment->update(['purchase_snapshot' => array_merge($payment->purchase_snapshot, ['billing_cycle' => 'yearly'])]);
    recurringCycleReceipt($payment, '2026-05-01 10:00:00', '2027-05-01 10:00:00');
    $subscription->update(['meta' => array_merge($subscription->meta, ['billing_cycle' => 'yearly', 'period_ends_at' => '2027-05-01T10:00:00+03:00'])]);
    foreach (['2026-06-01', '2026-07-01', '2027-04-01'] as $day) {
        Carbon::setTestNow($day.' 10:05:00');
        app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
        $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
        expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits());
        app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
        $state = recurringCycleFinancialState($customer);
        $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
        app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
        expect(recurringCycleFinancialState($customer))->toBe($state);
    }
    Carbon::setTestNow('2027-05-01 10:00:00');
    $state = recurringCycleFinancialState($customer);
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect(recurringCycleFinancialState($customer))->toBe($state);
    Http::assertNothingSent();
});

it('does not reset an annual renewal twice when calendar and provider processing overlap', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $payment->update(['purchase_snapshot' => array_merge($payment->purchase_snapshot, ['billing_cycle' => 'yearly'])]);
    $subscription->update(['meta' => array_merge($subscription->meta, ['billing_cycle' => 'yearly'])]);
    Carbon::setTestNow('2027-05-01 10:05:00');
    recurringCycleReceipt($payment, '2027-05-01 10:00:00', '2028-05-01 10:00:00');
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
    $state = recurringCycleFinancialState($customer);
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect(recurringCycleFinancialState($customer))->toBe($state);
});

it('retains customer cancellation intent and paid access then establishes Free exactly once', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $requested = now()->toIso8601String();
    $subscription->update(['auto_renew' => false, 'canceled_at' => now(), 'ends_at' => $payment->active_until,
        'meta' => array_merge($subscription->meta, ['cancel_source' => 'customer_web', 'cancel_requested_at' => $requested])]);
    app(CreditService::class)->charge($customer->id, 100, 'cancel_spend');
    $state = recurringCycleFinancialState($customer);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect($subscription->fresh()->auto_renew)->toBeFalse()
        ->and(data_get($subscription->fresh()->meta, 'cancel_requested_at'))->toBe($requested)
        ->and(data_get($subscription->fresh()->meta, 'cancel_source'))->toBe('customer_web')
        ->and($customer->fresh()->currentServicePlan()->id)->toBe($plan->id)
        ->and(recurringCycleFinancialState($customer))->toBe($state);
    Carbon::setTestNow('2026-06-01 10:00:00');
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    $count = $subscription->newQuery()->where('customer_id', $customer->id)->count();
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    $this->artisan('subscriptions:reconcile', ['--customer' => $customer->id, '--skip-provider-sync' => true])->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('ended')
        ->and($customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and($subscription->newQuery()->where('customer_id', $customer->id)->count())->toBe($count)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(recurringCycleFinancialState($customer))->toBe($state);
});

it('retains provider cancelled paid coverage and downgrades at expiry', function (bool $expired) {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $payment->update(['provider_subscription_status' => 'CANCELLED']);
    recurringCycleStoredCollection($payment);
    Carbon::setTestNow($expired ? '2026-06-01 10:00:00' : '2026-05-15 10:00:00');
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect($subscription->fresh()->auto_renew)->toBeFalse()
        ->and($subscription->fresh()->status)->toBe($expired ? 'ended' : 'active')
        ->and($customer->fresh()->currentServicePlan()->code)->toBe($expired ? 'free' : $plan->code)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PAID);
})->with([false, true]);

it('uses the known paid boundary during provider failure without granting or repairing history', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andThrow(new RuntimeException('fixture unavailable'));
    Carbon::setTestNow('2026-05-31 10:00:00');
    $state = recurringCycleFinancialState($customer);
    try {
        app(SyncFibCheckoutStatus::class)->handle($payment, 'test');
    } catch (RuntimeException) {
    }
    expect($customer->fresh()->currentServicePlan()->id)->toBe($plan->id)
        ->and(recurringCycleFinancialState($customer))->toBe($state);
    Carbon::setTestNow('2026-06-01 10:00:00');
    $this->artisan('subscriptions:reconcile', ['--customer' => $customer->id, '--skip-provider-sync' => true])->assertSuccessful();
    expect($customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(recurringCycleFinancialState($customer))->toBe($state);
});

it('leaves missing historical coverage reviewable without fabricated periods or new paid allocations', function () {
    [$customer, , $payment, $subscription] = recurringCycleFixture();
    $payment->update(['active_until' => null, 'last_payment_at' => null, 'status_response' => []]);
    $subscription->update(['meta' => [], 'ends_at' => null]);
    Carbon::setTestNow('2026-07-01 10:05:00');
    $state = recurringCycleFinancialState($customer);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect($subscription->fresh()->ends_at)->toBeNull()
        ->and(data_get($subscription->fresh()->meta, 'renewal_metadata_missing'))->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('active')
        ->and(recurringCycleFinancialState($customer))->toBe($state);
});

it('keeps API allowance zero while retaining separate add-ons on renewal', function () {
    [$customer, $plan, $payment] = recurringCycleFixture();
    $plan->update(['api_monthly_credits' => 0]);
    $customer->apiWallet->update(['addon_balance_credits' => 81, 'balance_credits' => $plan->apiMonthlyCredits() + 81]);
    recurringCycleReceipt($payment);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect($customer->fresh()->apiWallet->subscription_balance_credits)->toBe(0)
        ->and($customer->fresh()->apiWallet->balance_credits)->toBe(81)
        ->and($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits());
});

it('rejects a second durable claim even if the application check is bypassed', function () {
    [, , , $subscription] = recurringCycleFixture();
    $claim = \App\Models\SubscriptionCreditAllocation::where('subscription_id', $subscription->id)->firstOrFail();
    expect(fn () => $claim->replicate()->save())->toThrow(\Illuminate\Database\QueryException::class);
});

it('ignores the old plan cycle after a switch even if its provider receipt is newer', function () {
    [$customer, , $payment, $old] = recurringCycleFixture();
    $next = ServicePlan::where('code', 'student')->firstOrFail();
    $new = app(PlanSwitcher::class)->switchServicePlan($customer, $next->id);
    app(CreditService::class)->charge($customer->id, 100, 'switch_spend');
    $state = recurringCycleFinancialState($customer);
    $newState = $new->fresh()->getAttributes();
    recurringCycleReceipt($payment);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    app(CreditService::class)->applyProviderRenewalCycle($old, ['provider_cycle_key' => $payment->providerRecurringCycleKey(),
        'cycle_started_at' => $payment->last_payment_at, 'cycle_ends_at' => $payment->active_until]);
    expect(recurringCycleFinancialState($customer))->toBe($state)
        ->and($new->fresh()->getAttributes())->toBe($newState)
        ->and($new->previous_service_plan_id)->toBe($old->service_plan_id)
        ->and($old->fresh()->status)->toBe('ended');
});

it('retains verified annual collection through an incomplete later observation', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    recurringCycleReceipt($payment, '2026-05-01 10:00:00', '2027-05-01 10:00:00');
    $payment->update(['purchase_snapshot' => array_merge($payment->purchase_snapshot, ['billing_cycle' => 'yearly'])]);
    $subscription->update(['meta' => array_merge($subscription->meta, ['billing_cycle' => 'yearly'])]);
    $paid = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => '2026-05-01T10:00:00+03:00', 'activeUntil' => '2027-05-01T10:00:00+03:00']);
    $incomplete = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($paid, $incomplete);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'test');
    app(SyncFibCheckoutStatus::class)->handle($payment, 'test');
    Carbon::setTestNow('2026-06-01 10:05:00');
    app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits());
});

it('uses monthly slices of a paid one-time annual term and preserves explicit manual grants', function (bool $manual) {
    [$customer, $plan, $payment] = recurringCycleFixture();
    $meta = ['billing_cycle' => 'yearly', 'provider' => 'admin_manual_grant'];
    if (! $manual) {
        $payment->update(['provider_object_type' => 'payment', 'payment_mode' => 'one_time',
            'purchase_snapshot' => array_merge($payment->purchase_snapshot, ['billing_cycle' => 'yearly'])]);
        $meta['payment_id'] = $payment->id;
        $meta['provider'] = 'fib';
    }
    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, $meta);
    Carbon::setTestNow('2026-06-01 10:05:00');
    app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits());
    Http::assertNothingSent();
})->with([false, true]);

it('rolls back calendar wallet changes if its grant cannot be recorded and retries safely', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    $payment->update(['purchase_snapshot' => array_merge($payment->purchase_snapshot, ['billing_cycle' => 'yearly'])]);
    recurringCycleReceipt($payment, '2026-05-01 10:00:00', '2027-05-01 10:00:00');
    $subscription->update(['meta' => array_merge($subscription->meta, ['billing_cycle' => 'yearly'])]);
    Carbon::setTestNow('2026-06-01 10:05:00');
    app(CreditService::class)->charge($customer->id, 100, 'annual_spend');
    $state = recurringCycleFinancialState($customer);
    $fail = true;
    CreditMonthlyGrant::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('fixture grant failure');
        }
    });
    expect(fn () => \Illuminate\Support\Facades\Artisan::call('credits:refill-monthly', ['--customer' => $customer->id]))
        ->toThrow(RuntimeException::class, 'fixture grant failure');
    expect(recurringCycleFinancialState($customer))->toBe($state);
    $fail = false;
    $this->artisan('credits:refill-monthly', ['--customer' => $customer->id])->assertSuccessful();
    expect($customer->fresh()->wallet->subscription_balance_credits)->toBe($plan->appMonthlyCredits());
});

it('does not extend paid access from an ACTIVE observation with no collection timestamp', function () {
    [$customer, , $payment, $subscription] = recurringCycleFixture();
    $end = $payment->active_until->timestamp;
    $status = FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => null, 'activeUntil' => '2026-07-01T10:00:00+03:00']);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->twice()->andReturn($status);
    app(SyncFibCheckoutStatus::class)->handle($payment, 'test');
    expect($payment->fresh()->active_until->timestamp)->toBe($end);
    Carbon::setTestNow('2026-06-01 10:00:00');
    $this->artisan('subscriptions:reconcile', ['--customer' => $customer->id, '--skip-provider-sync' => true])->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('ended')
        ->and($customer->fresh()->currentServicePlan()->code)->toBe('free');
});

it('does not replay a Payment cycle through a second normalized subscription row', function () {
    [$customer, , $payment, $subscription] = recurringCycleFixture();
    recurringCycleReceipt($payment);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    $replacement = $subscription->fresh()->replicate();
    $replacement->meta = [];
    $replacement->save();
    app(CreditService::class)->charge($customer->id, 100, 'rebound_spend');
    $state = recurringCycleFinancialState($customer);
    app(SyncProviderSubscriptionLifecycle::class)->handle($payment, 'test');
    expect(recurringCycleFinancialState($customer))->toBe($state);
    $claim = \App\Models\SubscriptionCreditAllocation::where('payment_id', $payment->id)
        ->where('allocation_type', 'provider_renewal')->firstOrFail()->replicate();
    $claim->subscription_id = $replacement->id;
    expect(fn () => $claim->save())->toThrow(\Illuminate\Database\QueryException::class);
});

it('retains the current paid term when an operator explicitly stops its renewal', function () {
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    config(['fib.profiles.subscription.client_id' => 'fixture', 'fib.profiles.subscription.client_secret' => 'fixture']);
    Http::fake([
        '*/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$payment->fib_subscription_id.'/cancel' => Http::response([], 202),
        '*/subscriptions/'.$payment->fib_subscription_id => Http::response($payment->status_response),
    ]);
    $this->artisan('payments:fib:cancel-subscription', [
        'payment' => $payment->id, '--customer' => $customer->id, '--force-current' => true, '--execute' => true,
    ])->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->auto_renew)->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and($customer->fresh()->currentServicePlan()->id)->toBe($plan->id);
    Carbon::setTestNow('2026-06-01 10:00:00');
    $this->artisan('subscriptions:reconcile', ['--customer' => $customer->id, '--skip-provider-sync' => true])->assertSuccessful();
    expect($customer->fresh()->currentServicePlan()->code)->toBe('free');
});

it('serializes concurrent renewal claims and plan switches without the wrong allowance', function (bool $switchPlan) {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    [$customer, $plan, $payment, $subscription] = recurringCycleFixture();
    app(CreditService::class)->charge($customer->id, 100, 'race_spend');
    app(CreditService::class)->charge($customer->id, 50, 'race_spend', [], 'api');
    recurringCycleReceipt($payment);
    $targetPlan = ServicePlan::where('code', 'student')->firstOrFail();
    $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metkurd-cycle-'.Str::uuid().'.sqlite';
    fclose(fopen($database, 'x'));
    $barrier = $database.'.go';
    $ready = [$database.'.ready1', $database.'.ready2'];
    $processes = [];
    $copy = null;
    try {
        // Copy only this in-memory fixture. No configured disk database is opened.
        $copy = new PDO('sqlite:'.$database);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $copy->beginTransaction();
        $schema = Illuminate\Support\Facades\DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC");
        foreach ($schema as $entry) {
            if ($entry->type !== 'table') {
                continue;
            }
            $copy->exec($entry->sql);
            $rows = Illuminate\Support\Facades\DB::table($entry->name)->get();
            foreach ($rows as $row) {
                $values = (array) $row;
                $columns = implode(',', array_map(fn ($key) => '"'.str_replace('"', '""', $key).'"', array_keys($values)));
                $statement = $copy->prepare('INSERT INTO "'.$entry->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
        }
        foreach ($schema as $entry) {
            if ($entry->type === 'index') {
                $copy->exec($entry->sql);
            }
        }
        $copy->commit();
        $before = (int) $copy->query('SELECT COUNT(*) FROM credit_ledgers')->fetchColumn();
        foreach ($ready as $workerIndex => $signal) {
            $process = new Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/billing-cycle-replay-worker.php'),
                $database, (string) $subscription->id, $barrier, $signal,
                $switchPlan && $workerIndex === 1 ? 'switch' : 'renewal', (string) $targetPlan->id], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/p0-isolated-config.php',
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DATABASE_URL' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array']);
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while ((! is_file($ready[0]) || ! is_file($ready[1])) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException($process->getErrorOutput().$process->getOutput());
                }
            }
            usleep(10000);
        }
        expect(is_file($ready[0]) && is_file($ready[1]))->toBeTrue();
        touch($barrier);
        $outcomes = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            expect($process->isSuccessful(), $process->getErrorOutput())->toBeTrue();
            if (! is_file($ready[$index].'.result')) {
                throw new RuntimeException($process->getErrorOutput().$process->getOutput());
            }
            $outcomes[] = json_decode(file_get_contents($ready[$index].'.result'), true, flags: JSON_THROW_ON_ERROR);
        }
        if ($switchPlan) {
            $latest = $copy->query('SELECT service_plan_id FROM customer_service_subscriptions WHERE customer_id = '.(int) $customer->id.' ORDER BY id DESC LIMIT 1')->fetchColumn();
            expect((int) $latest)->toBe($targetPlan->id)
                ->and($copy->query('SELECT status FROM customer_service_subscriptions WHERE id = '.(int) $subscription->id)->fetchColumn())->toBe('ended');
        } else {
            expect(count(array_filter($outcomes, fn ($result) => $result['applied'])))->toBe(1)
                ->and(count(array_filter($outcomes, fn ($result) => $result['already_applied'])))->toBe(1)
                ->and((int) $copy->query('SELECT COUNT(*) FROM credit_ledgers')->fetchColumn())->toBe($before + 2)
                ->and((int) $copy->query("SELECT COUNT(*) FROM subscription_credit_allocations WHERE allocation_type = 'provider_renewal' AND status = 'applied'")->fetchColumn())->toBe(1);
        }
        $wallets = $copy->prepare('SELECT wallet_type, subscription_balance_credits FROM credit_wallets WHERE customer_id = ?');
        $wallets->execute([$customer->id]);
        $balances = $wallets->fetchAll(PDO::FETCH_KEY_PAIR);
        expect((int) $balances['app'])->toBe(($switchPlan ? $targetPlan : $plan)->appMonthlyCredits())
            ->and((int) $balances['api'])->toBe(($switchPlan ? $targetPlan : $plan)->apiMonthlyCredits());
        unset($wallets);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        unset($statement);
        $copy = null;
        foreach ([$database, $database.'-journal', $barrier, ...$ready, $ready[0].'.result', $ready[1].'.result'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
})->with([false, true]);
