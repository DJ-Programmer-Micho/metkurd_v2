<?php

use App\Domain\Payments\Actions\FulfillPlanSubscription;
use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\AdminAuditEvent;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Admin\AdminOperations;
use App\Services\Billing\BillingReportingBoundary;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\PaymentDomainCutover;
use App\Services\Billing\SubscriptionCyclePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Storage::fake('s3');
    config(['metkurd_v2.enabled' => true]);
    $this->owner = Customer::create(['username' => 'epoch_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => 1, 'phone_verify' => 1]);
    $this->pro = ServicePlan::where('code', 'pro')->firstOrFail();
    $this->actingAs($this->owner, 'app');
});

function epochAudit(): void
{
    AdminAuditEvent::create(['action' => BillingReportingBoundary::ACTION, 'target_type' => PaymentDomainCutover::class,
        'target_id' => (string) Str::uuid(), 'after_state' => ['reporting_boundary' => [
            'starts_at' => now()->toDateTimeString(), 'credit_order_id' => CreditOrder::max('id') ?? 0, 'payment_id' => Payment::max('id') ?? 0,
        ]]]);
}

function epochPayment(Customer $customer, array $overrides = []): Payment
{
    return Payment::create(array_merge(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'awaiting_customer_action', 'internal_status' => 'requires_review', 'fib_subscription_id' => 'mock-'.Str::uuid(),
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => ServicePlan::where('code', 'pro')->value('id'), 'amount' => 24000, 'currency' => 'IQD',
        'purchase_snapshot' => ['billing_cycle' => 'monthly', 'name' => 'Pro', 'code' => 'pro', 'amount_iqd' => 24000]], $overrides));
}

it('retires stale provider authority and cached plan access without deriving a plan from preserved wallets', function () {
    $old = epochPayment($this->owner, ['status' => 'paid', 'paid_at' => now(), 'internal_status' => 'applied']);
    CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->pro->id,
        'status' => 'active', 'source' => 'fib', 'payment_id' => $old->id, 'ends_at' => now()->addYear()]);
    DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['balance_credits' => 300000, 'subscription_balance_credits' => 300000]);
    $before = DB::table('credit_wallets')->get()->toJson();
    expect($this->owner->currentServicePlan()->code)->toBe('pro');
    app(\App\Support\AppShellData::class)->forCurrentCustomer();
    epochAudit();
    expect($this->owner->currentServicePlan()->code)->toBe('free')
        ->and($this->owner->activeServiceSubscription()->first()?->servicePlan->code)->toBe('free')
        ->and($this->owner->servicePlan()->first()?->code)->toBe('free')
        ->and(app(\App\Services\CustomerApi\CustomerApiAccessService::class)->customerHasApiAccess($this->owner))->toBeFalse();
    $shell = app(\App\Support\AppShellData::class)->forCurrentCustomer();
    expect($shell['plan_code'])->toBe('free')
        ->and(DB::table('credit_wallets')->get()->toJson())->toBe($before);
});

it('rejects expired or ambiguous retained manual terms and prevents old refills without changing history', function ($term) {
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->pro->id,
        'status' => 'active', 'source' => 'admin_manual', 'auto_renew' => true, 'starts_at' => now()->subMonths(4),
        'meta' => ['period_ends_at' => $term]]);
    epochAudit();
    $before = $sub->fresh()->getAttributes();
    expect(app(CustomerBillingStateService::class)->servicePlanState($this->owner)['current_plan']->code)->toBe('free')
        ->and(app(SubscriptionCyclePolicy::class)->calendarAllocation($sub, now()))->toBeNull()
        ->and($sub->fresh()->getAttributes())->toBe($before);
})->with(['2026-07-02T13:51:38+03:00', 'not-a-date']);

it('retains a valid bounded manual grant while rejecting another customers payment binding', function () {
    epochAudit();
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->pro->id,
        'status' => 'active', 'source' => 'admin_manual_grant', 'ends_at' => now()->addYear()]);
    expect($this->owner->currentServicePlan()->code)->toBe('pro');
    $other = Customer::create(['username' => 'other_epoch', 'email' => 'other_epoch@example.test', 'password' => 'fixture']);
    $payment = epochPayment($other, ['status' => 'paid', 'paid_at' => now(), 'internal_status' => 'applied']);
    $sub->update(['source' => 'fib', 'payment_id' => $payment->id]);
    expect($this->owner->currentServicePlan()->code)->toBe('free');
});

it('excludes legacy orders from App and Admin current history without filtering jobs usage or ledgers', function () {
    $old = CreditOrder::create(['customer_id' => $this->owner->id, 'order_type' => 'subscription', 'status' => 'paid', 'base_amount_iqd' => 12345,
        'created_at' => now()->subDay()]);
    MlJob::create(['id' => Str::uuid(), 'customer_id' => $this->owner->id, 'job_kind' => 'leo', 'status' => 'done', 'credits_charged' => 12, 'created_at' => now()->subDay()]);
    $preserved = collect(['ml_jobs', 'customer_usages', 'customer_files', 'credit_ledgers', 'credit_orders'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
    epochAudit();
    $component = Livewire::test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.no_payments'));
    expect($component->get('dashboard')['payments']->total())->toBe(0)
        ->and($component->get('dashboard')['jobs']->total())->toBe(1)
        ->and($component->get('dashboard')['stats']['period_credits_spent'])->toBe(12)
        ->and(CreditOrder::currentBillingPeriod()->sum('base_amount_iqd'))->toBe(0);
    $admin = User::forceCreate(['name' => 'Epoch reader', 'email' => 'epoch_admin@example.test', 'password' => 'fixture', 'status' => 1]);
    $this->actingAs($admin, 'admin');
    expect(app(AdminOperations::class)->query('orders')->count())->toBe(0)
        ->and(app(AdminOperations::class)->query('orders', ['financialEra' => 'legacy'])->pluck('id')->all())->toContain($old->id)
        ->and(app(AdminOperations::class)->customer($this->owner->id)['plan']['plan'])->toBe('Free');
    foreach ($preserved as $table => $data) {
        expect(DB::table($table)->get()->toJson())->toBe($data);
    }
});

it('ignores archived review checkouts without polling fulfilling or changing their status', function () {
    $old = epochPayment($this->owner);
    expect(app(PaymentCheckoutState::class)->blocks($old))->toBeTrue();
    epochAudit();
    $before = $old->fresh()->getAttributes();
    expect(app(PaymentCheckoutState::class)->blocks($old))->toBeFalse()
        ->and(app(PaymentCheckoutState::class)->blocker($this->owner, ServicePlan::class))->toBeNull();
    app(SyncFibCheckoutStatus::class)->handle($old);
    app(FulfillPlanSubscription::class)->handle($old);
    expect($old->fresh()->getAttributes())->toBe($before);
    Http::assertNothingSent();
});

it('includes the first post-epoch verified fulfillment once and allocates both wallets idempotently', function () {
    CreditOrder::create(['customer_id' => $this->owner->id, 'order_type' => 'subscription', 'status' => 'paid', 'base_amount_iqd' => 999]);
    epochAudit();
    $payment = epochPayment($this->owner, ['status' => 'paid', 'internal_status' => 'paid_pending_application', 'paid_at' => now(),
        'last_payment_at' => now(), 'active_until' => now()->addMonth(), 'provider_subscription_status' => 'ACTIVE']);
    $payment->update(['status_response' => ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
        'lastPaymentAt' => now()->toIso8601String(), 'activeUntil' => now()->addMonth()->toIso8601String()]]);
    app(FulfillPlanSubscription::class)->handle($payment);
    expect($payment->fresh()->isFulfilled())->toBeTrue()->and($this->owner->currentServicePlan()->code)->toBe('pro');
    $before = DB::table('credit_ledgers')->get()->toJson();
    app(FulfillPlanSubscription::class)->handle($payment->fresh());
    expect(DB::table('credit_ledgers')->get()->toJson())->toBe($before)
        ->and((int) $this->owner->fresh()->wallet->subscription_balance_credits)->toBe($this->pro->appMonthlyCredits())
        ->and((int) $this->owner->fresh()->apiWallet->subscription_balance_credits)->toBe($this->pro->apiMonthlyCredits())
        ->and(Payment::currentBillingPeriod()->count())->toBe(1)
        ->and((int) CreditOrder::currentBillingPeriod()->revenueIncluded()->sum('base_amount_iqd'))->toBe(24000);
    $component = Livewire::test('app::v2.pages.account.app-billing');
    expect($component->get('dashboard')['payments']->total())->toBe(1);
    Http::assertNothingSent();
});

it('uses both the audit timestamp and retained ID watermarks', function () {
    $futureOld = epochPayment($this->owner, ['created_at' => now()->addYear()]);
    $futureOld->forceFill(['created_at' => now()->addYear()])->save();
    epochAudit();
    $backdated = epochPayment($this->owner, ['created_at' => now()->subDay()]);
    $backdated->forceFill(['created_at' => now()->subDay()])->save();
    $current = epochPayment($this->owner);
    expect(Payment::currentBillingPeriod()->pluck('id')->all())->toBe([$current->id])
        ->and($futureOld->isCurrentBillingPeriod())->toBeFalse()->and($backdated->isCurrentBillingPeriod())->toBeFalse();
});

it('keeps expired current paid subscriptions available to expiry but not effective access', function () {
    epochAudit();
    $p = epochPayment($this->owner, ['status' => 'paid', 'paid_at' => now()->subMonth(), 'active_until' => now()->subMinute()]);
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->pro->id,
        'status' => 'active', 'source' => 'fib', 'payment_id' => $p->id]);
    expect($this->owner->currentServicePlan()->code)->toBe('free')
        ->and(app(SubscriptionCyclePolicy::class)->isCurrent($sub))->toBeTrue();
});

it('audits capabilities and preflight without granting privileges or changing rows', function () {
    $admin = User::forceCreate(['name' => 'Reader', 'email' => 'reader_epoch@example.test', 'password' => 'fixture', 'status' => 1]);
    $before = $admin->fresh()->getAttributes();
    $this->artisan('admin:capability-audit', ['user' => $admin->id])->assertFailed();
    expect($admin->fresh()->getAttributes())->toBe($before);
    $exit = \Illuminate\Support\Facades\Artisan::call('metkurd:production-preflight');
    $preflight = \Illuminate\Support\Facades\Artisan::output();
    // General development seeders do not supply both retained V1-bound actions.
    // Preflight must catch that; prepare an explicitly active isolated catalog next.
    $checks = json_decode($preflight, true, flags: JSON_THROW_ON_ERROR)['checks'];
    expect($exit)->toBe(1)->and($checks['action:xomni.generate'])->toBeFalse()
        ->and($checks['action:clone_xomni.generate'])->toBeFalse();
    foreach (['xomni.generate', 'clone_xomni.generate'] as $code) {
        [$toolCode, $actionCode] = explode('.', $code);
        \App\Models\Tool::updateOrCreate(['code' => $toolCode], ['name' => $toolCode, 'is_active' => true]);
        \App\Models\ToolAction::updateOrCreate(['full_code' => $code], ['tool_code' => $toolCode,
            'action_code' => $actionCode, 'name' => $code, 'is_active' => true]);
    }
    $this->artisan('metkurd:production-preflight')->assertSuccessful();
    $this->artisan('metkurd:production-preflight', ['--require-epoch' => true])->assertFailed();
    epochAudit();
    $this->artisan('metkurd:production-preflight', ['--require-epoch' => true])->assertSuccessful();
    $this->artisan('metkurd:production-preflight', ['--production' => true])->assertFailed();
    expect(AdminAuditEvent::count())->toBe(1);
    Http::assertNothingSent();
});
