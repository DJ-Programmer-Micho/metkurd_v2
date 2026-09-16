<?php

use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditProduct;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\CustomerPurchaseCheckout;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Mail::fake();
    Notification::fake();
    Http::preventStrayRequests();
    $this->seed();
    config(['metkurd_v2.enabled' => true]);
    $this->customer = Customer::create(['username' => 'purchase_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CustomerProfile::create(['customer_id' => $this->customer->id, 'first_name' => 'Purchase', 'last_name' => 'Fixture']);
    $this->actingAs($this->customer->fresh(), 'app');
    // Availability is mocked only in this UI suite; existing FIB suites exercise real configuration rules.
    $method = new \App\Models\PaymentMethod(['code' => 'fib', 'name' => 'FIB', 'driver' => 'fib', 'supports_recurring' => true, 'is_active' => true, 'is_visible' => true]);
    $catalog = Mockery::mock(PaymentMethodCatalog::class)->makePartial();
    $catalog->shouldReceive('availableForPurpose')->andReturn(new \Illuminate\Database\Eloquent\Collection([$method]));
    app()->instance(PaymentMethodCatalog::class, $catalog);
});

it('renders all V2 purchase pages in each locale without payment creation', function ($locale) {
    $before = Payment::count();
    foreach (['subscription-plans', 'storage-plans', 'addon-credits'] as $page) {
        $this->get(route('app.v2.'.$page, ['locale' => $locale]))->assertOk()->assertSee('v2-purchase-grid', false)->assertDontSee('purchase_v2.');
    }
    expect(Payment::count())->toBe($before);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('automatically selects the only service payment mode without an all option', function (string $locale, string $mode) {
    app()->setLocale($locale);
    ServicePlan::where('is_active', true)->where('is_free', false)->update(['payment_mode' => $mode]);
    Livewire::test('app::v2.pages.account.subscription-plans')
        ->assertSet('mode', $mode)->assertSee('data-purchase-mode-readonly', false)
        ->assertSee(__('purchase_v2.'.$mode))->assertDontSee('id="purchase-mode"', false)
        ->assertDontSee(__('purchase_v2.all_modes'));
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku'])->with(['recurring', 'one_time']);

it('keeps the mode filter when multiple service payment modes are available', function () {
    ServicePlan::where('code', 'student')->update(['payment_mode' => 'one_time']);
    ServicePlan::where('code', 'pro')->update(['payment_mode' => 'recurring']);
    Livewire::test('app::v2.pages.account.subscription-plans')->assertSet('mode', 'all')
        ->assertSee('id="purchase-mode"', false)->assertSee(__('purchase_v2.all_modes'));
});

it('explains an invalid recurring callback before creating any payment or contacting FIB', function (string $locale) {
    app()->setLocale($locale);
    config(['fib.environment' => 'staging', 'fib.callback_base_url' => 'http://127.0.0.1:8000']);
    $plan = ServicePlan::where('code', 'student')->firstOrFail();
    $plan->update(['payment_mode' => 'recurring']);
    $before = [];
    foreach (['payments', 'payment_events', 'coupon_redemptions', 'credit_wallets', 'credit_ledgers'] as $table) {
        $before[$table] = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson();
    }
    $page = Livewire::test('app::v2.pages.account.subscription-plans')->call('select', $plan->id);
    // Repeating a rejected setup attempt must not accumulate failed Payments.
    foreach ([1, 2] as $_) {
        $page->call('purchase')->assertHasErrors(['checkout'])
            ->assertSee(__('purchase_v2.callback_unavailable'))->assertDontSee(__('purchase_v2.failed'));
    }
    foreach ($before as $table => $rows) {
        expect(\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

function purchaseEpochFixture(\App\Models\Customer $customer): Payment
{
    $old = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'internal_status' => 'requires_review', 'status' => 'pending', 'local_reference' => Str::uuid(),
        'idempotency_key' => Str::uuid(), 'amount' => 12000, 'currency' => 'IQD',
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => ServicePlan::where('code', 'student')->value('id')]);
    \App\Models\CreditOrder::create(['customer_id' => $customer->id, 'order_type' => 'subscription', 'status' => 'pending', 'base_amount_iqd' => 12000]);
    \App\Models\AdminAuditEvent::create(['action' => \App\Services\Billing\BillingReportingBoundary::ACTION,
        'target_type' => \App\Services\Billing\PaymentDomainCutover::class, 'target_id' => (string) Str::uuid(),
        'after_state' => ['reporting_boundary' => ['starts_at' => now()->toDateTimeString(),
            'credit_order_id' => \App\Models\CreditOrder::max('id'), 'payment_id' => $old->id]]]);

    return $old;
}

it('creates a Student FIB checkout after cutover without letting archived history block or change', function (bool $retainPayment) {
    config(['fib.callback_base_url' => 'https://metkurd.test']);
    $old = purchaseEpochFixture($this->customer);
    if (! $retainPayment) {
        $old->delete();
    }
    $oldState = $retainPayment ? $old->fresh()->getAttributes() : null;
    expect(Payment::currentBillingPeriod()->count())->toBe(0);
    $before = [];
    foreach (['credit_orders', 'credit_wallets', 'credit_ledgers'] as $table) {
        $before[$table] = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson();
    }
    $plan = ServicePlan::where('code', 'student')->firstOrFail();
    $plan->update(['payment_mode' => 'recurring']);
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibSubscriptionClient::class);
    $client->shouldReceive('createSubscription')->once()->andReturn(
        \App\Domain\Payments\Data\FibCreateSubscriptionResponseData::fromArray([
            'subscriptionId' => (string) Str::uuid(), 'status' => 'DRAFT', 'qrCode' => 'fixture-qr',
            'readableCode' => 'FIXTURE', 'validUntil' => now()->addHour()->toIso8601String(),
        ])
    );
    app()->instance(\App\Domain\Payments\Fib\FibSubscriptionClient::class, $client);
    $page = Livewire::test('app::v2.pages.account.subscription-plans')->call('select', $plan->id)
        ->call('purchase')->assertHasNoErrors();
    $created = Payment::currentBillingPeriod()->sole();
    $page->assertRedirect(route('app.v2.payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $created]));
    expect($created->purchasable_id)->toBe($plan->id)->and($created->customer_id)->toBe($this->customer->id)
        ->and($created->status->value)->toBe('awaiting_customer_action')
        ->and(app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->state($created))->toBe('awaiting')
        ->and($created->fulfilled_at)->toBeNull();
    // A repeat click reuses the current checkout rather than creating another provider object.
    $page->call('purchase')->assertHasNoErrors();
    expect(Payment::currentBillingPeriod()->count())->toBe(1);
    if ($retainPayment) {
        expect($old->fresh()->getAttributes())->toBe($oldState);
    }
    foreach ($before as $table => $rows) {
        expect(\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    Http::assertNothingSent();
})->with([true, false]);

it('still directs a current epoch review payment to review without another provider request', function () {
    $old = purchaseEpochFixture($this->customer);
    $current = $old->replicate(['uuid', 'local_reference', 'idempotency_key']);
    $current->fill(['uuid' => Str::uuid(), 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid()])->save();
    config(['fib.callback_base_url' => 'http://127.0.0.1:8000']);
    $before = Payment::count();
    Livewire::test('app::v2.pages.account.subscription-plans')->assertSee(__('payment_v2.block_review'))
        ->call('select', $current->purchasable_id)->call('purchase')
        ->assertRedirect(route('app.v2.payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $current]));
    expect(Payment::count())->toBe($before)
        ->and($current->fresh()->internal_status->value)->toBe('requires_review');
    Http::assertNothingSent();
});

it('keeps authentication and feature gating', function () {
    auth('app')->logout();
    $this->get('/en/app-v2/subscription-plans')->assertRedirect(route('app.signin'));
    $this->actingAs($this->customer, 'app');
    config(['metkurd_v2.enabled' => false]);
    $this->get('/en/app-v2/storage-plans')->assertNotFound();
});

it('shares the exact switch allowance preview and preserves both add-on buckets', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->update(['subscription_balance_credits' => 71, 'addon_balance_credits' => 23, 'balance_credits' => 94]);
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['subscription_balance_credits' => 91, 'addon_balance_credits' => 37, 'balance_credits' => 128]);
    $page = Livewire::test('app::v2.pages.account.subscription-plans')->call('select', $plan->id)->assertSee(__('purchase_v2.credit_policy'));
    $preview = $page->get('catalog')['selected']['preview'];
    app(PlanSwitcher::class)->switchServicePlan($this->customer, $plan->id, ['provider' => 'fixture']);
    foreach (['app', 'api'] as $channel) {
        $wallet = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', $channel)->firstOrFail();
        expect($wallet->subscription_balance_credits)->toBe($preview[$channel]['subscription'])->and($wallet->addon_balance_credits)->toBe($preview[$channel]['addon'])->and($wallet->balance_credits)->toBe($preview[$channel]['total']);
    }
});

it('passes configured mode interval and method without a coupon to the existing plan action', function ($mode, $cycle) {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $plan->update(['payment_mode' => $mode, 'billing_intervals' => ['monthly', 'yearly']]);
    $action = Mockery::mock(CreatePlanSubscriptionPayment::class);
    $action->shouldReceive('handle')->once()->with(Mockery::on(fn ($c) => $c->id === $this->customer->id), $plan->id, $cycle, null, 'fib', true)->andReturn(new Payment(['uuid' => Str::uuid()]));
    app()->instance(CreatePlanSubscriptionPayment::class, $action);
    Livewire::test('app::v2.pages.account.subscription-plans')->set('cycle', $cycle)->call('select', $plan->id)->assertDontSee('purchase-coupon', false)->call('purchase')->assertHasNoErrors();
})->with([['recurring', 'monthly'], ['recurring', 'yearly'], ['one_time', 'monthly']]);

it('rejects mode tampering and same-plan checkout in the server entry', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $service = app(CustomerPurchaseCheckout::class);
    expect(fn () => $service->start($this->customer, 'service', $plan->id, 'monthly', 'one_time', 'fib', null))->toThrow(\Illuminate\Validation\ValidationException::class);
    app(PlanSwitcher::class)->switchServicePlan($this->customer, $plan->id, ['provider' => 'fixture']);
    expect(fn () => $service->start($this->customer, 'service', $plan->id, 'monthly', $plan->checkoutPaymentModeValue(), 'fib', null))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('reuses an owned pending payment and refuses concurrent creation', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $pending = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'internal_status' => 'pending', 'status' => 'pending', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 100, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class, 'purchasable_id' => $plan->id]);
    $service = app(CustomerPurchaseCheckout::class);
    expect($service->start($this->customer, 'service', $plan->id, 'monthly', 'recurring', 'fib', null)->id)->toBe($pending->id);
    Http::assertNothingSent();
    $lock = Cache::lock('customer-purchase:'.$this->customer->id.':service', 300);
    $lock->get();
    try {
        expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($this->customer, $plan->id, 'monthly', null, 'fib'))->toThrow(\Illuminate\Validation\ValidationException::class);
    } finally {
        $lock->release();
    }
    expect(Payment::count())->toBe(1);
});

it('warns on storage reduction and blocks replacement of an unretired provider subscription', function () {
    $current = StoragePlan::create(['code' => 'fixture-large', 'name' => 'Large', 'quota_mb' => 20000, 'price_iqd' => 20000, 'payment_mode' => 'recurring', 'billing_intervals' => ['monthly'], 'is_active' => true]);
    $target = StoragePlan::create(['code' => 'fixture-small', 'name' => 'Small', 'quota_mb' => 1000, 'price_iqd' => 5000, 'payment_mode' => 'recurring', 'billing_intervals' => ['monthly'], 'is_active' => true]);
    app(PlanSwitcher::class)->switchStoragePlan($this->customer, $current->id, ['provider' => 'fixture']);
    $this->customer->usage()->update(['storage_used_bytes' => $current->quota_mb * 1048576]);
    Livewire::test('app::v2.pages.account.storage-plans')->call('select', $target->id)->assertSee(__('purchase_v2.quota_warning'));
    Payment::create(['uuid' => Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib', 'purchase_type' => 'storage_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription', 'fib_subscription_id' => Str::uuid(), 'provider_subscription_status' => 'ACTIVE', 'fulfilled_at' => now(), 'internal_status' => 'applied', 'status' => 'paid', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 100, 'currency' => 'IQD', 'purchasable_type' => StoragePlan::class, 'purchasable_id' => $current->id]);
    $service = app(CustomerPurchaseCheckout::class);
    expect($service->storageReplacementBlocked($this->customer))->toBeTrue();
    expect(fn () => $service->start($this->customer, 'storage', $target->id, 'monthly', $target->checkoutPaymentModeValue(), 'fib', null))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('uses the separate storage and one-time addon actions', function () {
    $plan = StoragePlan::create(['code' => 'fixture-storage', 'name' => 'Storage', 'quota_mb' => 1000, 'price_iqd' => 5000, 'payment_mode' => 'one_time', 'billing_intervals' => ['monthly'], 'is_active' => true]);
    $action = Mockery::mock(CreateStorageSubscriptionPayment::class);
    $action->shouldReceive('handle')->once()->andReturn(new Payment(['uuid' => Str::uuid()]));
    app()->instance(CreateStorageSubscriptionPayment::class, $action);
    Livewire::test('app::v2.pages.account.storage-plans')->call('select', $plan->id)->call('purchase')->assertHasNoErrors();
    app(PlanSwitcher::class)->switchServicePlan($this->customer, ServicePlan::where('code', 'pro')->value('id'), ['provider' => 'fixture']);
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $addon = Mockery::mock(CreateAddonPayment::class);
    $addon->shouldReceive('handle')->once()->with(Mockery::on(fn ($c) => $c->id === $this->customer->id), $product->id, null, true)->andReturn(new Payment(['uuid' => Str::uuid()]));
    app()->instance(CreateAddonPayment::class, $addon);
    Livewire::test('app::v2.pages.account.addon-credits')->call('select', $product->id)->call('purchase')->assertHasNoErrors();
});

it('shows unavailable methods and sanitized checkout failures', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $action = Mockery::mock(CreatePlanSubscriptionPayment::class);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('SECRET-PROVIDER-PAYLOAD'));
    app()->instance(CreatePlanSubscriptionPayment::class, $action);
    Livewire::test('app::v2.pages.account.subscription-plans')->call('select', $plan->id)->call('purchase')->assertHasErrors(['checkout'])->assertDontSee('SECRET-PROVIDER-PAYLOAD');
    $methods = Mockery::mock(PaymentMethodCatalog::class)->makePartial();
    $methods->shouldReceive('availableForPurpose')->andReturn(new \Illuminate\Database\Eloquent\Collection);
    app()->instance(PaymentMethodCatalog::class, $methods);
    Livewire::test('app::v2.pages.account.subscription-plans')->assertSee(__('purchase_v2.unavailable'));
});

it('rejects unsupported yearly one-time storage fulfillment and exposes the reason', function () {
    $plan = StoragePlan::create(['code' => 'fixture-yearly', 'name' => 'Yearly storage', 'quota_mb' => 1000, 'price_iqd' => 5000, 'payment_mode' => 'one_time', 'billing_intervals' => ['monthly', 'yearly'], 'is_active' => true]);
    Livewire::test('app::v2.pages.account.storage-plans')->set('cycle', 'yearly')->call('select', $plan->id)->assertSee(__('purchase_v2.storage_interval_blocked'));
    expect(fn () => app(CustomerPurchaseCheckout::class)->start($this->customer, 'storage', $plan->id, 'yearly', 'one_time', 'fib', null))->toThrow(\Illuminate\Validation\ValidationException::class);
    Http::assertNothingSent();
});

it('keeps pending payment ownership separate and never serializes its provider data', function () {
    $other = Customer::create(['username' => 'other_purchase', 'email' => 'other_purchase@example.test', 'password' => 'fixture']);
    $payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $other->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'internal_status' => 'pending', 'status' => 'pending', 'local_reference' => 'PRIVATE-REFERENCE', 'idempotency_key' => Str::uuid(), 'amount' => 100, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class, 'purchasable_id' => ServicePlan::where('code', 'pro')->value('id'), 'create_response' => ['secret' => 'PRIVATE-PAYLOAD']]);
    Livewire::test('app::v2.pages.account.subscription-plans')->assertDontSee($payment->uuid)->assertDontSee('PRIVATE-PAYLOAD')->assertDontSee('PRIVATE-REFERENCE');
    expect(app(CustomerPurchaseCheckout::class)->pending($this->customer, 'service'))->toBeNull();
});

it('delegates confirmed service and storage cancellation to their own domain services', function () {
    foreach (['subscription-plans' => \App\Services\Billing\ScheduleServicePlanCancellation::class, 'storage-plans' => \App\Services\Billing\ScheduleStoragePlanCancellation::class] as $page => $service) {
        $mock = Mockery::mock($service);
        $mock->shouldReceive('handle')->once()->with(Mockery::on(fn ($c) => $c->id === $this->customer->id))->andThrow(new RuntimeException('PRIVATE-CANCEL-ERROR'));
        app()->instance($service, $mock);
        Livewire::test('app::v2.pages.account.'.$page)->call('cancel')->assertHasNoErrors()->set('confirmCancellation', true)->call('cancel')->assertHasErrors(['cancellation'])->assertDontSee('PRIVATE-CANCEL-ERROR');
    }
});

it('replays an addon checkout through the real creation action without a second provider create', function () {
    config(['fib.callback_base_url' => 'https://metkurd.test']);
    app(PlanSwitcher::class)->switchServicePlan($this->customer, ServicePlan::where('code', 'pro')->value('id'), ['provider' => 'fixture']);
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class);
    $client->shouldReceive('createPayment')->once()->andReturn(\App\Domain\Payments\Data\FibCreatePaymentResponseData::fromArray([
        'paymentId' => (string) Str::uuid(), 'readableCode' => 'FIXTURE', 'validUntil' => now()->addHour()->toIso8601String(),
    ]));
    app()->instance(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class, $client);
    $balances = CreditWallet::where('customer_id', $this->customer->id)->get()->map->only(['wallet_type', 'subscription_balance_credits', 'addon_balance_credits'])->all();
    $checkout = app(CustomerPurchaseCheckout::class);
    $first = $checkout->start($this->customer, 'addon', $product->id, 'monthly', 'one_time', 'fib', null);
    $second = $checkout->start($this->customer, 'addon', $product->id, 'monthly', 'one_time', 'fib', null);
    expect($second->id)->toBe($first->id)->and(Payment::count())->toBe(1)
        ->and($first->payment_mode->value)->toBe('one_time')->and($first->fulfilled_at)->toBeNull();
    expect(CreditWallet::where('customer_id', $this->customer->id)->get()->map->only(['wallet_type', 'subscription_balance_credits', 'addon_balance_credits'])->all())->toBe($balances);
    Http::assertNothingSent();
});

it('keeps displayed identity and current plan scoped to the signed-in customer across accounts', function (string $locale) {
    app()->setLocale($locale);
    $other = Customer::create(['username' => 'other_plan_fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $pro = ServicePlan::where('code', 'pro')->firstOrFail();
    app(PlanSwitcher::class)->switchServicePlan($other, $pro->id, ['provider' => 'fixture']);
    $before = CreditWallet::orderBy('id')->get()->toArray();
    $paymentCount = Payment::count();

    foreach ([[$other, $this->customer, 'pro'], [$this->customer, $other, 'free'], [$other, $this->customer, 'pro']] as [$signedIn, $differentCustomer, $expectedPlan]) {
        $this->actingAs($signedIn->fresh(), 'app');
        $page = Livewire::test('app::v2.pages.account.subscription-plans');
        $catalog = $page->get('catalog');
        expect($catalog['available'])->toBeTrue()
            ->and($catalog['current']->code)->toBe($expectedPlan)
            ->and($signedIn->fresh()->currentServicePlan()->code)->toBe($expectedPlan)
            ->and($catalog['items']->where('same', true)->pluck('id')->all())->toBe([$catalog['current']->id]);
        // An Admin bookmark's customer query must never select the App account.
        $html = $this->get(route('app.v2.subscription-plans', ['locale' => $locale, 'customer' => $differentCustomer->id]))->assertOk()->getContent();
        preg_match('/<p[^>]*data-account-identity[^>]*>(.*?)<\/p>/s', $html, $identity);
        expect($identity[1] ?? '')->toContain('#'.$signedIn->id.'<', '@'.$signedIn->username)
            ->not->toContain('@'.$differentCustomer->username);
        expect($html)->not->toContain('account_v2.customer_id');
    }
    expect(CreditWallet::orderBy('id')->get()->toArray())->toBe($before)
        ->and(Payment::count())->toBe($paymentCount);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);
