<?php

use App\Models\User;
use App\Services\Admin\AdminOperations;
use App\Support\Admin\AdminNavigation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();
    $this->operator = User::forceCreate(['name' => 'Shell Operator', 'email' => Str::uuid().'@example.test',
        'password' => 'isolated-fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->actingAs($this->operator, 'admin');
});

it('gives every registered Admin page a real navigation or customer-context destination', function () {
    $items = collect(AdminNavigation::groups())->flatten(1);
    $routes = collect(Route::getRoutes())->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => str_starts_with($name ?? '', 'admin.'));
    expect($routes->diff($items->pluck('route'))->values()->all())
        ->toEqualCanonicalizing(['admin.signin', 'admin.logout', 'admin.customers.detail', 'admin.services.rules']);
    foreach ($items as $item) {
        expect(Route::has($item['route']))->toBeTrue();
        if ($item['route'] === 'admin.operations') {
            expect(AdminOperations::SECTIONS)->toContain($item['query']['section']);
        }
    }
    expect(array_keys(AdminNavigation::groups()))->toBe(['overview', 'customers', 'services', 'billing', 'developer', 'storage', 'system']);
    expect($items->where('route', 'admin.customers.register'))->toHaveCount(1);
});

it('uses exact section-aware active navigation and localized page context', function (string $locale, string $section) {
    $response = $this->get(route('admin.operations', ['locale' => $locale, 'section' => $section]))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    $active = $xpath->query('//*[@data-admin-nav and @aria-current="page"]');
    expect($active->length)->toBe(1)
        ->and($active->item(0)->getAttribute('href'))->toBe(route('admin.operations', ['locale' => $locale, 'section' => $section]))
        ->and($dom->documentElement->getAttribute('dir'))->toBe($locale === 'en' ? 'ltr' : 'rtl')
        ->and($xpath->query('//form[@id="logout-form" and @method="POST"]//input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="admin-sidebar"]//a[not(contains(@href,"/'.$locale.'/adm/"))]')->length)->toBe(0);
    expect($response->getContent())->not->toContain('admin_shell.', '/admin/js/app.js');
    Http::assertNothingSent();
})->with([['en', 'payments'], ['ar', 'api'], ['ku', 'files'], ['en', 'audit'], ['en', 'jobs']]);

it('renders retained Landing pages in the same Admin shell', function (string $route) {
    $this->get(route($route, ['locale' => 'en']))->assertOk()->assertSee('data-admin-sidebar-toggle', false);
    Http::assertNothingSent();
})->with(['admin.landing.tools', 'admin.landing.translations', 'admin.landing.contact', 'admin.landing.meta']);

it('keeps customer detail distinct and the rules redirect intact', function () {
    $customer = \App\Models\Customer::create(['username' => 'shell_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
    $this->get(route('admin.customers.detail', ['locale' => 'ar', 'customer' => $customer->id]))
        ->assertOk()->assertSee(__('admin_shell.customer_detail'));
    $this->get(route('admin.services.rules', ['locale' => 'ku']))
        ->assertRedirect(route('admin.services.voices', ['locale' => 'ku']));
});

it('keeps dashboard queues consistent with existing operational readers and performs no external calls', function () {
    $customer = \App\Models\Customer::create(['username' => 'queue_fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
    $action = \App\Models\ToolAction::where('full_code', 'tts.standard')->firstOrFail();
    foreach (['queued', 'failed'] as $status) {
        \App\Models\MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'tool_id' => $action->tool_id, 'tool_action_id' => $action->id, 'status' => $status, 'input' => [], 'output' => []]);
    }
    $home = Livewire::test('admin::pages.home.app-home');
    $reader = app(AdminOperations::class);
    expect($home->instance()->operationalOverview)->toBe([
        'queued' => $reader->query('jobs', ['status' => 'queued'])->count(),
        'attention' => $reader->query('jobs', ['group' => 'attention'])->count(),
        'payment_review' => $reader->query('review', ['queue' => 'payment_review'])->count(),
    ]);
    expect($home->instance()->operationalOverview['queued'])->toBe(1)
        ->and($home->instance()->operationalOverview['attention'])->toBe(1);
    $home->assertSee(__('admin_shell.evidence_notice'))->assertDontSee('Healthy');
    Http::assertNothingSent();
});

it('only describes customer MCP evidence when the existing gate is enabled', function (bool $enabled) {
    config(['mcp.enabled' => $enabled]);
    $response = $this->get(route('admin.home', ['locale' => 'en']))->assertOk();
    if ($enabled) {
        $response->assertSee(__('admin_shell.mcp_help'));
    } else {
        $response->assertDontSee(__('admin_shell.mcp_help'));
    }
    Http::assertNothingSent();
})->with([true, false]);

it('does not turn navigation visibility into mutation permission or an inactive-user bypass', function () {
    expect(AdminNavigation::groups())->not->toBeEmpty();
    expect(\App\Support\Admin\AdminUiAccess::can('admin.finance'))->toBeFalse();
    $this->operator->update(['status' => 0]);
    $this->get(route('admin.home', ['locale' => 'en']))->assertForbidden();
});
