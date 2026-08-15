<?php

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Services\Billing\CreditService;
use App\Services\Billing\PlanSwitcher;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiCreditReservationService;
use App\Services\Plans\PlanConcurrencyService;
use App\Support\AppShellData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function separateWalletCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "wallet_split_{$suffix}",
        'email' => $email ?? "wallet-split-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);
}

function assignSeparateWalletPlan(Customer $customer, string $code): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, (int) $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function seedSeparateWallet(Customer $customer, string $walletType, int $subscriptionCredits, int $addonCredits = 0): CreditWallet
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

it('assigns separate app and api monthly wallet balances for paid plans', function (string $code, int $appCredits, int $apiCredits) {
    $customer = separateWalletCustomer("{$code}-wallets@example.com", "{$code}_wallets_user");
    $plan = assignSeparateWalletPlan($customer, $code);

    expect($plan->appMonthlyCredits())->toBe($appCredits)
        ->and($plan->apiMonthlyCredits())->toBe($apiCredits)
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->subscription_balance_credits)->toBe($appCredits)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->subscription_balance_credits)->toBe($apiCredits);
})->with([
    ['student', 100000, 150000],
    ['pro', 250000, 300000],
    ['premium', 600000, 800000],
]);

it('keeps free app credits and zero api credits on onboarding', function () {
    $customer = separateWalletCustomer('free-wallets@example.com', 'free_wallets_user');
    $plan = $customer->currentServicePlan();
    $apiAccess = app(CustomerApiAccessService::class);

    expect($plan?->code)->toBe('free')
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->subscription_balance_credits)->toBe((int) $plan?->appMonthlyCredits())
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->subscription_balance_credits)->toBe(0)
        ->and($apiAccess->customerHasApiAccess($customer->fresh()))->toBeFalse();
});

it('debits only the app wallet for dashboard credit charges', function () {
    $customer = separateWalletCustomer('dashboard-wallet@example.com', 'dashboard_wallet_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 1000, 50);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 5000, 0);

    app(CreditService::class)->charge((int) $customer->id, 250, 'dashboard_charge', [
        'tool_action' => 'tts.standard',
        'source_type' => 'web_tool',
    ]);

    expect((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe(800)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->balance_credits)->toBe(5000);
});

it('debits only the api wallet for api reservations', function () {
    $customer = separateWalletCustomer('api-wallet@example.com', 'api_wallet_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 1000, 0);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 5000, 0);

    $reservation = app(CustomerApiCreditReservationService::class)->reserve((int) $customer->id, 'job_wallet_split', 600, [
        'api_key_id' => 42,
        'tool_code' => 'tts',
        'tool_action' => 'tts.standard',
        'metric_code' => 'character',
        'metric_quantity' => 120,
    ]);

    expect((string) $reservation->status)->toBe('reserved')
        ->and((string) data_get($reservation->meta, 'wallet_type'))->toBe(CreditWallet::TYPE_API)
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe(1000)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->balance_credits)->toBe(4400);
});

it('rejects api reservations when only the app wallet has credits', function () {
    $customer = separateWalletCustomer('api-insufficient@example.com', 'api_insufficient_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 5000, 0);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 0, 0);

    expect(fn () => app(CustomerApiCreditReservationService::class)->reserve((int) $customer->id, 'job_api_fail', 100, [
        'tool_code' => 'tts',
        'tool_action' => 'tts.standard',
    ]))->toThrow(RuntimeException::class, 'Not enough credits.');
});

it('rejects dashboard charges when only the api wallet has credits', function () {
    $customer = separateWalletCustomer('dashboard-insufficient@example.com', 'dashboard_insufficient_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 0, 0);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 5000, 0);

    expect(fn () => app(CreditService::class)->charge((int) $customer->id, 100, 'dashboard_charge', [
        'tool_action' => 'tts.standard',
    ]))->toThrow(RuntimeException::class, 'Not enough credits.');
});

it('keeps dashboard and api concurrency limits separate', function () {
    $customer = separateWalletCustomer('plan-limits-split@example.com', 'plan_limits_split_user');
    $plan = assignSeparateWalletPlan($customer, 'pro');
    $plan->update([
        'concurrent_jobs_limit' => 3,
        'api_concurrent_jobs' => 10,
    ]);

    expect(app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($customer->fresh()))->toBe(3)
        ->and(app(CustomerApiAccessService::class)->allowedConcurrentJobs($customer->fresh()))->toBe(10);
});

it('shows app credits in the shell and api credits on the api access page', function () {
    $customer = separateWalletCustomer('wallet-shell-ui@example.com', 'wallet_shell_ui_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 1111, 0);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 2222, 0);

    $this->actingAs($customer->fresh(), 'app');

    $shell = app(AppShellData::class)->forCurrentCustomer(true);

    expect((int) data_get($shell, 'credit_balance'))->toBe(1111);
    expect((int) data_get($shell, 'app_credits.balance'))->toBe(1111)
        ->and((int) data_get($shell, 'api_credits.balance'))->toBe(2222);

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->assertSet('creditBalance', 2222)
        ->assertSet('monthlyAllowance', 300000);
});

it('uses separate pricing channels for app and public api', function () {
    $customer = separateWalletCustomer('wallet-pricing-split@example.com', 'wallet_pricing_split_user');
    assignSeparateWalletPlan($customer, 'pro');
    $actionId = (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id');

    PricingRule::query()->where('tool_action_id', $actionId)->delete();

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'app',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 1,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'api',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 4,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    expect($customer->fresh()->priceCreditsFor('tts.standard', ['chars' => 25, 'channel' => 'app']))->toBe(25)
        ->and($customer->fresh()->priceCreditsFor('tts.standard', ['chars' => 25, 'channel' => 'api']))->toBe(100);
});

it('prefers exact app mobile and api pricing over legacy all channel fallback', function () {
    $customer = separateWalletCustomer('wallet-pricing-fallback@example.com', 'wallet_pricing_fallback_user');
    assignSeparateWalletPlan($customer, 'pro');
    $actionId = (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id');

    PricingRule::query()->where('tool_action_id', $actionId)->delete();

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'all',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 50,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 9,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'app',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 1,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'mobile',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 2,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'api',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 4,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    expect($customer->fresh()->priceCreditsFor('tts.standard', ['chars' => 25, 'channel' => 'app']))->toBe(25)
        ->and($customer->fresh()->priceCreditsFor('tts.standard', ['chars' => 25, 'channel' => 'mobile']))->toBe(50)
        ->and($customer->fresh()->priceCreditsFor('tts.standard', ['chars' => 25, 'channel' => 'api']))->toBe(100);
});

it('shows api credits in the header only for api enabled plans', function () {
    $paidCustomer = separateWalletCustomer('wallet-header-paid@example.com', 'wallet_header_paid_user');
    assignSeparateWalletPlan($paidCustomer, 'pro');
    seedSeparateWallet($paidCustomer, CreditWallet::TYPE_APP, 1111, 0);
    seedSeparateWallet($paidCustomer, CreditWallet::TYPE_API, 2222, 0);

    Livewire::actingAs($paidCustomer->fresh(), 'app')
        ->test('app::partials.components.header-account-chip')
        ->assertSee('App Credits')
        ->assertSee('1,111')
        ->assertSee('API Credits')
        ->assertSee('2,222');

    $freeCustomer = separateWalletCustomer('wallet-header-free@example.com', 'wallet_header_free_user');

    Livewire::actingAs($freeCustomer->fresh(), 'app')
        ->test('app::partials.components.header-account-chip')
        ->assertSee('App Credits')
        ->assertDontSee('API Credits');
});

it('renders the V2 resource meter from the canonical shell data without clipping over-limit balances', function () {
    $customer = separateWalletCustomer('wallet-v2-meter@example.com', 'wallet_v2_meter_user');
    assignSeparateWalletPlan($customer, 'pro');
    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 300001, 0);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 2222, 0);
    \App\Models\CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 243 * 1024 * 1024],
    );

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::v2.components.account-resources')
        ->assertSee('Credits & Resources')
        ->assertSee('<details', false)
        ->assertSee('App Credits')
        ->assertSee('300,001')
        ->assertSee('API Credits')
        ->assertSee('2,222')
        ->assertSee('243 MB / 512 MB')
        ->assertSee('width: 100%', false);
});

it('refills both app and api wallets from the service plan allowances', function () {
    Carbon::setTestNow('2026-04-10 10:00:00');

    $customer = separateWalletCustomer('wallet-refill@example.com', 'wallet_refill_user');
    assignSeparateWalletPlan($customer, 'pro');

    seedSeparateWallet($customer, CreditWallet::TYPE_APP, 150, 25);
    seedSeparateWallet($customer, CreditWallet::TYPE_API, 275, 0);

    Carbon::setTestNow('2026-05-10 10:00:00');

    $this->artisan('credits:refill-monthly', [
        '--customer' => (int) $customer->id,
    ])->assertSuccessful();

    expect((int) $customer->fresh()->wallet()->firstOrFail()->subscription_balance_credits)->toBe(250000)
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe(250025)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->subscription_balance_credits)->toBe(300000)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->balance_credits)->toBe(300000);
});
