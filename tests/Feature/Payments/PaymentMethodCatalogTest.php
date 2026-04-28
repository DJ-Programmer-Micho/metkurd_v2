<?php

use App\Models\PaymentMethod;
use App\Services\Payments\PaymentMethodCatalog;

beforeEach(function () {
    config()->set('payments.providers.fake.enabled', true);
    config()->set('payments.fake_enabled', true);
    config()->set('payments.providers.fib.enabled', false);
    config()->set('payments.providers.areeba.enabled', false);

    $this->seed();
});

it('exposes only checkout-ready visible payment methods for customer checkout', function () {
    $options = app(PaymentMethodCatalog::class)->checkoutOptions('service_plan');
    $codes = collect($options)->pluck('code')->all();

    expect($codes)->toContain('fake')
        ->and($codes)->not->toContain('fib')
        ->and($codes)->not->toContain('areeba');
});

it('removes hidden methods from checkout options after the catalog cache is flushed', function () {
    $method = PaymentMethod::query()->where('code', 'fake')->firstOrFail();
    $method->update(['is_visible' => false]);

    app(PaymentMethodCatalog::class)->flushCache();

    $codes = collect(app(PaymentMethodCatalog::class)->checkoutOptions('service_plan'))
        ->pluck('code')
        ->all();

    expect($codes)->not->toContain('fake');
});

it('keeps fake provider unavailable when disabled even if configured as default', function () {
    config()->set('payments.default_provider', 'fake');
    config()->set('payments.providers.fake.enabled', false);
    config()->set('payments.fake_enabled', false);
    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    PaymentMethod::query()->where('code', 'fib')->update([
        'is_active' => true,
        'is_visible' => true,
    ]);

    app(PaymentMethodCatalog::class)->flushCache();

    $catalog = app(PaymentMethodCatalog::class);
    $default = $catalog->defaultForPurpose('service_plan', 'IQD');
    $codes = collect($catalog->checkoutOptions('service_plan'))->pluck('code')->all();

    expect($codes)->not->toContain('fake')
        ->and($default)->not->toBeNull()
        ->and($default?->driver)->toBe('fib');
});

it('does not silently return fake when fake is disabled and no other method is checkout-ready', function () {
    config()->set('payments.default_provider', 'fake');
    config()->set('payments.providers.fake.enabled', false);
    config()->set('payments.fake_enabled', false);
    config()->set('payments.providers.fib.enabled', false);
    config()->set('payments.providers.areeba.enabled', false);

    app(PaymentMethodCatalog::class)->flushCache();

    $catalog = app(PaymentMethodCatalog::class);
    $default = $catalog->defaultForPurpose('service_plan', 'IQD');
    $codes = collect($catalog->checkoutOptions('service_plan'))->pluck('code')->all();

    expect($codes)->not->toContain('fake')
        ->and($default)->toBeNull();
});
