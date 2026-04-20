<?php

use App\Domain\Payments\Fib\FibTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage-subscriptions.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
});

it('retrieves and caches a fib access token', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib-stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
            'token_type' => 'Bearer',
            'scope' => 'profile',
        ], 200),
    ]);

    $service = app(FibTokenService::class);

    $first = $service->getToken();
    $second = $service->getToken();

    expect($first->accessToken)->toBe('fib-access-token')
        ->and($second->accessToken)->toBe('fib-access-token')
        ->and($first->expiresIn)->toBe(60);

    Http::assertSentCount(1);
});

it('uses the subscription credential profile when requested', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib-stage-subscriptions.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => function ($request) {
            return data_get($request->data(), 'client_id') === 'fib-subscription-client'
                ? Http::response([
                    'access_token' => 'fib-subscription-access-token',
                    'expires_in' => 60,
                    'token_type' => 'Bearer',
                    'scope' => 'profile',
                ], 200)
                : Http::response([
                    'access_token' => 'unexpected-payment-token',
                    'expires_in' => 60,
                    'token_type' => 'Bearer',
                    'scope' => 'profile',
                ], 200);
        },
    ]);

    $service = app(FibTokenService::class);

    $token = $service->getToken('subscription');

    expect($token->accessToken)->toBe('fib-subscription-access-token');
});
