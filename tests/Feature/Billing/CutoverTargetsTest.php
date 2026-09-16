<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\Cutover\CutoverIdentity;
use App\Services\Billing\PaymentDomainCutover;
use App\Services\Billing\PaymentHistoryResetRefused;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CutoverIdentityFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    Storage::shouldReceive('disk')->never();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 15)->startOfDay());
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->identity = CutoverIdentityFixture::install('production');
    $this->operator = User::forceCreate(['name' => 'Fixture operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->owner = Customer::create(['username' => 'target_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture']);
    $this->payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'canceled', 'internal_status' => 'canceled', 'fib_subscription_id' => 'fixture-old-ref',
        'provider_subscription_status' => 'CANCELLED', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
        'amount' => 24000, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => ServicePlan::where('code', 'pro')->value('id'),
        'meta' => ['provider_cancellation' => ['state' => 'confirmed', 'provider_ref' => 'fixture-old-ref',
            'provider_cancel_pending' => false, 'provider_cancel_confirmed_at' => now()->subDay()->toIso8601String(),
            'result' => 'already_canceled', 'observed_active_until' => now()->subMonth()->toIso8601String()]]]);
    $this->subscription = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'payment_id' => $this->payment->id,
        'service_plan_id' => $this->payment->purchasable_id, 'source' => 'fib', 'provider_ref' => 'fixture-old-ref',
        'status' => 'active', 'auto_renew' => true, 'starts_at' => now()->subYear(), 'ends_at' => now()->subMonth()]);
});

function productionCutoverOptions($test): array
{
    app()->maintenanceMode()->activate(['time' => time()]);

    return ['--target' => 'production', '--execute' => true,
        '--review-hash' => app(PaymentDomainCutover::class)->review('production', $test->operator->id)['review_hash'],
        '--confirm' => 'RESET-V2-PRODUCTION-BILLING-DOMAIN', '--admin' => $test->operator->id,
        '--reason' => 'Reviewed synthetic production-shaped cutover fixture.', '--workers-stopped' => true,
        '--backup-confirmed' => true, '--restore-confirmed' => true];
}

it('fails closed on deployment identity assertions without touching SQL rows', function (string $fault) {
    match ($fault) {
        'local environment' => $this->identity->connectionFacts['environment'] = 'local',
        'mariadb' => $this->identity->serverFacts['engine_family'] = 'mariadb',
        'host' => $this->identity->connectionFacts['host'] = 'unexpected.example.test',
        'schema' => $this->identity->serverFacts['schema'] = 'unexpected_schema',
        'port' => $this->identity->serverFacts['port'] = 3307,
        'readonly' => $this->identity->serverFacts['read_only'] = 1,
        'super readonly' => $this->identity->serverFacts['super_read_only'] = 1,
        'writable replica' => $this->identity->serverFacts['replica_channels'] = 1,
        'foreign keys' => $this->identity->serverFacts['foreign_keys'] = 0,
        'split' => $this->identity->connectionFacts['split'] = true,
        'prefix' => $this->identity->connectionFacts['prefix'] = true,
        'socket' => $this->identity->connectionFacts['socket'] = true,
        'missing assertion' => config(['billing_cutover.expected_database' => null]),
        'disabled' => config(['billing_cutover.enabled' => false]),
        'wrong target' => config(['billing_cutover.target' => 'local-rehearsal']),
    };
    expect(fn () => app(CutoverIdentity::class)->inspect('production'))->toThrow(PaymentHistoryResetRefused::class);
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with(['local environment', 'mariadb', 'host', 'schema', 'port', 'readonly', 'super readonly', 'writable replica', 'foreign keys', 'split', 'prefix', 'socket', 'missing assertion', 'disabled', 'wrong target']);

it('requires an explicit CLI target and rejects production environment under local policy', function () {
    $this->artisan('billing:cutover-reset-payment-domain')->assertFailed();
    $identity = CutoverIdentityFixture::install();
    $identity->connectionFacts['environment'] = 'production';
    expect(fn () => $identity->inspect('local-rehearsal'))->toThrow(PaymentHistoryResetRefused::class);
});

it('reviews production before maintenance with no writes and reports all readiness and provider evidence', function () {
    $this->artisan('admin:capability-audit', ['user' => $this->operator->id])->assertSuccessful();
    DB::enableQueryLog();
    $review = app(PaymentDomainCutover::class)->review('production', $this->operator->id);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($review['blockers'])->toBe([])->and($review['readiness']['migrations']['pending'])->toBe([])
        ->and($review['provider_obligations']['summary']['retired_confirmed_cancelled'])->toBe(1)
        ->and($review['database']['server']['hostname'])->toBe('remote-fixture');
    foreach ($queries as $query) {
        expect(preg_match('/^\s*(insert|update|delete|alter|drop|create|replace)\b/i', $query['query']))->toBe(0);
    }
    expect(app()->isDownForMaintenance())->toBeFalse()->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
});

it('blocks production preflight for missing capabilities migrations or backup evidence', function (string $fault) {
    match ($fault) {
        'capabilities' => $this->operator->forceFill(['admin_capabilities' => ['admin.read']])->save(),
        'inactive' => $this->operator->forceFill(['status' => 0])->save(),
        'migration' => DB::table('migrations')->where('migration', '2026_09_06_000002_add_admin_operation_safety')->delete(),
        'backup' => config(['billing_cutover.backup_reference' => null]),
        'restore' => config(['billing_cutover.restore_reference' => null]),
    };
    $review = app(PaymentDomainCutover::class)->review('production', $this->operator->id);
    expect($review['blockers'])->not->toBeEmpty();
    $this->artisan('billing:cutover-reset-payment-domain', productionCutoverOptions($this))->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
})->with(['capabilities', 'inactive', 'migration', 'backup', 'restore']);

it('never treats unknown remote evidence or valid coverage as disposable production history', function (string $fault, string $classification) {
    match ($fault) {
        'missing evidence' => $this->payment->update(['meta' => []]),
        'post only' => $this->payment->update(['meta' => ['provider_cancellation' => ['state' => 'requested', 'result' => 'cancel_requested']]]),
        'review' => $this->payment->update(['internal_status' => 'requires_review']),
        'coverage' => $this->payment->update(['paid_at' => now()->subMonth(), 'active_until' => now()->addMonth()]),
        'malformed coverage' => $this->payment->update(['meta' => ['provider_cancellation' => ['effective_access_until' => 'unknown']]]),
        'orphan' => $this->subscription->update(['payment_id' => null]),
        'conflicting reference' => $this->subscription->update(['provider_ref' => 'different-remote-obligation']),
        'retained subscription coverage' => $this->subscription->update(['ends_at' => now()->addMonth()]),
        'retained collection' => $this->payment->update(['paid_at' => now()->subMonth(), 'meta' => array_merge($this->payment->meta,
            ['verified_subscription_collection' => ['provider_object_id' => $this->payment->fib_subscription_id, 'paid_through' => now()->addMonth()->toIso8601String()]])]),
        'legacy recurring intent' => DB::table('payment_intents')->insert(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id,
            'provider' => 'fixture-provider', 'purpose_type' => 'service_plan', 'status' => 'canceled', 'is_recurring' => true,
            'base_amount_iqd' => 1, 'gross_amount_iqd' => 1, 'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]),
    };
    $review = app(PaymentDomainCutover::class)->review('production', $this->operator->id);
    expect($review['provider_obligations']['summary'][$classification])->toBeGreaterThan(0)->and($review['blockers'])->not->toBeEmpty();
    $this->artisan('billing:cutover-reset-payment-domain', productionCutoverOptions($this))->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with([['missing evidence', 'unresolved_remote_obligation'], ['post only', 'unresolved_remote_obligation'],
    ['review', 'requires_operator_review'], ['coverage', 'valid_coverage_to_preserve'], ['malformed coverage', 'requires_operator_review'],
    ['orphan', 'unresolved_remote_obligation'], ['conflicting reference', 'unresolved_remote_obligation'],
    ['retained subscription coverage', 'requires_operator_review'], ['retained collection', 'valid_coverage_to_preserve'],
    ['legacy recurring intent', 'unresolved_remote_obligation']]);

it('rejects review hashes across targets in either direction', function (string $from, string $to) {
    CutoverIdentityFixture::install($from);
    $hash = app(PaymentDomainCutover::class)->review($from, $from === 'production' ? $this->operator->id : null)['review_hash'];
    CutoverIdentityFixture::install($to);
    app()->maintenanceMode()->activate(['time' => time()]);
    $options = ['--target' => $to, '--execute' => true, '--review-hash' => $hash, '--admin' => $this->operator->id,
        '--reason' => 'Cross-target fixture must refuse the reviewed hash.', '--workers-stopped' => true,
        '--backup-confirmed' => true, '--restore-confirmed' => true,
        '--confirm' => $to === 'production' ? 'RESET-V2-PRODUCTION-BILLING-DOMAIN' : 'RESET-V2-BILLING-DOMAIN'];
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
})->with([['local-rehearsal', 'production'], ['production', 'local-rehearsal']]);

it('aborts execution on changed identity state or missing execution safeguards', function (string $fault) {
    $options = productionCutoverOptions($this);
    match ($fault) {
        'host' => [$this->identity->connectionFacts['host'] = 'changed.example.us-east-1.rds.amazonaws.com', config(['billing_cutover.expected_host' => 'changed.example.us-east-1.rds.amazonaws.com'])],
        'schema' => [$this->identity->connectionFacts['configured_schema'] = 'changed_fixture', $this->identity->serverFacts['schema'] = 'changed_fixture', config(['billing_cutover.expected_database' => 'changed_fixture'])],
        'state' => DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['addon_balance_credits' => 999]),
        'maintenance' => app()->maintenanceMode()->deactivate(),
        'writers' => $options['--workers-stopped'] = false,
        'backup confirmation' => $options['--backup-confirmed'] = false,
        'restore confirmation' => $options['--restore-confirmed'] = false,
        'local phrase' => $options['--confirm'] = 'RESET-V2-BILLING-DOMAIN',
    };
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
})->with(['host', 'schema', 'state', 'maintenance', 'writers', 'backup confirmation', 'restore confirmation', 'local phrase']);

it('executes the shared mutation algorithm with production-shaped facts and refuses a second epoch', function () {
    $before = app(PaymentDomainCutover::class)->review('production', $this->operator->id);
    $options = productionCutoverOptions($this);
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertSuccessful();
    expect(Payment::count())->toBe(0)->and(AdminAuditEvent::count())->toBe(1)
        ->and($this->subscription->fresh()->status)->toBe('ended');
    $after = app(PaymentDomainCutover::class)->review('production', $this->operator->id);
    foreach ($before['expected_after'] as $table => $hash) {
        if ($table !== 'admin_audit_events') {
            expect($after['fingerprints'][$table])->toBe($hash);
        }
    }
    expect(AdminAuditEvent::first()->after_state['manifest']['target'])->toBe('production');
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect(AdminAuditEvent::count())->toBe(1);
    Http::assertNothingSent();
});
