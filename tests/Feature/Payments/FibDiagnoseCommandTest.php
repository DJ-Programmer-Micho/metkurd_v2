<?php

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    $this->seed();

    config()->set('app.url', 'https://metkurd.ai');
    config()->set('fib.environment', 'production');
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.callback_base_url', 'https://metkurd.ai');
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.profiles.payment.base_url', 'https://fib.prod.fib.iq');
    config()->set('fib.profiles.payment.base_url_source', 'FIB_PAYMENT_BASE_URL');
    config()->set('fib.profiles.payment.client_id', 'pg-met-production');
    config()->set('fib.profiles.payment.client_id_source', 'FIB_PAYMENT_CLIENT_ID');
    config()->set('fib.profiles.payment.client_secret', 'payment-secret');
    config()->set('fib.profiles.payment.client_secret_source', 'FIB_PAYMENT_CLIENT_SECRET');
    config()->set('fib.profiles.subscription.base_url', 'https://fib.prod.fib.iq');
    config()->set('fib.profiles.subscription.base_url_source', 'FIB_SUBSCRIPTION_BASE_URL');
    config()->set('fib.profiles.subscription.client_id', 'sub-met-production');
    config()->set('fib.profiles.subscription.client_id_source', 'FIB_SUBSCRIPTION_CLIENT_ID');
    config()->set('fib.profiles.subscription.client_secret', 'subscription-secret');
    config()->set('fib.profiles.subscription.client_secret_source', 'FIB_SUBSCRIPTION_CLIENT_SECRET');
    config()->set('fib.paths.token', '/auth/realms/{realm}/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
});

it('prints fib runtime and profile diagnostics without a payment uuid', function () {
    $this->artisan('payments:fib:diagnose --no-provider-check')
        ->expectsOutputToContain('RUNTIME CONTEXT')
        ->expectsOutputToContain('PAYMENT PROFILE')
        ->expectsOutputToContain('SUBSCRIPTION PROFILE')
        ->expectsOutputToContain('https://metkurd.ai/payments/webhooks/fib')
        ->assertExitCode(0);
});

it('shows local and live provider status details for a one-time fib payment', function () {
    Http::preventStrayRequests();

    $customer = Customer::create([
        'username' => 'fib_diag_'.Str::lower(Str::random(8)),
        'email' => 'fib-diagnose-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'FIB-DIAG-'.strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'fib-prod-payment-123',
        'amount' => 25000,
        'currency' => 'IQD',
    ]);

    Http::fake([
        'https://fib.prod.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-production-access-token',
            'expires_in' => 60,
            'token_type' => 'Bearer',
        ], 200),
        'https://fib.prod.fib.iq/protected/v1/payments/fib-prod-payment-123/status' => Http::response([
            'paymentId' => 'fib-prod-payment-123',
            'status' => 'DECLINED',
            'decliningReason' => 'PAYMENT_AUTHORIZATION_FAILED',
            'declinedAt' => '2026-04-27T10:12:00Z',
            'validUntil' => '2026-04-27T10:30:00Z',
            'amount' => [
                'amount' => '25000',
                'currency' => 'IQD',
            ],
            'errors' => [[
                'code' => 'REJECTED_BY_PROVIDER',
                'title' => 'Rejected by provider',
                'detail' => 'Authorization rejected',
            ]],
        ], 200),
    ]);

    $this->artisan('payments:fib:diagnose '.$payment->uuid)
        ->expectsOutputToContain('PAYMENT RECORD')
        ->expectsOutputToContain((string) $payment->uuid)
        ->expectsOutputToContain('LIVE PROVIDER STATUS')
        ->expectsOutputToContain('DECLINED')
        ->expectsOutputToContain('failed')
        ->expectsOutputToContain('PAYMENT_AUTHORIZATION_FAILED')
        ->expectsOutputToContain('REJECTED_BY_PROVIDER')
        ->assertExitCode(0);
});
