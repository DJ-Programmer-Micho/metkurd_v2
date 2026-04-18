<?php

use App\Domain\Payments\Fib\FibTokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config()->set('services.fib.base_url', 'https://fib-stage.fib.iq');
    config()->set('services.fib.realm', 'fib-online-shop');
    config()->set('services.fib.client_id', 'fib-test-client');
    config()->set('services.fib.client_secret', 'fib-secret');
    config()->set('services.fib.token_ttl_seconds', 60);
    config()->set('services.fib.http.timeout', 15);
    config()->set('services.fib.http.retries', 1);
    config()->set('services.fib.http.retry_sleep_ms', 1);
    config()->set('services.fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
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
