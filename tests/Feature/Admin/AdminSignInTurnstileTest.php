<?php

use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\AreaJsonTranslations;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    config(['services.turnstile.site_key' => 'fixture-site-key', 'services.turnstile.secret_key' => 'fixture-secret-key']);
    $this->admin = User::forceCreate(['name' => 'Turnstile Admin', 'email' => 'turnstile@example.test',
        'password' => 'Test-password-123!', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->rateKey = 'admin_login:127.0.0.1:'.$this->admin->email;
    RateLimiter::clear($this->rateKey);
});

function adminTurnstileForm($test, string $token = 'fixture-token')
{
    return Livewire::test('admin::auth.signin-one')
        ->set('login', $test->admin->email)->set('password', 'Test-password-123!')
        ->set('cfTurnstileResponse', $token);
}

it('verifies the challenge before real Admin authentication and preserves session remember guard and capabilities', function () {
    $verified = false;
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => function ($request) use (&$verified) {
        expect(auth('admin')->check())->toBeFalse();
        expect($request['secret'])->toBe('fixture-secret-key')
            ->and($request['response'])->toBe('fixture-token')->and($request['remoteip'])->toBe('127.0.0.1');
        $verified = true;

        return Http::response(['success' => true]);
    }]);
    Event::listen(Attempting::class, function ($event) use (&$verified) {
        expect($verified)->toBeTrue()->and($event->guard)->toBe('admin');
    });
    RateLimiter::hit($this->rateKey, 60);
    $page = $this->get('/adm/signin')->assertOk()->assertSee('fixture-site-key')
        ->assertDontSee('fixture-secret-key')->assertSee('name="csrf-token"', false);
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    $sessionId = session()->getId();
    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot,
        'updates' => ['login' => $this->admin->email, 'password' => 'Test-password-123!',
            'remember' => true, 'cfTurnstileResponse' => 'fixture-token'],
        'calls' => [['method' => 'signIn', 'params' => [], 'path' => '']],
    ]]], ['X-Livewire' => 'true'])->assertOk()
        ->assertJsonPath('components.0.effects.redirect', route('admin.home', ['locale' => app()->getLocale()]))
        ->assertCookie(auth('admin')->getRecallerName());
    $this->assertAuthenticatedAs($this->admin, 'admin');
    $this->assertGuest('app');
    expect(session()->getId())->not->toBe($sessionId)
        ->and(RateLimiter::attempts($this->rateKey))->toBe(0)
        ->and($this->admin->fresh()->admin_capabilities)->toBe(['admin.read']);
    expect(fn () => AdminAccess::authorize('admin.finance'))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    Http::assertSentCount(1);
});

it('blocks a missing or blank challenge before contacting Cloudflare or checking credentials', function (string $token) {
    Http::fake();
    Event::fake([Attempting::class]);
    adminTurnstileForm($this, $token)->call('signIn')->assertHasErrors(['cfTurnstileResponse'])
        ->assertSet('cfTurnstileResponse', '')->assertDispatched('turnstile-reset');
    $this->assertGuest('admin');
    Event::assertNotDispatched(Attempting::class);
    Http::assertNothingSent();
})->with(['', '   ']);

it('blocks invalid expired and failed Cloudflare responses without exposing provider details', function (mixed $payload, int $status) {
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response($payload, $status)]);
    Event::fake([Attempting::class]);
    adminTurnstileForm($this)->call('signIn')->assertHasErrors(['cfTurnstileResponse'])
        ->assertSet('cfTurnstileResponse', '')->assertDispatched('turnstile-reset')
        ->assertSee(__('Human verification failed. Please refresh the challenge and try again.'))
        ->assertDontSee('fixture-provider-detail')->assertDontSee('fixture-secret-key');
    Event::assertNotDispatched(Attempting::class);
    $this->assertGuest('admin');
    Http::assertSentCount(1);
})->with([
    'invalid' => [['success' => false, 'error-codes' => ['invalid-input-response'], 'detail' => 'fixture-provider-detail'], 200],
    'expired or replayed' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 200],
    'provider unavailable' => [['detail' => 'fixture-provider-detail'], 503],
    'malformed' => ['fixture-provider-detail', 200],
]);

it('blocks connection failure and excludes raw exception details from responses and logs', function () {
    Http::fake(fn () => throw new ConnectionException('fixture-provider-sensitive-response'));
    Event::fake([Attempting::class]);
    Log::spy();
    adminTurnstileForm($this)->call('signIn')->assertHasErrors(['cfTurnstileResponse'])
        ->assertDispatched('turnstile-reset')->assertDontSee('fixture-provider-sensitive-response');
    Event::assertNotDispatched(Attempting::class);
    $this->assertGuest('admin');
    Log::shouldHaveReceived('warning')->once()->with('TURNSTILE_SITEVERIFY_EXCEPTION',
        Mockery::on(fn ($context) => ($context['type'] ?? null) === 'ConnectionException'
            && ! str_contains(json_encode($context), 'fixture-provider-sensitive-response')));
});

it('keeps missing configuration closed on localhost rather than bypassing verification', function () {
    Http::fake();
    config(['services.turnstile.site_key' => '', 'services.turnstile.secret_key' => '']);
    $this->get('/adm/signin')->assertOk()
        ->assertSee(__('Human verification is currently unavailable. Please try again later.'));
    Event::fake([Attempting::class]);
    adminTurnstileForm($this)->call('signIn')->assertHasErrors(['cfTurnstileResponse']);
    Event::assertNotDispatched(Attempting::class);
    Http::assertNothingSent();
    $this->assertGuest('admin');
});

it('keeps wrong-password failures and the existing sixty-second Admin throttle', function () {
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true])]);
    adminTurnstileForm($this)->set('password', 'wrong-password')->call('signIn')
        ->assertHasErrors(['login'])->assertSet('cfTurnstileResponse', '')->assertDispatched('turnstile-reset');
    $this->assertGuest('admin');
    expect(RateLimiter::attempts($this->rateKey))->toBe(1)
        ->and(RateLimiter::availableIn($this->rateKey))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('preserves the eight-attempt Admin limit even with a valid challenge', function () {
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true])]);
    foreach (range(1, 8) as $attempt) {
        RateLimiter::hit($this->rateKey, 60);
    }
    Event::fake([Attempting::class]);
    adminTurnstileForm($this)->call('signIn')->assertDispatched('alert', type: 'error')
        ->assertSet('cfTurnstileResponse', '')->assertDispatched('turnstile-reset');
    Event::assertNotDispatched(Attempting::class);
    $this->assertGuest('admin');
    expect(RateLimiter::attempts($this->rateKey))->toBe(8);
});

it('keeps inactive Admin accounts denied after a valid challenge', function () {
    Http::fake(['challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true])]);
    $this->admin->update(['status' => 0]);
    adminTurnstileForm($this)->call('signIn')->assertHasErrors(['login'])->assertDispatched('turnstile-reset');
    $this->assertGuest('admin');
});

it('clears only challenge validation when a fresh token arrives', function () {
    Http::fake();
    $form = adminTurnstileForm($this, '')->set('password', '')->call('signIn')
        ->assertHasErrors(['password', 'cfTurnstileResponse']);
    $form->set('cfTurnstileResponse', 'fresh-token')->assertHasNoErrors(['cfTurnstileResponse'])
        ->assertHasErrors(['password']);
    Http::assertNothingSent();
});

it('renders the shared widget and localized Admin verification errors', function (string $locale) {
    session(['applocale' => $locale]);
    $page = $this->get('/adm/signin')->assertOk()->assertSee('name="cf-turnstile-response"', false)
        ->assertSee('data-turnstile-sitekey="fixture-site-key"', false)
        ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
        ->assertSee('lang="'.$locale.'"', false)
        ->assertDontSee('fixture-secret-key');
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $response = $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5),
        'updates' => ['login' => $this->admin->email, 'password' => 'Test-password-123!'],
        'calls' => [['method' => 'signIn', 'params' => [], 'path' => '']],
    ]]], ['X-Livewire' => 'true'])->assertOk();
    expect($response->json('components.0.effects.html'))
        ->toContain(e(AreaJsonTranslations::get('Please complete the human verification challenge.', 'admin', $locale)));
    $this->assertGuest('admin');
})->with(['en', 'ar', 'ku']);

it('retains CSRF protection on the actual login submission', function () {
    $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
        return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    });
    $page = $this->get('/adm/signin')->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    Http::fake();
    $this->postJson('/livewire/update', ['components' => [[
        'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5),
        'updates' => ['login' => $this->admin->email, 'password' => 'Test-password-123!', 'cfTurnstileResponse' => 'fixture-token'],
        'calls' => [['method' => 'signIn', 'params' => [], 'path' => '']],
    ]]], ['X-Livewire' => 'true'])->assertStatus(419);
    $this->assertGuest('admin');
    Http::assertNothingSent();
});
