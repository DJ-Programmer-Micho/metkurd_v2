<?php

use Illuminate\Support\Facades\Http;

function fibDebugJwt(string $issuer): string
{
    $encode = static function (array $payload): string {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    };

    return $encode(['alg' => 'HS256', 'typ' => 'JWT']) . '.' . $encode(['iss' => $issuer]) . '.signature';
}

beforeEach(function () {
    config()->set('fib.environment', 'staging');
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.callback_base_url', 'https://callbacks.metkurd.test');
    config()->set('fib.http.timeout', 15);
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.base_url_source', 'FIB_PAYMENT_BASE_URL_STAGING');
    config()->set('fib.profiles.payment.client_id', 'fib-payment-client');
    config()->set('fib.profiles.payment.client_id_source', 'FIB_PAYMENT_CLIENT_ID');
    config()->set('fib.profiles.payment.client_secret', 'fib-payment-secret');
    config()->set('fib.profiles.payment.client_secret_source', 'FIB_PAYMENT_CLIENT_SECRET');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.base_url_source', 'legacy:FIB_BASE_URL_STAGING');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_id_source', 'legacy:FIB_CLIENT_ID');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.profiles.subscription.client_secret_source', 'legacy:FIB_CLIENT_SECRET');
    config()->set('fib.paths.token', '/auth/realms/{realm}/protocol/openid-connect/token');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
});

it('prints the resolved host and source for each fib profile', function () {
    $this->artisan('fib:debug-config')
        ->expectsOutputToContain('PAYMENT PROFILE')
        ->expectsOutputToContain('FIB_PAYMENT_BASE_URL_STAGING')
        ->expectsOutputToContain('SUBSCRIPTION PROFILE')
        ->expectsOutputToContain('Using legacy base_url fallback from legacy:FIB_BASE_URL_STAGING.')
        ->assertExitCode(0);
});

it('probes the configured payment profile without printing secrets', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => fibDebugJwt('https://fib-stage.fib.iq/auth/realms/fib-online-shop'),
            'expires_in' => 60,
            'token_type' => 'Bearer',
        ], 200),
        'https://fib-stage.fib.iq/protected/v1/payments/not-a-real-id/status' => Http::response([
            'traceId' => 'probe-trace-id',
            'errors' => [[
                'code' => 'INVALID_REQUEST',
                'detail' => 'general_invalid_request_details',
            ]],
        ], 400),
    ]);

    $this->artisan('fib:debug-config --profile=payment --probe')
        ->expectsOutputToContain('token_status: 200')
        ->expectsOutputToContain('token_issuer: https://fib-stage.fib.iq/auth/realms/fib-online-shop')
        ->expectsOutputToContain('protected_status: 400')
        ->expectsOutputToContain('probe-trace-id')
        ->doesntExpectOutputToContain('fib-payment-secret')
        ->assertExitCode(0);
});
