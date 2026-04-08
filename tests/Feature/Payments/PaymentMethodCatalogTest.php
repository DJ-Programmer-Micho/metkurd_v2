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
