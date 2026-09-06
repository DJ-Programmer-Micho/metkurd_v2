<?php

use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\LocalizationMainMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    // The existing usage query uses MySQL CONCAT. Emulate only that function in this isolated SQLite route test.
    \Illuminate\Support\Facades\DB::connection()->getPdo()->sqliteCreateFunction('CONCAT',
        static fn (...$values) => in_array(null, $values, true) ? null : implode('', $values), -1);
});

function routeReviewAdmin(int $status = 1): User
{
    $admin = User::forceCreate(['name' => 'Route Review', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password', 'status' => $status, 'admin_capabilities' => ['admin.read']]);
    $admin->profile()->create(['first_name' => 'Route', 'last_name' => 'Review']);

    return $admin;
}

it('sends unauthenticated Admin page requests to Admin sign-in and customer requests to customer sign-in', function () {
    $this->get('/en/adm/home')->assertRedirect(route('admin.signin'));
    $this->get('/en/app/home')->assertRedirect(route('app.signin'));
    $this->get('/en/app-v2')->assertRedirect(route('app.signin'));
});

it('keeps Admin sign-in public and logout reachable for an inactive authenticated Admin', function () {
    $this->get('/adm/signin')->assertOk();
    $this->actingAs(routeReviewAdmin(0), 'admin');
    $this->get('/en/adm/home')->assertForbidden();
    $this->post('/adm/logout')->assertRedirect(route('admin.signin'));
    expect(auth('admin')->check())->toBeFalse();
});

it('keeps routes registered and sign-in public without P0 schema while withholding Admin access', function () {
    $admin = routeReviewAdmin();
    \Illuminate\Support\Facades\Schema::drop('admin_operations');
    \Illuminate\Support\Facades\Schema::drop('admin_audit_events');
    \Illuminate\Support\Facades\Schema::table('users', fn ($table) => $table->dropColumn(['status', 'admin_capabilities']));
    $this->get('/adm/signin')->assertOk();
    expect(Route::has('admin.home'))->toBeTrue()->and(Route::has('app.v2.api'))->toBeTrue();
    $this->actingAs($admin->fresh(), 'admin');
    $this->get('/en/adm/home')->assertForbidden();
});

it('allows an active support Admin to render the scoped read page', function (string $name) {
    $this->seed();
    $this->actingAs(routeReviewAdmin(), 'admin');
    $this->get(route($name, ['locale' => 'en']))->assertOk();
})->with([
    'admin.home', 'admin.services.tools', 'admin.services.voices', 'admin.services.pricing', 'admin.services.entitlements',
    'admin.customers.list', 'admin.customers.ranking', 'admin.customers.register', 'admin.customers.phone-countries',
    'admin.customers.usage', 'admin.customers.suspended', 'admin.payments.plans', 'admin.payments.addons',
    'admin.payments.storage', 'admin.payments.coupons', 'admin.payments.methods', 'admin.payments.currencies',
]);

it('keeps Admin customer V2 legacy and Landing middleware contracts separate', function () {
    $names = [];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name) {
            expect(in_array($name, $names, true))->toBeFalse();
            $names[] = $name;
        }
        $middleware = $route->gatherMiddleware();
        if (str_starts_with($name ?? '', 'admin.') && ! in_array($name, ['admin.signin', 'admin.logout'], true)) {
            expect($route->uri())->toStartWith('{locale}/adm/')
                ->and($middleware)->toContain('auth:admin', EnsureAdminIsActive::class, LocalizationMainMiddleware::class)
                ->not->toContain('auth:app', 'app.active', 'app.verified', 'app.v2.enabled');
        }
        if (str_contains($route->uri(), '/app-v2')) {
            expect($middleware)->toContain('auth:app', 'app.active', 'app.verified', 'app.v2.enabled')
                ->not->toContain('auth:admin', EnsureAdminIsActive::class);
        }
        if (str_starts_with($name ?? '', 'landing.')) {
            expect($middleware)->not->toContain('auth:admin', EnsureAdminIsActive::class, 'app.v2.enabled');
        }
    }
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())->toContain(EnsureAdminIsActive::class);
});

it('rejects an actual Livewire update after the Admin becomes inactive', function () {
    $this->seed();
    $admin = routeReviewAdmin();
    $this->actingAs($admin, 'admin');
    $response = $this->get('/en/adm/services/tools')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = null;
    foreach ($matches[1] as $encoded) {
        $candidate = html_entity_decode($encoded, ENT_QUOTES);
        if (data_get(json_decode($candidate, true), 'memo.name') === 'admin::pages.services.adm-services-tools') {
            $snapshot = $candidate;
            break;
        }
    }
    expect($snapshot)->not->toBeNull();
    $admin->update(['status' => 0]);
    $this->withHeader('X-Livewire', 'true')->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]])->assertForbidden();
});

it('matches specific V2 and Landing paths before generic service routes', function (string $uri, string $name) {
    expect(Route::getRoutes()->match(Request::create($uri))->getName())->toBe($name);
})->with([
    ['/en/app-v2/ocr/scanner', 'app.v2.ocr'], ['/en/app-v2/stem/2-stem', 'app.v2.stem'],
    ['/en/app-v2/stem/4-stem', 'app.v2.stem'], ['/en/app-v2/api', 'app.v2.api'],
    ['/en/app-v2/storage', 'app.v2.storage'], ['/en/app-v2/clone-text-to-speech/vector-2', 'app.v2.tool'],
    ['/en/tools', 'landing.tools'], ['/en/contact', 'landing.contact'], ['/en/app/home', 'app.home'],
]);
