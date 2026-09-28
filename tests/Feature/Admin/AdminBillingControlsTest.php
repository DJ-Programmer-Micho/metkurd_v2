<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
});

function billingAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'admin_capabilities' => \App\Support\Admin\AdminAccess::CAPABILITIES,
            'name' => 'Billing Admin',
            'email' => 'billing-admin@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function billingCustomer(): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => "billing_{$suffix}",
        'email' => "billing-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh();
}

function assignBillingPlan(Customer $customer, string $planCode): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $planCode)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, (int) $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function seedBillingWallet(Customer $customer, string $walletType, int $subscriptionCredits, int $addonCredits = 0): CreditWallet
{
    return CreditWallet::query()->updateOrCreate(
        [
            'customer_id' => (int) $customer->id,
            'wallet_type' => $walletType,
        ],
        [
            'balance_credits' => $subscriptionCredits + $addonCredits,
            'subscription_balance_credits' => $subscriptionCredits,
            'addon_balance_credits' => $addonCredits,
        ]
    );
}

it('creates a manual plan grant without revenue or provider subscription records', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $paymentCountBefore = Payment::query()->count();
    $creditOrderCountBefore = CreditOrder::query()->count();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('servicePlanAdjustmentId', (string) $proPlan->id)
        ->set('servicePlanGrantReason', 'internal_team_account')
        ->set('servicePlanBillingCycle', 'monthly')
        ->set('servicePlanAdjustmentNote', 'Grant internal team access without creating revenue.')
        ->call('applyServicePlanAdjustment')
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);
    $subscription = $customer->activeServiceSubscription()->firstOrFail();

    expect($customer->currentServicePlanId())->toBe($proPlan->id)
        ->and($subscription->payment_id)->toBeNull()
        ->and($subscription->source)->toBe('admin_manual_grant')
        ->and($subscription->provider_ref)->toBeNull()
        ->and(data_get($subscription->meta, 'billing_source'))->toBe('admin_manual_grant')
        ->and(data_get($subscription->meta, 'revenue_record'))->toBeFalse()
        ->and(data_get($subscription->meta, 'fib_subscription_id'))->toBeNull()
        ->and(Payment::query()->count())->toBe($paymentCountBefore)
        ->and(CreditOrder::query()->count())->toBe($creditOrderCountBefore);
});

it('requires a reason before applying a manual grant', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('servicePlanAdjustmentId', (string) $proPlan->id)
        ->set('servicePlanBillingCycle', 'monthly')
        ->call('applyServicePlanAdjustment')
        ->assertHasErrors(['servicePlanGrantReason' => 'required']);
});

it('applies a safe manual plan correction for upgrades across app and api wallets', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $studentPlan = assignBillingPlan($customer, 'student');
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 3170, 200);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 120, 50);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('servicePlanAdjustmentId', (string) $proPlan->id)
        ->set('servicePlanGrantReason', 'company_account')
        ->set('servicePlanBillingCycle', 'monthly')
        ->set('servicePlanAdjustmentNote', 'Upgrade confirmed after provider payment review.')
        ->call('applyServicePlanAdjustment')
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);

    expect($customer->activeServiceSubscription?->servicePlan?->code)->toBe('pro')
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe(3170 + (int) $proPlan->appMonthlyCredits())
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(200)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe(120 + (int) $proPlan->apiMonthlyCredits())
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(50)
        ->and((int) $studentPlan->appMonthlyCredits())->toBeLessThan((int) $proPlan->appMonthlyCredits());

    expect(CreditLedger::query()
        ->where('customer_id', $customer->id)
        ->where('source_type', 'admin_manual_grant')
        ->where('wallet_type', CreditWallet::TYPE_APP)
        ->where('credits_delta', (int) $proPlan->appMonthlyCredits())
        ->exists())->toBeTrue()
        ->and(CreditLedger::query()
            ->where('customer_id', $customer->id)
            ->where('source_type', 'admin_manual_grant')
            ->where('wallet_type', CreditWallet::TYPE_API)
            ->where('credits_delta', (int) $proPlan->apiMonthlyCredits())
            ->exists())->toBeTrue();
});

it('does not subtract existing balances during a safe downgrade correction', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    assignBillingPlan($customer, 'pro');
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 120000, 25);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 250000, 40);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('servicePlanAdjustmentId', (string) $studentPlan->id)
        ->set('servicePlanGrantReason', 'partner_access')
        ->set('servicePlanBillingCycle', 'monthly')
        ->set('servicePlanAdjustmentNote', 'Downgrade correction after subscription mismatch review.')
        ->call('applyServicePlanAdjustment')
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);

    expect($customer->activeServiceSubscription?->servicePlan?->code)->toBe('student')
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe(120000)
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(25)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe(250000)
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(40)
        ->and(CreditLedger::query()
            ->where('customer_id', $customer->id)
            ->where('source_type', 'admin_manual_grant')
            ->count())->toBe(0);
});

it('syncs current plan credits with the customer button logic and skips duplicate no-op ledgers', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $plan = assignBillingPlan($customer, 'pro');

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 90000, 12);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 199000, 7);

    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('creditSyncReason', 'Verified allowance correction requested by support.')->call('syncCustomerCreditsToPlan', (int) $customer->id)
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet']);

    expect((int) $customer->wallet->subscription_balance_credits)->toBe((int) $plan->appMonthlyCredits())
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(12)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe((int) $plan->apiMonthlyCredits())
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(7);

    $ledgerCount = CreditLedger::query()
        ->where('customer_id', $customer->id)
        ->where('source_type', 'admin_credit_sync')
        ->count();

    expect($ledgerCount)->toBe(2);

    $component->set('creditSyncReason', 'Verified allowance correction requested by support.')->call('syncCustomerCreditsToPlan', (int) $customer->id);

    expect(CreditLedger::query()
        ->where('customer_id', $customer->id)
        ->where('source_type', 'admin_credit_sync')
        ->count())->toBe($ledgerCount);
});

it('renders the customer sync credits action through the Admin SweetAlert bridge', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    assignBillingPlan($customer, 'student');
    $this->actingAs($admin, 'admin');
    $component = Livewire::test('admin::pages.customers.adm-customers-register')->call('focusCustomer', $customer->id)
        ->assertSee('Sync Credits To Plan')->assertSee('data-admin-method="syncCustomerCreditsToPlan"', false)
        ->assertDontSee('confirm(', false)->assertDontSee('wire:click="syncCustomerCreditsToPlan', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($component->html());
    $buttons = (new DOMXPath($dom))->query('//*[@data-admin-method="syncCustomerCreditsToPlan"]');
    expect($buttons->length)->toBeGreaterThan(0);
    foreach ($buttons as $button) {
        expect(json_decode($button->getAttribute('data-admin-args'), true))->toBe([$customer->id])
            ->and($button->getAttribute('data-admin-impact'))->toContain("customer's subscription credits");
    }
});

it('renders manual billing customer labels with real values and never with literal blade syntax', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->assertSee($customer->username.' ('.$customer->email.')')
        ->assertDontSee('{{ $focusedCustomer->username }}', false);
});

it('sync credits to plan tops up only the missing app and api credits for the current plan', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $plan = assignBillingPlan($customer, 'pro');

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 3170, 25);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 5410, 40);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('creditSyncReason', 'Verified allowance correction requested by support.')->call('syncCustomerCreditsToPlan', (int) $customer->id)
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet']);

    expect((int) $customer->wallet->subscription_balance_credits)->toBe((int) $plan->appMonthlyCredits())
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe((int) $plan->apiMonthlyCredits())
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(25)
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(40)
        ->and(CreditLedger::query()
            ->where('customer_id', $customer->id)
            ->where('source_type', 'admin_credit_sync')
            ->where('wallet_type', CreditWallet::TYPE_APP)
            ->value('credits_delta'))->toBe((int) $plan->appMonthlyCredits() - 3170)
        ->and(CreditLedger::query()
            ->where('customer_id', $customer->id)
            ->where('source_type', 'admin_credit_sync')
            ->where('wallet_type', CreditWallet::TYPE_API)
            ->value('credits_delta'))->toBe((int) $plan->apiMonthlyCredits() - 5410);
});

it('uses updated plan definitions for future sync credits to plan actions', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $plan = assignBillingPlan($customer, 'student');

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openEditPlanModal', (int) $plan->id)
        ->set('appMonthlyCredits', 111111)
        ->set('apiMonthlyCredits', 222222)
        ->call('savePlan')
        ->assertHasNoErrors();

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 1100, 10);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 2200, 20);

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('creditSyncReason', 'Verified allowance correction requested by support.')->call('syncCustomerCreditsToPlan', (int) $customer->id)
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet']);

    expect((int) $customer->wallet->subscription_balance_credits)->toBe(111111)
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(10)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe(222222)
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(20);
});

it('uses updated plan definitions for future manual billing corrections', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    assignBillingPlan($customer, 'student');
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openEditPlanModal', (int) $proPlan->id)
        ->set('appMonthlyCredits', 333333)
        ->set('apiMonthlyCredits', 444444)
        ->call('savePlan')
        ->assertHasNoErrors();

    seedBillingWallet($customer, CreditWallet::TYPE_APP, 1000, 5);
    seedBillingWallet($customer, CreditWallet::TYPE_API, 2000, 6);

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('servicePlanAdjustmentId', (string) $proPlan->id)
        ->set('servicePlanGrantReason', 'founder_admin_access')
        ->set('servicePlanBillingCycle', 'monthly')
        ->set('servicePlanAdjustmentNote', 'Apply updated plan allowance.')
        ->call('applyServicePlanAdjustment')
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);

    expect($customer->activeServiceSubscription?->servicePlan?->code)->toBe('pro')
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe(1000 + 333333)
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(5)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe(2000 + 444444)
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(6);
});

it('repairs a provider-paid fib subscription locally and supersedes the older fib plan row', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

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
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');

    $oldPayment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'local_reference' => 'OLD-FIB-STUDENT-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-old-student-admin-123',
        'amount' => 25000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'provider_payment_status' => 'PAID',
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $studentPlan->code,
            'name' => $studentPlan->name,
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $studentPlan->id,
        'paid_at' => now()->subHours(3),
        'fulfilled_at' => now()->subHours(3),
        'active_until' => now()->addMonth(),
        'last_payment_at' => now()->subHours(3),
    ]);

    app(PlanSwitcher::class)->switchServicePlan($customer, (int) $studentPlan->id, [
        'provider' => 'fib',
        'payment_id' => $oldPayment->id,
        'provider_ref' => $oldPayment->providerReference(),
        'billing_cycle' => 'monthly',
        'renewal_strategy' => 'provider_schedule',
        'provider_last_payment_at' => $oldPayment->last_payment_at?->toIso8601String(),
        'provider_cycle_key' => $oldPayment->providerRecurringCycleKey(),
    ]);

    $brokenPayment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'BROKEN-FIB-PRO-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-broken-pro-admin-456',
        'amount' => 35000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'provider_payment_status' => null,
        'callback_payload' => [
            'id' => 'fib-broken-pro-admin-456',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
        ],
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $proPlan->code,
            'name' => $proPlan->name,
            'checkout_context' => [
                'current_service_plan_id' => (int) $studentPlan->id,
                'current_service_plan_code' => (string) $studentPlan->code,
            ],
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
        'active_until' => now()->addMonth(),
    ]);

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-broken-pro-admin-456' => Http::response([
            'id' => 'fib-broken-pro-admin-456',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'paymentStatus' => 'PAID',
            'status' => 'ACTIVE',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => null,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-old-student-admin-123' => Http::response([
            'id' => 'fib-old-student-admin-123',
            'status' => 'ACTIVE',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->subHours(3)->toIso8601String(),
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-old-student-admin-123/cancel' => Http::response(null, 204),
    ]);

    // Callback claims alone no longer expose a provider-paid repair action.
    expect($brokenPayment->hasProviderPaidSubscriptionEvidence())->toBeFalse();
    app(\App\Domain\Payments\Actions\SyncFibCheckoutStatus::class)->handle($brokenPayment, 'test_verified_status', null, false, false);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->assertSee('Provider Paid / Local Not Applied')
        ->call('repairPaidSubscription', (int) $brokenPayment->id)
        ->assertHasNoErrors();

    $brokenPayment = $brokenPayment->fresh();
    $oldPayment = $oldPayment->fresh();
    $customer = $customer->fresh();

    expect($brokenPayment->fulfilled_at)->not->toBeNull()
        ->and($customer->currentServicePlanId())->toBe($proPlan->id)
        ->and($customer->activeServiceSubscription()->count())->toBe(1)
        ->and(data_get($oldPayment->meta, 'supersession.superseded_by_payment_id'))->toBe((int) $brokenPayment->id)
        ->and(data_get($oldPayment->meta, 'supersession.provider_cancel_result'))->toBe('cancel_requested')
        ->and(Payment::query()
            ->where('customer_id', $customer->id)
            ->where('purchase_type', PurchaseType::PLAN_SUBSCRIPTION)
            ->where('internal_status', PaymentInternalStatus::APPLIED)
            ->count())->toBe(2);
});

it('reconciles a real paid fib subscription in no-refill mode without duplicating credits', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = assignBillingPlan($customer, 'pro');

    seedBillingWallet($customer, CreditWallet::TYPE_APP, (int) $proPlan->appMonthlyCredits(), 15);
    seedBillingWallet($customer, CreditWallet::TYPE_API, (int) $proPlan->apiMonthlyCredits(), 9);

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

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'NO-REFILL-FIB-PRO-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-no-refill-pro-123',
        'amount' => 35000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'callback_payload' => [
            'id' => 'fib-no-refill-pro-123',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
        ],
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $proPlan->code,
            'name' => $proPlan->name,
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
    ]);

    $creditLedgerCountBefore = CreditLedger::query()->count();

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-no-refill-pro-123' => Http::response([
            'id' => 'fib-no-refill-pro-123',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->subMinute()->toIso8601String(),
        ], 200),
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('paidReconciliationPaymentId', (string) $payment->id)
        ->set('paidReconciliationFibSubscriptionId', 'fib-no-refill-pro-123')
        ->set('paidReconciliationMode', 'manual_correction_already_applied')
        ->set('paidReconciliationReason', 'Reconnect real FIB revenue after manual correction already applied.')
        ->call('applyPaidSubscriptionReconciliation')
        ->assertHasNoErrors();

    $payment = $payment->fresh();
    $customer = $customer->fresh(['wallet', 'apiWallet']);
    $subscription = $customer->activeServiceSubscription()->firstOrFail();

    expect($payment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(data_get($payment->meta, 'billing_source'))->toBe('admin_paid_reconciliation')
        ->and(data_get($payment->meta, 'manual_correction_already_applied'))->toBeTrue()
        ->and(data_get($payment->meta, 'no_credit_refill'))->toBeTrue()
        ->and($subscription->payment_id)->toBe($payment->id)
        ->and($subscription->source)->toBe('fib')
        ->and($subscription->provider_ref)->toBe('fib-no-refill-pro-123')
        ->and(data_get($subscription->meta, 'billing_source'))->toBe('admin_paid_reconciliation')
        ->and(CreditLedger::query()->count())->toBe($creditLedgerCountBefore)
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe((int) $proPlan->appMonthlyCredits())
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe((int) $proPlan->apiMonthlyCredits());
});

it('attaches the correct fib reference from review mode without refilling credits twice', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = assignBillingPlan($customer, 'pro');

    seedBillingWallet($customer, CreditWallet::TYPE_APP, (int) $proPlan->appMonthlyCredits(), 15);
    seedBillingWallet($customer, CreditWallet::TYPE_API, (int) $proPlan->apiMonthlyCredits(), 9);

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

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'local_reference' => 'REVIEW-NO-REFILL-FIB-PRO-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-review-no-refill-pro-123',
        'amount' => 35000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'NOT_FOUND',
        'callback_payload' => [
            'id' => 'fib-review-no-refill-pro-123',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
        ],
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $proPlan->code,
            'name' => $proPlan->name,
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
        'review_required_at' => now(),
        'mismatch_reason' => 'Stored FIB subscription id needs manual verification before local application.',
    ]);

    $creditLedgerCountBefore = CreditLedger::query()->count();

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-review-no-refill-pro-123' => Http::response([
            'id' => 'fib-review-no-refill-pro-123',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->subMinute()->toIso8601String(),
        ], 200),
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->call('openReviewPayment', (int) $payment->id)
        ->assertSee('Review Payment')
        ->set('reviewCorrectFibSubscriptionId', 'fib-review-no-refill-pro-123')
        ->set('reviewReconnectMode', 'manual_correction_already_applied')
        ->set('reviewResolutionReason', 'Matched the paid FIB subscription manually and only need to reconnect it locally.')
        ->call('attachCorrectReviewProviderReference')
        ->assertHasNoErrors();

    $payment = $payment->fresh();
    $customer = $customer->fresh(['wallet', 'apiWallet']);
    $subscription = $customer->activeServiceSubscription()->firstOrFail();

    expect($payment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($payment->review_required_at)->toBeNull()
        ->and(data_get($payment->meta, 'review_resolution.action'))->toBe('attach_correct_provider_reference')
        ->and(data_get($payment->meta, 'review_resolution.mode'))->toBe('manual_correction_already_applied')
        ->and($subscription->payment_id)->toBe($payment->id)
        ->and($subscription->source)->toBe('fib')
        ->and(CreditLedger::query()->count())->toBe($creditLedgerCountBefore)
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe((int) $proPlan->appMonthlyCredits())
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe((int) $proPlan->apiMonthlyCredits());
});

it('requires a local payment id for paid reconciliation', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('paidReconciliationFibSubscriptionId', 'fib-required-check')
        ->set('paidReconciliationReason', 'Reconnect real paid FIB subscription safely.')
        ->call('applyPaidSubscriptionReconciliation')
        ->assertHasErrors(['paidReconciliationPaymentId' => 'required']);
});

it('requires a fib subscription id for paid reconciliation', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('paidReconciliationPaymentId', '123')
        ->set('paidReconciliationReason', 'Reconnect real paid FIB subscription safely.')
        ->call('applyPaidSubscriptionReconciliation')
        ->assertHasErrors(['paidReconciliationFibSubscriptionId' => 'required']);
});

it('defaults paid reconciliation to the safer no-credit-refill mode and renders clear labels', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->assertSet('paidReconciliationMode', 'manual_correction_already_applied')
        ->assertSee(__('admin_ux.grant_classification'))
        ->assertSee('Paid Customer Reconciliation — Real FIB Payment')
        ->assertSee(__('admin_ux.grant_action'))
        ->assertSee('Reconcile Paid FIB Subscription');
});

it('applies paid reconciliation fulfillment once and creates credit ledgers once', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

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

    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/subscriptions/fib-apply-once-pro-001' => Http::response([
            'id' => 'fib-apply-once-pro-001',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
            'activeUntil' => now()->addMonth()->toIso8601String(),
            'lastPaymentAt' => now()->subMinute()->toIso8601String(),
        ], 200),
    ]);

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'APPLY-ONCE-FIB-PRO-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-apply-once-pro-001',
        'amount' => 35000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'callback_payload' => [
            'id' => 'fib-apply-once-pro-001',
            'monetaryValue' => ['amount' => 35000, 'currency' => 'IQD'],
            'status' => 'ACTIVE',
            'paymentStatus' => 'PAID',
        ],
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $proPlan->code,
            'name' => $proPlan->name,
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
    ]);

    $creditLedgerCountBefore = CreditLedger::query()->count();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('paidReconciliationPaymentId', (string) $payment->id)
        ->set('paidReconciliationFibSubscriptionId', 'fib-apply-once-pro-001')
        ->set('paidReconciliationMode', 'apply_fulfillment_once')
        ->set('paidReconciliationReason', 'Apply the real paid FIB subscription once after missed local fulfillment.')
        ->call('applyPaidSubscriptionReconciliation')
        ->assertHasNoErrors();

    $payment = $payment->fresh();
    $customer = $customer->fresh(['activeServiceSubscription.servicePlan']);

    expect($payment->fulfilled_at)->not->toBeNull()
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($customer->activeServiceSubscription?->servicePlan?->code)->toBe('pro')
        ->and(CreditLedger::query()->count())->toBe($creditLedgerCountBefore + 2);
});

it('blocks already fulfilled payments unless status-only reconciliation is explicitly confirmed', function () {
    $admin = billingAdmin();
    $customer = billingCustomer();
    $proPlan = assignBillingPlan($customer, 'pro');

    seedBillingWallet($customer, CreditWallet::TYPE_APP, (int) $proPlan->appMonthlyCredits(), 0);
    seedBillingWallet($customer, CreditWallet::TYPE_API, (int) $proPlan->apiMonthlyCredits(), 0);

    Http::fake();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'local_reference' => 'ALREADY-FULFILLED-FIB-PRO-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-already-fulfilled-pro-001',
        'amount' => 35000,
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'paid_at' => now()->subMinutes(5),
        'fulfilled_at' => now()->subMinutes(4),
        'purchase_snapshot' => [
            'billing_cycle' => 'monthly',
            'code' => $proPlan->code,
            'name' => $proPlan->name,
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $proPlan->id,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('customerFilter', (string) $customer->id)
        ->set('paidReconciliationPaymentId', (string) $payment->id)
        ->set('paidReconciliationFibSubscriptionId', 'fib-already-fulfilled-pro-001')
        ->set('paidReconciliationMode', 'manual_correction_already_applied')
        ->set('paidReconciliationReason', 'Only reconnect local status after a previously fulfilled payment.')
        ->call('applyPaidSubscriptionReconciliation')
        ->assertHasErrors(['paidReconciliationStatusOnlyConfirmation']);

    Http::assertNothingSent();
});

it('lets an admin create grouped pricing rules for app mobile and api from one modal', function () {
    $admin = billingAdmin();
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('ruleToolActionId', (int) $action->id)
        ->set('ruleType', 'unit')
        ->set('rulePriority', 250)
        ->set('ruleMetricCode', 'character')
        ->set('ruleUnitSize', '1')
        ->set('ruleAppCreditsPerUnit', '10')
        ->set('ruleMobileCreditsPerUnit', '11')
        ->set('ruleApiCreditsPerUnit', '8')
        ->set('ruleRoundingMode', 'ceil')
        ->set('ruleRoundingStep', '1')
        ->set('ruleMinimumCredits', 1)
        ->set('ruleStatus', 'active')
        ->call('savePricingRule')
        ->assertHasNoErrors();

    expect(PricingRule::query()
        ->where('tool_action_id', (int) $action->id)
        ->where('pricing_channel', 'app')
        ->where('credits_per_unit', 10)
        ->exists())->toBeTrue()
        ->and(PricingRule::query()
            ->where('tool_action_id', (int) $action->id)
            ->where('pricing_channel', 'mobile')
            ->where('credits_per_unit', 11)
            ->exists())->toBeTrue()
        ->and(PricingRule::query()
            ->where('tool_action_id', (int) $action->id)
            ->where('pricing_channel', 'api')
            ->where('credits_per_unit', 8)
            ->exists())->toBeTrue()
        ->and(PricingRule::query()
            ->where('tool_action_id', (int) $action->id)
            ->where('priority', 250)
            ->where('pricing_channel', 'all')
            ->exists())->toBeFalse();
});

it('lets an admin edit grouped pricing rules across app mobile and api', function () {
    $admin = billingAdmin();
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();

    $appRule = PricingRule::query()->create([
        'tool_action_id' => (int) $action->id,
        'service_plan_id' => null,
        'pricing_channel' => 'app',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 150,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 4,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => (int) $action->id,
        'service_plan_id' => null,
        'pricing_channel' => 'mobile',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 150,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 5,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => (int) $action->id,
        'service_plan_id' => null,
        'pricing_channel' => 'api',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 150,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 6,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openPricingRuleEditModal', (int) $appRule->id)
        ->set('ruleAppCreditsPerUnit', '12')
        ->set('ruleMobileCreditsPerUnit', '13')
        ->set('ruleApiCreditsPerUnit', '9')
        ->call('savePricingRule')
        ->assertHasNoErrors();

    expect((float) PricingRule::query()->where('tool_action_id', (int) $action->id)->where('pricing_channel', 'app')->value('credits_per_unit'))->toBe(12.0)
        ->and((float) PricingRule::query()->where('tool_action_id', (int) $action->id)->where('pricing_channel', 'mobile')->value('credits_per_unit'))->toBe(13.0)
        ->and((float) PricingRule::query()->where('tool_action_id', (int) $action->id)->where('pricing_channel', 'api')->value('credits_per_unit'))->toBe(9.0);
});

it('keeps api entitlements aligned with api allowed plan scopes', function () {
    $admin = billingAdmin();
    $plan = ServicePlan::query()->where('code', 'free')->firstOrFail();
    $action = ToolAction::query()->where('full_code', 'xomni-v2.generate')->firstOrFail();

    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.services.adm-services-entitlements')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('entitlementServicePlanId', (int) $plan->id)
        ->set('entitlementToolActionId', (int) $action->id)
        ->set('entitlementChannel', 'api')
        ->set('entitlementAllowed', 'allowed')
        ->call('saveEntitlement')
        ->assertHasNoErrors();

    $entitlement = PlanEntitlement::query()
        ->where('service_plan_id', (int) $plan->id)
        ->where('tool_action_id', (int) $action->id)
        ->where('entitlement_channel', 'api')
        ->firstOrFail();

    expect((array) $plan->fresh()->api_allowed_tools)->toContain('v2:speech');

    $component->call('toggleEntitlementAllowed', (int) $entitlement->id);

    expect((bool) $entitlement->fresh()->allowed)->toBeFalse()
        ->and((array) $plan->fresh()->api_allowed_tools)->not->toContain('v2:speech');
});
