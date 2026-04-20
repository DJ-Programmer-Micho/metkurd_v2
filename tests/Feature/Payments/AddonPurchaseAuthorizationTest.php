<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    Cache::flush();
});

function createPaymentTestCustomer(string $email, string $username): Customer
{
    return Customer::create([
        'username' => $username,
        'email' => $email,
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['wallet', 'activeServiceSubscription.servicePlan']);
}

function configureAddonFib(): void
{
    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    config()->set('fib.callback_base_url', 'https://metkurd.test');
    config()->set('fib.callback_secret', 'fib-callback-secret');
    config()->set('fib.callback_secret_header', 'x-callback-secret');
    config()->set('fib.payment.category', 'ECOMMERCE');
    config()->set('fib.payment.expires_in', 'PT1H');
    config()->set('fib.payment.refundable_for', 'PT48H');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
}

function addonStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq' . $path;
}

function addonCreateResponse(string $paymentId): array
{
    return [
        'paymentId' => $paymentId,
        'readableCode' => 'ADDON-CODE-123',
        'qrCode' => 'data:image/png;base64,addon-qr',
        'personalAppLink' => 'https://fib.iq/personal/' . $paymentId,
        'businessAppLink' => 'https://fib.iq/business/' . $paymentId,
        'corporateAppLink' => 'https://fib.iq/corporate/' . $paymentId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ];
}

function addonStatusResponse(string $paymentId, string $status): array
{
    return [
        'paymentId' => $paymentId,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'paidAt' => '2026-05-01T10:05:00Z',
        'amount' => [
            'amount' => '25000',
            'currency' => 'IQD',
        ],
    ];
}

it('blocks add-on purchases for customers on the free plan', function () {
    $customer = createPaymentTestCustomer('free-addon@example.com', 'free_addon_user');
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    expect(fn () => app(AddonPurchaseService::class)->purchase($customer, $product->id))
        ->toThrow(AuthorizationException::class);
});

it('allows add-on purchases for customers with an active paid plan', function () {
    $customer = createPaymentTestCustomer('paid-addon@example.com', 'paid_addon_user');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $order = app(AddonPurchaseService::class)->purchase($customer->fresh(), $product->id, [
        'provider' => 'fake',
        'payment_method' => 'fake',
    ]);

    $wallet = $customer->fresh()->wallet()->first();

    expect($order->status)->toBe('paid')
        ->and($order->source_type)->toBe('credit_product')
        ->and((int) $order->credits_amount)->toBe((int) $product->credits_amount)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);
});

it('does not create an addon payment record for free-plan customers', function () {
    configureAddonFib();

    $customer = createPaymentTestCustomer('free-addon-payment@example.com', 'free_addon_payment_user');
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    expect(fn () => app(CreateAddonPayment::class)->handle($customer, $product->id))
        ->toThrow(AuthorizationException::class);

    expect(Payment::query()->count())->toBe(0);
});

it('shows a blocked addon page state for free-plan customers', function () {
    $customer = createPaymentTestCustomer('free-addon-page@example.com', 'free_addon_page_user');

    $this->actingAs($customer, 'app')
        ->get(route('addon-credits', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Upgrade required')
        ->assertSee('View Subscription Plans')
        ->assertDontSee('Buy Add-on');
});

it('allows paid subscribers to access the addon page', function () {
    $customer = createPaymentTestCustomer('paid-addon-page@example.com', 'paid_addon_page_user');
    grantPaidPlanForAddon($customer);

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('addon-credits', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Buy Add-on')
        ->assertDontSee('Upgrade required');
});

it('creates addon fib payments as one-time purchases only for paid subscribers', function () {
    configureAddonFib();

    $customer = createPaymentTestCustomer('paid-addon-payment@example.com', 'paid_addon_payment_user');
    grantPaidPlanForAddon($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        addonStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        addonStageUrl('/protected/v1/payments') => Http::response(
            addonCreateResponse('fib-addon-one-time-123'),
            201
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id)->fresh();

    expect($payment->purchase_type)->toBe(PurchaseType::ADDON_CREDITS)
        ->and($payment->payment_mode)->toBe(PaymentMode::ONE_TIME)
        ->and($payment->status->value)->toBe('awaiting_customer_action')
        ->and(data_get($payment->purchase_snapshot, 'renewal_strategy'))->toBeNull();
});

it('fulfills addon credits without creating recurring subscription records', function () {
    configureAddonFib();

    $customer = createPaymentTestCustomer('paid-addon-fulfillment@example.com', 'paid_addon_fulfillment_user');
    grantPaidPlanForAddon($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();
    $serviceSubscriptionsBefore = CustomerServiceSubscription::query()->where('customer_id', $customer->id)->count();
    $storageSubscriptionsBefore = CustomerStorageSubscription::query()->where('customer_id', $customer->id)->count();

    Http::fake([
        addonStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        addonStageUrl('/protected/v1/payments') => Http::response(
            addonCreateResponse('fib-addon-fulfillment-123'),
            201
        ),
        addonStageUrl('/protected/v1/payments/fib-addon-fulfillment-123/status') => Http::response(
            addonStatusResponse('fib-addon-fulfillment-123', 'PAID'),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'addon_one_time_confirmation')->fresh();

    expect($payment->payment_mode)->toBe(PaymentMode::ONE_TIME)
        ->and(CustomerServiceSubscription::query()->where('customer_id', $customer->id)->count())->toBe($serviceSubscriptionsBefore)
        ->and(CustomerStorageSubscription::query()->where('customer_id', $customer->id)->count())->toBe($storageSubscriptionsBefore);
});

function grantPaidPlanForAddon(Customer $customer): ServicePlan
{
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}
