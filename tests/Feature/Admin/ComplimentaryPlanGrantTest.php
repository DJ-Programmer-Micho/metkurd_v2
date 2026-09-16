<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Admin\AdminFinancialCorrections;
use App\Services\Billing\ExpireSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    Mail::fake();
    $this->travelTo(now()->setDate(2026, 9, 9)->setTime(10, 0));
    $this->admin = User::forceCreate(['name' => 'Complimentary operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture',
        'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.finance']]);
    $this->customer = Customer::create(['username' => 'grant_'.Str::random(10), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
    $this->actingAs($this->admin, 'admin');
});

it('grants complimentary Pro with correct allowances no financial sale and local expiry', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $this->customer->wallet->update(['subscription_balance_credits' => 500, 'addon_balance_credits' => 77, 'balance_credits' => 577]);
    $this->customer->apiWallet->update(['subscription_balance_credits' => 700, 'addon_balance_credits' => 91, 'balance_credits' => 791]);
    $tables = ['payments', 'payment_events', 'payment_intents', 'payment_transactions', 'credit_orders'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $revenue = CreditOrder::revenueIncluded()->where('status', 'paid')->sum('amount_usd');
    $id = Str::uuid()->toString();
    $result = app(AdminFinancialCorrections::class)->plan($id, $this->customer->id, $plan->id, 'monthly', 'Employee access approved for internal duties.', 'internal_team_account');
    $subscription = CustomerServiceSubscription::findOrFail($result['subscription_id']);
    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table]);
    }
    expect($subscription->payment_id)->toBeNull()->and($subscription->provider_ref)->toBeNull()
        ->and($subscription->source)->toBe('admin_manual_grant')->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->renewal_strategy)->toBe('manual_renewal')
        ->and(data_get($subscription->meta, 'grant_reason_code'))->toBe('internal_team_account')
        ->and(data_get($subscription->meta, 'admin_id'))->toBe($this->admin->id)
        ->and(data_get($subscription->meta, 'reason'))->toBe('Employee access approved for internal duties.')
        ->and(data_get($subscription->meta, 'revenue_excluded'))->toBeTrue()
        ->and($this->customer->fresh()->currentServicePlanId())->toBe($plan->id)
        ->and($this->customer->fresh()->wallet->subscription_balance_credits)->toBe(500 + $plan->appMonthlyCredits())
        ->and($this->customer->fresh()->apiWallet->subscription_balance_credits)->toBe(700 + $plan->apiMonthlyCredits())
        ->and($this->customer->fresh()->wallet->addon_balance_credits)->toBe(77)
        ->and($this->customer->fresh()->apiWallet->addon_balance_credits)->toBe(91)
        ->and(CreditOrder::revenueIncluded()->where('status', 'paid')->sum('amount_usd'))->toBe($revenue)
        ->and(AdminAuditEvent::where('operation_id', $id)->where('action', 'grant.plan')->exists())->toBeTrue();
    $wallets = DB::table('credit_wallets')->where('customer_id', $this->customer->id)->orderBy('id')->get()->toJson();
    app(AdminFinancialCorrections::class)->plan($id, $this->customer->id, $plan->id, 'monthly', 'Employee access approved for internal duties.', 'internal_team_account');
    expect(DB::table('credit_wallets')->where('customer_id', $this->customer->id)->orderBy('id')->get()->toJson())->toBe($wallets);
    $this->travelTo($subscription->ends_at->copy()->subSecond());
    expect(app(ExpireSubscription::class)->handle($subscription))->toBeFalse();
    $this->travelTo($subscription->ends_at);
    expect(app(ExpireSubscription::class)->handle($subscription))->toBeTrue()
        ->and($this->customer->fresh()->currentServicePlan()->code)->toBe('free');
    $count = $this->customer->serviceSubscriptions()->count();
    app(ExpireSubscription::class)->handle($subscription);
    expect($this->customer->serviceSubscriptions()->count())->toBe($count)
        ->and($this->customer->fresh()->wallet->addon_balance_credits)->toBe(77)
        ->and($this->customer->fresh()->apiWallet->addon_balance_credits)->toBe(91);
    Http::assertNothingSent();
});

it('does not classify complimentary access as a paid subscriber or dashboard revenue', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $before = Livewire::test('admin::pages.home.app-home')->instance()->overviewStats;
    app(AdminFinancialCorrections::class)->plan(Str::uuid()->toString(), $this->customer->id, $plan->id, 'monthly', 'Promotional access without any payment.', 'promotional');
    Cache::flush();
    $after = Livewire::test('admin::pages.home.app-home')->instance()->overviewStats;
    foreach (['paid_subscribers', 'revenue_total', 'revenue_period', 'credits_sold_total'] as $key) {
        expect($after[$key])->toBe($before[$key]);
    }
    expect(CustomerServiceSubscription::where('status', 'active')->where('service_plan_id', $plan->id)->count())->toBe(1);
});

it('shows translated complimentary intent separate allowances and history', function (string $locale) {
    app()->setLocale($locale);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    Livewire::test('admin::pages.customers.adm-customers-register')->set('customerFilter', (string) $this->customer->id)
        ->set('servicePlanAdjustmentId', (string) $plan->id)->set('servicePlanGrantReason', 'promotional')
        ->assertSee(__('admin_ux.grant_title'))->assertSee(__('admin_ux.grant_confirmation'))
        ->assertSee(__('admin_ux.grant_app_allowance', ['credits' => number_format($plan->appMonthlyCredits())]))
        ->assertSee(__('admin_ux.grant_api_allowance', ['credits' => number_format($plan->apiMonthlyCredits())]))
        ->call('applyServicePlanAdjustment')->assertHasNoErrors()->assertSee(__('admin_ux.grant_history'));
})->with(['en', 'ar', 'ku']);

it('preserves prior paid subscription classification when replacing it with a grant', function () {
    $old = $this->customer->activeServiceSubscription()->firstOrFail();
    $old->update(['source' => 'fib', 'meta' => ['billing_source' => 'fib', 'revenue_record' => true, 'reason' => 'Original purchase']]);
    app(AdminFinancialCorrections::class)->plan(Str::uuid()->toString(), $this->customer->id, ServicePlan::where('code', 'pro')->value('id'), 'monthly', 'Approved internal replacement grant.');
    expect(data_get($old->fresh()->meta, 'revenue_record'))->toBeTrue()
        ->and(data_get($old->fresh()->meta, 'billing_source'))->toBe('fib')->and(data_get($old->fresh()->meta, 'reason'))->toBe('Original purchase');
});

it('rejects unauthorized inactive or reasonless grants without a financial write', function (string $case) {
    if ($case === 'inactive') {
        $this->admin->update(['status' => 0]);
    } elseif ($case === 'capability') {
        $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    }
    $count = CustomerServiceSubscription::count();
    expect(fn () => app(AdminFinancialCorrections::class)->plan(Str::uuid()->toString(), $this->customer->id,
        ServicePlan::where('code', 'pro')->value('id'), 'monthly', $case === 'reason' ? '' : 'Approved complimentary account access.'))
        ->toThrow($case === 'reason' ? \Illuminate\Validation\ValidationException::class : \Illuminate\Auth\Access\AuthorizationException::class);
    expect(CustomerServiceSubscription::count())->toBe($count)->and(Payment::count())->toBe(0);
})->with(['inactive', 'capability', 'reason']);

it('includes historical Admin-assigned subscriptions without requiring payment or revenue evidence', function (string $locale) {
    app()->setLocale($locale);
    $subscription = $this->customer->activeServiceSubscription()->firstOrFail();
    $subscription->update(['source' => 'admin_manual', 'payment_id' => null, 'meta' => ['admin_note' => 'Historical agency access fixture.']]);
    $before = $subscription->fresh()->getRawOriginal();
    $wallets = DB::table('credit_wallets')->where('customer_id', $this->customer->id)->orderBy('id')->get()->toJson();
    $paymentCount = Payment::count();
    $auditCount = AdminAuditEvent::count();
    $page = Livewire::test('admin::pages.customers.adm-customers-register')
        ->set('customerFilter', (string) $this->customer->id);
    $html = $page->html();
    $start = strpos($html, __('Recent Subscription History'));
    $end = strpos($html, __('FIB Payment Ledger'), $start);
    $history = substr($html, $start, $end - $start);
    expect($history)->toContain(__('admin_ux.subscription_admin_source'), 'Historical agency access fixture.', __('Source'));
    expect($subscription->fresh()->getRawOriginal())->toBe($before)
        ->and(DB::table('credit_wallets')->where('customer_id', $this->customer->id)->orderBy('id')->get()->toJson())->toBe($wallets)
        ->and(Payment::count())->toBe($paymentCount)->and(AdminAuditEvent::count())->toBe($auditCount);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);
