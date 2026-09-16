<?php

use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\AdminOperation;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\ServicePlanAgreement;
use App\Models\SubscriptionCreditAllocation;
use App\Models\User;
use App\Services\Admin\AdminCatalogDeletion;
use App\Services\Admin\AdminServiceAgreements;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\ExpireSubscription;
use App\Services\Billing\ServiceAgreementLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 13)->setTime(10, 0));
    config(['metkurd_v2.enabled' => true, 'fib.reconciliation.enabled' => false]);
    $this->admin = User::forceCreate(['name' => 'Agreement Operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.finance']]);
    $this->customer = Customer::create(['username' => 'agreement_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $this->actingAs($this->admin, 'admin');
    foreach (['app', 'api'] as $channel) {
        CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', $channel)->update(['addon_balance_credits' => 19, 'subscription_balance_credits' => 10, 'balance_credits' => 29]);
    }
    $this->recordAgreement = fn ($start = '2026-09-13', $expiry = '2027-09-03', $id = null) => app(AdminServiceAgreements::class)->record(
        $id ?? (string) Str::uuid(), $this->customer->id, $this->plan->id, $start, $expiry, 115200, 'AGENCY-FIXTURE', 'Agency agreement approved; collections handled externally.');
});

it('records a dated agreement with one initial allocation audit and no payment or revenue', function () {
    $money = collect(['payments', 'payment_events', 'credit_orders', 'payment_intents'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    $prices = $this->plan->getRawOriginal();
    $id = (string) Str::uuid();
    $result = ($this->recordAgreement)('2026-09-13', '2027-09-03', $id);
    $agreement = ServicePlanAgreement::findOrFail($result['agreement_id']);
    expect($agreement->status)->toBe('active')->and($agreement->starts_at->format('Y-m-d H:i:s'))->toBe('2026-09-13 00:00:00')
        ->and($agreement->ends_at->format('Y-m-d H:i:s'))->toBe('2027-09-04 00:00:00')
        ->and($agreement->subscription->payment_id)->toBeNull()->and($agreement->subscription->auto_renew)->toBeFalse()
        ->and($agreement->subscription->source)->toBe(ServiceAgreementLifecycle::SOURCE)
        ->and($agreement->agreed_amount_iqd)->toBe(115200);
    foreach (['app' => $this->plan->appMonthlyCredits(), 'api' => $this->plan->apiMonthlyCredits()] as $channel => $credits) {
        $wallet = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', $channel)->firstOrFail();
        expect($wallet->subscription_balance_credits)->toBe($credits)->and($wallet->addon_balance_credits)->toBe(19)
            ->and($wallet->balance_credits)->toBe($credits + 19)->and(strlen($wallet->current_cycle_key))->toBe(7);
    }
    ($this->recordAgreement)('2026-09-13', '2027-09-03', $id);
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    $this->artisan('credits:refill-monthly', ['--customer' => $this->customer->id])->assertSuccessful();
    expect(ServicePlanAgreement::count())->toBe(1)->and(SubscriptionCreditAllocation::where('subscription_id', $agreement->subscription_id)->count())->toBe(1)
        ->and(CreditLedger::where('source_type', ServiceAgreementLifecycle::SOURCE)->count())->toBe(2)
        ->and(AdminAuditEvent::where('operation_id', $id)->where('action', 'agreement.record')->count())->toBe(1)
        ->and(AdminAuditEvent::where('operation_id', $id)->where('action', 'agreement.activated')->count())->toBe(1)
        ->and($this->plan->fresh()->getRawOriginal())->toBe($prices);
    foreach ($money as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
});

it('does not change current access before a future start and activates when due without FIB', function () {
    $before = DB::table('credit_wallets')->where('customer_id', $this->customer->id)->get()->toJson();
    $subCount = $this->customer->serviceSubscriptions()->count();
    $result = ($this->recordAgreement)('2026-10-13', '2027-09-03');
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect($result['status'])->toBe('scheduled')->and($this->customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and($this->customer->serviceSubscriptions()->count())->toBe($subCount)
        ->and(DB::table('credit_wallets')->where('customer_id', $this->customer->id)->get()->toJson())->toBe($before);
    $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
    $this->artisan('billing:process-service-agreements', ['--dry-run' => true])->assertSuccessful();
    expect(ServicePlanAgreement::find($result['agreement_id'])->subscription_id)->toBeNull();
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect($this->customer->fresh()->currentServicePlan()->code)->toBe('pro');
    Http::assertNothingSent();
});

it('resets monthly without rollover or missed-month stacking and expires through shared expiry', function () {
    $result = ($this->recordAgreement)();
    $agreement = ServicePlanAgreement::find($result['agreement_id']);
    CreditWallet::where('customer_id', $this->customer->id)->update(['subscription_balance_credits' => 7, 'balance_credits' => 26]);
    $this->travelTo(now()->setDate(2026, 10, 12)->endOfDay());
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect($this->customer->fresh()->wallet->subscription_balance_credits)->toBe(7);
    $this->travelTo(now()->setDate(2026, 12, 15)->startOfDay());
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect(SubscriptionCreditAllocation::where('subscription_id', $agreement->subscription_id)->count())->toBe(2)
        ->and($this->customer->fresh()->wallet->subscription_balance_credits)->toBe($agreement->app_monthly_credits);
    $this->travelTo($agreement->ends_at->copy()->subSecond());
    expect($this->customer->fresh()->currentServicePlan()->code)->toBe('pro');
    $this->travelTo($agreement->ends_at);
    expect($this->customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and(app(CustomerBillingStateService::class)->servicePlanState($this->customer->fresh())['current_plan']->code)->toBe('free');
    // The pre-existing expiry entry point must clear the agreement bucket too.
    app(ExpireSubscription::class)->handle($agreement->subscription);
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect($this->customer->fresh()->currentServicePlan()->code)->toBe('free')->and($agreement->fresh()->status)->toBe('ended');
    foreach (['wallet', 'apiWallet'] as $relation) {
        expect($this->customer->fresh()->$relation->subscription_balance_credits)->toBe(0)
            ->and($this->customer->fresh()->$relation->addon_balance_credits)->toBe(19);
    }
    expect(CreditLedger::where('source_type', ServiceAgreementLifecycle::SOURCE)->count())->toBe(6);
    Http::assertNothingSent();
});

it('uses anniversary dates correctly across short months', function () {
    $this->travelTo(now()->setDate(2026, 1, 31)->setTime(10, 0));
    $this->customer->serviceSubscriptions()->update(['starts_at' => '2026-01-01 00:00:00']);
    $result = ($this->recordAgreement)('2026-01-31', '2026-04-01');
    foreach (['2026-02-28', '2026-03-31'] as $date) {
        $this->travelTo(\Carbon\Carbon::parse($date, config('app.timezone')));
        $this->artisan('billing:process-service-agreements')->assertSuccessful();
    }
    $agreement = ServicePlanAgreement::find($result['agreement_id']);
    expect(SubscriptionCreditAllocation::where('subscription_id', $agreement->subscription_id)->orderBy('id')->get()->map(fn ($a) => $a->cycle_started_at->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('rejects authorization failures and intent payload changes', function (string $case) {
    if ($case === 'capability') {
        $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    } else {
        $this->admin->update(['status' => 0]);
    }
    expect(fn () => ($this->recordAgreement)())->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    expect(ServicePlanAgreement::count())->toBe(0);
})->with(['capability', 'inactive']);

it('rejects overlap invalid dates missing reason and operation reuse for a different deal', function () {
    expect(fn () => ($this->recordAgreement)('2027-09-03', '2026-09-13'))->toThrow(ValidationException::class);
    expect(fn () => ($this->recordAgreement)('2020-01-01', '2020-02-01'))->toThrow(ValidationException::class);
    expect(fn () => app(AdminServiceAgreements::class)->record((string) Str::uuid(), $this->customer->id, $this->plan->id,
        '2026-09-13', '2027-09-03', null, 'fixture', ''))->toThrow(ValidationException::class);
    $id = (string) Str::uuid();
    ($this->recordAgreement)('2026-09-13', '2027-09-03', $id);
    expect(fn () => ($this->recordAgreement)('2026-09-13', '2027-09-04', $id))->toThrow(ValidationException::class);
    expect(fn () => ($this->recordAgreement)())->toThrow(ValidationException::class);
    expect(ServicePlanAgreement::count())->toBe(1);
});

it('retains review blockers and permits only an authorized owned explicit retry', function () {
    $payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib',
        'status' => 'pending', 'internal_status' => 'requires_review', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring',
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $this->plan->id, 'amount' => 100, 'currency' => 'IQD']);
    $result = ($this->recordAgreement)();
    expect($result['status'])->toBe('requires_review')->and($this->customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and(SubscriptionCreditAllocation::count())->toBe(0);
    $other = Customer::create(['username' => Str::random(15), 'email' => Str::uuid().'@example.test', 'password' => 'fixture']);
    expect(fn () => app(AdminServiceAgreements::class)->retry((string) Str::uuid(), $other->id, $result['agreement_id'], 'Reviewed conflict and retry requested.'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $payment->update(['status' => 'expired', 'internal_status' => 'expired']); // Simulated operator resolution, test fixture only.
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect(ServicePlanAgreement::find($result['agreement_id'])->status)->toBe('requires_review');
    app(AdminServiceAgreements::class)->retry((string) Str::uuid(), $this->customer->id, $result['agreement_id'], 'Reviewed conflict and retry requested.');
    expect($this->customer->fresh()->currentServicePlan()->code)->toBe('pro');
    Http::assertNothingSent();
});

it('protects reserved plans and prevents online replacement or cancellation of an external agreement', function () {
    ($this->recordAgreement)();
    expect(app(AdminCatalogDeletion::class)->hasDependencies($this->plan))->toBeTrue();
    expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($this->customer, ServicePlan::where('code', 'premium')->value('id')))->toThrow(ValidationException::class);
    expect(fn () => app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($this->customer))->toThrow(ValidationException::class);
    expect(app(CustomerBillingStateService::class)->servicePlanState($this->customer->fresh())['cancelable'])->toBeFalse();
    Http::assertNothingSent();
});

it('renders the form history and customer explanation in each locale with server authorization', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::test('admin::pages.customers.adm-customers-register')->set('customerFilter', (string) $this->customer->id)
        ->set('agreementPlanId', (string) $this->plan->id)->set('agreementStart', '2026-09-13')->set('agreementExpiry', '2027-09-03')
        ->set('agreementAmount', '115200')->set('agreementReference', 'AGENCY-PRIVATE-REFERENCE')
        ->set('agreementReason', 'External agency agreement approved for access.')
        ->assertSee(__('agreement.title'))->assertSee('data-admin-method="recordServiceAgreement"', false)
        ->call('recordServiceAgreement')->assertHasNoErrors()->assertSee('2027-09-03');
    $this->actingAs($this->customer, 'app');
    foreach (['billing', 'subscription-plans'] as $route) {
        $this->get(route('app.v2.'.$route, ['locale' => $locale]))->assertOk()->assertSee(__('agreement.customer_help'))
            ->assertDontSee('AGENCY-PRIVATE-REFERENCE')->assertDontSee('recordServiceAgreement');
    }
    $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $page->call('recordServiceAgreement')->assertForbidden();
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('rolls back both wallets and allocation history on a partial failure and retains retry identity', function () {
    $id = (string) Str::uuid();
    $before = $this->customer->fresh()->wallet->getRawOriginal();
    $api = $this->customer->fresh()->apiWallet->getAttributes();
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->delete();
    expect(fn () => ($this->recordAgreement)('2026-09-13', '2027-09-03', $id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(ServicePlanAgreement::count())->toBe(0)->and(SubscriptionCreditAllocation::count())->toBe(0)
        ->and($this->customer->fresh()->wallet->getRawOriginal())->toBe($before)
        ->and(AdminOperation::find($id)->status)->toBe('pending');
    CreditWallet::forceCreate($api);
    ($this->recordAgreement)('2026-09-13', '2027-09-03', $id);
    expect(ServicePlanAgreement::count())->toBe(1)->and(AdminOperation::find($id)->status)->toBe('completed');
});

it('does not replace an active paid subscription or modify its history', function () {
    $existing = $this->customer->activeServiceSubscription()->firstOrFail();
    $existing->update(['service_plan_id' => $this->plan->id, 'source' => 'fib', 'meta' => ['revenue_record' => true]]);
    $before = $existing->fresh()->getRawOriginal();
    $result = ($this->recordAgreement)();
    expect($result['status'])->toBe('requires_review')->and($existing->fresh()->getRawOriginal())->toBe($before)
        ->and(SubscriptionCreditAllocation::count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves the new owners wallet after another authorized plan supersedes the agreement', function () {
    $result = ($this->recordAgreement)();
    $agreement = ServicePlanAgreement::find($result['agreement_id']);
    app(\App\Services\Admin\AdminFinancialCorrections::class)->plan((string) Str::uuid(), $this->customer->id,
        ServicePlan::where('code', 'premium')->value('id'), 'monthly', 'A separate authorized internal plan assignment.');
    $before = DB::table('credit_wallets')->where('customer_id', $this->customer->id)->get()->toJson();
    $this->travelTo($agreement->ends_at);
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect(ServicePlanAgreement::find($agreement->id)->status)->toBe('ended')
        ->and(DB::table('credit_wallets')->where('customer_id', $this->customer->id)->get()->toJson())->toBe($before);
});

it('freezes monthly allowances and does not depend on later catalog price or credit edits', function () {
    $expected = $this->plan->appMonthlyCredits();
    $result = ($this->recordAgreement)('2026-10-13', '2027-09-03');
    $this->plan->update(['app_monthly_credits' => 123, 'price_iqd_monthly' => 999]);
    $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect($this->customer->fresh()->wallet->subscription_balance_credits)->toBe($expected)
        ->and(ServicePlanAgreement::find($result['agreement_id'])->agreed_amount_iqd)->toBe(115200);
});

it('renders safely before the new schema is installed and never runs the migration automatically', function () {
    \Illuminate\Support\Facades\Schema::drop('service_plan_agreements');
    Livewire::test('admin::pages.customers.adm-customers-register')->set('customerFilter', (string) $this->customer->id)
        ->assertSee(__('agreement.migration'))->assertDontSee('data-admin-method="recordServiceAgreement"', false);
    expect(fn () => ($this->recordAgreement)())->toThrow(ValidationException::class);
    $this->artisan('billing:process-service-agreements')->assertSuccessful();
    expect(\Illuminate\Support\Facades\Schema::hasTable('service_plan_agreements'))->toBeFalse();
});

it('keeps agreement translations complete with matching replacement tokens', function () {
    $english = require resource_path('lang/en/agreement.php');
    foreach (['ar', 'ku'] as $locale) {
        $catalog = require resource_path('lang/'.$locale.'/agreement.php');
        expect(array_keys($catalog))->toBe(array_keys($english));
        foreach ($english as $key => $message) {
            preg_match_all('/:[a-z_]+/', $message, $expected);
            preg_match_all('/:[a-z_]+/', $catalog[$key], $actual);
            expect($actual[0])->toBe($expected[0]);
        }
    }
});

it('saves only explicitly approved API scopes with fresh authorization and retained audit', function () {
    $this->admin->profile()->create(['first_name' => 'Scope', 'last_name' => 'Operator']);
    $this->admin->forceFill(['admin_capabilities' => ['admin.read', 'admin.pricing']])->save();
    $before = $this->plan->fresh()->getRawOriginal();
    $legacy = ['tts:apollo-1-5v'];
    $scopes = [...$legacy, ...array_map(fn ($service) => 'v2:'.$service, \App\Services\CustomerApi\V2\ApiCatalog::SERVICES)];
    $component = Livewire::test('admin::pages.payments.adm-payments-plans')->assertOk()
        ->call('openEditPlanModal', $this->plan->id)->assertOk()->assertSet('editingPlanId', $this->plan->id)->set('apiAllowedToolsText', implode("\n", $scopes))
        ->set('priceIqdMonthly', 1)->set('appMonthlyCredits', 1)->set('adminChangeReason', 'Approved V2 API service scope configuration')->call('saveApiScopes')->assertOk()->assertHasNoErrors()->assertDispatched('alert', type: 'success', message: __('admin_p1.scopes_saved'));
    $after = $this->plan->fresh()->getRawOriginal();
    foreach (array_diff(array_keys($before), ['api_allowed_tools', 'meta', 'updated_at']) as $key) {
        expect($after[$key])->toBe($before[$key], $key);
    }
    expect(app(\App\Services\Admin\AdminEntitlementScopes::class)->explicit($this->plan->fresh()))->toBe($scopes)
        ->and(AdminAuditEvent::where('admin_id', $this->admin->id)->where('target_type', ServicePlan::class)->where('target_id', $this->plan->id)->where('action', 'updated')->exists())->toBeTrue();
    $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $component->set('apiAllowedToolsText', 'v2:*')->call('saveApiScopes')->assertForbidden();
});

it('explains legacy-only Pro scopes without silently widening API permissions', function () {
    ($this->recordAgreement)();
    $this->plan->update(['api_enabled' => true, 'api_requests_per_minute' => 300, 'api_allowed_tools' => ['tts:apollo-1-5v']]);
    $catalog = app(\App\Services\CustomerApi\V2\ApiCatalog::class);
    expect($catalog->hasAccess($this->customer))->toBeTrue()->and($catalog->scopes($this->customer))->toBe([])
        ->and($catalog->keyAccessMessage($this->customer))->toBe(__('account_v2.api_scopes_missing'));
    $this->actingAs($this->customer, 'app');
    Livewire::test('app::v2.pages.api.app-api')->set('keyName', 'Must not issue')->call('createKey')->assertHasErrors('keyName')
        ->assertSee(__('account_v2.api_scopes_missing'));
    expect(\App\Models\CustomerApiKey::where('customer_id', $this->customer->id)->count())->toBe(0)
        ->and($this->plan->fresh()->api_allowed_tools)->toBe(['tts:apollo-1-5v']);
    Http::assertNothingSent();
});

it('uses the normalized current subscription despite partial stale and synthetic plan state', function () {
    $stale = $this->customer->fresh(['servicePlan:service_plans.id,service_plans.name', 'activeServiceSubscription.servicePlan:id,name']);
    expect($stale->currentServicePlan()->code)->toBe('free');
    $result = ($this->recordAgreement)();
    $agreement = ServicePlanAgreement::findOrFail($result['agreement_id']);
    $stale->setAttribute('service_plan_id', ServicePlan::where('code', 'free')->value('id'));
    expect($stale->currentServicePlan()->code)->toBe('pro')->and($stale->currentServicePlanId())->toBe($this->plan->id)
        ->and($stale->servicePlan->api_enabled)->toBeTrue();
    $future = $stale->serviceSubscriptions()->create(['service_plan_id' => ServicePlan::where('code', 'premium')->value('id'), 'status' => 'active', 'starts_at' => now()->addMonth(), 'source' => 'fixture']);
    expect($stale->fresh()->activeServiceSubscription->id)->toBe($agreement->subscription_id)
        ->and($stale->fresh()->servicePlan->code)->toBe('pro')
        ->and($stale->currentServicePlan()->code)->toBe('pro');
    $future->update(['status' => 'ended']);
    $this->travelTo($agreement->ends_at);
    expect($stale->currentServicePlan()->code)->toBe('free')->and($stale->servicePlan->code)->toBe('free');
});

it('keeps App Admin API and monthly allowances consistent through a scheduled agreement activation and expiry', function () {
    $this->plan->update(['api_enabled' => true, 'api_requests_per_minute' => 300, 'api_allowed_tools' => ['v2:*']]);
    $this->actingAs($this->customer, 'app');
    $stale = $this->customer->fresh(['servicePlan:service_plans.id,service_plans.name', 'wallet', 'apiWallet']);
    $result = ($this->recordAgreement)('2026-10-13', '2026-11-12');
    $agreement = ServicePlanAgreement::findOrFail($result['agreement_id']);
    $check = function ($code, $apiEnabled, $allowances) use ($stale) {
        $state = $stale->servicePlanState();
        expect($state['current_plan']->code)->toBe($code)->and($stale->currentServicePlan()->code)->toBe($code);
        $admin = app(\App\Services\Admin\AdminOperations::class)->customer($stale->id);
        expect($admin['plan']['plan_id'])->toBe($state['current_plan_id'])
            ->and($admin['api_access']['api_enabled'])->toBe($apiEnabled);
        $catalog = Livewire::test('app::v2.pages.account.subscription-plans')->assertOk()->get('catalog');
        expect($catalog['current']->code)->toBe($code)->and($catalog['items']->firstWhere('same', true)['id'])->toBe($state['current_plan_id']);
        $billing = Livewire::test('app::v2.pages.account.app-billing')->assertOk()->get('dashboard');
        expect($billing['state']['current_plan']->code)->toBe($code);
        $profile = Livewire::test('app::v2.pages.account.app-profile')->assertOk();
        expect($profile->get('serviceState')['current_plan']->code)->toBe($code);
        expect(app(\App\Services\CustomerApi\V2\ApiCatalog::class)->scopes($stale) !== [])->toBe($apiEnabled);
        foreach ($allowances as $type => $amount) {
            expect($admin[$type.'_credits']['allowance'])->toBe($amount)
                ->and(app(\App\Services\Billing\CustomerUsageSummaryService::class)->forCustomer($stale, $type)['credits']['monthly'])->toBe($amount);
        }
    };
    $check('free', false, ['app' => 10000, 'api' => 0]);
    Livewire::test('app::v2.pages.account.subscription-plans')->assertSee(__('agreement.scheduled'));
    $this->travelTo($agreement->starts_at);
    app(ServiceAgreementLifecycle::class)->process($agreement->id);
    $check('pro', true, ['app' => $agreement->app_monthly_credits, 'api' => $agreement->api_monthly_credits]);
    // A later catalog allowance edit must not rewrite the approved agreement snapshot.
    $this->plan->update(['app_monthly_credits' => 123, 'api_monthly_credits' => 456]);
    $check('pro', true, ['app' => $agreement->app_monthly_credits, 'api' => $agreement->api_monthly_credits]);
    Livewire::test('app::v2.pages.api.app-api')->set('keyName', 'Isolated agreement key')->call('createKey')->assertHasNoErrors()->assertDispatched('api-key-created');
    $this->travelTo($agreement->ends_at);
    $check('free', false, ['app' => 10000, 'api' => 0]);
    app(ServiceAgreementLifecycle::class)->process($agreement->id);
    $check('free', false, ['app' => 10000, 'api' => 0]);
    foreach (['app', 'api'] as $type) {
        $wallet = CreditWallet::where('customer_id', $stale->id)->where('wallet_type', $type)->first();
        expect($wallet->subscription_balance_credits)->toBe(0)->and($wallet->addon_balance_credits)->toBe(19);
    }
    expect(Payment::count())->toBe(0)->and(DB::table('credit_orders')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keys entitlement caches by the effective plan across activation and expiry', function () {
    $action = \App\Models\ToolAction::where('full_code', 'leo.transcribe')->firstOrFail();
    foreach (['free' => false, 'pro' => true] as $code => $allowed) {
        \App\Models\PlanEntitlement::updateOrCreate(['service_plan_id' => ServicePlan::where('code', $code)->value('id'), 'tool_action_id' => $action->id, 'entitlement_channel' => 'api'], ['allowed' => $allowed]);
    }
    expect($this->customer->isAllowed('leo.transcribe', 'api'))->toBeFalse();
    $result = ($this->recordAgreement)();
    expect($this->customer->isAllowed('leo.transcribe', 'api'))->toBeTrue();
    $this->travelTo(ServicePlanAgreement::find($result['agreement_id'])->ends_at);
    expect($this->customer->isAllowed('leo.transcribe', 'api'))->toBeFalse();
});

it('does not reuse the App shell plan cache across agreement activation or the expiry boundary', function () {
    $this->actingAs($this->customer, 'app');
    $shell = app(\App\Support\AppShellData::class);
    expect($shell->forCurrentCustomer()['plan_code'])->toBe('free');
    $result = ($this->recordAgreement)();
    expect($shell->forCurrentCustomer()['plan_code'])->toBe('pro')
        ->and($shell->forCurrentCustomer()['app_credits']['monthly'])->toBe($this->plan->appMonthlyCredits());
    $this->travelTo(ServicePlanAgreement::find($result['agreement_id'])->ends_at);
    expect($shell->forCurrentCustomer()['plan_code'])->toBe('free');
    Http::assertNothingSent();
});
