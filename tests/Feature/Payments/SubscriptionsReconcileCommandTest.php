<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditMonthlyGrant;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        'username' => $prefix.'_'.Str::lower(Str::random(8)),
        'email' => $prefix.'-'.Str::lower(Str::random(8)).'@example.com',
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
    ?string $activeUntil = '2026-06-01 08:00:00',
    ?string $lastPaymentAt = null,
    array $overrides = []
): Payment {
    $activeUntilAt = is_string($activeUntil) && trim($activeUntil) !== ''
        ? Carbon::parse($activeUntil)
        : null;
    $lastPaymentAtValue = is_string($lastPaymentAt) && trim($lastPaymentAt) !== ''
        ? Carbon::parse($lastPaymentAt)
        : ($activeUntilAt?->copy()->subDay());
    $paidAt = $activeUntilAt?->copy()->subMonth() ?? now()->subMonth();
    $fulfilledAt = $activeUntilAt?->copy()->subMonth() ?? now()->subMonth();

    return Payment::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => $purchaseType,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'local_reference' => 'REC-'.strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => $subscriptionId,
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => $providerStatus,
        'active_until' => $activeUntilAt,
        'last_payment_at' => $lastPaymentAtValue,
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => $purchaseType === PurchaseType::PLAN_SUBSCRIPTION ? ServicePlan::class : StoragePlan::class,
        'purchasable_id' => $purchasableId,
        'paid_at' => $paidAt,
        'fulfilled_at' => $fulfilledAt,
    ], $overrides));
}

function reconcileFibConfigure(): void
{
    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
}

function reconcileStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq'.$path;
}

function reconcileSubscriptionStatusResponse(string $subscriptionId, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'title' => 'MET KURD Subscription',
        'description' => 'Recurring checkout',
        'monetaryValue' => [
            'amount' => '25000',
            'currency' => 'IQD',
        ],
        'interval' => 'P1M',
        'trialPeriod' => null,
        'status' => $status,
        'validUntil' => '2026-06-01T10:15:00Z',
        'activeUntil' => '2026-07-01T10:15:00Z',
        'lastPaymentAt' => '2026-06-01T10:05:00Z',
        'appLink' => 'https://fib.iq/app/'.$subscriptionId,
    ], $overrides);
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

it('reports missing renewal metadata separately and keeps fib active entitlements unchanged in dry-run mode', function () {
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_missing_meta_dry');
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
        subscriptionId: 'fib-service-missing-meta-dry-123',
        providerStatus: 'ACTIVE',
        activeUntil: null,
        lastPaymentAt: null,
    );

    $storagePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::STORAGE_SUBSCRIPTION,
        purchasableId: $storagePlan->id,
        subscriptionId: 'fib-storage-missing-meta-dry-123',
        providerStatus: 'ACTIVE',
        activeUntil: null,
        lastPaymentAt: null,
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
    ]);

    $serviceMetaBefore = $serviceSubscription->meta;
    $storageMetaBefore = $storageSubscription->meta;
    $servicePaymentMetaBefore = $servicePayment->meta;
    $storagePaymentMetaBefore = $storagePayment->meta;

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--chunk' => 50,
        '--stale-minutes' => 0,
        '--grace-minutes' => 0,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Service candidates: 1')
        ->expectsOutputToContain('Service skipped missing renewal metadata: 1')
        ->expectsOutputToContain('Service would downgrade: 0')
        ->expectsOutputToContain('Storage candidates: 1')
        ->expectsOutputToContain('Storage skipped missing renewal metadata: 1')
        ->expectsOutputToContain('Storage would downgrade: 0')
        ->assertSuccessful();

    expect($serviceSubscription->fresh()->status)->toBe('active')
        ->and($storageSubscription->fresh()->status)->toBe('active')
        ->and($serviceSubscription->fresh()->meta)->toBe($serviceMetaBefore)
        ->and($storageSubscription->fresh()->meta)->toBe($storageMetaBefore)
        ->and($servicePayment->fresh()->meta)->toBe($servicePaymentMetaBefore)
        ->and($storagePayment->fresh()->meta)->toBe($storagePaymentMetaBefore)
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('pro')
        ->and($customer->fresh()->currentStoragePlan()?->code)->toBe('pro-5120')
        ->and(PaymentEvent::query()
            ->whereIn('payment_id', [$servicePayment->id, $storagePayment->id])
            ->whereIn('event_type', ['subscription_renewal_metadata_missing', 'provider_status_sync_failed'])
            ->count())->toBe(0);
});

it('does not downgrade applied fib subscriptions when active renewal metadata is missing and records a throttled warning instead', function () {
    Http::preventStrayRequests();
    reconcileFibConfigure();
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_missing_meta_live');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    $servicePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-service-missing-meta-live-123',
        providerStatus: 'ACTIVE',
        activeUntil: null,
        lastPaymentAt: null,
        overrides: [
            'last_status_checked_at' => now()->subMinutes(30),
        ],
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
    ]);

    Http::fake([
        reconcileStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-subscription-access-token',
            'expires_in' => 60,
        ], 200),
        reconcileStageUrl('/protected/v1/subscriptions/fib-service-missing-meta-live-123') => Http::response(
            reconcileSubscriptionStatusResponse('fib-service-missing-meta-live-123', 'ACTIVE', [
                'activeUntil' => null,
                'lastPaymentAt' => null,
            ]),
            200
        ),
    ]);

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--chunk' => 50,
        '--stale-minutes' => 0,
        '--grace-minutes' => 0,
    ])
        ->expectsOutputToContain('Service candidates: 1')
        ->expectsOutputToContain('Service skipped missing renewal metadata: 1')
        ->expectsOutputToContain('Service downgraded: 0')
        ->assertSuccessful();

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--chunk' => 50,
        '--stale-minutes' => 0,
        '--grace-minutes' => 0,
    ])->assertSuccessful();

    $servicePayment = $servicePayment->fresh();
    $serviceSubscription = $serviceSubscription->fresh();

    expect($serviceSubscription->status)->toBe('active')
        ->and($serviceSubscription->auto_renew)->toBeTrue()
        ->and(data_get($serviceSubscription->meta, 'renewal_metadata_missing'))->toBeTrue()
        ->and(data_get($serviceSubscription->meta, 'renewal_metadata_missing_reason'))->toBe('provider_active_without_active_until')
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('pro')
        ->and($servicePayment->status)->toBe(PaymentStatus::PAID)
        ->and($servicePayment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($servicePayment->active_until)->toBeNull()
        ->and($servicePayment->review_required_at)->toBeNull()
        ->and(PaymentEvent::query()
            ->where('payment_id', $servicePayment->id)
            ->where('event_type', 'provider_status_sync_failed')
            ->count())->toBe(0)
        ->and(PaymentEvent::query()
            ->where('payment_id', $servicePayment->id)
            ->where('event_type', 'provider_renewal_sync_failed')
            ->count())->toBe(0)
        ->and(PaymentEvent::query()
            ->where('payment_id', $servicePayment->id)
            ->where('event_type', 'subscription_renewal_metadata_missing')
            ->count())->toBe(1);
});

it('keeps subscriptions reconcile focused on unresolved checkout rows and avoids legacy sync failure noise', function () {
    Http::preventStrayRequests();
    reconcileFibConfigure();
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_mixed_checkout');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    $awaitingPayment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'REC-MIXED-AWAIT-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-mixed-awaiting-123',
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'UNPAID',
        'last_status_checked_at' => now()->subMinutes(30),
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $servicePlan->id,
    ]);

    $reviewPayment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'local_reference' => 'REC-MIXED-REVIEW-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-mixed-review-123',
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'DRAFT',
        'review_required_at' => now()->subHour(),
        'paid_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $servicePlan->id,
    ]);

    $appliedRejectedPayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-mixed-applied-rejected-123',
        providerStatus: 'REJECTED',
        activeUntil: null,
        lastPaymentAt: null,
        overrides: [
            'last_status_checked_at' => now()->subMinutes(30),
        ],
    );

    $missingMetadataPayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-mixed-missing-metadata-123',
        providerStatus: 'ACTIVE',
        activeUntil: null,
        lastPaymentAt: null,
        overrides: [
            'last_status_checked_at' => now()->subMinutes(30),
        ],
    );

    $activeSubscription = CustomerServiceSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $missingMetadataPayment->id,
        'service_plan_id' => $servicePlan->id,
        'status' => 'active',
        'source' => 'fib',
        'provider_ref' => $missingMetadataPayment->providerReference(),
        'starts_at' => now()->subMonth(),
        'auto_renew' => true,
        'renewal_strategy' => 'provider_schedule',
        'cycle_started_on' => now()->subMonth()->toDateString(),
        'cycle_ends_on' => now()->subDay()->toDateString(),
        'next_renewal_on' => now()->subDay()->toDateString(),
    ]);

    Http::fake([
        reconcileStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-subscription-access-token',
            'expires_in' => 60,
        ], 200),
        reconcileStageUrl('/protected/v1/subscriptions/fib-mixed-awaiting-123') => Http::response(
            reconcileSubscriptionStatusResponse('fib-mixed-awaiting-123', 'UNPAID', [
                'activeUntil' => null,
                'lastPaymentAt' => null,
            ]),
            200
        ),
        reconcileStageUrl('/protected/v1/subscriptions/fib-mixed-missing-metadata-123') => Http::response(
            reconcileSubscriptionStatusResponse('fib-mixed-missing-metadata-123', 'ACTIVE', [
                'activeUntil' => null,
                'lastPaymentAt' => null,
            ]),
            200
        ),
    ]);

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--chunk' => 50,
        '--stale-minutes' => 0,
        '--grace-minutes' => 0,
    ])
        ->expectsOutputToContain('Service skipped missing renewal metadata: 1')
        ->assertSuccessful();

    $protectedIds = [
        $reviewPayment->id,
        $appliedRejectedPayment->id,
        $missingMetadataPayment->id,
    ];

    expect(PaymentEvent::query()
        ->whereIn('payment_id', $protectedIds)
        ->where('event_type', 'provider_status_sync_failed')
        ->count())->toBe(0)
        ->and(PaymentEvent::query()
            ->where('payment_id', $awaitingPayment->id)
            ->where('event_type', 'provider_status_changed')
            ->count())->toBe(0)
        ->and($awaitingPayment->fresh()->last_status_checked_at->equalTo(now()))->toBeTrue()
        ->and(data_get($awaitingPayment->fresh()->meta, 'provider_observation.valid'))->toBeTrue()
        ->and($activeSubscription->fresh()->status)->toBe('active')
        ->and(data_get($activeSubscription->fresh()->meta, 'renewal_metadata_missing'))->toBeTrue();
});

it('still downgrades fib subscriptions when active_until exists and is expired beyond grace period', function () {
    Carbon::setTestNow('2026-06-01 10:30:00');

    $customer = reconcileCustomer('reconcile_expired_active_until');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->where('status', 'active')
        ->update(['status' => 'ended', 'ends_at' => now()->subDay()]);

    $servicePayment = fibSubscriptionPayment(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        purchasableId: $servicePlan->id,
        subscriptionId: 'fib-service-expired-proof-123',
        providerStatus: 'ACTIVE',
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

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--grace-minutes' => 0,
        '--skip-provider-sync' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Service candidates: 1')
        ->expectsOutputToContain('Service skipped missing renewal metadata: 0')
        ->expectsOutputToContain('Service would downgrade: 1')
        ->assertSuccessful();

    $this->artisan('subscriptions:reconcile', [
        '--customer' => $customer->id,
        '--grace-minutes' => 0,
        '--skip-provider-sync' => true,
    ])->assertSuccessful();

    expect($serviceSubscription->fresh()->status)->toBe('ended')
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('free')
        ->and(PaymentEvent::query()
            ->where('payment_id', $servicePayment->id)
            ->where('event_type', 'subscription_renewal_metadata_missing')
            ->count())->toBe(0);
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
        'local_reference' => 'PAY-'.strtoupper(Str::random(10)),
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
        'local_reference' => 'REC-'.strtoupper(Str::random(10)),
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
