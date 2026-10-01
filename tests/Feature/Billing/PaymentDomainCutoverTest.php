<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\BillingReportingBoundary;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\PaymentDomainCutover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    \Tests\Support\CutoverIdentityFixture::install();
    Http::preventStrayRequests();
    Mail::fake();
    Storage::shouldReceive('disk')->never();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->owner = Customer::create(['username' => 'cutover_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => 1, 'phone_verify' => 1]);
    $this->operator = User::forceCreate(['name' => 'Cutover operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture',
        'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.finance', 'admin.reconcile']]);
    $this->payment = cutoverPayment($this->owner);
    $this->subscription = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'payment_id' => $this->payment->id,
        'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'), 'source' => 'fib', 'provider_ref' => 'old-provider-object',
        'status' => 'active', 'starts_at' => '2026-01-01', 'ends_at' => '2027-01-01', 'auto_renew' => true,
        'meta' => ['provider_status' => 'ACTIVE']]);
    $this->order = CreditOrder::create(['customer_id' => $this->owner->id, 'payment_id' => $this->payment->id,
        'order_type' => 'subscription', 'source_type' => 'service_plan', 'status' => 'paid', 'credits_amount' => 1000,
        'base_amount_iqd' => 24000, 'amount_usd' => 16, 'created_at' => '2026-01-01']);
});

function cutoverPayment(Customer $customer, array $overrides = []): Payment
{
    return Payment::create(array_replace(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'awaiting_customer_action', 'internal_status' => 'requires_review', 'fib_subscription_id' => 'old-'.Str::uuid(),
        'provider_subscription_status' => 'DRAFT', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
        'amount' => 24000, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => ServicePlan::where('code', 'pro')->value('id')], $overrides));
}

function cutoverOptions($test): array
{
    app()->maintenanceMode()->activate(['time' => time()]);

    return ['--target' => 'local-rehearsal', '--execute' => true, '--review-hash' => app(PaymentDomainCutover::class)->review('local-rehearsal')['review_hash'],
        '--confirm' => 'RESET-V2-BILLING-DOMAIN', '--admin' => $test->operator->id,
        '--reason' => 'Approved synthetic local billing cutover rehearsal.', '--workers-stopped' => true];
}

it('reviews unknown remote state without writes HTTP storage access or secret exposure', function () {
    $this->payment->update(['qr_code' => 'SECRET-QR', 'status_response' => ['secret' => 'SECRET-PAYLOAD']]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $review = app(PaymentDomainCutover::class)->review('local-rehearsal');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($review['blockers'])->toBe([])->and(app(PaymentDomainCutover::class)->review('local-rehearsal'))->toBe($review)
        ->and(json_encode($review))->not->toContain('SECRET-QR', 'SECRET-PAYLOAD');
    foreach ($queries as $query) {
        expect(preg_match('/^\s*(insert|update|delete|alter|drop|create|replace)\b/i', $query['query']))->toBe(0);
    }
    $this->artisan('billing:cutover-reset-payment-domain', ['--target' => 'local-rehearsal'])->expectsOutputToContain('DRY RUN')->assertSuccessful();
    expect(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
});

it('retires every payment locally preserving full rows except exact reviewed links and subscription authority', function () {
    $compact = cutoverPayment($this->owner, ['meta' => ['persistence_version' => 2], 'qr_code' => 'data:image/png;base64,fixture']);
    expect($compact->fresh()->qr_code)->toBeNull()->and($compact->usesCompactPersistence())->toBeTrue();
    DB::table('payment_events')->insert(['payment_id' => $this->payment->id, 'provider' => 'fib', 'event_type' => 'created', 'source' => 'fixture']);
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fake',
        'purpose_type' => 'service_plan', 'status' => 'failed', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]);
    $parent = DB::table('payment_transactions')->insertGetId(['payment_intent_id' => $intent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'failed']);
    DB::table('payment_transactions')->insert(['payment_intent_id' => $intent, 'parent_transaction_id' => $parent, 'provider' => 'fake', 'transaction_type' => 'refund', 'status' => 'failed']);
    $this->order->update(['payment_intent_id' => $intent]);
    DB::table('ad_conversion_events')->insert(['event_name' => 'Purchase', 'payment_id' => $this->payment->id, 'customer_id' => $this->owner->id, 'transaction_id' => 'fixture', 'dedupe_key' => Str::uuid()]);
    DB::table('credit_ledgers')->insert(['customer_id' => $this->owner->id, 'wallet_type' => 'app', 'type' => 'grant',
        'related_type' => Payment::class, 'related_id' => $this->payment->id, 'direction' => 'credit', 'amount' => 1, 'credits_delta' => 1, 'balance_after' => 1]);
    $before = app(PaymentDomainCutover::class)->review('local-rehearsal');
    $options = cutoverOptions($this);
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertSuccessful();
    foreach (PaymentDomainCutover::PROCESSING as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $after = app(PaymentDomainCutover::class)->review('local-rehearsal');
    foreach ($before['expected_after'] as $table => $hash) {
        if ($table !== 'admin_audit_events') {
            expect($after['fingerprints'][$table])->toBe($hash, $table);
        }
    }
    expect($this->subscription->fresh()->status)->toBe('ended')->and($this->subscription->fresh()->auto_renew)->toBeFalse()
        ->and($this->subscription->fresh()->meta)->toBe(['provider_status' => 'ACTIVE'])
        ->and($this->order->fresh()->status)->toBe('paid')->and($this->order->fresh()->payment_id)->toBeNull()
        ->and(app(CustomerBillingStateService::class)->servicePlanState($this->owner)['current_plan']->code)->toBe('free')
        ->and(AdminAuditEvent::count())->toBe(1)
        ->and(AdminAuditEvent::first()->after_state['manifest']['archived_polymorphic_links'])->not->toBeEmpty();
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect(AdminAuditEvent::count())->toBe(1);
    Http::assertNothingSent();
});

it('preserves explicit complimentary access without allocating credits', function () {
    $manual = CustomerServiceSubscription::create(['customer_id' => $this->owner->id,
        'service_plan_id' => $this->subscription->service_plan_id, 'source' => 'admin_manual_grant', 'status' => 'active',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'auto_renew' => false]);
    $original = $manual->fresh()->getAttributes();
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    expect($manual->fresh()->getAttributes())->toBe($original)
        ->and(app(CustomerBillingStateService::class)->resolveActiveServiceSubscription($this->owner)->id)->toBe($manual->id)
        ->and(DB::table('subscription_credit_allocations')->count())->toBe(0);
});

it('preserves a valid cash agreement and its existing allocation and audit evidence', function () {
    $agency = Customer::create(['username' => 'agency_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => 1, 'phone_verify' => 1]);
    $this->actingAs($this->operator, 'admin');
    $result = app(\App\Services\Admin\AdminServiceAgreements::class)->record((string) Str::uuid(), $agency->id,
        $this->subscription->service_plan_id, '2026-09-13', '2027-09-03', 115200, 'AGENCY-FIXTURE', 'Approved external collection agreement.');
    $before = app(PaymentDomainCutover::class)->review('local-rehearsal');
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    $after = app(PaymentDomainCutover::class)->review('local-rehearsal');
    foreach (['service_plan_agreements', 'subscription_credit_allocations', 'credit_wallets', 'credit_ledgers'] as $table) {
        expect($after['fingerprints'][$table])->toBe($before['fingerprints'][$table]);
    }
    $agreement = \App\Models\ServicePlanAgreement::findOrFail($result['agreement_id']);
    expect(app(CustomerBillingStateService::class)->resolveActiveServiceSubscription($agency)->id)->toBe($agreement->subscription_id)
        ->and(AdminAuditEvent::count())->toBe($before['counts']['admin_audit_events'] + 1);
});

it('recognizes matched historical manual orders without treating provider-shaped fields as FIB', function () {
    $order = CreditOrder::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->subscription->service_plan_id,
        'order_type' => 'subscription', 'status' => 'paid', 'provider' => 'admin_manual', 'payment_method' => 'admin_manual', 'provider_ref' => 'local-manual-reference']);
    $manual = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'service_plan_id' => $this->subscription->service_plan_id,
        'source' => 'admin_manual', 'status' => 'active', 'provider_ref' => $order->provider_ref, 'renewal_strategy' => 'provider_schedule', 'auto_renew' => true,
        'starts_at' => now()->subMonth(), 'meta' => ['provider' => 'admin_manual', 'order_id' => $order->id]]);
    $before = $manual->fresh()->getAttributes();
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    expect($manual->fresh()->getAttributes())->toBe($before);
});

it('rolls back deletion and the new audit if a preservation invariant fails during execution', function () {
    $options = cutoverOptions($this);
    $before = app(PaymentDomainCutover::class)->review('local-rehearsal');
    $altered = false;
    DB::listen(function ($query) use (&$altered) {
        if (! $altered && str_starts_with($query->sql, 'delete from "payments"')) {
            $altered = true;
            DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['addon_balance_credits' => 99]);
        }
    });
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect($altered)->toBeTrue()->and(app(PaymentDomainCutover::class)->review('local-rehearsal')['fingerprints'])->toBe($before['fingerprints']);
});

it('detaches a historical coupon reservation while preserving its status amounts and usage limit', function () {
    $coupon = \App\Models\Coupon::create(['code' => 'CUTOVER', 'name' => 'Historical fixture', 'discount_type' => 'fixed', 'discount_value' => 500,
        'target_type' => 'all', 'duration_type' => 'once', 'used_count' => 3, 'max_total_uses' => 10]);
    $id = DB::table('coupon_redemptions')->insertGetId(['coupon_id' => $coupon->id, 'customer_id' => $this->owner->id,
        'payment_id' => $this->payment->id, 'purchase_type' => 'plan_subscription', 'coupon_code' => 'CUTOVER',
        'original_amount_iqd' => 24000, 'discount_amount_iqd' => 500, 'final_amount_iqd' => 23500]);
    $before = (array) DB::table('coupon_redemptions')->find($id);
    $before['payment_id'] = null;
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    expect((array) DB::table('coupon_redemptions')->find($id))->toBe($before)
        ->and($coupon->fresh()->used_count)->toBe(3)->and($coupon->fresh()->max_total_uses)->toBe(10);
});

it('invalidates warmed Admin revenue caches while retaining the customer order history', function () {
    $this->actingAs($this->operator, 'admin');
    $home = \Livewire\Livewire::test('admin::pages.home.app-home');
    expect($home->instance()->overviewStats['revenue_total'])->toBeGreaterThan(0);
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    $home = \Livewire\Livewire::test('admin::pages.home.app-home');
    expect($home->instance()->overviewStats['revenue_total'])->toBe(0.0)
        ->and($home->instance()->overviewStats['period_orders'])->toBe(0)
        ->and($this->owner->creditOrders()->count())->toBe(1);
});

it('blocks allocation evidence conflicting local access and unknown dependencies', function (string $issue) {
    match ($issue) {
        'allocation' => \App\Models\SubscriptionCreditAllocation::create(['customer_id' => $this->owner->id, 'subscription_id' => $this->subscription->id,
            'payment_id' => $this->payment->id, 'cycle_key' => 'retained', 'allocation_type' => 'initial', 'cycle_started_at' => now()]),
        'local authority' => $this->subscription->update(['source' => 'admin_manual_grant']),
        'unknown paid' => $this->subscription->update(['source' => null, 'payment_id' => null, 'provider_ref' => null]),
    };
    $before = app(PaymentDomainCutover::class)->review('local-rehearsal');
    expect($before['blockers'])->not->toBeEmpty();
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertFailed();
    expect(app(PaymentDomainCutover::class)->review('local-rehearsal')['fingerprints'])->toBe($before['fingerprints']);
})->with(['allocation', 'local authority', 'unknown paid']);

it('enforces the execution guards and rejects changed reviewed state', function (string $issue) {
    $options = cutoverOptions($this);
    match ($issue) {
        'maintenance' => app()->maintenanceMode()->deactivate(),
        'inactive' => $this->operator->update(['status' => 0]),
        'capability' => $this->operator->forceFill(['admin_capabilities' => ['admin.finance']])->save(),
        'wallet changed' => DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['addon_balance_credits' => 123]),
        'reason' => $options['--reason'] = '',
        'confirmation' => $options['--confirm'] = 'wrong',
        'workers' => $options['--workers-stopped'] = false,
        'hash' => $options['--review-hash'] = str_repeat('0', 64),
        'dry run' => $options['--dry-run'] = true,
    };
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
})->with(['maintenance', 'inactive', 'capability', 'wallet changed', 'reason', 'confirmation', 'workers', 'hash', 'dry run']);

it('excludes retained future-dated revenue and counts a new payment and order without hiding history', function () {
    $this->order->update(['created_at' => now()->addYear()]);
    $this->artisan('billing:cutover-reset-payment-domain', cutoverOptions($this))->assertSuccessful();
    expect(CreditOrder::currentBillingPeriod()->count())->toBe(0)->and(CreditOrder::count())->toBe(1);
    $new = cutoverPayment($this->owner);
    CreditOrder::create(['customer_id' => $this->owner->id, 'payment_id' => $new->id, 'order_type' => 'subscription',
        'status' => 'paid', 'base_amount_iqd' => 24000]);
    expect(CreditOrder::currentBillingPeriod()->where('status', 'paid')->sum('base_amount_iqd'))->toBe(24000)
        ->and(app(BillingReportingBoundary::class)->apply(Payment::query(), 'payments')->count())->toBe(1)
        ->and(CreditOrder::count())->toBe(2);
});

it('refuses nonlocal database configurations before connecting', function (string $option, mixed $value) {
    app()->forgetInstance(\App\Services\Billing\Cutover\CutoverIdentity::class);
    app()->instance('env', 'local');
    config(['database.default' => 'mysql', 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 3306,
        'database.connections.mysql.database' => 'cutover_fixture', 'database.connections.mysql.'.$option => $value]);
    DB::purge('mysql');
    expect(fn () => app(PaymentDomainCutover::class)->identity('local-rehearsal'))->toThrow(\App\Services\Billing\PaymentHistoryResetRefused::class);
    config(['database.default' => 'sqlite']);
})->with([['host', 'production.rds.amazonaws.com'], ['port', 3307], ['database', 'production'], ['read', ['host' => '127.0.0.1']]]);
