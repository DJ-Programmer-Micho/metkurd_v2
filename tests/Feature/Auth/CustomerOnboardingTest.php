<?php

use App\Models\Customer;
use App\Models\ServicePlan;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('assigns the free plan with the registration timestamp when a customer is created', function () {
    Carbon::setTestNow('2026-03-22 12:34:56');

    $customer = Customer::create([
        'username' => 'observer_user',
        'email' => 'observer@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => false,
        'phone_verify' => false,
    ])->fresh(['activeServiceSubscription.servicePlan']);

    expect($customer->activeServiceSubscription)->not->toBeNull();
    expect($customer->activeServiceSubscription->servicePlan->code)->toBe('free');
    expect($customer->activeServiceSubscription->starts_at?->toDateTimeString())
        ->toBe($customer->created_at?->toDateTimeString());
});

it('assigns the free plan when socialite creates a customer on first login', function () {
    Carbon::setTestNow('2026-03-22 14:15:16');

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => 'google-123',
        'name' => 'Social Person',
        'email' => 'social@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
    ]);

    $provider = \Mockery::mock();
    $provider->shouldReceive('stateless')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('social.google.callback'));

    $customer = Customer::query()
        ->where('email', 'social@example.com')
        ->firstOrFail()
        ->fresh(['activeServiceSubscription.servicePlan']);

    $response->assertRedirect(route('app.phone.otp'));
    $this->assertAuthenticatedAs($customer, 'app');

    expect($customer->g_id)->toBe('google-123');
    expect($customer->activeServiceSubscription)->not->toBeNull();
    expect($customer->activeServiceSubscription->servicePlan->code)->toBe('free');
    expect($customer->activeServiceSubscription->starts_at?->toDateTimeString())
        ->toBe($customer->created_at?->toDateTimeString());
});

it('fails cleanly when no active free service plan is configured', function () {
    ServicePlan::query()->where('code', 'free')->delete();

    expect(fn () => Customer::create([
        'username' => 'broken_user',
        'email' => 'broken@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => false,
        'phone_verify' => false,
    ]))->toThrow(RuntimeException::class, 'No active default service plan is configured.');

    expect(
        Customer::query()->where('email', 'broken@example.com')->exists()
    )->toBeFalse();
});
