<?php

use App\Models\AdminOperation;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    // Match the existing route suite's SQLite emulation of the MySQL usage query.
    DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();
    $this->operator = User::forceCreate(['name' => 'P3 Operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->operator->profile()->create(['first_name' => 'P3', 'last_name' => 'Operator']);
    $this->actingAs($this->operator, 'admin');
    $this->customer = Customer::create(['username' => 'کڕیار_fixture', 'email' => Str::uuid().'@example.test', 'uid' => Str::uuid(), 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
});

it('renders every scoped Admin surface in its own locale and direction', function (string $locale) {
    foreach (['home', 'services.tools', 'services.voices', 'services.pricing', 'services.entitlements', 'customers.list', 'customers.ranking', 'customers.register', 'customers.phone-countries', 'customers.usage', 'customers.suspended', 'payments.plans', 'payments.addons', 'payments.storage', 'payments.coupons', 'payments.methods', 'payments.currencies', 'operations', 'customers.detail'] as $page) {
        $params = ['locale' => $locale];
        if ($page === 'customers.detail') {
            $params['customer'] = $this->customer->id;
        }
        if (in_array($page, ['customers.register', 'customers.usage'])) {
            $params['customer'] = $this->customer->id;
        }
        $html = $this->get(route('admin.'.$page, $params))->assertOk()->getContent();
        expect($html)->toContain('lang="'.$locale.'" dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"')
            ->toContain('data-admin-ui')->not->toContain('admin_p3.');
        expect(substr_count($html, 'data-admin-ui'))->toBe(1);
        if ($page === 'home') {
            preg_match('/id="admin-home-chart-data">(.*?)<\/script>/s', $html, $chart);
            $payload = json_decode($chart[1], true, flags: JSON_THROW_ON_ERROR);
            expect($payload['ui']['revenue'])->toBe(__('Revenue'));
            if ($locale !== 'en') {
                expect($payload['ui']['revenue'])->not->toBe('Revenue');
            }
        }
        if ($page === 'services.pricing') {
            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $search = $xpath->query('//input[@id="admin-field-adm-services-pricing-1"]')->item(0);
            expect($search?->getAttribute('wire:model.live.debounce.350ms'))->toBe('search');
            expect($xpath->query('//label[@for="admin-field-adm-services-pricing-1"]')->item(0)?->textContent)->toBe(__('Search'));
            $plan = $xpath->query('//select[@id="admin-field-adm-services-pricing-2"]')->item(0);
            expect($plan?->getAttribute('wire:model.live'))->toBe('planFilter');
            expect($html)->toContain(__('Showing'), __('results'));
            if ($locale !== 'en') {
                expect(__('Showing'))->not->toBe('Showing');
                expect(__('pagination.next'))->not->toBe('pagination.next')->not->toContain('Next');
                expect($html)->not->toMatch('/>\s*(Showing|results)\s*</');
            }
        }
        Http::assertNothingSent();
    }
})->with(['en', 'ar', 'ku']);

it('keeps customer bookmarks and trace links scoped and clears only view filters', function () {
    foreach (['customers.detail', 'customers.register', 'customers.usage'] as $page) {
        $html = $this->get(route('admin.'.$page, ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk()->getContent();
        foreach (['customers.register', 'customers.usage'] as $target) {
            expect(html_entity_decode($html))->toContain(route('admin.'.$target, ['locale' => 'en', 'customer' => $this->customer->id]));
        }
        expect($html)->toContain('customer-context', 'dir="auto"');
    }
    $component = Livewire::test('admin::pages.operations.adm-operations', ['customer' => $this->customer->id])
        ->set('section', 'api')->set('search', 'fixture')->set('status', 'failed')->call('resetFilters');
    expect($component->get('customer'))->toBe($this->customer->id)->and($component->get('section'))->toBe('api')->and($component->get('status'))->toBe('');
});

it('shows disabled mutation controls for read-only operators while retaining server authorization', function () {
    $html = $this->get(route('admin.customers.list', ['locale' => 'en']))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $buttons = $xpath->query('//*[@data-admin-method="toggleCustomerStatus"]');
    expect($buttons->length)->toBeGreaterThan(0);
    foreach ($buttons as $button) {
        expect($button->hasAttribute('disabled'))->toBeTrue();
    }
    Livewire::test('admin::pages.customers.adm-customers-list')->set('adminChangeReason', 'Fixture authorization check.')->call('toggleCustomerStatus', $this->customer->id)->assertForbidden();
    expect($this->customer->fresh()->status)->toBe(1);
});

it('preserves the financial operation identity through validation correction refresh and replay', function () {
    $this->operator->forceFill(['admin_capabilities' => ['admin.read', 'admin.finance']])->save();
    app(\App\Services\Billing\PlanSwitcher::class)->switchServicePlan($this->customer, \App\Models\ServicePlan::where('code', 'student')->firstOrFail()->id, ['provider' => 'fake', 'billing_cycle' => 'monthly']);
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $component = Livewire::test('admin::pages.customers.adm-customers-register')->assertStatus(200)->call('focusCustomer', $this->customer->id)->assertStatus(200);
    $ids = $component->get('adminIntentIds');
    $component->set('addonProductAdjustmentId', (string) $product->id)->assertStatus(200)->set('addonAdjustmentNote', '')->assertStatus(200)->call('applyAddonAdjustment')->assertHasErrors('addonAdjustmentNote');
    expect($component->get('adminIntentIds'))->toBe($ids)->and(AdminOperation::count())->toBe(0);
    $component->set('addonAdjustmentNote', 'Approved isolated fixture correction.')->assertStatus(200)->call('applyAddonAdjustment')->assertStatus(200)->assertHasNoErrors();
    $wallets = DB::table('credit_wallets')->where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $component->call('$refresh')->assertStatus(200);
    $component->call('applyAddonAdjustment')->assertStatus(200)->assertHasNoErrors();
    expect($component->get('adminIntentIds'))->toBe($ids)->and(AdminOperation::count())->toBe(1)
        ->and(DB::table('credit_wallets')->where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
});

it('separates provider completion from persisted results and keeps technical values LTR', function () {
    $html = \Illuminate\Support\Facades\Blade::render('<x-admin-operation-status :row="$row" /><x-admin-operation-values :values="$row" />', ['row' => ['id' => 'fixture-uuid', 'customer' => 'کڕیار', 'local_lifecycle' => 'running', 'provider_status' => 'provider_success_recorded', 'persisted_result' => 'not_confirmed']]);
    expect($html)->toContain('Local status', 'Provider evidence', 'Persistence state', 'dir="ltr"', 'dir="auto"', 'fixture-uuid');
});

it('reuses capability hints within a single table render', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    for ($i = 0; $i < 25; $i++) {
        expect(\App\Support\Admin\AdminUiAccess::can('admin.pricing'))->toBeFalse();
    }
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'from "users"'))->count())->toBe(1);
});
