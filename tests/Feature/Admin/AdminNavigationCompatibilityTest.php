<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
});

it('resolves shared navigation through the installed Livewire Finder to the App components', function () {
    foreach (['nav-feature-link', 'nav-multi-feature-link'] as $component) {
        $resolved = app('livewire.finder')->resolveSingleFileComponentPath('partials.components.'.$component);
        expect(realpath($resolved))->toBe(realpath(resource_path('views/app/partials/components/⚡'.$component.'.blade.php')));
    }
});

it('renders nested Admin navigation destinations in each locale', function (string $locale) {
    $admin = User::forceCreate(['name' => 'Navigation Operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $admin->profile()->create(['first_name' => 'Navigation', 'last_name' => 'Operator']);
    $html = $this->actingAs($admin, 'admin')->get(route('admin.home', ['locale' => $locale]))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    foreach (\App\Support\Admin\AdminNavigation::groups() as $group => $items) {
        foreach ($items as $item) {
            $url = route($item['route'], ['locale' => $locale] + $item['query']);
            expect($xpath->query('//*[@id="admin-sidebar"]//a[@data-admin-nav and @href="'.$url.'"]')->length)->toBe(1);
        }
    }
    expect($xpath->query('//details[contains(@class,"admin-nav-system") and not(@open)]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="admin-sidebar"]//a[@aria-current="page"]')->length)->toBe(1);
    expect($html)->toContain('lang="'.$locale.'" dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"');
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('keeps the retained Profile and Billing shell navigation readable in each locale', function (string $locale) {
    $customer = Customer::create(['username' => 'navigation_'.Str::random(10), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($customer, 'app');
    foreach (['app.profile', 'app.billing'] as $page) {
        $html = $this->get(route($page, ['locale' => $locale]))->assertOk()->getContent();
        expect($html)->toContain('id="app-navbar-menu"');
        foreach (['app.home', 'app.profile', 'app.billing'] as $destination) {
            expect($html)->toContain('href="'.e(route($destination, ['locale' => $locale])).'"');
        }
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        expect($dom->documentElement->getAttribute('lang'))->toBe($locale);
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('keeps unavailable customer features hidden in both shared navigation components', function (string $locale) {
    app()->setLocale($locale);
    $customer = Customer::create(['username' => 'locked_'.Str::random(10), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($customer, 'app');

    foreach (['nav-feature-link', 'nav-multi-feature-link'] as $component) {
        $parameters = $component === 'nav-feature-link' ? ['route' => 'app.profile'] : ['id' => 'fixture-navigation'];
        $parameters['label'] = 'Profile';
        $locked = Livewire::test('partials.components.'.$component, $parameters + ['toolCodes' => ['fixture-unavailable-tool']])->assertOk()->html();
        $dom = new DOMDocument;
        @$dom->loadHTML($locked);
        $xpath = new DOMXPath($dom);
        expect($xpath->query('//li[@hidden and @aria-hidden="true"]')->length)->toBe(1)
            ->and($xpath->query('//a')->length)->toBe(0);

        $available = Livewire::test('partials.components.'.$component, $parameters)->assertOk()->html();
        $url = $component === 'nav-feature-link' ? route('app.profile', ['locale' => $locale]) : '#fixture-navigation';
        expect($available)->toContain('href="'.e($url).'"', 'aria-label="'.e(__('Profile')).'"')
            ->not->toContain('aria-hidden="true"');
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);
