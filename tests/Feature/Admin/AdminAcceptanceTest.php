<?php

use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminCustomerWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $this->operator = User::forceCreate(['name' => 'Acceptance operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->operator, 'admin');
    $this->customer = Customer::create(['username' => 'acceptance_fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
});

function acceptancePayment(Customer $customer): Payment
{
    return Payment::create(['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription',
        'payment_mode' => 'recurring', 'status' => 'pending', 'internal_status' => 'requires_review',
        'local_reference' => 'ACCEPT-'.Str::random(8), 'idempotency_key' => (string) Str::uuid(), 'amount' => 10000, 'currency' => 'IQD',
        'create_response' => ['private_customer_content' => 'PRIVATE_ACCEPTANCE_PAYLOAD'], 'meta' => ['private_note' => 'PRIVATE_ACCEPTANCE_PAYLOAD']]);
}

it('keeps customer summary payment projection narrow even when Operations preselects columns', function () {
    acceptancePayment($this->customer);
    $summary = app(AdminCustomerWorkspace::class)->read($this->customer->id);
    expect(array_keys($summary['latest_payment']))->toEqualCanonicalizing(['id', 'provider', 'status', 'purchase_type', 'amount', 'currency', 'created_at', 'paid_at', 'fulfilled_at', 'review_required_at']);
    expect(json_encode($summary))->not->toContain('PRIVATE_ACCEPTANCE_PAYLOAD');
    $this->get(route('admin.customers.detail', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk()->assertDontSee('PRIVATE_ACCEPTANCE_PAYLOAD');
    Http::assertNothingSent();
});

it('renders representative pages with unique modal names and control labels in every locale without writes', function (string $locale) {
    $payment = acceptancePayment($this->customer);
    $routes = ['home' => [], 'customers.register' => ['customer' => $this->customer->id], 'customers.detail' => ['customer' => $this->customer->id],
        'services.tools' => [], 'services.entitlements' => [], 'services.pricing' => [], 'payments.plans' => [], 'services.voices' => []];
    $requests = [];
    foreach ($routes as $route => $params) {
        $requests[] = ['admin.'.$route, ['locale' => $locale] + $params];
    }
    foreach (['payments', 'subscriptions', 'orders', 'review', 'audit'] as $section) {
        $requests[] = ['admin.operations', ['locale' => $locale, 'section' => $section, 'queue' => 'payment_review', 'payment' => $payment->id, 'customerFilter' => $this->customer->id]];
    }
    $problems = [];
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    foreach ($requests as [$route, $params]) {
        $html = $this->get(route($route, $params))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xp = new DOMXPath($dom);
        expect($dom->documentElement->getAttribute('dir'))->toBe($locale === 'en' ? 'ltr' : 'rtl');
        foreach ($xp->query('//*[@aria-labelledby]') as $node) {
            foreach (explode(' ', $node->getAttribute('aria-labelledby')) as $id) {
                if ($xp->query('//*[@id="'.$id.'"]')->length !== 1) {
                    $problems[] = $route.': invalid label target '.$id;
                }
            }
        }
        foreach ($xp->query('//main//button[not(@aria-label) and not(@aria-labelledby)]') as $node) {
            if (trim($node->textContent) === '') {
                $problems[] = $route.': unnamed button '.$node->getAttribute('id');
            }
        }
        foreach ($xp->query('//main//input[not(@type="hidden") and not(@type="submit")] | //main//select | //main//textarea') as $node) {
            $id = $node->getAttribute('id');
            $label = $id !== '' && $xp->query('//label[@for="'.$id.'"]')->length > 0;
            // Match shared initialization, including fields inside an advanced-details wrapper.
            $adjacent = false;
            foreach ($xp->query('//main//label[contains(@class,"form-label") and not(@for)]') as $candidate) {
                $field = $xp->query('.//input[not(@type="hidden")] | .//select | .//textarea', $candidate->parentNode)->item(0);
                if ($field?->isSameNode($node)) {
                    $adjacent = true;
                    break;
                }
            }
            if (! $label && ! $adjacent && ! $node->hasAttribute('aria-label') && ! $node->hasAttribute('aria-labelledby') && $xp->query('ancestor::label', $node)->length === 0) {
                $problems[] = $route.': unlabelled '.$node->tagName.' #'.$id;
            }
        }
        expect($html)->not->toContain('PRIVATE_ACCEPTANCE_PAYLOAD');
    }
    expect($problems)->toBe([]);
    expect(collect($queries)->filter(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $sql))->all())->toBe([]);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('preserves customer trace filters and evidence page numbers during locale change', function () {
    $payment = acceptancePayment($this->customer);
    $params = ['section' => 'payments', 'payment' => $payment->id, 'customerFilter' => $this->customer->id, 'eventsPage' => 2, 'allocationsPage' => 3];
    $previous = route('admin.operations', ['locale' => 'en'] + $params);
    foreach (['ar', 'ku', 'en'] as $locale) {
        $this->from($previous)->post(route('setLocale'), ['locale' => $locale])
            ->assertRedirect(route('admin.operations', ['locale' => $locale] + $params));
        $previous = route('admin.operations', ['locale' => $locale] + $params);
    }
});

it('explains empty customer and financial evidence states and safely rejects a missing selected record', function (string $locale) {
    app()->setLocale($locale);
    config(['customer_api.v2_enabled' => false, 'mcp.enabled' => false]);
    // A normal Admin-created customer already has legitimate creation audit events.
    $this->customer = Customer::withoutEvents(fn () => Customer::create(['username' => 'empty_'.Str::random(8),
        'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]));
    $this->get(route('admin.customers.detail', ['locale' => $locale, 'customer' => $this->customer->id]))->assertOk()
        ->assertSee(__('admin_customer.no_agreement'))->assertSee(__('admin_customer.no_current_payment'))
        ->assertSee(__('admin_customer.no_audit'))->assertSee(__('admin_customer.unavailable'))->assertSee(__('admin_customer.feature_disabled'));
    foreach (['payments', 'subscriptions', 'orders', 'review', 'audit'] as $section) {
        $this->get(route('admin.operations', ['locale' => $locale, 'section' => $section, 'customerFilter' => $this->customer->id]))->assertOk()
            ->assertSee(__('admin_p2.empty'));
    }
    $payment = acceptancePayment($this->customer);
    $this->get(route('admin.operations', ['locale' => $locale, 'section' => 'payments', 'payment' => $payment->id]))->assertOk()
        ->assertSee(__('admin_billing.timeline'))->assertSee(__('admin_p2.empty'));
    $this->get(route('admin.operations', ['locale' => $locale, 'section' => 'payments', 'payment' => $payment->id + 100000]))->assertNotFound();
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('denies an already-open payment correction after capability revocation without partial financial writes', function (string $capability) {
    $payment = acceptancePayment($this->customer);
    $component = Livewire::withQueryParams(['customer' => (string) $this->customer->id])->test('admin::pages.customers.adm-customers-register')
        ->call('openReviewPayment', $payment->id)->set('reviewResolutionReason', 'Isolated acceptance correction reason.');
    $snapshot = fn () => collect(['payments', 'credit_wallets', 'credit_ledgers', 'credit_orders', 'admin_operations', 'admin_audit_events'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $this->operator->forceFill(['admin_capabilities' => array_values(array_diff(AdminAccess::CAPABILITIES, [$capability]))])->save();
    $before = $snapshot();
    $component->call('markReviewPaymentInvalid')->assertForbidden();
    expect($snapshot())->toBe($before);
    Http::assertNothingSent();
})->with(['admin.finance', 'admin.reconcile']);
