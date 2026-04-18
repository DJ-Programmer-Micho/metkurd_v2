<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Enums\PaymentMode;
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
    config()->set('services.fib.enabled', true);
    config()->set('services.fib.base_url', 'https://fib-stage.fib.iq');
    config()->set('services.fib.realm', 'fib-online-shop');
    config()->set('services.fib.client_id', 'fib-test-client');
    config()->set('services.fib.client_secret', 'fib-secret');
    config()->set('services.fib.callback_secret', 'fib-callback-secret');
    config()->set('services.fib.callback_secret_header', 'x-callback-secret');
    config()->set('services.fib.payment.category', 'ECOMMERCE');
    config()->set('services.fib.payment.expires_in', 'PT1H');
    config()->set('services.fib.payment.refundable_for', 'PT48H');
    config()->set('services.fib.token_ttl_seconds', 60);
    config()->set('services.fib.http.timeout', 15);
    config()->set('services.fib.http.retries', 1);
    config()->set('services.fib.http.retry_sleep_ms', 1);
    config()->set('services.fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('services.fib.paths.payments', '/protected/v1/payments');
    config()->set('services.fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('services.fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
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

function fibFlowGrantPaidPlan(Customer $customer): ServicePlan
{
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

it('creates a plan payment and stores fib checkout details', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-plan-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly')->fresh();
    $quote = app(PaymentFeeCalculator::class)->quote('fib', $plan->priceIqdForCycle('yearly'));

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and((int) round((float) $payment->amount))->toBe((int) $quote['gross_amount_iqd'])
        ->and($payment->fib_payment_id)->toBe('fib-plan-123')
        ->and($payment->readable_code)->toBe('CODE-123')
        ->and($payment->valid_until)->not->toBeNull()
        ->and(data_get($payment->create_payload, 'monetaryValue.currency'))->toBe('IQD')
        ->and((int) data_get($payment->purchase_snapshot, 'fee_quote.net_amount_iqd'))->toBe($plan->priceIqdForCycle('yearly'))
        ->and(data_get($payment->purchase_snapshot, 'renewal_strategy'))->toBe('manual_renewal');

    Http::assertSent(function ($request) use ($quote) {
        if ($request->url() !== fibFlowStageUrl('/protected/v1/payments')) {
            return true;
        }

        return data_get($request->data(), 'monetaryValue.amount') === (string) $quote['gross_amount_iqd']
            && data_get($request->data(), 'monetaryValue.currency') === 'IQD'
            && data_get($request->data(), 'category') === 'ECOMMERCE'
            && data_get($request->data(), 'expiresIn') === 'PT1H'
            && data_get($request->data(), 'refundableFor') === 'PT48H'
            && filled(data_get($request->data(), 'statusCallbackUrl'))
            && filled(data_get($request->data(), 'redirectUri'));
    });
});

it('marks the local payment as failed when fib payment creation fails', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response([
            'message' => 'invalid request',
        ], 400),
    ]);

    expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly'))
        ->toThrow(FibApiException::class);

    $payment = Payment::query()->latest('id')->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fib_payment_id)->toBeNull()
        ->and($payment->status_reason)->toContain('invalid request')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_payment_create_failed')
            ->exists())->toBeTrue();
});

it('updates payment state from a validated callback and fulfills storage exactly once', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-storage-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-storage-123/status') => Http::response(
            fibFlowStatusResponse('fib-storage-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->postJson(
        route('payments.fib.callback'),
        ['id' => $payment->fib_payment_id, 'status' => 'PAID'],
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
        ->assertSee('Congrats! Your payment was confirmed successfully.');
});

it('updates payment state from a manual status refresh and redirects back to the fib payment page', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-refresh-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-refresh-123/status') => Http::response(
            fibFlowStatusResponse('fib-refresh-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
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
        ->assertSee('Congrats! Your payment was confirmed successfully.');
});

it('renders automatic polling on pending fib payment pages and keeps manual refresh as a fallback', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-live-page-123'),
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
        ->assertSee('Refresh Status');
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

it('fulfills a successful plan subscription payment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-plan-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-plan-fulfill-123/status') => Http::response(
            fibFlowStatusResponse('fib-plan-fulfill-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_plan_confirmation')->fresh();

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id)
        ->and(CustomerServiceSubscription::query()
            ->where('payment_id', $payment->id)
            ->where('service_plan_id', $plan->id)
            ->exists())->toBeTrue()
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

it('fulfills a successful storage subscription payment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-storage-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-storage-fulfill-123/status') => Http::response(
            fibFlowStatusResponse('fib-storage-fulfill-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_storage_confirmation')->fresh();

    expect($payment->purchase_type)->toBe(PurchaseType::STORAGE_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id)
        ->and(CustomerStorageSubscription::query()
            ->where('payment_id', $payment->id)
            ->where('storage_plan_id', $plan->id)
            ->exists())->toBeTrue();
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

it('cancels a fib payment and keeps it unfulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-cancel-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-cancel-123/cancel') => Http::response(null, 204),
        fibFlowStageUrl('/protected/v1/payments/fib-cancel-123/status') => Http::response(
            fibFlowStatusResponse('fib-cancel-123', 'DECLINED', [
                'decliningReason' => 'PAYMENT_CANCELLATION',
                'declinedAt' => '2026-05-01T10:08:00Z',
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
            ->where('event_type', 'payment_cancel_requested')
            ->exists())->toBeTrue();
});

it('keeps unpaid payments awaiting customer action without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-unpaid-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-unpaid-123/status') => Http::response(
            fibFlowStatusResponse('fib-unpaid-123', 'UNPAID'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_unpaid_confirmation')->fresh();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($plan->id);
});

it('marks expired payments as expired without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-expired-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-expired-123/status') => Http::response(
            fibFlowStatusResponse('fib-expired-123', 'DECLINED', [
                'decliningReason' => 'PAYMENT_EXPIRATION',
                'declinedAt' => '2026-05-01T10:08:00Z',
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

it('prevents one customer from accessing another customers fib payment', function () {
    Http::preventStrayRequests();

    $owner = fibFlowCustomer('owner@example.com', 'fib_owner');
    $other = fibFlowCustomer('other@example.com', 'fib_other');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-private-123'),
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

it('polls the fib payment page until the payment becomes paid and fulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-poll-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-poll-123/status') => Http::response(
            fibFlowStatusResponse('fib-poll-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
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

it('reflects callback-confirmed fib payments on the page without another manual refresh request', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-callback-page-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-callback-page-123/status') => Http::response(
            fibFlowStatusResponse('fib-callback-page-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app');

    $component = Livewire::test('app::pages.payments.fib-payment', ['payment' => $payment]);

    $this->postJson(
        route('payments.fib.callback'),
        ['id' => $payment->fib_payment_id, 'status' => 'PAID'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202);

    Http::assertSentCount(3);

    $component
        ->call('pollStatus')
        ->assertSee('Redirecting you to your app home');

    Http::assertSentCount(3);

    expect($payment->fresh()->fulfilled_at)->not->toBeNull();
});

it('renders a localized app home redirect after successful payment confirmation', function () {
    Http::preventStrayRequests();
    app()->setLocale('ar');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-ar-home-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-ar-home-123/status') => Http::response(
            fibFlowStatusResponse('fib-ar-home-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
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

it('stops automatic polling once the fib payment reaches a terminal state', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-terminal-page-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-terminal-page-123/status') => Http::response(
            fibFlowStatusResponse('fib-terminal-page-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
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
