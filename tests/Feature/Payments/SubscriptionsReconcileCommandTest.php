<?php

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditMonthlyGrant;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function reconcileCustomer(string $prefix): Customer
{
    return Customer::create([
        'username' => $prefix . '_' . Str::lower(Str::random(8)),
        'email' => $prefix . '-' . Str::lower(Str::random(8)) . '@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function fibSubscriptionPayment(
    Customer $customer,
    PurchaseType $purchaseType,
    int $purchasableId,
    string $subscriptionId,
    string $providerStatus = 'FAILED',
    string $activeUntil = '2026-06-01 08:00:00'
): Payment {
    return Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => $purchaseType,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'local_reference' => 'REC-' . strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => $subscriptionId,
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => $providerStatus,
        'active_until' => Carbon::parse($activeUntil),
        'last_payment_at' => Carbon::parse($activeUntil)->subDay(),
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => $purchaseType === PurchaseType::PLAN_SUBSCRIPTION ? ServicePlan::class : StoragePlan::class,
        'purchasable_id' => $purchasableId,
        'paid_at' => Carbon::parse($activeUntil)->subMonth(),
        'fulfilled_at' => Carbon::parse($activeUntil)->subMonth(),
    ]);
}

it('downgrades overdue failed recurring service and storage subscriptions via local reconciliation fallback', function () {
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_overdue');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $storagePlan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    CustomerStorageSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    $servicePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-service-overdue-123',
        providerStatus: 'FAILED',
        activeUntil: '2026-06-01 08:00:00',
    );

    $storagePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::STORAGE_SUBSCRIPTION,
        purchasableId: $storagePlan->id,
        subscriptionId: 'fib-storage-overdue-123',
        providerStatus: 'FAILED',
        activeUntil: '2026-06-01 08:00:00',
    );

    $serviceSubscription = CustomerServiceSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $servicePayment->id,
        'service_plan_id' => $servicePlan->id,
        'status' => 'active',
        'source' => 'fib',
        'provider_ref' => $servicePayment->providerReference(),
        'starts_at' => now()->subMonth(),
        'auto_renew' => true,
        'renewal_strategy' => 'provider_schedule',
        'cycle_started_on' => now()->subMonth()->toDateString(),
        'cycle_ends_on' => now()->subDay()->toDateString(),
        'next_renewal_on' => now()->subDay()->toDateString(),
        'meta' => [
            'period_ends_at' => now()->subHours(2)->toIso8601String(),
        ],
    ]);

    $storageSubscription = CustomerStorageSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $storagePayment->id,
        'storage_plan_id' => $storagePlan->id,
        'status' => 'active',
        'source' => 'fib',
        'provider_ref' => $storagePayment->providerReference(),
        'starts_at' => now()->subMonth(),
        'auto_renew' => true,
        'renewal_strategy' => 'provider_schedule',
        'cycle_started_on' => now()->subMonth()->toDateString(),
        'cycle_ends_on' => now()->subDay()->toDateString(),
        'next_renewal_on' => now()->subDay()->toDateString(),
        'meta' => [
            'period_ends_at' => now()->subHours(2)->toIso8601String(),
        ],
    ]);

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--skip-provider-sync' => true,
    ])->assertSuccessful();

    $serviceSubscription = $serviceSubscription->fresh();
    $storageSubscription = $storageSubscription->fresh();
    $freshCustomer = $customer->fresh();

    expect($serviceSubscription->status)->toBe('ended')
        ->and($serviceSubscription->auto_renew)->toBeFalse()
        ->and(data_get($serviceSubscription->meta, 'cancel_source'))->toBe('renewal_failed')
        ->and($freshCustomer->currentServicePlan()?->code)->toBe('free');

    expect($storageSubscription->status)->toBe('ended')
        ->and($storageSubscription->auto_renew)->toBeFalse()
        ->and(data_get($storageSubscription->meta, 'cancel_source'))->toBe('renewal_failed')
        ->and($freshCustomer->currentStoragePlan()?->code)->toBe('free-512');
});

it('does not downgrade valid recurring subscriptions with a future active_until', function () {
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_valid');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    $servicePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-service-valid-123',
        providerStatus: 'ACTIVE',
        activeUntil: '2026-06-03 08:00:00',
    );

    $serviceSubscription = CustomerServiceSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $servicePayment->id,
        'service_plan_id' => $servicePlan->id,
        'status' => 'active',
        'source' => 'fib',
        'provider_ref' => $servicePayment->providerReference(),
        'starts_at' => now()->subMonth(),
        'auto_renew' => true,
        'renewal_strategy' => 'provider_schedule',
        'cycle_started_on' => now()->subMonth()->toDateString(),
        'cycle_ends_on' => now()->addDay()->toDateString(),
        'next_renewal_on' => now()->addDay()->toDateString(),
        'meta' => [
            'period_ends_at' => now()->addDays(2)->toIso8601String(),
        ],
    ]);

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--skip-provider-sync' => true,
    ])->assertSuccessful();

    expect($serviceSubscription->fresh()->status)->toBe('active')
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('pro');
});

it('detects and downgrades expired one-time paid service subscriptions, then refills free credits', function () {
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_one_time_expired');
    $freePlan = ServicePlan::query()->where('code', 'free')->firstOrFail();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update([
            'status' => 'ended',
            'ends_at' => now()->subDay(),
            'canceled_at' => now()->subDay(),
        ]);

    CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', now()->format('Y-m'))
        ->delete();

    $initialPaidPayment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::PAID,
        'local_reference' => 'PAY-' . strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => (string) Str::uuid(),
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_payment_status' => 'PAID',
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'manual_renewal',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
        'paid_at' => Carbon::parse('2026-04-30 22:13:31'),
        'fulfilled_at' => Carbon::parse('2026-04-30 22:13:31'),
    ]);

    Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::FAILED,
        'local_reference' => 'REC-' . strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => (string) Str::uuid(),
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_status' => 'REJECTED',
        'provider_subscription_status' => 'REJECTED',
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
    ]);

    $expiredPaidSubscription = CustomerServiceSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $initialPaidPayment->id,
        'service_plan_id' => $proPlan->id,
        'status' => 'active',
        'source' => 'fib',
        'starts_at' => Carbon::parse('2026-04-30 22:13:36'),
        'auto_renew' => false,
        'renewal_strategy' => 'manual_renewal',
        'cycle_started_on' => '2026-04-30',
        'cycle_ends_on' => '2026-05-30',
        'next_renewal_on' => '2026-05-30',
    ]);

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--grace-minutes' => 0,
        '--skip-provider-sync' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Service candidates: 1')
        ->expectsOutputToContain('Service would downgrade: 1')
        ->assertSuccessful();

    expect($expiredPaidSubscription->fresh()->status)->toBe('active');

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--grace-minutes' => 0,
        '--skip-provider-sync' => true,
    ])->assertSuccessful();

    $activeSubscription = CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->latest('id')
        ->first();

    expect($expiredPaidSubscription->fresh()->status)->toBe('ended')
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('free')
        ->and($activeSubscription?->service_plan_id)->toBe((int) $freePlan->id)
        ->and($activeSubscription?->source)->toBe('system');

    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('plan=free')
        ->assertSuccessful();

    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
    ])->assertSuccessful();

    $grant = CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', now()->format('Y-m'))
        ->first();

    expect($grant)->not->toBeNull()
        ->and((int) ($grant?->service_plan_id ?? 0))->toBe((int) $freePlan->id)
        ->and((int) ($grant?->granted_credits ?? 0))->toBe((int) $freePlan->monthly_credits);
});
