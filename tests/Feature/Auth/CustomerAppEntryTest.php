<?php

use App\Models\Customer;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Notification::fake();
    $this->seed();
    config(['app.url' => 'http://localhost', 'customer_app.v1_enabled' => true, 'metkurd_v2.enabled' => true]);
    Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
    $this->customer = Customer::create(['username' => 'entry-test', 'email' => 'entry@example.test', 'password' => 'Secret123!',
        'status' => 1, 'email_verify' => true, 'phone_verify' => true, 'phone_verified_at' => now()]);
    $this->mock(TurnstileVerifier::class)->shouldReceive('verify')->andReturn(['success' => true]);
});

function signInAtCustomerBoundary($test, string $destination, bool $remember = false, string $password = 'Secret123!'): void
{
    $page = $test->get('/app/signin')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    $test->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['login' => $test->customer->email, 'password' => $password, 'remember' => $remember, 'cfTurnstileResponse' => 'fixture'],
        'calls' => [['method' => 'signIn', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertOk()->assertJsonPath('components.0.effects.redirect', $destination);
}

it('uses the version matrix for real password sign in and already authenticated entry', function ($locale, $v1, $v2, $suffix) {
    config(['customer_app.v1_enabled' => $v1, 'metkurd_v2.enabled' => $v2]);
    session(['applocale' => $locale]);
    $destination = 'http://localhost/'.$locale.$suffix;
    signInAtCustomerBoundary($this, $destination, true);
    $this->assertAuthenticatedAs($this->customer, 'app');
    expect($this->customer->fresh()->getRememberToken())->not->toBeNull();
    $this->get('/app/signin')->assertRedirect($destination);
    $this->get('/')->assertRedirect($destination);
    if ($v1) {
        $this->get('/'.$locale.'/app/home')->assertOk();
    } else {
        $this->get('/'.$locale.'/app/home')->assertRedirect($destination);
    }
    $this->get('/'.$locale.'/app-v2')->assertStatus($v2 ? 200 : 404);
    if (! $v1 && ! $v2) {
        $this->get('/'.$locale)->assertOk();
    }
})->with(['en', 'ar', 'ku'])->with([[true, true, '/app-v2'], [false, true, '/app-v2'], [true, false, '/app/home'], [false, false, '']]);

it('retains guest locale and intended V2 deep links through login and logout', function () {
    $this->get('/ku/app-v2/ocr/scanner')->assertRedirect(route('app.signin'));
    expect(session('applocale'))->toBe('ku');
    signInAtCustomerBoundary($this, 'http://localhost/ku/app-v2/ocr/scanner');
    expect(session()->has('url.intended'))->toBeFalse();
    $this->post('/app/logout')->assertRedirect(route('app.signin'));
    $this->assertGuest('app');
    expect(session('applocale'))->toBe('ku');
    signInAtCustomerBoundary($this, 'http://localhost/ku/app-v2');
});

it('remaps disabled V1 intentions and rejects external intentions at the login boundary', function ($intended, $target) {
    config(['customer_app.v1_enabled' => false]);
    session(['applocale' => 'ar', 'url.intended' => $intended]);
    signInAtCustomerBoundary($this, 'http://localhost'.$target);
    expect(session()->has('url.intended'))->toBeFalse();
})->with([['/ar/app/ocr', '/ar/app-v2/ocr/scanner'], ['//evil.test', '/ar/app-v2']]);

it('keeps verification reachable and the intended destination until phone verification completes', function () {
    config(['customer_app.v1_enabled' => false]);
    $this->customer->update(['email_verify' => false, 'phone_verify' => false, 'phone_verified_at' => null, 'phone_otp_number' => '123456']);
    session(['applocale' => 'ku', 'url.intended' => 'http://localhost/ku/app-v2/ocr/scanner']);
    signInAtCustomerBoundary($this, route('app.email.otp'));
    expect(session('url.intended'))->toBe('http://localhost/ku/app-v2/ocr/scanner');
    $this->get('/app/email')->assertOk();
    $this->customer->update(['email_verify' => true]);
    $this->actingAs($this->customer->fresh(), 'app');
    Cache::put('phone_otp_expires_'.$this->customer->id, now()->addMinutes(5)->timestamp, 360);
    Livewire::test('app::auth.phone-otp')->set('otpCode', '123456')->call('confirm')
        ->assertRedirect('http://localhost/ku/app-v2/ocr/scanner');
});

it('gates legacy actions while preserving shared customer routes and independent interfaces', function () {
    $page = $this->actingAs($this->customer, 'app')->get('/ar/app/home')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    config(['customer_app.v1_enabled' => false]);
    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    $this->actingAs($this->customer, 'app')->get('/ar/app/ocr')->assertRedirect('http://localhost/ar/app-v2/ocr/scanner');
    $this->withHeader('X-Livewire', 'true')->get('/ar/app/home')->assertNotFound();
    foreach (['app.logout', 'app.email.otp', 'app.phone.otp', 'app.password.request', 'app.password.reset.form',
        'payments.fib.status', 'payments.fib.refresh', 'payments.fib.cancel', 'app.renders.xomni.stream',
        'app.renders.xomni-v2.stream', 'app.ctts-references.stream', 'app.xomni.speaker.preview',
        'app.v2.home', 'admin.home', 'passport.authorizations.authorize'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->not->toContain('app.v1.enabled');
    }
    expect(Livewire::getPersistentMiddleware())->toContain(App\Http\Middleware\EnsureAppV1Enabled::class);
});

it('preserves the intended destination after existing customer social login', function () {
    session(['applocale' => 'ar', 'url.intended' => 'http://localhost/ar/app-v2/ocr/scanner']);
    $providerUser = new Laravel\Socialite\Two\User;
    $providerUser->map(['id' => 'local-fixture', 'email' => $this->customer->email]);
    $service = $this->mock(App\Services\Auth\CustomerSocialAuthService::class);
    $service->shouldReceive('fetchProviderUserFromCallback')->once()->with('google')->andReturn($providerUser);
    $service->shouldReceive('customerExistsForProviderUser')->once()->with($providerUser, 'google')->andReturn(true);
    $service->shouldReceive('authenticateProviderUser')->once()->with($providerUser, 'google', true)->andReturn($this->customer);
    $this->get('/auth/google/callback')->assertRedirect('http://localhost/ar/app-v2/ocr/scanner');
    $this->assertAuthenticatedAs($this->customer, 'app');
});

it('keeps password reset and subsequent login available when V1 is disabled', function () {
    config(['customer_app.v1_enabled' => false]);
    session(['applocale' => 'ar']);
    $this->get('/forgot-password')->assertOk();
    $token = Illuminate\Support\Facades\Password::broker('customers')->createToken($this->customer);
    $this->get('/reset-password/'.$token.'?email='.urlencode($this->customer->email))->assertOk();
    Livewire::withQueryParams(['email' => $this->customer->email])->test('app::auth.reset-password', ['token' => $token])
        ->set('password', 'Changed123!')->set('password_confirmation', 'Changed123!')->call('resetPassword')
        ->assertHasNoErrors()->assertRedirect(route('app.signin'));
    signInAtCustomerBoundary($this, 'http://localhost/ar/app-v2', password: 'Changed123!');
});

it('keeps the shared completed payment status route and changes only its destination', function ($v1, $v2, $suffix) {
    config(['customer_app.v1_enabled' => $v1, 'metkurd_v2.enabled' => $v2]);
    $payment = App\Domain\Payments\Models\Payment::create([
        'uuid' => Illuminate\Support\Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'provider_object_type' => 'payment',
        'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now(), 'paid_at' => now(),
        'local_reference' => Illuminate\Support\Str::uuid(), 'idempotency_key' => Illuminate\Support\Str::uuid(),
        'amount' => 5000, 'currency' => 'IQD', 'purchasable_type' => App\Models\ServicePlan::class,
        'purchasable_id' => App\Models\ServicePlan::where('code', 'pro')->value('id'),
    ]);
    $snapshot = fn () => collect(['payments', 'credit_wallets', 'credit_ledgers'])
        ->mapWithKeys(fn ($table) => [$table => Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $this->actingAs($this->customer, 'app')->getJson(route('payments.fib.status', ['locale' => 'ar', 'payment' => $payment]))
        ->assertOk()->assertJsonPath('redirect_url', 'http://localhost/ar'.$suffix);
    expect($snapshot())->toBe($before);
    Http::assertNothingSent();
})->with([[true, true, '/app-v2'], [false, true, '/app-v2'], [true, false, '/app/home'], [false, false, '']]);
