<?php

use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    Cache::flush();
    $this->seed();
});

function dashboardPopulationFixture(int $status = 1, string $created = '2020-01-01 00:00:00'): int
{
    return DB::table('customers')->insertGetId(['username' => 'population_'.Str::random(10), 'email' => Str::uuid().'@example.test',
        'password' => 'isolated-fixture', 'status' => $status, 'email_verify' => false, 'phone_verify' => false, 'created_at' => $created, 'updated_at' => $created]);
}

it('updates the complete activity window while preserving lifetime totals and independent currency state', function () {
    $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
    dashboardPopulationFixture(1, '2026-09-02 00:00:00');
    dashboardPopulationFixture(1, '2026-09-01 23:59:59');
    dashboardPopulationFixture(1, '2025-01-01 00:00:00');
    $this->actingAs(adminDashboardAdmin(), 'admin');
    $home = Livewire::withQueryParams(['period' => '7', 'currency' => 'IQD'])->test('admin::pages.home.app-home');
    expect($home->instance()->recentActivity['rows'])->toHaveCount(7)
        ->and($home->instance()->overviewStats['period_new_customers'])->toBe(1)
        ->and($home->instance()->overviewStats['customers_total'])->toBe(3);
    $home->set('periodFilter', '30')->set('displayCurrencyCode', 'USD')->assertSet('periodFilter', '30');
    expect($home->instance()->chartPayload['activity']['labels'])->toHaveCount(30)
        ->and(array_sum($home->instance()->chartPayload['activity']['customers']))->toBe(2)
        ->and($home->instance()->chartPayload['currency']['code'])->toBe('USD')
        ->and($home->instance()->overviewStats['customers_total'])->toBe(3);
    $home->set('periodFilter', '90')->assertSet('displayCurrencyCode', 'USD');
    expect($home->instance()->recentActivity['rows'])->toHaveCount(90);
    $home->set('periodFilter', '365');
    expect(count($home->instance()->recentActivity['rows']))->toBeLessThanOrEqual(13);
    $home->set('periodFilter', 'all');
    expect(array_sum($home->instance()->chartPayload['activity']['customers']))->toBe(3);
    $home->set('periodFilter', 'invalid')->assertSet('periodFilter', '30');
    Http::assertNothingSent();
});

it('counts the full directory population including no jobs no subscriptions historical and suspended customers', function () {
    dashboardPopulationFixture();
    $historical = dashboardPopulationFixture();
    dashboardPopulationFixture(0);
    dashboardPopulationFixture(2);
    $action = \App\Models\ToolAction::where('full_code', 'tts.standard')->firstOrFail();
    \App\Models\MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $historical, 'tool_id' => $action->tool_id, 'tool_action_id' => $action->id, 'status' => 'done', 'input' => [], 'output' => []]);
    $this->actingAs(adminDashboardAdmin(), 'admin');
    $home = Livewire::test('admin::pages.home.app-home')->assertSee('Total Customers')->assertSee('3 active')->assertSee('1 suspended');
    $stats = $home->instance()->overviewStats;
    $directory = Livewire::test('admin::pages.customers.adm-customers-list')->set('perPage', 2);
    expect($stats['customers_total'])->toBe(4)->and($stats['active_customers'])->toBe(3)->and($stats['suspended_customers'])->toBe(1)
        ->and($stats['customers_total'])->toBe($directory->instance()->customers->total())
        ->and($stats['customers_total'])->toBe($directory->instance()->topStats['customers'])
        ->and($directory->instance()->customers->items())->toHaveCount(2);
    $home->set('periodFilter', 'all');
    expect($home->instance()->overviewStats['customers_total'])->toBe(4)->and($home->instance()->overviewStats['period_new_customers'])->toBe(4);
    Http::assertNothingSent();
});

it('recomputes population after model and bulk changes despite a warm analytics cache', function () {
    $this->actingAs(adminDashboardAdmin(), 'admin');
    $component = Livewire::test('admin::pages.home.app-home');
    $initial = $component->instance()->overviewStats['customers_total'];
    $customer = adminDashboardCustomer();
    $component->call('$refresh');
    expect($component->instance()->overviewStats['customers_total'])->toBe($initial + 1);
    $customer->update(['status' => 0]);
    $component->call('$refresh');
    expect($component->instance()->overviewStats['suspended_customers'])->toBe(1);
    dashboardPopulationFixture(1, now()->format('Y-m-d H:i:s'));
    DB::table('customers')->where('id', $customer->id)->update(['status' => 1]);
    $component->call('$refresh');
    expect($component->instance()->overviewStats['customers_total'])->toBe($initial + 2)
        ->and($component->instance()->overviewStats['suspended_customers'])->toBe(0)
        ->and($component->instance()->overviewStats['active_customers'])->toBe($initial + 2);
});

it('ignores cached population from the previous database or imported fixture', function () {
    dashboardPopulationFixture();
    $this->actingAs(adminDashboardAdmin(), 'admin');
    Cache::put('admin-dashboard:overview:30', ['customers_total' => 449], 300);
    $component = Livewire::test('admin::pages.home.app-home');
    $key = (fn () => $this->analyticsCacheKey('overview'))->call($component->instance());
    $cached = Cache::get($key);
    expect($cached)->toBeArray()->not->toHaveKeys(['customers_total', 'active_customers', 'suspended_customers', 'period_new_customers']);
    Cache::put($key, array_merge($cached, ['customers_total' => 449, 'active_customers' => 449]), 300);
    dashboardPopulationFixture(0);
    $component->call('$refresh');
    expect($component->instance()->overviewStats['customers_total'])->toBe(2)->and($component->instance()->overviewStats['active_customers'])->toBe(1);
});

it('separates database and environment cache identities without exposing connection details', function () {
    $this->actingAs(adminDashboardAdmin(), 'admin');
    $component = Livewire::test('admin::pages.home.app-home')->instance();
    $key = fn () => (fn () => $this->analyticsCacheKey('overview'))->call($component);
    $original = $key();
    $connection = DB::connection();
    $originalDatabase = $connection->getDatabaseName();
    try {
        // Metadata-only identity simulation; no connection to another database is opened.
        $connection->setDatabaseName('isolated-other-database');
        $other = $key();
        expect($other)->not->toBe($original)->not->toContain('isolated-other-database')->toMatch('/^admin-dashboard:v4:[a-f0-9]{64}:overview:30$/');
        Cache::put($original, ['customers_total' => 449], 300);
        expect(Cache::get($other))->toBeNull();
        app()->instance('env', 'dashboard-other-fixture');
        expect($key())->not->toBe($other)->not->toContain('dashboard-other-fixture');
    } finally {
        $connection->setDatabaseName($originalDatabase);
        app()->instance('env', 'testing');
    }
});

it('renders an explicit localized total customer label', function (string $locale, string $label) {
    $admin = adminDashboardAdmin();
    $admin->profile()->create(['first_name' => 'Fixture', 'last_name' => 'Admin']);
    $this->actingAs($admin, 'admin');
    $this->get(route('admin.home', ['locale' => $locale]))->assertOk()->assertSee($label);
})->with([['en', 'Total Customers'], ['ar', 'إجمالي العملاء'], ['ku', 'کۆی کڕیارەکان']]);

function adminDashboardAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'admin_capabilities' => \App\Support\Admin\AdminAccess::CAPABILITIES,
            'name' => 'Admin Dashboard User',
            'email' => 'admin-dashboard@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function adminDashboardCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    return Customer::create([
        'username' => 'dashboard_'.$suffix,
        'email' => 'dashboard-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan']);
}

it('renders the admin dashboard in IQD by default and switches to USD on toggle', function () {
    $admin = adminDashboardAdmin();
    $customer = adminDashboardCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $billing = app(BillingCurrencyService::class);

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $canonicalAmount = $plan->priceIqdForCycle('monthly');
    $expectedIqd = $billing->formatAmount($canonicalAmount, 'IQD');
    $expectedUsd = $billing->formatAmount(
        (float) $billing->convertBaseAmount($canonicalAmount, 'USD')['rounded_amount'],
        'USD'
    );

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.home.app-home')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->assertSee($expectedIqd)
        ->set('displayCurrencyCode', 'USD')
        ->assertSee($expectedUsd);
});

it('keeps dashboard totals and chart revenue numerically consistent across the IQD and USD display modes', function () {
    $admin = adminDashboardAdmin();
    $customer = adminDashboardCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $billing = app(BillingCurrencyService::class);

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.home.app-home')->set('adminChangeReason', 'Authorized catalog correction for regression verification.');
    $iqdCharts = (fn () => $this->chartPayload)->call($component->instance());
    $iqdStats = (fn () => $this->overviewStats)->call($component->instance());

    $component->set('displayCurrencyCode', 'USD');

    $usdCharts = (fn () => $this->chartPayload)->call($component->instance());
    $usdStats = (fn () => $this->overviewStats)->call($component->instance());
    $expectedUsdRevenue = (float) $billing->convertBaseAmount((int) round((float) $iqdStats['revenue_total']), 'USD')['rounded_amount'];

    expect($iqdStats['revenue_total'])->toBeGreaterThan(0)
        ->and($usdStats['revenue_total'])->toBe($iqdStats['revenue_total'])
        ->and(data_get($iqdCharts, 'currency.code'))->toBe('IQD')
        ->and(data_get($usdCharts, 'currency.code'))->toBe('USD')
        ->and((float) data_get($usdCharts, 'purchase_mix.revenue.0'))->toBe($expectedUsdRevenue)
        ->and((int) data_get($iqdCharts, 'purchase_mix.revenue.0'))->toBe((int) round((float) $iqdStats['revenue_total']))
        ->and((int) data_get($iqdCharts, 'plan_mix.subscribers.0'))->toBeGreaterThan(0);
});

it('excludes internal non-revenue orders from dashboard revenue totals', function () {
    $admin = adminDashboardAdmin();
    $customer = adminDashboardCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $realOrder = CreditOrder::query()->latest('id')->firstOrFail();

    CreditOrder::create([
        'customer_id' => $customer->id,
        'payment_id' => null,
        'order_type' => 'subscription',
        'source_type' => 'service_plan',
        'service_plan_id' => $plan->id,
        'status' => 'paid',
        'credits_amount' => (int) $plan->appMonthlyCredits(),
        'amount_usd' => 999.99,
        'currency' => 'IQD',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => 999999,
        'original_amount_iqd' => 999999,
        'discount_amount_iqd' => 0,
        'discounted_amount_iqd' => 999999,
        'gross_amount_iqd' => 999999,
        'surcharge_amount_iqd' => 0,
        'provider_fee_amount_iqd' => 0,
        'net_amount_iqd' => 999999,
        'fee_currency_code' => 'IQD',
        'provider' => 'admin_manual',
        'payment_method' => 'admin_manual',
        'provider_ref' => 'ADMIN-NON-REVENUE-001',
        'paid_at' => now(),
        'meta' => [
            'billing_source' => 'admin_manual_grant',
            'revenue_record' => false,
            'revenue_excluded' => true,
        ],
    ]);

    Cache::flush();
    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.home.app-home')->set('adminChangeReason', 'Authorized catalog correction for regression verification.');
    $stats = (fn () => $this->overviewStats)->call($component->instance());

    expect((float) $stats['revenue_total'])->toBe((float) $realOrder->base_amount_iqd)
        ->and((int) $stats['period_orders'])->toBe(1);
});
