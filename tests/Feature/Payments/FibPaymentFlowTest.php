<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Exceptions\FibApiException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\PaymentFeeCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Notification::fake();

    fibFlowConfigure();
    $this->seed();
});

function fibFlowConfigure(): void
{
    app()->setLocale('en');

    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.callback_base_url', 'https://metkurd.test');
    config()->set('fib.callback_secret', 'fib-callback-secret');
    config()->set('fib.callback_secret_header', 'x-callback-secret');
    config()->set('fib.payment.category', 'ECOMMERCE');
    config()->set('fib.payment.expires_in', 'PT1H');
    config()->set('fib.payment.refundable_for', 'PT48H');
    config()->set('fib.subscription.expires_in', 'PT1H');
    config()->set('fib.subscription.trial_period', null);
    config()->set('fib.subscription.intervals.monthly', 'P1M');
    config()->set('fib.subscription.intervals.yearly', 'P1Y');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
}

function fibFlowCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "fib_user_{$suffix}",
        'email' => $email ?? "fib-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function fibFlowStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq' . $path;
}

function fibFlowCreateResponse(string $paymentId, array $overrides = []): array
{
    return array_merge([
        'paymentId' => $paymentId,
        'readableCode' => 'CODE-123',
        'qrCode' => 'data:image/png;base64,fake-qr',
        'personalAppLink' => 'https://fib.iq/personal/' . $paymentId,
        'businessAppLink' => 'https://fib.iq/business/' . $paymentId,
        'corporateAppLink' => 'https://fib.iq/corporate/' . $paymentId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ], $overrides);
}

function fibFlowStatusResponse(string $paymentId, string $status, array $overrides = []): array
{
    return array_merge([
        'paymentId' => $paymentId,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'amount' => [
            'amount' => '26500',
            'currency' => 'IQD',
        ],
    ], $overrides);
}

function fibFlowSubscriptionCreateResponse(string $subscriptionId, array $overrides = []): array
{
    return array_merge([
        'subscriptionId' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'qrCode' => 'data:image/png;base64,fake-subscription-qr',
        'appLink' => 'https://fib.iq/app/' . $subscriptionId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ], $overrides);
}

function fibFlowSubscriptionStatusResponse(string $subscriptionId, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'title' => 'MET KURD Subscription',
        'description' => 'Recurring checkout',
        'monetaryValue' => [
            'amount' => '26500',
            'currency' => 'IQD',
        ],
        'interval' => 'P1M',
        'trialPeriod' => null,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'activeUntil' => '2026-06-01T10:15:00Z',
        'lastPaymentAt' => '2026-05-01T10:05:00Z',
        'appLink' => 'https://fib.iq/app/' . $subscriptionId,
    ], $overrides);
}

function fibFlowGrantPaidPlan(Customer $customer): ServicePlan
{
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

it('creates a plan subscription checkout and stores fib subscription details', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => function ($request) {
            return str_contains((string) data_get($request->data(), 'client_id'), 'subscription')
                ? Http::response(['access_token' => 'fib-subscription-access-token', 'expires_in' => 60], 200)
                : Http::response(['access_token' => 'fib-access-token', 'expires_in' => 60], 200);
        },
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly')->fresh();
    $quote = app(PaymentFeeCalculator::class)->quote('fib', $plan->priceIqdForCycle('yearly'));

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and((int) round((float) $payment->amount))->toBe((int) $quote['gross_amount_iqd'])
        ->and($payment->fib_subscription_id)->toBe('fib-plan-sub-123')
        ->and($payment->fib_payment_id)->toBeNull()
        ->and($payment->provider_interval)->toBe('P1Y')
        ->and($payment->readable_code)->toBe('SUB-CODE-123')
        ->and($payment->valid_until)->not->toBeNull()
        ->and(data_get($payment->create_payload, 'monetaryValue.currency'))->toBe('IQD')
        ->and((int) data_get($payment->purchase_snapshot, 'fee_quote.net_amount_iqd'))->toBe($plan->priceIqdForCycle('yearly'))
        ->and(data_get($payment->purchase_snapshot, 'renewal_strategy'))->toBe('provider_schedule');

    Http::assertSent(function ($request) use ($quote) {
        if ($request->url() !== fibFlowStageUrl('/protected/v1/subscriptions')) {
            return true;
        }

        return data_get($request->data(), 'monetaryValue.amount') === (string) $quote['gross_amount_iqd']
            && data_get($request->data(), 'monetaryValue.currency') === 'IQD'
            && data_get($request->data(), 'interval') === 'P1Y'
            && filled(data_get($request->data(), 'statusCallbackUrl'));
    });
});

it('marks the local subscription checkout as failed when fib subscription creation fails', function () {
    Log::spy();
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response([
            'traceId' => 'beed4fde9ac0f37f935ff34f60694a7e',
            'errors' => [[
                'code' => 'CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION',
                'title' => 'Subscription credentials rejected',
                'detail' => 'The configured client is not enabled for subscription APIs.',
            ]],
        ], 400),
    ]);

    expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly'))
        ->toThrow(FibApiException::class);

    $payment = Payment::query()->latest('id')->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fib_subscription_id)->toBeNull()
        ->and($payment->status_reason)->toContain('CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION')
        ->and($payment->status_reason)->toContain('traceId: beed4fde9ac0f37f935ff34f60694a7e')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_subscription_create_failed')
            ->exists())->toBeTrue();

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'FIB provider request failed'
                && data_get($context, 'event') === 'subscription_create_failed'
                && data_get($context, 'profile') === 'subscription'
                && data_get($context, 'provider_object_type') === 'subscription'
                && data_get($context, 'client_id') === 'fib-subscription-client'
                && data_get($context, 'trace_id') === 'beed4fde9ac0f37f935ff34f60694a7e'
                && data_get($context, 'error_codes') === ['CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION'];
        })
        ->once();
});

it('updates storage subscription state from a validated callback and fulfills it exactly once', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-storage-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202)->assertJson([
        'ok' => true,
        'status' => 'accepted',
    ]);

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CustomerStorageSubscription::query()
            ->where('payment_id', $payment->id)
            ->where('storage_plan_id', $plan->id)
            ->exists())->toBeTrue()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'callback_processed')
            ->count())->toBe(1);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee('Congrats! Your subscription was confirmed successfully.');
});

it('updates subscription state from a manual status refresh and redirects back to the fib checkout page', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-refresh-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-refresh-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-refresh-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->post(route('payments.fib.refresh', ['locale' => 'en', 'payment' => $payment]))
        ->assertRedirect(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('Success')
        ->assertSee('Congrats! Your subscription was confirmed successfully.');
});

it('renders automatic polling on pending fib checkout pages and keeps manual refresh as a fallback', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-live-page-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('data-payment-status-polling="active"', false)
        ->assertSee('wire:poll.5s="pollStatus"', false)
        ->assertSee('Automatic check is active.')
        ->assertSee('Refresh Status')
        ->assertSee('Complete Subscription In FIB');
});

it('ignores duplicate callbacks without double-fulfilling addon credits', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-duplicate-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-duplicate-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-duplicate-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payload = ['id' => $payment->fib_payment_id, 'status' => 'PAID'];
    $headers = ['x-callback-secret' => 'fib-callback-secret'];

    $this->postJson(route('payments.fib.callback'), $payload, $headers)->assertStatus(202);
    $this->postJson(route('payments.fib.callback'), $payload, $headers)->assertStatus(202);

    $payment = $payment->fresh();
    $wallet = $customer->fresh()->wallet()->first();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'payment_fulfilled')->count())->toBe(1)
        ->and(PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'callback_processed')->count())->toBe(1)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);
});

it('fulfills a successful plan subscription checkout', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-plan-fulfill-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-plan-fulfill-123', 'ACTIVE', [
                'interval' => 'P1Y',
                'activeUntil' => '2027-05-01T10:15:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_plan_confirmation')->fresh();

    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->where('service_plan_id', $plan->id)
        ->firstOrFail();

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id)
        ->and($subscription->renewal_strategy)->toBe('provider_schedule')
        ->and($subscription->auto_renew)->toBeTrue()
        ->and(CreditOrder::query()
            ->where('payment_id', $payment->id)
            ->where('source_type', 'service_plan')
            ->exists())->toBeTrue();

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee('Success');
});

it('fulfills a successful storage subscription checkout', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-fulfill-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-storage-fulfill-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_storage_confirmation')->fresh();

    $subscription = CustomerStorageSubscription::query()
        ->where('payment_id', $payment->id)
        ->where('storage_plan_id', $plan->id)
        ->firstOrFail();

    expect($payment->purchase_type)->toBe(PurchaseType::STORAGE_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id)
        ->and($subscription->renewal_strategy)->toBe('provider_schedule')
        ->and($subscription->auto_renew)->toBeTrue();
});

it('fulfills a successful addon credits payment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-fulfill-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-fulfill-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_addon_confirmation')->fresh();
    $wallet = $customer->fresh()->wallet()->first();

    expect($payment->purchase_type)->toBe(PurchaseType::ADDON_CREDITS)
        ->and($payment->payment_mode)->toBe(PaymentMode::ONE_TIME)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::PAYMENT)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee('Success');
});

it('cancels a fib subscription checkout and keeps it unfulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-cancel-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-cancel-sub-123/cancel') => Http::response(null, 204),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-cancel-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-cancel-sub-123', 'CANCELED', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->actingAs($customer, 'app')
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertRedirect(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::CANCELED)
        ->and($payment->canceled_at)->not->toBeNull()
        ->and($payment->fulfilled_at)->toBeNull()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_cancel_requested')
            ->exists())->toBeTrue();
});

it('keeps unpaid subscription checkouts awaiting customer action without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-unpaid-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-unpaid-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-unpaid-sub-123', 'UNPAID', [
                'lastPaymentAt' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_unpaid_confirmation')->fresh();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($plan->id);
});

it('marks expired subscription checkouts as expired without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-expired-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-expired-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-expired-sub-123', 'EXPIRED', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_expired_confirmation')->fresh();

    expect($payment->status)->toBe(PaymentStatus::EXPIRED)
        ->and($payment->expired_at)->not->toBeNull()
        ->and($payment->fulfilled_at)->toBeNull()
        ->and(CustomerStorageSubscription::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('prevents one customer from accessing another customers fib checkout', function () {
    Http::preventStrayRequests();

    $owner = fibFlowCustomer('owner@example.com', 'fib_owner');
    $other = fibFlowCustomer('other@example.com', 'fib_other');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-private-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($owner, $plan->id, 'monthly');

    $this->actingAs($other, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();

    $this->actingAs($other, 'app')
        ->post(route('payments.fib.refresh', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();

    $this->actingAs($other, 'app')
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();
});

it('polls the fib checkout page until the subscription becomes paid and fulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-poll-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-poll-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-poll-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.payments.fib-payment', ['payment' => $payment])
        ->call('pollStatus')
        ->assertSee('Redirecting you to your app home');

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);
});

it('reflects callback-confirmed fib subscriptions on the page without another manual refresh request', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-callback-page-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-callback-page-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-callback-page-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app');

    $component = Livewire::test('app::pages.payments.fib-payment', ['payment' => $payment]);

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202);

    Http::assertSentCount(3);

    $component
        ->call('pollStatus')
        ->assertSee('Redirecting you to your app home');

    Http::assertSentCount(3);

    expect($payment->fresh()->fulfilled_at)->not->toBeNull();
});

it('renders a localized app home redirect after successful subscription confirmation', function () {
    Http::preventStrayRequests();
    app()->setLocale('ar');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-ar-home-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-ar-home-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-ar-home-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'localized_home_redirect')->fresh();

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'ar', 'payment' => $payment]))
        ->assertOk()
        ->assertSee(route('app.home', ['locale' => 'ar']))
        ->assertSee('Redirecting you to your app home');
});

it('stops automatic polling once the fib checkout reaches a terminal state', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-terminal-page-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-terminal-page-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-terminal-page-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'terminal_page_assertion')->fresh();

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('data-payment-status-polling="stopped"', false)
        ->assertDontSee('wire:poll.5s="pollStatus"', false)
        ->assertDontSee('Refresh Status');
});
