<?php

use App\Models\PaymentMethod;
use App\Services\Payments\PaymentProviderManager;

it('marks fake payments as config-ready and checkout-ready', function () {
    config()->set('payments.providers.fake.enabled', true);

    $method = new PaymentMethod([
        'code' => 'fake',
        'driver' => 'fake',
        'name' => 'Fake Payments',
    ]);

    $manager = app(PaymentProviderManager::class);

    expect($manager->isEnabled($method->driver))->toBeTrue()
        ->and($manager->configurationReady($method))->toBeTrue()
        ->and($manager->checkoutReady($method))->toBeTrue()
        ->and($manager->configurationIssues($method))->toBe([]);
});

it('marks fib as config-ready and checkout-ready when credentials are present', function () {
    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.base_url', 'https://fib.stage.fib.iq');
    config()->set('fib.client_id', 'fib-test-client');
    config()->set('fib.client_secret', 'fib-secret');
    config()->set('fib.realm', 'fib-online-shop');

    $method = new PaymentMethod([
        'code' => 'fib',
        'driver' => 'fib',
        'name' => 'First Iraqi Bank',
    ]);

    $manager = app(PaymentProviderManager::class);

    expect($manager->isEnabled($method->driver))->toBeTrue()
        ->and($manager->configurationReady($method))->toBeTrue()
        ->and($manager->checkoutReady($method))->toBeTrue()
        ->and($manager->configurationIssues($method))->toBe([]);
});

it('reports missing fib configuration clearly', function () {
    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.base_url', null);
    config()->set('fib.client_id', null);
    config()->set('fib.client_secret', null);
    config()->set('fib.realm', null);

    $method = new PaymentMethod([
        'code' => 'fib',
        'driver' => 'fib',
        'name' => 'First Iraqi Bank',
    ]);

    $manager = app(PaymentProviderManager::class);

    expect($manager->configurationReady($method))->toBeFalse()
        ->and($manager->checkoutReady($method))->toBeFalse()
        ->and($manager->configurationIssues($method))->toContain('Missing Base URL')
        ->and($manager->configurationIssues($method))->toContain('Missing Client ID')
        ->and($manager->configurationIssues($method))->toContain('Missing Client Secret')
        ->and($manager->configurationIssues($method))->toContain('Missing Realm');
});
