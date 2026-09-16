<?php

use App\Console\Commands\CutoverInventory;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    Mail::fake();
    Http::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 9, 9)->startOfDay());
});

function cutoverCustomer(): Customer
{
    return Customer::create(['username' => 'cutover_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
}

function cutoverPaid(Customer $customer, bool $expired = false): array
{
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $last = $expired ? now()->subMonths(2) : now()->subDay();
    $end = $expired ? now()->subMonth() : now()->addMonth();
    $ref = Str::uuid()->toString();
    $payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'provider_object_type' => 'subscription', 'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring',
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => $plan->id,
        'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => $last,
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 1000, 'currency' => 'IQD',
        'fib_subscription_id' => $ref, 'provider_subscription_status' => $expired ? 'EXPIRED' : 'ACTIVE',
        'last_payment_at' => $last, 'active_until' => $end, 'meta' => [
            'verified_subscription_collection' => ['provider_object_id' => $ref, 'last_payment_at' => $last->copy()->utc()->toIso8601String(), 'paid_through' => $end->copy()->utc()->toIso8601String()],
            'secret' => 'NEVER-PRINT-TOKEN',
        ]]);
    CustomerServiceSubscription::where('customer_id', $customer->id)->update(['status' => 'ended']);
    $sub = CustomerServiceSubscription::create(['customer_id' => $customer->id, 'service_plan_id' => $plan->id,
        'payment_id' => $payment->id, 'provider_ref' => $ref, 'status' => $expired ? 'ended' : 'active',
        'source' => 'fib', 'starts_at' => $last, 'ends_at' => $end]);

    return [$payment, $sub];
}

it('identifies the endpoint without inferring production from environment', function () {
    expect(CutoverInventory::targetHint('mysql', '127.0.0.1'))->toContain('Local endpoint')
        ->and(CutoverInventory::targetHint('mysql', 'example.region.rds.amazonaws.com'))->toContain('not independently verified')
        ->and(CutoverInventory::targetHint('mysql', 'example.internal'))->toContain('Other/unverified');
    Artisan::call('billing:cutover-inventory');
    $output = Artisan::output();
    foreach (['laravel_environment', 'driver', 'host', 'port', 'configured_schema', 'server_version', 'database_time', 'application_timezone', 'code_revision', 'Local endpoint'] as $key) {
        expect($output)->toContain($key);
    }
    expect(strpos($output, 'Database identity'))->toBeLessThan(strpos($output, '"counts"'));
    expect($output)->not->toContain('password', 'username');
});

it('passes an empty payment domain and a Free customer without creating state', function () {
    cutoverCustomer();
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('PASS — no confirmed current paid obligations found')->assertSuccessful();
});

it('blocks a verified current paid obligation', function () {
    cutoverPaid(cutoverCustomer());
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('provider_paid_looking')
        ->expectsOutputToContain('BLOCKED — current/unknown paid obligations require review')->assertFailed();
});

it('never converts missing or mismatched collection evidence into expired', function (string $case) {
    [$payment] = cutoverPaid(cutoverCustomer(), true);
    $changes = match ($case) {
        'missing_boundary' => ['active_until' => null],
        'missing_receipt' => ['meta' => []],
        'wrong_identity' => ['meta' => ['verified_subscription_collection' => ['provider_object_id' => 'other']]],
        'wrong_owner' => ['customer_id' => cutoverCustomer()->id],
    };
    $payment->update($changes);
    $this->artisan('billing:cutover-inventory', ['--details' => true])->expectsOutputToContain('missing_or_unverified_collection_binding_or_paid_through')->assertFailed();
})->with(['missing_boundary', 'missing_receipt', 'wrong_identity', 'wrong_owner']);

it('reports proven elapsed paid coverage as stale', function () {
    cutoverPaid(cutoverCustomer(), true);
    $this->artisan('billing:cutover-inventory', ['--details' => true])->expectsOutputToContain('verified_paid_term_elapsed')->assertSuccessful();
});

it('preserves valid complimentary access without counting it as paid', function () {
    $customer = cutoverCustomer();
    CustomerServiceSubscription::where('customer_id', $customer->id)->update(['status' => 'ended']);
    CustomerServiceSubscription::create(['customer_id' => $customer->id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'),
        'source' => 'admin_manual_grant', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        'renewal_strategy' => 'manual_renewal', 'auto_renew' => false,
        'meta' => ['revenue_excluded' => true, 'revenue_record' => false, 'reason' => 'Sensitive operator reason']]);
    $this->artisan('billing:cutover-inventory', ['--details' => true])->expectsOutputToContain('effective_explicit_manual_term')->assertSuccessful();
});

it('blocks unlinked provider evidence even when the customer resolves to Free', function () {
    [$p, $s] = cutoverPaid(cutoverCustomer());
    $s->update(['payment_id' => null, 'provider_ref' => null, 'service_plan_id' => ServicePlan::where('code', 'free')->value('id'), 'source' => 'system']);
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('unlinked_plan_or_provider_evidence')->assertFailed();
});

it('blocks pending review and paid unfulfilled payments', function (string $state) {
    [$p] = cutoverPaid(cutoverCustomer());
    $p->update($state === 'review' ? ['internal_status' => 'requires_review'] : ($state === 'pending'
        ? ['status' => 'pending', 'internal_status' => 'pending', 'fulfilled_at' => null]
        : ['fulfilled_at' => null]));
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('unresolved_payments')->assertFailed();
})->with(['review', 'pending', 'unfulfilled']);

it('runs only select queries leaves every table unchanged and makes no HTTP request', function () {
    [$p] = cutoverPaid($customer = cutoverCustomer());
    CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'app')->update(['addon_balance_credits' => 17, 'balance_credits' => DB::raw('subscription_balance_credits + 17')]);
    $tables = Schema::getTableListing(schemaQualified: false);
    $fingerprint = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => hash('sha256', DB::table($t)->get()->toJson())])->all();
    $before = $fingerprint();
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        $queries[] = $event->sql;
    });
    Artisan::call('billing:cutover-inventory', ['--details' => true]);
    foreach ($queries as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select')->not->toContain('for update');
    }
    expect($fingerprint())->toBe($before);
    expect(Artisan::output())->not->toContain('NEVER-PRINT-TOKEN', $p->fib_subscription_id, $customer->email, 'Sensitive operator reason');
    Http::assertNothingSent();
});

it('omits detail IDs by default', function () {
    cutoverPaid(cutoverCustomer());
    Artisan::call('billing:cutover-inventory');
    expect(Artisan::output())->not->toContain('"details"', '"customer_id"', '"subscription_id"', '"payment_id"');
});

it('blocks unavailable schema without printing SQL or connection secrets', function () {
    Schema::drop('subscription_credit_allocations');
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('Inventory unavailable')->expectsOutputToContain('BLOCKED')->assertFailed();
});

it('classifies storage obligations independently', function (string $case) {
    [$payment, $service] = cutoverPaid($customer = cutoverCustomer(), $case === 'expired');
    $plan = \App\Models\StoragePlan::create(['code' => 'cutover-storage', 'name' => 'Fixture', 'quota_mb' => 2048, 'price_iqd' => 2000, 'price_usd' => 2, 'is_active' => true]);
    // Remove the synthetic service row so only storage is under review.
    $service->delete();
    $payment->update(['purchasable_type' => \App\Models\StoragePlan::class, 'purchasable_id' => $plan->id]);
    if ($case === 'unknown') {
        $payment->update(['active_until' => null]);
    }
    DB::table('customer_storage_subscriptions')->where('customer_id', $customer->id)->update(['status' => 'ended']);
    \App\Models\CustomerStorageSubscription::create(['customer_id' => $customer->id, 'storage_plan_id' => $plan->id,
        'payment_id' => $payment->id, 'provider_ref' => $payment->fib_subscription_id, 'source' => 'fib',
        'status' => $case === 'expired' ? 'ended' : 'active', 'starts_at' => $payment->last_payment_at, 'ends_at' => $payment->active_until]);
    $code = Artisan::call('billing:cutover-inventory', ['--details' => true]);
    expect($code)->toBe($case === 'expired' ? 0 : 1);
    expect(Artisan::output())->toContain('cutover-storage');
})->with(['current', 'unknown', 'expired']);

it('blocks elapsed coverage when provider state remains active or absent', function (?string $state) {
    [$p] = cutoverPaid(cutoverCustomer(), true);
    $p->update(['provider_subscription_status' => $state]);
    $this->artisan('billing:cutover-inventory', ['--details' => true])->expectsOutputToContain('elapsed_coverage_but_provider_state_unresolved')->assertFailed();
})->with(['ACTIVE', null]);

it('reports retained paid and addon credit dependency without making it a blocker', function () {
    $customer = cutoverCustomer();
    \App\Models\CreditOrder::create(['customer_id' => $customer->id, 'order_type' => 'addon', 'source_type' => 'credit_product',
        'status' => 'paid', 'provider' => 'fib', 'credits_amount' => 15]);
    CreditWallet::where('customer_id', $customer->id)->update(['balance_credits' => 15, 'addon_balance_credits' => 15, 'subscription_balance_credits' => 0]);
    expect(Artisan::call('billing:cutover-inventory'))->toBe(0);
    expect(Artisan::output())->toContain('"addon_purchase_customers_with_addon_balance": 1', '"historical_paid_record_customers_with_credits": 1');
});

it('blocks a detached historical paid plan order rather than infer Free means no obligation', function () {
    \App\Models\CreditOrder::create(['customer_id' => cutoverCustomer()->id, 'order_type' => 'subscription', 'source_type' => 'service_plan',
        'status' => 'paid', 'provider' => 'fib', 'credits_amount' => 100]);
    $this->artisan('billing:cutover-inventory')->expectsOutputToContain('unlinked_paid_plan_orders')->assertFailed();
});

it('recognizes an explicit manual calendar boundary without fabricating provider coverage', function () {
    $customer = cutoverCustomer();
    CustomerServiceSubscription::where('customer_id', $customer->id)->update(['status' => 'ended']);
    CustomerServiceSubscription::create(['customer_id' => $customer->id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'),
        'source' => 'admin_manual', 'status' => 'active', 'starts_at' => now()->subDay(), 'cycle_ends_on' => now()->addMonth()->toDateString(),
        'renewal_strategy' => 'manual_renewal', 'auto_renew' => false]);
    $this->artisan('billing:cutover-inventory', ['--details' => true])->expectsOutputToContain('effective_explicit_manual_term')->assertSuccessful();
});
