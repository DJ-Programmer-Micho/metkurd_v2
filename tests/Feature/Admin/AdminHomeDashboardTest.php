<?php

use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

function adminDashboardAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
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

    Livewire::test('admin::pages.home.app-home')
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

    $component = Livewire::test('admin::pages.home.app-home');
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

    $component = Livewire::test('admin::pages.home.app-home');
    $stats = (fn () => $this->overviewStats)->call($component->instance());

    expect((float) $stats['revenue_total'])->toBe((float) $realOrder->base_amount_iqd)
        ->and((int) $stats['period_orders'])->toBe(1);
});
