<?php

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\PlanSwitcher;
use App\Support\LandingPricingCatalog;
use Database\Seeders\BillingMasterDataSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

function paymentPlansAdminUser(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'admin_capabilities' => \App\Support\Admin\AdminAccess::CAPABILITIES,
            'name' => 'Payments Admin',
            'email' => 'payments-admin-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function paymentPlansCustomerRecord(): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => "payment_plan_{$suffix}",
        'email' => "payment-plan-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh();
}

function assignPaymentPlansServicePlan(Customer $customer, string $planCode): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $planCode)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, (int) $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function seedPaymentPlansWallet(Customer $customer, string $walletType, int $subscriptionCredits, int $addonCredits = 0): CreditWallet
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

it('shows the current database app and api credits on the admin payment plans page', function () {
    $admin = paymentPlansAdminUser();
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    DB::table('service_plans')
        ->where('id', (int) $plan->id)
        ->update([
            'monthly_credits' => 50000,
            'app_monthly_credits' => 123456,
            'api_monthly_credits' => 654321,
        ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->assertSee('123,456')
        ->assertSee('654,321')
        ->assertSee(__('admin_service.plan_defaults'));
});

it('updates plan credit fields, refreshes the table immediately, and clears landing pricing cache', function () {
    $admin = paymentPlansAdminUser();
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $catalog = app(LandingPricingCatalog::class);

    expect(collect($catalog->servicePlans())->firstWhere('code', 'student')['monthly_credits'])->toBe((int) $plan->appMonthlyCredits());

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openEditPlanModal', (int) $plan->id)
        ->set('appMonthlyCredits', 222222)
        ->set('apiMonthlyCredits', 333333)
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee('222,222')
        ->assertSee('333,333');

    $plan->refresh();

    expect((int) $plan->monthly_credits)->toBe(222222)
        ->and((int) $plan->app_monthly_credits)->toBe(222222)
        ->and((int) $plan->api_monthly_credits)->toBe(333333)
        ->and((int) Cache::get('service-plans:cache-version', 1))->toBeGreaterThan(1);

    $landingStudent = collect($catalog->servicePlans())->firstWhere('code', 'student');

    expect((int) ($landingStudent['monthly_credits'] ?? 0))->toBe(222222);
});

it('does not overwrite existing customer wallet balances when a plan definition changes', function () {
    $admin = paymentPlansAdminUser();
    $customer = paymentPlansCustomerRecord();
    $plan = assignPaymentPlansServicePlan($customer, 'student');

    seedPaymentPlansWallet($customer, CreditWallet::TYPE_APP, 4312, 55);
    seedPaymentPlansWallet($customer, CreditWallet::TYPE_API, 8765, 21);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openEditPlanModal', (int) $plan->id)
        ->set('appMonthlyCredits', 444444)
        ->set('apiMonthlyCredits', 555555)
        ->call('savePlan')
        ->assertHasNoErrors();

    $customer = $customer->fresh(['wallet', 'apiWallet']);
    $plan->refresh();

    expect((int) $plan->app_monthly_credits)->toBe(444444)
        ->and((int) $plan->api_monthly_credits)->toBe(555555)
        ->and((int) $customer->wallet->subscription_balance_credits)->toBe(4312)
        ->and((int) $customer->wallet->addon_balance_credits)->toBe(55)
        ->and((int) $customer->apiWallet->subscription_balance_credits)->toBe(8765)
        ->and((int) $customer->apiWallet->addon_balance_credits)->toBe(21);
});

it('billing master data seeder preserves existing operator-modified plan credits', function () {
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    $plan->update([
        'monthly_credits' => 777777,
        'app_monthly_credits' => 777777,
        'api_monthly_credits' => 888888,
    ]);

    $this->seed(BillingMasterDataSeeder::class);
    $plan->refresh();

    expect((int) $plan->monthly_credits)->toBe(777777)
        ->and((int) $plan->app_monthly_credits)->toBe(777777)
        ->and((int) $plan->api_monthly_credits)->toBe(888888);
});
