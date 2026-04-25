<?php

use App\Models\PaymentMethod;

beforeEach(function () {
    $this->seed();
});

it('passes billing master-data diagnostics on a healthy baseline database', function () {
    $this->artisan('metkurd:diagnose-billing-master-data')
        ->expectsOutputToContain('service_plans table exists')
        ->expectsOutputToContain('FIB payment method row exists')
        ->expectsOutputToContain('All critical billing master-data checks passed.')
        ->assertExitCode(0);
});

it('fails billing master-data diagnostics when required payment method rows are missing', function () {
    PaymentMethod::query()->where('code', 'fib')->delete();

    $this->artisan('metkurd:diagnose-billing-master-data')
        ->expectsOutputToContain('FIB payment method row exists')
        ->assertExitCode(1);
});

it('can seed missing billing master data and recover failed diagnostics', function () {
    PaymentMethod::query()->where('code', 'fib')->delete();

    $this->artisan('metkurd:diagnose-billing-master-data --seed-missing')
        ->expectsOutputToContain('Running BillingMasterDataSeeder before diagnostics...')
        ->expectsOutputToContain('All critical billing master-data checks passed.')
        ->assertExitCode(0);

    expect(PaymentMethod::query()->where('code', 'fib')->exists())->toBeTrue();
});

it('fails when no recurring-capable method is available for plan subscriptions and recovers after seeding', function () {
    PaymentMethod::query()->where('code', 'fib')->update([
        'supports_recurring' => false,
    ]);

    $this->artisan('metkurd:diagnose-billing-master-data')
        ->expectsOutputToContain('active+visible recurring service-plan method available')
        ->assertExitCode(1);

    $this->artisan('metkurd:diagnose-billing-master-data --seed-missing')
        ->expectsOutputToContain('Running BillingMasterDataSeeder before diagnostics...')
        ->expectsOutputToContain('All critical billing master-data checks passed.')
        ->assertExitCode(0);

    expect((bool) PaymentMethod::query()->where('code', 'fib')->value('supports_recurring'))->toBeTrue();
});
