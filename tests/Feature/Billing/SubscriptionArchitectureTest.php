<?php

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\CustomerUsage;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\StorageQuotaExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function billingConfigureFibRecurring(): void
{
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
}

function billingArchitectureCustomer(string $email, string $username): Customer
{
    return Customer::create([
        'username' => $username,
        'email' => $email,
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['usage', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function grantPaidMainPlan(Customer $customer, string $code = 'pro'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

function grantPaidStoragePlan(Customer $customer, string $code = 'premium-10240'): StoragePlan
{
    $plan = StoragePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
        'provider' => 'fake',
    ]);

    return $plan;
}

function fibRecurringPayment(Customer $customer, PurchaseType $purchaseType, int $purchasableId, string $subscriptionId): Payment
{
    return Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => $purchaseType,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => 'applied',
        'fulfilled_at' => now(), // This fixture represents an already applied recurring plan.
        'local_reference' => 'TEST-'.strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => $subscriptionId,
        'amount' => 25000,
        'currency' => 'IQD',
        'purchase_snapshot' => [
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => $purchaseType === PurchaseType::PLAN_SUBSCRIPTION ? ServicePlan::class : StoragePlan::class,
        'purchasable_id' => $purchasableId,
        'paid_at' => now(),
        'active_until' => now()->addMonth(), // Persisted paid-through fixture, not a guessed anniversary.
        'last_payment_at' => now(),
    ]);
}

it('hides the free plan card when the customer already has an active paid main plan', function () {
    $customer = billingArchitectureCustomer('paid-plan-page@example.com', 'paid_plan_page_user');
    grantPaidMainPlan($customer);

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.subscription-plan.subscription-plan')
        ->assertSee('PRO')
        ->assertDontSee('FREE');
});

it('renders the subscription plan page when the current period end is missing', function () {
    $customer = billingArchitectureCustomer('subscription-null-period@example.com', 'subscription_null_period_user');

    CustomerServiceSubscription::query()
        ->where('customer_id', $customer->id)
        ->delete();

    $this->actingAs($customer->fresh(), 'app');

    Livewire::test('app::pages.subscription-plan.subscription-plan')
        ->assertSee('Subscription (Service Credits)');
});

it('renders the storage plan page when the current period end is missing', function () {
    $customer = billingArchitectureCustomer('storage-null-period@example.com', 'storage_null_period_user');

    CustomerStorageSubscription::query()
        ->where('customer_id', $customer->id)
        ->delete();

    $this->actingAs($customer->fresh(), 'app');

    Livewire::test('app::pages.storage-plan.storage-plan')
        ->assertSee('Storage Plans');
});

it('schedules main plan cancellation for period end only and keeps paid access until then', function () {
    $customer = billingArchitectureCustomer('cancel-main@example.com', 'cancel_main_user');
    $plan = grantPaidMainPlan($customer);
    $subscription = $customer->fresh()->activeServiceSubscription()->firstOrFail();
    $expectedEnd = \Illuminate\Support\Carbon::parse((string) data_get($subscription->meta, 'period_ends_at'));

    $scheduled = app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->canceled_at)->not->toBeNull()
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and($scheduled->ends_at?->toDateTimeString())->toBe($expectedEnd?->toDateTimeString())
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->subMinute());

    expect(Customer::query()->findOrFail($customer->id)->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);

    expect($freshCustomer->currentServicePlan()?->code)->toBe('free')
        ->and($freshCustomer->hasPaidServicePlan())->toBeFalse();
});

it('cancels the fib provider subscription when scheduling main plan cancellation at period end', function () {
    Http::preventStrayRequests();
    billingConfigureFibRecurring();

    $customer = billingArchitectureCustomer('cancel-main-fib@example.com', 'cancel_main_fib_user');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $payment = fibRecurringPayment($customer, PurchaseType::PLAN_SUBSCRIPTION, $plan->id, 'fib-service-sub-123');

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fib',
        'payment_id' => $payment->id,
        'provider_ref' => $payment->providerReference(),
        'billing_cycle' => 'monthly',
        'renewal_strategy' => 'provider_schedule',
    ]);

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-service-sub-123' => Http::response([
            'id' => 'fib-service-sub-123',
            'status' => 'ACTIVE',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->toIso8601String(),
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-service-sub-123/cancel' => Http::response(null, 204),
    ]);

    $scheduled = app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());

    Http::assertSent(fn ($request) => $request->url() === 'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-service-sub-123/cancel');

    expect(data_get($scheduled->meta, 'provider_cancellation.provider'))->toBe('fib')
        ->and(data_get($scheduled->meta, 'provider_cancellation.provider_ref'))->toBe($payment->providerReference())
        ->and(data_get($scheduled->meta, 'cancel_source'))->toBe('customer_web')
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'service_subscription_cancel_requested')
            ->exists())->toBeTrue();
});

it('does not touch active storage subscriptions when scheduling main plan cancellation', function () {
    Http::preventStrayRequests();
    billingConfigureFibRecurring();

    $customer = billingArchitectureCustomer('cancel-main-keep-storage@example.com', 'cancel_main_keep_storage_user');
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $storagePlan = grantPaidStoragePlan($customer, 'premium-10240');
    $servicePayment = fibRecurringPayment($customer, PurchaseType::PLAN_SUBSCRIPTION, $servicePlan->id, 'fib-service-only-cancel-123');

    app(PlanSwitcher::class)->switchServicePlan($customer, $servicePlan->id, [
        'provider' => 'fib',
        'payment_id' => $servicePayment->id,
        'provider_ref' => $servicePayment->providerReference(),
        'billing_cycle' => 'monthly',
        'renewal_strategy' => 'provider_schedule',
    ]);

    $storageSubscriptionId = $customer->fresh()->activeStorageSubscription()->firstOrFail()->id;

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-service-only-cancel-123' => Http::response([
            'id' => 'fib-service-only-cancel-123',
            'status' => 'ACTIVE',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->toIso8601String(),
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-service-only-cancel-123/cancel' => Http::response(null, 204),
    ]);

    app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());

    $freshCustomer = $customer->fresh();
    $freshStorageSubscription = CustomerStorageSubscription::query()->findOrFail($storageSubscriptionId);

    expect((int) ($freshCustomer->currentStoragePlan()?->id ?? 0))->toBe($storagePlan->id)
        ->and($freshStorageSubscription->status)->toBe('active')
        ->and(PaymentEvent::query()
            ->where('event_type', 'storage_subscription_cancel_requested')
            ->doesntExist())->toBeTrue();
});

it('schedules storage cancellation for period end and downgrades entitlement to the free storage plan afterwards', function () {
    $customer = billingArchitectureCustomer('cancel-storage@example.com', 'cancel_storage_user');
    $plan = grantPaidStoragePlan($customer);
    $subscription = $customer->fresh()->activeStorageSubscription()->firstOrFail();
    $expectedEnd = \Illuminate\Support\Carbon::parse((string) data_get($subscription->meta, 'period_ends_at'));

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->canceled_at)->not->toBeNull()
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and($scheduled->ends_at?->toDateTimeString())->toBe($expectedEnd?->toDateTimeString())
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);
    $state = $freshCustomer->storageQuotaState();

    expect($freshCustomer->currentStoragePlan()?->code)->toBe('free-512')
        ->and((int) ($state['current_limit_mb'] ?? 0))->toBe(512);
});

it('cancels the fib provider subscription when scheduling storage cancellation at period end', function () {
    Http::preventStrayRequests();
    billingConfigureFibRecurring();

    $customer = billingArchitectureCustomer('cancel-storage-fib@example.com', 'cancel_storage_fib_user');
    $plan = StoragePlan::query()->where('code', 'premium-10240')->firstOrFail();
    $payment = fibRecurringPayment($customer, PurchaseType::STORAGE_SUBSCRIPTION, $plan->id, 'fib-storage-sub-123');

    app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
        'provider' => 'fib',
        'payment_id' => $payment->id,
        'provider_ref' => $payment->providerReference(),
        'renewal_strategy' => 'provider_schedule',
    ]);

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-storage-sub-123' => Http::response([
            'id' => 'fib-storage-sub-123',
            'status' => 'ACTIVE',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->toIso8601String(),
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-storage-sub-123/cancel' => Http::response(null, 204),
    ]);

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());

    Http::assertSent(fn ($request) => $request->url() === 'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-storage-sub-123/cancel');

    expect(data_get($scheduled->meta, 'provider_cancellation.provider'))->toBe('fib')
        ->and(data_get($scheduled->meta, 'provider_cancellation.provider_ref'))->toBe($payment->providerReference())
        ->and(data_get($scheduled->meta, 'cancel_source'))->toBe('customer_web')
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'storage_subscription_cancel_requested')
            ->exists())->toBeTrue();
});

it('keeps existing files intact after a storage downgrade makes the account over quota', function () {
    Storage::fake('s3');

    $customer = billingArchitectureCustomer('storage-preserve@example.com', 'storage_preserve_user');
    grantPaidStoragePlan($customer);

    $storage = app(CustomerOutputStorage::class);
    $path = 'renders/test/preserved.txt';

    $storage->saveTextToS3($customer->id, $path, 'preserve me', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 700 * 1024 * 1024]
    );

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());
    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);
    $state = $freshCustomer->storageQuotaState();

    expect($state['over_quota'])->toBeTrue()
        ->and(Storage::disk('s3')->exists($path))->toBeTrue()
        ->and(CustomerFile::query()->where('customer_id', $customer->id)->where('path', $path)->where('status', 'active')->exists())->toBeTrue();
});

it('blocks new uploads while the downgraded storage account is over quota and allows them again after usage is reduced', function () {
    Storage::fake('s3');

    $customer = billingArchitectureCustomer('storage-block@example.com', 'storage_block_user');
    grantPaidStoragePlan($customer);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 700 * 1024 * 1024]
    );

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());
    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $storage = app(CustomerOutputStorage::class);

    expect(fn () => $storage->saveTextToS3($customer->id, 'renders/test/blocked.txt', 'blocked', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]))->toThrow(StorageQuotaExceededException::class);

    CustomerUsage::query()
        ->where('customer_id', $customer->id)
        ->update(['storage_used_bytes' => 100 * 1024 * 1024]);

    $saved = $storage->saveTextToS3($customer->id, 'renders/test/restored.txt', 'restored', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]);

    expect(Storage::disk('s3')->exists('renders/test/restored.txt'))->toBeTrue()
        ->and((int) ($saved['bytes'] ?? 0))->toBeGreaterThan(0);
});

it('shows the storage cancellation warning with the current usage, current limit, and future limit values', function () {
    $customer = billingArchitectureCustomer('storage-warning@example.com', 'storage_warning_user');
    grantPaidStoragePlan($customer);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 9728 * 1024 * 1024]
    );

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.storage-plan.storage-plan')
        ->call('openCancelConfirm')
        ->assertSee(__('Current usage:'))
        ->assertSee(number_format(9728).' MB')
        ->assertSee(number_format(10240).' MB')
        ->assertSee(number_format(512).' MB')
        ->assertSee('uploads and storage-growing actions will be blocked');
});
