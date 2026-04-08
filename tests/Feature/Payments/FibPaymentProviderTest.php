<?php

use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentMethod;
use App\Services\Payments\Providers\FibPaymentProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();

    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.environment', 'staging');
    config()->set('fib.base_url', 'https://fib.stage.fib.iq');
    config()->set('fib.client_id', 'fib-test-client');
    config()->set('fib.client_secret', 'fib-secret');
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.paths.token', '/auth/realms/{realm}/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.payment_refund', '/protected/v1/payments/{paymentId}/refund');
});

function fibTestMethod(): PaymentMethod
{
    return new PaymentMethod([
        'code' => 'fib',
        'driver' => 'fib',
        'name' => 'First Iraqi Bank',
        'supports_webhooks' => true,
        'supports_qr' => true,
        'supports_redirect' => true,
    ]);
}

function fibTestCustomer(): Customer
{
    return new Customer([
        'id' => 7,
        'username' => 'fib_test_customer',
        'email' => 'fib@example.com',
    ]);
}

function fibTestIntent(array $attributes = []): PaymentIntent
{
    return new PaymentIntent(array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 7,
        'provider' => 'fib',
        'payment_method' => 'fib',
        'purpose_type' => 'service_plan',
        'purpose_id' => 1,
        'purpose_code' => 'pro',
        'purpose_name' => 'Pro',
        'billing_interval' => 'monthly',
        'base_amount_iqd' => 26500,
        'gross_amount_iqd' => 26500,
        'status' => PaymentIntentStatus::PENDING->value,
        'merchant_transaction_id' => 'FIB-TEST-' . strtoupper(Str::random(8)),
        'meta' => [],
    ], $attributes));
}

it('initializes fib checkout as a pending payment action', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments' => Http::response([
            'paymentId' => 'fib-payment-123',
            'readableCode' => 'S3LE-NZ2S-ZNGF',
            'qrCode' => 'data:image/png;base64,fake-qr',
            'validUntil' => '2026-04-01T10:15:00Z',
            'personalAppLink' => 'https://fib.iq/personal-app-link?paymentId=fib-payment-123',
            'businessAppLink' => 'https://fib.iq/business-app-link?paymentId=fib-payment-123',
            'corporateAppLink' => 'https://fib.iq/corporate-app-link?paymentId=fib-payment-123',
        ], 201),
    ]);

    $provider = app(FibPaymentProvider::class);
    $intent = fibTestIntent();

    $checkout = $provider->initializeCheckout(
        $intent,
        fibTestMethod(),
        fibTestCustomer(),
        ['name' => 'Pro'],
        ['redirect_url' => 'https://app.test/en/app/subscription-plans']
    );

    expect($checkout['status'])->toBe(PaymentIntentStatus::REQUIRES_ACTION->value)
        ->and($checkout['provider_payment_id'])->toBe('fib-payment-123')
        ->and(data_get($checkout, 'meta.checkout_action.readable_code'))->toBe('S3LE-NZ2S-ZNGF')
        ->and(data_get($checkout, 'transactions.0.transaction_type'))->toBe('initiate');

    Http::assertSent(function ($request) use ($intent) {
        if ($request->url() !== 'https://fib.stage.fib.iq/protected/v1/payments') {
            return true;
        }

        $data = $request->data();

        return data_get($data, 'monetaryValue.amount') === 26500
            && data_get($data, 'monetaryValue.currency') === 'IQD'
            && data_get($data, 'statusCallbackUrl') !== null
            && array_key_exists('description', $data)
            && ! array_key_exists('category', $data)
            && str_contains((string) data_get($data, 'description', ''), (string) $intent->merchant_transaction_id);
    });
});

it('syncs fib checkout status to paid', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments/fib-payment-123/status' => Http::response([
            'paymentId' => 'fib-payment-123',
            'status' => 'PAID',
            'paidAt' => '2026-04-01T10:10:00Z',
        ], 200),
    ]);

    $provider = app(FibPaymentProvider::class);
    $intent = fibTestIntent([
        'status' => PaymentIntentStatus::REQUIRES_ACTION->value,
        'provider_payment_id' => 'fib-payment-123',
        'meta' => [
            'checkout_action' => [
                'readable_code' => 'S3LE-NZ2S-ZNGF',
            ],
        ],
    ]);

    $checkout = $provider->synchronizeCheckout($intent, fibTestMethod());

    expect($checkout['status'])->toBe(PaymentIntentStatus::PAID->value)
        ->and($checkout['paid_at'])->not->toBeNull()
        ->and(data_get($checkout, 'transactions.0.transaction_type'))->toBe('status_sync')
        ->and(data_get($checkout, 'meta.checkout_action'))->toBeNull();
});

it('maps fib declining reasons to canceled and expired intent states', function () {
    $provider = app(FibPaymentProvider::class);
    $method = fibTestMethod();
    $intent = fibTestIntent();

    $expired = $provider->normalizeWebhookPayload([
        'id' => 'fib-payment-expired',
        'status' => 'DECLINED',
        'decliningReason' => 'PAYMENT_EXPIRATION',
    ]);

    $canceled = $provider->normalizeWebhookPayload([
        'id' => 'fib-payment-canceled',
        'status' => 'DECLINED',
        'decliningReason' => 'PAYMENT_CANCELLATION',
    ]);

    expect($expired['normalized_status'])->toBe(PaymentIntentStatus::EXPIRED->value)
        ->and($canceled['normalized_status'])->toBe(PaymentIntentStatus::CANCELED->value);
});
