<?php

use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\StoragePlan;
use App\Models\ServicePlan;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    app()->setLocale('en');
    myStorageConfigureFib();
    $this->seed();
});

function myStorageConfigureFib(): void
{
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
    config()->set('fib.subscription.hourly_testing_enabled', false);
    config()->set('fib.subscription.intervals.monthly', 'P1M');
    config()->set('fib.subscription.intervals.yearly', 'P1Y');
    config()->set('fib.subscription.intervals.hourly', 'PT1H');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
}

function myStorageCustomer(?string $email = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => 'storage_' . $suffix,
        'email' => $email ?? 'storage-' . $suffix . '@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function myStorageStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq' . $path;
}

function myStorageSubscriptionCreateResponse(string $subscriptionId): array
{
    return [
        'subscriptionId' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'qrCode' => 'data:image/png;base64,fake-subscription-qr',
        'appLink' => 'https://fib.iq/app/' . $subscriptionId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ];
}

function myStorageAddonCreateResponse(string $paymentId): array
{
    return [
        'paymentId' => $paymentId,
        'readableCode' => 'PAY-CODE-123',
        'qrCode' => 'data:image/png;base64,fake-payment-qr',
        'personalAppLink' => 'https://fib.iq/personal/' . $paymentId,
        'businessAppLink' => 'https://fib.iq/business/' . $paymentId,
        'corporateAppLink' => 'https://fib.iq/corporate/' . $paymentId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ];
}

it('creates a recurring storage subscription checkout from my-storage and redirects to the fib checkout page', function () {
    Http::preventStrayRequests();

    $customer = myStorageCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        myStorageStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-subscription-access-token',
            'expires_in' => 60,
        ], 200),
        myStorageStageUrl('/protected/v1/subscriptions') => Http::response(
            myStorageSubscriptionCreateResponse('fib-storage-sub-from-page-123'),
            201
        ),
    ]);

    $component = Livewire::actingAs($customer, 'app')
        ->test('app::pages.my-storage.app-storage')
        ->call('openStorageConfirm', $plan->id)
        ->call('confirmStoragePlanChange');

    $payment = Payment::query()->latest('id')->firstOrFail();

    $component->assertRedirect(route('payments.fib.show', [
        'locale' => 'en',
        'payment' => $payment,
    ]));

    expect($payment->purchase_type)->toBe(PurchaseType::STORAGE_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->fib_subscription_id)->toBe('fib-storage-sub-from-page-123')
        ->and($payment->fib_payment_id)->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === myStorageStageUrl('/protected/v1/subscriptions'));
    Http::assertNotSent(fn ($request) => $request->url() === myStorageStageUrl('/protected/v1/payments'));
});

it('keeps addon checkout on one-time payment objects', function () {
    Http::preventStrayRequests();

    $customer = myStorageCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    Http::fake([
        myStorageStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-payment-access-token',
            'expires_in' => 60,
        ], 200),
        myStorageStageUrl('/protected/v1/payments') => Http::response(
            myStorageAddonCreateResponse('fib-addon-from-storage-suite-123'),
            201
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id)->fresh();

    expect($payment->purchase_type)->toBe(PurchaseType::ADDON_CREDITS)
        ->and($payment->payment_mode)->toBe(PaymentMode::ONE_TIME)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::PAYMENT)
        ->and($payment->fib_payment_id)->toBe('fib-addon-from-storage-suite-123')
        ->and($payment->fib_subscription_id)->toBeNull();
});

it('schedules storage cancellation at period end from my-storage without removing access immediately', function () {
    $customer = myStorageCustomer();
    $plan = StoragePlan::query()->where('code', 'premium-10240')->firstOrFail();

    app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.my-storage.app-storage')
        ->call('openStorageCancelConfirm')
        ->call('confirmStoragePlanCancel')
        ->assertSet('currentStoragePlanCancellationScheduled', true);

    $subscription = $customer->fresh()->activeStorageSubscription()->firstOrFail();

    expect($subscription->status)->toBe('active')
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->ends_at)->not->toBeNull()
        ->and($subscription->ends_at->isFuture())->toBeTrue();
});

