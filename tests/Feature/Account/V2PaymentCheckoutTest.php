<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\CheckoutCreationGuard;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
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
    $this->customer = Customer::create(['username' => 'checkout_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'Fixture123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($this->customer, 'app');
});

function v2CheckoutFixture(Customer $customer, string $model = ServicePlan::class, array $attributes = []): Payment
{
    return Payment::create(array_merge([
        'uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => match ($model) {
            ServicePlan::class => 'plan_subscription', StoragePlan::class => 'storage_subscription', default => 'addon_credits'
        },
        'payment_mode' => 'one_time', 'provider_object_type' => 'payment', 'status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
        'provider_payment_status' => 'UNPAID', 'fib_payment_id' => Str::uuid(), 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
        'amount' => 5000, 'currency' => 'IQD', 'purchasable_type' => $model, 'purchasable_id' => $model::firstOrFail()->id,
        'valid_until' => now()->addHour(), 'purchase_snapshot' => ['name' => 'Fixture purchase', 'billing_cycle' => 'monthly'],
    ], $attributes));
}

dataset('checkout kinds', [ServicePlan::class, StoragePlan::class, CreditProduct::class]);

it('retains four-month-old expired history while permitting one new checkout', function ($model) {
    $old = v2CheckoutFixture($this->customer, $model, ['created_at' => now()->subMonths(4), 'valid_until' => now()->subMonths(4)->addHour()]);
    $policy = app(PaymentCheckoutState::class);
    expect($policy->state($old))->toBe('expired')->and($policy->blocker($this->customer, $model))->toBeNull();
    $page = match ($model) {
        ServicePlan::class => 'subscription-plans', StoragePlan::class => 'storage-plans', default => 'addon-credits'
    };
    Livewire::test('app::v2.pages.account.'.$page)->assertDontSee(__('payment_v2.block_active'))->assertDontSee(__('payment_v2.block_review'));
    $new = app(CheckoutCreationGuard::class)->run($this->customer, $model, fn () => v2CheckoutFixture($this->customer, $model));
    expect(Payment::count())->toBe(2)->and($old->fresh()->status->value)->toBe('expired')->and($new->id)->not->toBe($old->id);
    expect($old->fresh()->fulfilled_at)->toBeNull()->and($policy->blocker($this->customer, $model)->id)->toBe($new->id);
    $old->refresh();
    app(ConfirmFibPayment::class)->handle($old, 'livewire_poll');
    expect($old->fresh()->fulfilled_at)->toBeNull();
    Http::assertNothingSent();
})->with('checkout kinds');

it('rechecks the same live checkout in every real creation action', function ($model) {
    $payment = v2CheckoutFixture($this->customer, $model);
    $action = match ($model) {
        ServicePlan::class => CreatePlanSubscriptionPayment::class, StoragePlan::class => CreateStorageSubscriptionPayment::class, default => CreateAddonPayment::class
    };
    $result = app($action)->handle($this->customer, $payment->purchasable_id);
    expect($result->id)->toBe($payment->id)->and(Payment::count())->toBe(1);
    Http::assertNothingSent();
})->with('checkout kinds');

it('blocks ambiguous history rather than guessing expiry from its age', function ($attributes) {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, array_merge(['created_at' => now()->subMonths(4), 'valid_until' => null], $attributes));
    expect(app(PaymentCheckoutState::class)->state($payment))->toBe('review')->and(app(PaymentCheckoutState::class)->blocks($payment))->toBeTrue();
    Livewire::test('app::v2.pages.account.subscription-plans')->assertSee(__('payment_v2.block_review'))->assertSee('/app-v2/payments/fib/', false);
    expect(app(CreatePlanSubscriptionPayment::class)->handle($this->customer, $payment->purchasable_id)->id)->toBe($payment->id);
})->with([[[]], [['internal_status' => 'requires_review', 'review_required_at' => '2026-01-01', 'valid_until' => '2026-01-01']], [['provider_subscription_status' => 'ACTIVE', 'valid_until' => '2026-01-01']], [['provider_payment_status' => 'PAID', 'valid_until' => '2026-01-01']]]);

it('does not permanently block closed or fulfilled payments', function ($status) {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, $status === 'completed'
        ? ['status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()]
        : ['status' => $status, 'internal_status' => $status]);
    expect(app(PaymentCheckoutState::class)->blocks($payment))->toBeFalse();
})->with(['failed', 'canceled', 'expired', 'completed']);

it('uses one final lock across direct action calls and does not create on contention', function ($model) {
    $kind = match ($model) {
        ServicePlan::class => 'service', StoragePlan::class => 'storage', default => 'addon'
    };
    $action = match ($model) {
        ServicePlan::class => CreatePlanSubscriptionPayment::class, StoragePlan::class => CreateStorageSubscriptionPayment::class, default => CreateAddonPayment::class
    };
    $lock = Cache::lock('customer-purchase:'.$this->customer->id.':'.$kind, 300);
    $lock->get();
    try {
        expect(fn () => app($action)->handle($this->customer, 1))->toThrow(\Illuminate\Validation\ValidationException::class);
    } finally {
        $lock->release();
    }
    expect(Payment::count())->toBe(0);
    Http::assertNothingSent();
})->with('checkout kinds');

it('renders each payment state with safe V2 links in every locale', function ($locale) {
    foreach (['awaiting', 'completed', 'confirming', 'failed', 'canceled', 'expired', 'review'] as $state) {
        $attrs = match ($state) {
            'completed' => ['status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()],
            'confirming' => ['status' => 'paid', 'internal_status' => 'paid_pending_application'],
            'review' => ['internal_status' => 'requires_review', 'review_required_at' => now()],
            'awaiting' => [], default => ['status' => $state, 'internal_status' => $state],
        };
        $payment = v2CheckoutFixture($this->customer, ServicePlan::class, array_merge($attrs, [
            'create_response' => ['secret' => 'PRIVATE-PROVIDER-PAYLOAD'], 'status_reason' => 'PRIVATE-FAILURE',
            'provider_links' => ['personal' => 'https://fib.iq/personal/fixture', 'business' => 'javascript:alert(1)', 'corporate' => 'https://attacker.test'],
            'qr_code' => 'data:image/svg+xml;base64,PRIVATE-SVG',
        ]));
        app()->setLocale($locale);
        $response = $this->get(route('app.v2.payments.fib.show', ['locale' => $locale, 'payment' => $payment]));
        $response->assertOk()->assertSee(__('payment_v2.'.$state))->assertDontSee('payment_v2.')->assertDontSee('account_v2.')->assertDontSee('PRIVATE-PROVIDER-PAYLOAD')->assertDontSee('PRIVATE-FAILURE')->assertDontSee('PRIVATE-SVG')->assertDontSee('javascript:')->assertDontSee('attacker.test');
        $response->assertSee('/app-v2/subscription-plans', false)->assertSee('/app-v2/my-billing', false)->assertDontSee('/app/payments/fib/', false);
        if ($state === 'awaiting') {
            $response->assertSee('wire:poll.10s', false)->assertSee('https://fib.iq/personal/fixture', false);
        } else {
            $response->assertDontSee('wire:poll.10s', false)->assertDontSee('https://fib.iq/personal/fixture', false);
        }
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('returns expired storage and addons to their V2 purchase pages', function ($model) {
    $payment = v2CheckoutFixture($this->customer, $model, ['valid_until' => now()->subMonths(4)]);
    $page = match ($model) {
        ServicePlan::class => 'subscription-plans', StoragePlan::class => 'storage-plans', default => 'addon-credits'
    };
    $this->get(route('app.v2.payments.fib.show', ['locale' => 'en', 'payment' => $payment]))->assertOk()->assertSee(__('payment_v2.start_new'))->assertSee('/app-v2/'.$page, false);
})->with('checkout kinds');

it('checks ownership on route and every livewire action', function () {
    $other = Customer::create(['username' => 'other_checkout', 'email' => 'other_checkout@example.test', 'password' => 'Fixture123!']);
    $payment = v2CheckoutFixture($other);
    $this->get(route('app.v2.payments.fib.show', ['locale' => 'en', 'payment' => $payment]))->assertForbidden();
    $owned = v2CheckoutFixture($this->customer);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $owned]);
    $owned->update(['customer_id' => $other->id]);
    expect(fn () => $page->call('refreshStatus'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    Http::assertNothingSent();
});

it('bounds active polling and does not poll terminal or review pages', function () {
    $payment = v2CheckoutFixture($this->customer);
    $confirm = Mockery::mock(ConfirmFibPayment::class);
    $confirm->shouldReceive('handle')->once()->andReturn($payment);
    app()->instance(ConfirmFibPayment::class, $confirm);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment]);
    $page->call('pollStatus')->call('pollStatus');
    expect($page->get('polls'))->toBe(2);
    $payment->update(['internal_status' => 'requires_review']);
    $page->call('pollStatus')->assertDontSee('wire:poll.10s', false);
    expect($page->get('polls'))->toBe(2);
});

it('starts fresh V2 checkout through each real action after a known expiry', function ($model) {
    config(['payments.providers.fib.enabled' => true, 'fib.enabled' => true, 'fib.callback_base_url' => 'https://metkurd.test',
        'fib.profiles.payment.base_url' => 'https://fib-stage.fib.iq', 'fib.profiles.payment.client_id' => 'fixture', 'fib.profiles.payment.client_secret' => 'fixture']);
    $old = v2CheckoutFixture($this->customer, $model, ['created_at' => now()->subMonths(4), 'valid_until' => now()->subMonths(4)->addHour()]);
    if ($model === ServicePlan::class) {
        $target = ServicePlan::where('code', 'pro')->firstOrFail();
        $target->update(['payment_mode' => 'one_time', 'billing_intervals' => ['monthly']]);
    } elseif ($model === StoragePlan::class) {
        $target = StoragePlan::create(['code' => 'checkout-storage', 'name' => 'Storage fixture', 'quota_mb' => 1024, 'price_iqd' => 5000, 'payment_mode' => 'one_time', 'billing_intervals' => ['monthly'], 'is_active' => true]);
    } else {
        app(\App\Services\Billing\PlanSwitcher::class)->switchServicePlan($this->customer, ServicePlan::where('code', 'pro')->value('id'), ['provider' => 'fixture']);
        $target = CreditProduct::where('is_active', true)->firstOrFail();
    }
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class);
    $client->shouldReceive('createPayment')->once()->with(Mockery::on(fn ($request) => str_contains($request->toArray()['redirectUri'], '/app-v2/payments/fib/')))
        ->andReturn(\App\Domain\Payments\Data\FibCreatePaymentResponseData::fromArray(['paymentId' => Str::uuid(), 'validUntil' => now()->addHour()->utc()->toIso8601String(),
            'readableCode' => 'ONE-TIME-CODE', 'personalAppLink' => 'https://fib.iq/personal/fixture-checkout',
            'qrCode' => 'data:image/png;base64,'.str_repeat('A', 60000), 'debug' => str_repeat('PRIVATE_DEBUG', 10000)]));
    app()->instance(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class, $client);
    $kind = match ($model) {
        ServicePlan::class => 'service', StoragePlan::class => 'storage', default => 'addon'
    };
    $entry = app(\App\Services\Payments\CustomerPurchaseCheckout::class);
    $new = $entry->start($this->customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null);
    $again = $entry->start($this->customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null);
    expect($new->id)->not->toBe($old->id)->and($again->id)->toBe($new->id)->and(Payment::count())->toBe(2)
        ->and($old->fresh()->status->value)->toBe('expired')->and($old->fresh()->fulfilled_at)->toBeNull()
        ->and($new->fulfilled_at)->toBeNull()->and(app(PaymentCheckoutState::class)->state($new))->toBe('awaiting');
    expect($new->usesCompactPersistence())->toBeTrue()->and($new->qr_code)->toBeNull()
        ->and($new->meta)->not->toHaveKeys(['fee_quote', 'coupon', 'payment_driver', 'provider_object_type'])
        ->and(json_encode($new->getAttributes()).$new->events()->get()->toJson())->not->toContain('base64', 'PRIVATE_DEBUG');
    $this->get(route('app.v2.payments.fib.show', ['locale' => 'en', 'payment' => $new]))
        ->assertOk()->assertSee('data:image/png;base64,', false);
    Cache::forget('payment-checkout-qr:'.$new->customer_id.':'.$new->uuid);
    $before = $new->fresh()->getRawOriginal();
    $eventCount = $new->events()->count();
    $this->get(route('app.v2.payments.fib.show', ['locale' => 'en', 'payment' => $new]))
        ->assertOk()->assertDontSee('data:image/png;base64,', false)
        ->assertSee('ONE-TIME-CODE')->assertSee('https://fib.iq/personal/fixture-checkout', false);
    expect($entry->start($this->customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null)->id)->toBe($new->id)
        ->and($new->fresh()->getRawOriginal())->toBe($before)->and($new->events()->count())->toBe($eventCount)
        ->and(Payment::count())->toBe(2);
    Http::assertNothingSent();
})->with('checkout kinds');

it('keeps recurring V2 creation compact and preserves QR presentation and environment evidence', function ($model, $locale) {
    config(['payments.providers.fib.enabled' => true, 'fib.enabled' => true, 'fib.callback_base_url' => 'https://metkurd.test',
        'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq', 'fib.profiles.subscription.client_id' => 'fixture', 'fib.profiles.subscription.client_secret' => 'fixture']);
    $target = $model === ServicePlan::class ? ServicePlan::where('code', 'pro')->firstOrFail()
        : StoragePlan::create(['code' => 'compact-storage', 'name' => 'Storage fixture', 'quota_mb' => 1024, 'price_iqd' => 5000, 'is_active' => true]);
    $target->update(['payment_mode' => 'recurring', 'billing_intervals' => ['monthly']]);
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibSubscriptionClient::class);
    $client->shouldReceive('createSubscription')->once()->andReturn(\App\Domain\Payments\Data\FibCreateSubscriptionResponseData::fromArray([
        'subscriptionId' => (string) Str::uuid(), 'status' => 'DRAFT', 'readableCode' => 'FIXTURE-1234',
        'appLink' => 'https://p-stage.fib.iq/fixture-checkout', 'validUntil' => now()->addHour()->toIso8601String(),
        'qrCode' => 'data:image/png;base64,aGVsbG8=', 'debug' => str_repeat('PRIVATE_DEBUG', 10000),
    ]));
    app()->instance(\App\Domain\Payments\Fib\FibSubscriptionClient::class, $client);
    $payment = app(\App\Services\Payments\CustomerPurchaseCheckout::class)->start($this->customer,
        $model === ServicePlan::class ? 'service' : 'storage', $target->id, 'monthly', 'recurring', 'fib', null)->fresh();
    expect($payment->usesCompactPersistence())->toBeTrue()->and($payment->qr_code)->toBeNull()
        ->and($payment->provider_interval)->not->toBeNull()
        ->and(json_encode($payment->getAttributes()).$payment->events()->get()->toJson())->not->toContain('base64', 'PRIVATE_DEBUG');
    $event = $payment->events()->where('event_type', 'provider_subscription_created')->sole();
    expect(app(\App\Services\Billing\FibProviderProvenance::class)->classify($payment, [$event])['classification'])->toBe('confirmed_test_or_staging');
    app()->setLocale($locale);
    $this->get(route('app.v2.payments.fib.show', ['locale' => $locale, 'payment' => $payment]))
        ->assertOk()->assertSee('data:image/png;base64,aGVsbG8=', false)->assertSee('FIXTURE-1234')->assertSee($target->name)
        ->assertDontSee('payment_v2.')->assertDontSee('PRIVATE_DEBUG');
    Cache::forget('payment-checkout-qr:'.$payment->customer_id.':'.$payment->uuid);
    $before = $payment->getRawOriginal();
    $eventCount = $payment->events()->count();
    $this->get(route('app.v2.payments.fib.show', ['locale' => $locale, 'payment' => $payment]))
        ->assertOk()->assertDontSee('data:image/png;base64,', false)
        ->assertSee('FIXTURE-1234')->assertSee('https://p-stage.fib.iq/fixture-checkout', false);
    $again = app(\App\Services\Payments\CustomerPurchaseCheckout::class)->start($this->customer,
        $model === ServicePlan::class ? 'service' : 'storage', $target->id, 'monthly', 'recurring', 'fib', null);
    expect($again->id)->toBe($payment->id)->and(Payment::count())->toBe(1)
        ->and($payment->fresh()->getRawOriginal())->toBe($before)->and($payment->events()->count())->toBe($eventCount);
    Http::assertNothingSent();
})->with([[ServicePlan::class], [StoragePlan::class]])->with(['en', 'ar', 'ku']);

it('does not fulfill an expired checkout from a late paid callback observation', function () {
    $payment = v2CheckoutFixture($this->customer, CreditProduct::class, ['valid_until' => now()->subMonths(4)]);
    $balances = \App\Models\CreditWallet::where('customer_id', $this->customer->id)->get()->map->only(['wallet_type', 'subscription_balance_credits', 'addon_balance_credits'])->all();
    $service = Mockery::mock(\App\Domain\Payments\Fib\FibOneTimePaymentService::class);
    $service->shouldReceive('getStatus')->twice()->andReturn(\App\Domain\Payments\Data\FibPaymentStatusData::fromArray([
        'paymentId' => $payment->fib_payment_id, 'status' => 'PAID', 'amount' => ['amount' => $payment->amount, 'currency' => 'IQD'], 'paidAt' => now()->subMonths(4)->toIso8601String(),
    ]));
    app()->instance(\App\Domain\Payments\Fib\FibOneTimePaymentService::class, $service);
    foreach ([1, 2] as $replay) {
        app(ConfirmFibPayment::class)->handle($payment, 'callback', ['status' => 'PAID']);
    }
    expect($payment->fresh()->status->value)->toBe('expired')->and($payment->fresh()->fulfilled_at)->toBeNull()
        ->and($payment->fresh()->internal_status->value)->toBe('requires_review')
        ->and(app(PaymentCheckoutState::class)->state($payment->fresh()))->toBe('review');
    expect(\App\Models\CreditWallet::where('customer_id', $this->customer->id)->get()->map->only(['wallet_type', 'subscription_balance_credits', 'addon_balance_credits'])->all())->toBe($balances);
    expect(\App\Models\CreditOrder::count())->toBe(0);
});

it('uses the recorded provider offset and rejects malformed checkout deadlines', function () {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, ['valid_until' => now()->subHour(), 'create_response' => ['validUntil' => now()->addHour()->utc()->toIso8601String()]]);
    expect(app(PaymentCheckoutState::class)->state($payment))->toBe('awaiting');
    $payment->update(['create_response' => ['validUntil' => 'invalid-provider-date']]);
    expect(app(PaymentCheckoutState::class)->state($payment->fresh()))->toBe('review');
    foreach ([\App\Domain\Payments\Data\FibCreatePaymentResponseData::class, \App\Domain\Payments\Data\FibCreateSubscriptionResponseData::class] as $dto) {
        $parsed = $dto::fromArray(['validUntil' => '2026-05-01T10:15:00Z']);
        expect($parsed->validUntil->format('Y-m-d H:i:s'))->toBe('2026-05-01 13:15:00');
        expect($parsed->validUntil->utc()->format('H:i'))->toBe('10:15');
    }
});

it('stops at the polling limit and checks the latest terminal state before refresh', function () {
    $payment = v2CheckoutFixture($this->customer);
    $confirm = Mockery::mock(ConfirmFibPayment::class);
    $confirm->shouldNotReceive('handle');
    app()->instance(ConfirmFibPayment::class, $confirm);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment]);
    $component = $page->instance();
    $component->polls = 60;
    $component->pollStatus();
    expect($component->polls)->toBe(60);
    $payment->update(['status' => 'expired', 'internal_status' => 'expired']);
    $page->call('refreshStatus')->assertSee(__('payment_v2.expired'));
    Http::assertNothingSent();
});

it('shows expired history without rewriting the stored payment during a read', function () {
    $payment = v2CheckoutFixture($this->customer, CreditProduct::class, ['created_at' => now()->subMonths(4), 'valid_until' => now()->subMonths(4)->addHour()]);
    Livewire::withQueryParams(['period' => 'custom', 'from' => now()->subMonths(5)->toDateString(), 'to' => now()->toDateString()])
        ->test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.expired'))->assertSee('/app-v2/payments/fib/'.$payment->uuid, false);
    expect($payment->fresh()->status->value)->toBe('awaiting_customer_action')->and(Payment::count())->toBe(1);
    Http::assertNothingSent();
});

it('labels a refunded fulfilled payment without offering checkout or reporting success', function () {
    $payment = v2CheckoutFixture($this->customer, CreditProduct::class, ['status' => 'refunded', 'internal_status' => 'refunded', 'paid_at' => now(), 'fulfilled_at' => now()]);
    expect(app(PaymentCheckoutState::class)->state($payment))->toBe('refunded')->and(app(PaymentCheckoutState::class)->blocks($payment))->toBeFalse();
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])->assertSee(__('payment_v2.refunded'))->assertDontSee('wire:poll.10s', false);
});

it('stops legacy checkout polling too when the shared policy knows the session is expired', function () {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, ['valid_until' => now()->subMonths(4)]);
    $this->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))->assertOk()->assertSee('data-payment-status-polling="stopped"', false);
    $this->get(route('payments.fib.status', ['locale' => 'en', 'payment' => $payment]))->assertOk()->assertJsonPath('state', 'expired')->assertJsonPath('is_terminal', true);
    Http::assertNothingSent();
});

it('renders responsive safe QR app links and readable code in every locale', function (string $locale) {
    app()->setLocale($locale);
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, [
        'readable_code' => 'ABCD-1234', 'qr_code' => 'data:image/png;base64,aGVsbG8=',
        'provider_links' => ['personal' => 'https://fib.iq/personal/pay', 'business' => 'https://fib.iq/business/pay'],
        'status_response' => ['secret' => 'NEVER-EXPOSE-RAW-PAYLOAD'],
    ]);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment]);
    $page->assertSee(__('payment_v2.pay_app'))->assertSee(__('payment_v2.open_personal'))->assertSee(__('payment_v2.open_business'))
        ->assertSee('has-app-links', false)->assertSee('v2-payment-qr-primary', false)->assertSee('v2-payment-mobile-qr', false)
        ->assertSee('dir="ltr" data-v2-payment-code', false)->assertSee('ABCD-1234')->assertSee('data-v2-countdown', false)
        ->assertSee(__('payment_v2.copy_code'))->assertDontSee('NEVER-EXPOSE-RAW-PAYLOAD');
    foreach (['completed', 'confirming', 'failed', 'canceled', 'expired', 'review'] as $state) {
        $payment->update(['status' => $state === 'completed' || $state === 'confirming' ? 'paid' : ($state === 'review' ? 'awaiting_customer_action' : $state),
            'internal_status' => match ($state) {
                'completed' => 'applied', 'confirming' => 'paid_pending_application', 'review' => 'requires_review', default => $state
            }]);
        Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment->fresh()])
            ->assertDontSee('data-v2-payment-controls', false)->assertDontSee('ABCD-1234')->assertDontSee('https://fib.iq/personal/pay', false)
            ->assertDontSee('data:image/png', false)->assertDontSee('data-v2-countdown', false);
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('keeps usable fallbacks without unsafe URLs QR or provider HTML', function () {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, ['readable_code' => 'CODE-ONLY',
        'qr_code' => 'data:image/svg+xml,<svg onload="alert(1)"></svg>',
        'provider_links' => ['app' => 'javascript:alert(1)', 'personal' => 'https://fib.iq@evil.test/pay',
            'business' => 'https://user:secret@fib.iq/pay', 'corporate' => 'https://evil.test/pay']]);
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])->assertSee('CODE-ONLY')
        ->assertDontSee('has-app-links', false)->assertDontSee('<img class="v2-payment-qr"', false)
        ->assertDontSee('javascript:', false)->assertDontSee('evil.test', false)->assertDontSee('user:secret', false)->assertDontSee('data:image/svg', false)
        ->assertDontSee(__('payment_v2.no_link'));
    $payment->update(['qr_code' => 'https://fib.iq/qr.png']);
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment->fresh()])->assertSee('v2-payment-qr-primary', false)
        ->assertDontSee('has-app-links', false)->assertDontSee('v2-payment-mobile-qr', false);
    $payment->update(['qr_code' => null, 'readable_code' => null]);
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment->fresh()])->assertSee(__('payment_v2.no_link'))
        ->assertDontSee('data-v2-copy-code', false);
    Http::assertNothingSent();
});

it('re-evaluates countdown expiry and app return through a read-only Livewire refresh', function () {
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, ['readable_code' => 'REFRESH-CODE']);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment]);
    $this->travel(2)->hours();
    try {
        $page->call('$refresh')->assertSee(__('payment_v2.expired'))->assertDontSee('data-v2-payment-controls', false);
        expect($payment->fresh()->status->value)->toBe('awaiting_customer_action');
        $payment->update(['status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()]);
        $page->call('$refresh')->assertSee(__('payment_v2.completed'))->assertDontSee('wire:poll.10s', false)
            ->assertDontSee('REFRESH-CODE');
        Http::assertNothingSent();
    } finally {
        $this->travelBack();
    }
});

it('refreshes paused historical review locally without looking up an unknown-origin reference in the active environment', function (string $locale) {
    app()->setLocale($locale);
    config(['fib.environment' => 'staging']);
    $payment = v2CheckoutFixture($this->customer, ServicePlan::class, ['internal_status' => 'requires_review',
        'review_required_at' => now(), 'provider_subscription_status' => 'DRAFT', 'valid_until' => null,
        'meta' => ['latest_sync_failure_http_status' => 404, 'latest_sync_failure_pause_reconciliation' => true]]);
    $before = $payment->fresh()->getAttributes();
    $confirm = Mockery::mock(ConfirmFibPayment::class);
    $confirm->shouldNotReceive('handle');
    app()->instance(ConfirmFibPayment::class, $confirm);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])
        ->assertSee(__('payment_v2.refresh_review'))->assertSee(__('payment_v2.operator_review_help'))
        ->assertDontSee(__('payment_v2.refresh'))->assertDontSee('data-v2-payment-controls', false);
    $page->instance()->addError('status', __('payment_v2.refresh_failed'));
    $page->call('refreshStatus')->assertHasNoErrors('status')->assertSee(__('payment_v2.review'));
    expect($payment->fresh()->getAttributes())->toBe($before);
    expect(app(PaymentCheckoutState::class)->blocks($payment->fresh()))->toBeTrue();
    // Simulate the existing authorized Admin terminal transition, retaining historical failure evidence.
    $payment->update(['status' => 'expired', 'internal_status' => 'expired', 'expired_at' => now(), 'review_required_at' => null]);
    $page->call('refreshStatus')->assertHasNoErrors()->assertSee(__('payment_v2.expired'))->assertSee(__('payment_v2.start_new'))
        ->assertDontSee(__('payment_v2.refresh_review'));
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

function v2AbandonedFixture(Customer $customer, string $model = ServicePlan::class, array $attributes = []): Payment
{
    return v2CheckoutFixture($customer, $model, array_merge([
        'valid_until' => null, 'fib_payment_id' => null, 'provider_payment_status' => 'DRAFT',
        'create_response' => ['validUntil' => 'unparseable-deadline'],
    ], $attributes));
}

it('closes an owned local draft once preserving history wallets and provider fields in every locale', function ($locale) {
    app()->setLocale($locale);
    $payment = v2AbandonedFixture($this->customer);
    $event = app(\App\Domain\Payments\Support\PaymentEventRecorder::class)->record($payment,
        ['event_type' => 'local_payment_created', 'source' => 'fixture']);
    $before = $payment->getAttributes();
    $wallets = \App\Models\CreditWallet::orderBy('id')->get()->toArray();
    $ledger = \Illuminate\Support\Facades\DB::table('credit_ledgers')->orderBy('id')->get()->toJson();
    expect(app(PaymentCheckoutState::class)->blocks($payment))->toBeTrue();
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])
        ->assertSee(__('payment_v2.attention'))->assertSee(__('payment_v2.abandon_confirm'))
        ->assertSee('data-v2-confirm', false)->assertDontSee('wire:poll.10s', false);
    $page->call('abandonCheckout')->assertHasNoErrors()->assertDispatched('v2-checkout-changed')
        ->assertSee(__('payment_v2.checkout_canceled'))->assertSee(__('payment_v2.start_new'))
        ->assertDontSee('wire:click="abandonCheckout"', false);
    $page->call('abandonCheckout')->assertHasNoErrors();
    $payment->refresh();
    expect($payment->status->value)->toBe('canceled')->and($payment->internal_status->value)->toBe('canceled')
        ->and(app(PaymentCheckoutState::class)->blocks($payment))->toBeFalse()
        ->and($payment->events()->count())->toBe(2)->and($event->fresh())->not->toBeNull()
        ->and($payment->events()->where('event_type', 'customer_checkout_abandoned')->first()->meta['customer_id'])->toBe($this->customer->id)
        ->and(Payment::count())->toBe(1)
        ->and(\App\Models\CreditWallet::orderBy('id')->get()->toArray())->toBe($wallets)
        ->and(\Illuminate\Support\Facades\DB::table('credit_ledgers')->orderBy('id')->get()->toJson())->toBe($ledger);
    foreach (['provider_status', 'provider_payment_status', 'provider_subscription_status', 'create_response', 'paid_at', 'fulfilled_at'] as $field) {
        expect($payment->getAttributes()[$field] ?? null)->toBe($before[$field] ?? null);
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('rejects customer abandonment with retained financial or ambiguous evidence', function ($evidence) {
    $payment = v2AbandonedFixture($this->customer);
    match ($evidence) {
        'paid' => $payment->update(['status' => 'paid']),
        'fulfilled' => $payment->update(['fulfilled_at' => now()]),
        'refund' => $payment->update(['internal_status' => 'refund_requested']),
        'refunded' => $payment->update(['status' => 'refunded']),
        'reversal' => $payment->update(['status_response' => ['reversal' => ['amount' => 5000]]]),
        'collection' => $payment->update(['last_payment_at' => now()]),
        'paid through' => $payment->update(['meta' => ['paidThrough' => now()->addMonth()->toIso8601String()]]),
        'coverage' => $payment->update(['active_until' => now()->addMonth()]),
        'payload' => $payment->update(['callback_payload' => ['paymentStatus' => 'PAID']]),
        'old event' => app(\App\Domain\Payments\Support\PaymentEventRecorder::class)->record($payment,
            ['event_type' => 'provider_status_checked', 'source' => 'fixture', 'payload' => ['paymentStatus' => 'PAID']]),
        'entitlement' => $this->customer->activeServiceSubscription()->firstOrFail()->update(['payment_id' => $payment->id]),
        'order' => \App\Models\CreditOrder::create(['customer_id' => $this->customer->id, 'payment_id' => $payment->id, 'order_type' => 'subscription', 'status' => 'paid', 'credits_amount' => 1, 'base_amount_iqd' => 5000, 'currency' => 'IQD']),
        'remote draft' => $payment->update(['fib_subscription_id' => Str::uuid()]),
        'instructions' => $payment->update(['readable_code' => 'DRAFT-CODE']),
        'future deadline' => $payment->update(['create_response' => null, 'valid_until' => now()->addHour()]),
        'not found' => $payment->update(['meta' => ['latest_sync_failure_http_status' => 404], 'mismatch_reason' => 'FIB returned NOT_FOUND']),
        'unexplained review' => $payment->update(['internal_status' => 'requires_review', 'review_required_at' => now()]),
        'unknown status' => $payment->update(['status_response' => ['status' => 'UNKNOWN']]),
        'unclassified event' => app(\App\Domain\Payments\Support\PaymentEventRecorder::class)->record($payment,
            ['event_type' => 'provider_status_checked', 'source' => 'fixture', 'after_status' => 'AUTHORIZED']),
        'unmapped remote id' => $payment->update(['create_response' => ['id' => Str::uuid()]]),
    };
    $before = $payment->fresh()->getAttributes();
    $count = $payment->events()->count();
    expect(app(\App\Domain\Payments\Support\AbandonedCheckoutEligibility::class)->customerEligible($payment->fresh()))->toBeFalse();
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment->fresh()])
        ->assertDontSee('wire:click="abandonCheckout"', false)->call('abandonCheckout')->assertHasErrors('status');
    expect($payment->fresh()->getAttributes())->toBe($before)->and($payment->events()->count())->toBe($count);
    Http::assertNothingSent();
})->with(['paid', 'fulfilled', 'refund', 'refunded', 'reversal', 'collection', 'paid through', 'coverage', 'payload', 'old event', 'entitlement', 'order', 'remote draft', 'instructions', 'future deadline', 'not found', 'unexplained review', 'unknown status', 'unclassified event', 'unmapped remote id']);

it('rechecks ownership and evidence after customer confirmation', function () {
    $payment = v2AbandonedFixture($this->customer);
    $page = Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])->assertSee(__('payment_v2.abandon'));
    $payment->update(['paid_at' => now()]);
    $page->call('abandonCheckout')->assertHasErrors('status');
    $other = Customer::create(['username' => 'abandon_other', 'email' => 'abandon-other@example.test', 'password' => 'Fixture123!', 'status' => 1]);
    $this->actingAs($other, 'app');
    expect(fn () => app(\App\Domain\Payments\Actions\AbandonCustomerCheckout::class)->handle($payment->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    auth('app')->logout();
    expect(fn () => app(\App\Domain\Payments\Actions\AbandonCustomerCheckout::class)->handle($payment->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('releases only an unused legacy coupon reservation during customer closure', function ($status) {
    $payment = v2AbandonedFixture($this->customer);
    $coupon = \App\Models\Coupon::create(['code' => 'CUSTOMER-ABANDON', 'name' => 'Fixture', 'discount_type' => 'percent', 'discount_value' => 10, 'used_count' => 1]);
    $redemption = \App\Models\CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $this->customer->id,
        'payment_id' => $payment->id, 'purchase_type' => 'plan_subscription', 'redemption_type' => 'checkout',
        'status' => $status, 'coupon_code' => $coupon->code, 'original_amount_iqd' => 10000, 'final_amount_iqd' => 9000]);
    $action = app(\App\Domain\Payments\Actions\AbandonCustomerCheckout::class);
    if ($status === 'consumed') {
        expect(fn () => $action->handle($payment->id))->toThrow(\Illuminate\Validation\ValidationException::class);
    } else {
        $action->handle($payment->id);
        $action->handle($payment->id);
    }
    expect($redemption->fresh()->status->value)->toBe($status === 'consumed' ? 'consumed' : 'released')
        ->and((int) $coupon->fresh()->used_count)->toBe($status === 'consumed' ? 1 : 0);
    Http::assertNothingSent();
})->with(['reserved', 'consumed']);

it('refreshes an open purchase review after customer closure and creates a new checkout without coupons', function ($model) {
    config(['payments.providers.fib.enabled' => true, 'fib.enabled' => true, 'fib.callback_base_url' => 'https://metkurd.test',
        'fib.profiles.payment.base_url' => 'https://fib-stage.fib.iq', 'fib.profiles.payment.client_id' => 'fixture', 'fib.profiles.payment.client_secret' => 'fixture']);
    $old = v2AbandonedFixture($this->customer, $model);
    if ($model === ServicePlan::class) {
        $target = ServicePlan::where('code', 'pro')->firstOrFail();
        $target->update(['payment_mode' => 'one_time', 'billing_intervals' => ['monthly']]);
    } elseif ($model === StoragePlan::class) {
        $target = StoragePlan::create(['code' => 'checkout-storage', 'name' => 'Storage fixture', 'quota_mb' => 1024, 'price_iqd' => 5000, 'payment_mode' => 'one_time', 'billing_intervals' => ['monthly'], 'is_active' => true]);
    } else {
        app(\App\Services\Billing\PlanSwitcher::class)->switchServicePlan($this->customer, ServicePlan::where('code', 'pro')->value('id'), ['provider' => 'fixture']);
        $target = CreditProduct::where('is_active', true)->firstOrFail();
    }
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class);
    $client->shouldReceive('createPayment')->once()->with(Mockery::on(fn ($request) => str_contains($request->toArray()['redirectUri'], '/app-v2/payments/fib/')))
        ->andReturn(\App\Domain\Payments\Data\FibCreatePaymentResponseData::fromArray(['paymentId' => Str::uuid(), 'validUntil' => now()->addHour()->utc()->toIso8601String()]));
    app()->instance(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class, $client);
    $kind = match ($model) {
        ServicePlan::class => 'service', StoragePlan::class => 'storage', default => 'addon'
    };
    $pageName = match ($kind) {
        'service' => 'subscription-plans', 'storage' => 'storage-plans', default => 'addon-credits'
    };
    $page = Livewire::test('app::v2.pages.account.'.$pageName)->call('select', $target->id)
        ->assertSee(__('payment_v2.block_review'))->assertDontSee('purchase-coupon', false)
        ->assertDontSee('wire:model="coupon"', false)->assertSee(__('purchase_v2.subtotal'))->assertSee(__('purchase_v2.fees'));
    expect($page->get('catalog')['pendingUrl'])->not->toBeNull();
    app(\App\Domain\Payments\Actions\AbandonCustomerCheckout::class)->handle($old->id);
    $page->call('$refresh')->assertDontSee(__('payment_v2.block_review'));
    expect($page->get('selectedId'))->toBe($target->id)->and($page->get('catalog')['pendingUrl'])->toBeNull();
    $entry = app(\App\Services\Payments\CustomerPurchaseCheckout::class);
    $new = $entry->start($this->customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null);
    $again = $entry->start($this->customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null);
    expect($new->id)->not->toBe($old->id)->and($again->id)->toBe($new->id)->and(Payment::count())->toBe(2)
        ->and($old->fresh()->status->value)->toBe('canceled')->and($old->fresh()->fulfilled_at)->toBeNull()
        ->and($new->fulfilled_at)->toBeNull()->and(app(PaymentCheckoutState::class)->state($new))->toBe('awaiting');
    Http::assertNothingSent();
})->with('checkout kinds');
