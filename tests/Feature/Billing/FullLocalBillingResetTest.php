<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\BillingReportingBoundary;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\PaymentDomainCutover;
use App\Services\Billing\PaymentHistoryResetRefused;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    \Tests\Support\CutoverIdentityFixture::install();
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Storage::shouldReceive('disk')->never();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->operator = User::forceCreate(['name' => 'Reset fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture',
        'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->owner = Customer::create(['username' => 'reset_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => 1, 'phone_verify' => 1]);
    $this->payment = fullResetPayment($this->owner);
    $this->subscription = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'payment_id' => $this->payment->id,
        'service_plan_id' => $this->payment->purchasable_id, 'source' => 'fib', 'provider_ref' => 'retired-fib-reference',
        'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear(), 'auto_renew' => true,
        'meta' => ['provider_status' => 'ACTIVE', 'fib_subscription_id' => 'retired-fib-reference', 'order_id' => 123]]);
    $this->actingAs($this->operator, 'admin');
});

afterEach(function () {
    Http::assertNothingSent();
});

function fullResetPayment(Customer $customer, array $overrides = []): Payment
{
    return Payment::create(array_replace(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'awaiting_customer_action', 'internal_status' => 'requires_review', 'fib_subscription_id' => Str::uuid(),
        'provider_subscription_status' => 'DRAFT', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
        'amount' => 12000, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => ServicePlan::where('code', 'pro')->value('id')], $overrides))->fresh();
}

function fullResetReview($test): array
{
    return app(PaymentDomainCutover::class)->review('local-rehearsal', $test->operator->id, PaymentDomainCutover::FULL_LOCAL_RESET);
}

function executeFullResetFixture($test): array
{
    app()->maintenanceMode()->activate(['time' => time()]);
    $review = fullResetReview($test);
    expect($review['blockers'])->toBe([]);

    return app(PaymentDomainCutover::class)->execute('local-rehearsal', $review['review_hash'], 'Approved isolated full reset fixture.',
        true, $test->operator->id, true, true, PaymentDomainCutover::FULL_LOCAL_RESET);
}

it('empties exactly five processing tables and preserves populated financial customer and workload history', function () {
    foreach (['pending', 'paid', 'failed', 'canceled', 'expired'] as $status) {
        fullResetPayment($this->owner, ['status' => $status]);
    }
    DB::table('payment_events')->insert(['payment_id' => $this->payment->id, 'provider' => 'fib', 'event_type' => 'provider_status_checked', 'source' => 'fixture']);
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fib',
        'purpose_type' => 'service_plan', 'status' => 'paid', 'base_amount_iqd' => 12000, 'gross_amount_iqd' => 12000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]);
    DB::table('payment_transactions')->insert(['payment_intent_id' => $intent, 'provider' => 'fib', 'transaction_type' => 'charge', 'status' => 'paid']);
    DB::table('payment_webhook_events')->insert(['payment_intent_id' => $intent, 'provider' => 'fib', 'event_key' => Str::uuid(), 'processing_status' => 'processed']);
    $order = CreditOrder::create(['customer_id' => $this->owner->id, 'payment_id' => $this->payment->id, 'payment_intent_id' => $intent,
        'order_type' => 'subscription', 'status' => 'paid', 'base_amount_iqd' => 12000, 'credits_amount' => 1000]);
    $allocation = DB::table('subscription_credit_allocations')->insertGetId(['customer_id' => $this->owner->id,
        'subscription_id' => $this->subscription->id, 'payment_id' => $this->payment->id, 'cycle_key' => 'fixture-cycle',
        'allocation_type' => 'initial', 'cycle_started_at' => now(), 'status' => 'applied']);
    DB::table('credit_ledgers')->insert(['customer_id' => $this->owner->id, 'wallet_type' => 'app', 'type' => 'grant',
        'related_type' => Payment::class, 'related_id' => $this->payment->id, 'direction' => 'credit', 'amount' => 51, 'credits_delta' => 51, 'balance_after' => 51]);
    foreach (['app', 'api'] as $type) {
        expect(DB::table('credit_wallets')->where('customer_id', $this->owner->id)->where('wallet_type', $type)->count())->toBe(1);
        DB::table('credit_wallets')->where('customer_id', $this->owner->id)->where('wallet_type', $type)
            ->update(['balance_credits' => 107, 'subscription_balance_credits' => 51, 'addon_balance_credits' => 56]);
    }
    \App\Models\MlJob::create(['id' => Str::uuid(), 'customer_id' => $this->owner->id, 'status' => 'done', 'credits_charged' => 7]);
    \App\Models\CustomerFile::create(['customer_id' => $this->owner->id, 'purpose' => 'render', 'disk' => 's3', 'path' => 'fixture/private.wav', 'size_bytes' => 10]);
    DB::table('customer_usages')->updateOrInsert(['customer_id' => $this->owner->id], ['storage_used_bytes' => 10, 'jobs_total' => 1, 'jobs_succeeded' => 1]);
    $before = fullResetReview($this);
    $result = executeFullResetFixture($this);
    $after = fullResetReview($this);
    foreach (PaymentDomainCutover::PROCESSING as $table) {
        expect($before['counts'][$table])->toBeGreaterThan(0)->and(DB::table($table)->count())->toBe(0);
    }
    foreach ($before['expected_after'] as $table => $hash) {
        if ($table !== 'admin_audit_events') {
            expect($after['fingerprints'][$table])->toBe($hash, $table);
        }
    }
    foreach (['customers', 'credit_wallets', 'credit_ledgers', 'ml_jobs', 'customer_files', 'customer_usages'] as $table) {
        expect($after['fingerprints'][$table])->toBe($before['fingerprints'][$table]);
    }
    expect((int) $order->fresh()->base_amount_iqd)->toBe(12000)->and($order->fresh()->payment_id)->toBeNull()
        ->and($order->fresh()->payment_intent_id)->toBeNull()
        ->and(DB::table('subscription_credit_allocations')->find($allocation)->payment_id)->toBeNull()
        ->and($this->subscription->fresh()->provider_ref)->toBeNull()
        ->and($this->subscription->fresh()->meta)->toBe(['order_id' => 123])
        ->and($result['current_payment_count'])->toBe(0);
    $audit = AdminAuditEvent::where('action', BillingReportingBoundary::ACTION)->firstOrFail()->after_state;
    expect($audit)->toHaveKeys(['reporting_boundary', 'reset_verification'])->not->toHaveKey('manifest');
    expect(json_encode($audit))->not->toContain('retired-fib-reference', $this->payment->fib_subscription_id, 'legacy_processing_provenance', 'provider_obligations');
});

it('ends manual cash future and storage authority with every customer Free and no later refill', function () {
    $manual = CustomerServiceSubscription::create(['customer_id' => $this->owner->id,
        'service_plan_id' => $this->subscription->service_plan_id, 'source' => 'admin_manual_grant', 'status' => 'active',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    $storage = CustomerStorageSubscription::create(['customer_id' => $this->owner->id,
        'storage_plan_id' => \App\Models\StoragePlan::create(['code' => 'reset-storage', 'name' => 'Fixture storage', 'quota_mb' => 2048, 'price_iqd' => 2000, 'is_active' => true])->id,
        'source' => 'admin_manual', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    $other = Customer::create(['username' => 'cash_reset', 'email' => 'cash_reset@example.test', 'password' => 'fixture']);
    $agreement = app(\App\Services\Admin\AdminServiceAgreements::class)->record((string) Str::uuid(), $other->id,
        $this->subscription->service_plan_id, now()->addDay()->toDateString(), now()->addYear()->toDateString(), 115200,
        'CASH-RESET-FIXTURE', 'Approved future agreement fixture.');
    $activeCustomer = Customer::create(['username' => 'active_cash_reset', 'email' => 'active_cash_reset@example.test', 'password' => 'fixture']);
    $activeAgreement = app(\App\Services\Admin\AdminServiceAgreements::class)->record((string) Str::uuid(), $activeCustomer->id,
        $this->subscription->service_plan_id, now()->subDay()->toDateString(), now()->addYear()->toDateString(), 115200,
        'ACTIVE-CASH-RESET-FIXTURE', 'Approved active agreement fixture.');
    $stale = \App\Models\ServicePlanAgreement::findOrFail($agreement['agreement_id']);
    $before = fullResetReview($this);
    executeFullResetFixture($this);
    foreach (Customer::all() as $customer) {
        expect(app(CustomerBillingStateService::class)->resolveActiveServiceSubscription($customer))->toBeNull()
            ->and(app(CustomerBillingStateService::class)->resolveActiveStorageSubscription($customer))->toBeNull()
            ->and($customer->currentServicePlan()->code)->toBe('free');
    }
    expect($manual->fresh()->status)->toBe('ended')->and($storage->fresh()->status)->toBe('ended')
        ->and($stale->fresh()->status)->toBe('ended');
    $this->travel(2)->days();
    expect(app(\App\Services\Billing\ServiceAgreementLifecycle::class)->processLocked($stale, $other))->toBe('ended')
        ->and(app(\App\Services\Billing\ServiceAgreementLifecycle::class)->process($activeAgreement['agreement_id']))->toBe('ended');
    // Even a stale local model made active cannot cross the committed ID boundary.
    $manual->update(['status' => 'active', 'created_at' => now()->addYear()]);
    expect($this->owner->currentServicePlan()->code)->toBe('free')
        ->and(app(\App\Services\Billing\SubscriptionCyclePolicy::class)->calendarAllocation($manual, now()))->toBeNull();
    $after = fullResetReview($this);
    foreach (['credit_wallets', 'credit_ledgers', 'subscription_credit_allocations'] as $table) {
        expect($after['fingerprints'][$table])->toBe($before['fingerprints'][$table]);
    }
});

it('does not consult provider obligations or coverage dispositions in the explicit reset mode', function () {
    $this->mock(\App\Services\Billing\Cutover\ProviderObligationInventory::class)->shouldNotReceive('inspect');
    $this->mock(\App\Services\Billing\ProviderCoverageDispositions::class)->shouldNotReceive('approvedFor')->shouldNotReceive('eligibleIds');
    \Tests\Support\CutoverIdentityFixture::install('production');
    $review = app(PaymentDomainCutover::class)->review('production', $this->operator->id, PaymentDomainCutover::FULL_LOCAL_RESET);
    expect($review['blockers'])->toBe([])->and($review['preserved_local_access'])->toBe([]);
    app()->maintenanceMode()->activate(['time' => time()]);
    app(PaymentDomainCutover::class)->execute('production', $review['review_hash'], 'Synthetic target full reset fixture.', true,
        $this->operator->id, true, true, PaymentDomainCutover::FULL_LOCAL_RESET);
    expect(DB::table('provider_coverage_dispositions')->count())->toBe(0);
});

it('drops old unknown and malformed native callbacks and old queued callback work after reset', function () {
    $ref = $this->payment->fib_subscription_id;
    executeFullResetFixture($this);
    $preserved = collect(['customers', 'credit_wallets', 'credit_ledgers', 'subscription_credit_allocations'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
    app()->maintenanceMode()->deactivate();
    foreach ([['id' => $ref, 'status' => 'PAID'], ['id' => 'unknown', 'status' => 'PAID'], []] as $payload) {
        $this->postJson('/payments/webhooks/fib/subscription', $payload)->assertStatus(202);
        $this->postJson('/payments/webhooks/fib', $payload)->assertStatus(202);
    }
    config(['payments.providers.areeba.enabled' => true, 'areeba.webhook_secret' => 'fixture-secret']);
    $this->withHeader('x-webhook-secret', 'fixture-secret')->postJson('/payments/webhooks/areeba',
        ['merchantTransactionId' => 'retired-intent', 'transactionStatus' => 'PAID'])->assertStatus(410);
    app()->call([new \App\Jobs\Payments\ProcessFibPaymentStatus('subscription', $ref, ['status' => 'PAID']), 'handle']);
    try {
        app(\App\Services\Payments\PaymentWebhookService::class)->handle('fib', \Illuminate\Http\Request::create('/', 'POST', ['id' => $ref]));
        test()->fail('Legacy webhook must be closed.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(410);
    }
    foreach (PaymentDomainCutover::PROCESSING as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect($this->owner->currentServicePlan()->code)->toBe('free');
    foreach ($preserved as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($rows);
    }
    expect(fn () => app(\App\Services\Billing\ProviderObligationBatchReview::class)->review('local-rehearsal'))
        ->toThrow(PaymentHistoryResetRefused::class, 'old provider domain was retired locally');
});

it('admits only fresh compact Payments and fulfills a new epoch subscription once across repeated checks', function () {
    executeFullResetFixture($this);
    $new = fullResetPayment($this->owner, ['internal_status' => 'awaiting_customer_action', 'provider_status' => 'DRAFT',
        'qr_code' => 'data:image/png;base64,DO-NOT-STORE', 'valid_until' => now()->addDay(),
        'original_amount_iqd' => 12000, 'discounted_amount_iqd' => 12000, 'discount_amount_iqd' => 0,
        'purchase_snapshot' => ['billing_cycle' => 'monthly']]);
    expect($new->isCurrentBillingPeriod())->toBeTrue()->and($new->id)->toBeGreaterThan($this->payment->id)
        ->and($new->fresh()->usesCompactPersistence())->toBeTrue()->and($new->fresh()->qr_code)->toBeNull();
    $raw = ['id' => $new->fib_subscription_id, 'status' => 'PAID', 'monetaryValue' => ['amount' => 12000, 'currency' => 'IQD'],
        'lastPaymentAt' => now()->toIso8601String(), 'activeUntil' => now()->addMonth()->toIso8601String()];
    $this->partialMock(\App\Domain\Payments\Fib\FibSubscriptionService::class)->shouldReceive('getStatus')
        ->andReturn(\App\Domain\Payments\Data\FibSubscriptionStatusData::fromArray($raw));
    app(\App\Domain\Payments\Actions\SyncFibCheckoutStatus::class)->handle($new);
    expect($new->fresh()->mismatch_reason)->toBeNull()
        ->and($new->fresh()->internal_status->value)->toBe('applied')
        ->and($new->fresh()->fulfilled_at)->not->toBeNull()->and($this->owner->currentServicePlan()->code)->toBe('pro');
    $events = $new->events()->count();
    $wallets = DB::table('credit_wallets')->get()->toJson();
    for ($i = 0; $i < 100; $i++) {
        $this->travel(10)->seconds();
        app(\App\Domain\Payments\Actions\SyncFibCheckoutStatus::class)->handle($new);
    }
    app()->maintenanceMode()->deactivate();
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/payments/webhooks/fib/subscription', ['id' => $new->fib_subscription_id, 'status' => 'PAID'])->assertStatus(202);
    }
    expect($new->events()->count())->toBe($events)->and(DB::table('credit_wallets')->get()->toJson())->toBe($wallets);
});

it('binds reset mode to the review and requires backup restore maintenance and the reviewed operator', function () {
    $old = app(PaymentDomainCutover::class)->review('local-rehearsal', $this->operator->id);
    $reset = fullResetReview($this);
    expect($reset['review_hash'])->not->toBe($old['review_hash']);
    app()->maintenanceMode()->activate(['time' => time()]);
    foreach ([[false, true, $reset['review_hash']], [true, false, $reset['review_hash']], [true, true, $old['review_hash']]] as [$backup, $restore, $hash]) {
        expect(fn () => app(PaymentDomainCutover::class)->execute('local-rehearsal', $hash, 'Full reset fixture must refuse.', true,
            $this->operator->id, $backup, $restore, PaymentDomainCutover::FULL_LOCAL_RESET))->toThrow(PaymentHistoryResetRefused::class);
    }
    expect(AdminAuditEvent::where('action', BillingReportingBoundary::ACTION)->count())->toBe(0)->and($this->payment->fresh())->not->toBeNull();
});

it('keeps schema migration backup and privilege readiness as blockers', function (string $fault) {
    match ($fault) {
        'backup' => config(['billing_cutover.backup_reference' => null]),
        'restore' => config(['billing_cutover.restore_reference' => null]),
        'admin' => $this->operator->update(['status' => 0]),
        'migration' => DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete(),
    };
    expect(fullResetReview($this)['blockers'])->not->toBeEmpty();
})->with(['backup', 'restore', 'admin', 'migration']);

it('rolls back all changes if a wallet fingerprint changes during reset', function () {
    $before = fullResetReview($this);
    $altered = false;
    DB::listen(function ($query) use (&$altered) {
        if (! $altered && str_starts_with($query->sql, 'delete from "payments"')) {
            $altered = true;
            DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['addon_balance_credits' => 987]);
        }
    });
    expect(fn () => executeFullResetFixture($this))->toThrow(PaymentHistoryResetRefused::class);
    expect($altered)->toBeTrue()->and(fullResetReview($this)['fingerprints'])->toBe($before['fingerprints']);
});

it('rejects an unexpected dependency even when all provider concerns are retired', function () {
    \Illuminate\Support\Facades\Schema::create('unexpected_reset_dependency', function ($table) {
        $table->id();
        $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
    });
    DB::table('unexpected_reset_dependency')->insert(['payment_id' => $this->payment->id]);
    try {
        expect(implode(' ', fullResetReview($this)['blockers']))->toContain('retained evidence cannot be detached');
        app()->maintenanceMode()->activate(['time' => time()]);
        expect(fn () => app(PaymentDomainCutover::class)->execute('local-rehearsal', fullResetReview($this)['review_hash'],
            'Unexpected schema must remain blocked.', true, $this->operator->id, true, true, PaymentDomainCutover::FULL_LOCAL_RESET))
            ->toThrow(PaymentHistoryResetRefused::class);
        expect($this->payment->fresh())->not->toBeNull();
    } finally {
        \Illuminate\Support\Facades\Schema::drop('unexpected_reset_dependency');
    }
});

it('requires the distinct full reset CLI confirmation and accepts a fresh mode-bound review', function () {
    app()->maintenanceMode()->activate(['time' => time()]);
    $options = ['--target' => 'local-rehearsal', '--mode' => 'full-local-reset', '--execute' => true,
        '--review-hash' => fullResetReview($this)['review_hash'], '--admin' => $this->operator->id,
        '--reason' => 'Reviewed isolated full reset command fixture.', '--workers-stopped' => true,
        '--backup-confirmed' => true, '--restore-confirmed' => true, '--confirm' => 'RESET-V2-BILLING-DOMAIN'];
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertFailed();
    expect($this->payment->fresh())->not->toBeNull();
    $options['--confirm'] = 'RESET-V2-BILLING-DOMAIN-ALL-CUSTOMERS-FREE';
    $this->artisan('billing:cutover-reset-payment-domain', $options)->assertSuccessful();
    expect(Payment::count())->toBe(0)->and($this->owner->currentServicePlan()->code)->toBe('free');
});

it('refuses importing a pre-epoch Payment identity or timestamp after reset', function () {
    executeFullResetFixture($this);
    expect(fn () => Payment::unguarded(fn () => fullResetPayment($this->owner, ['created_at' => now()->subDay()])))->toThrow(\LogicException::class);
    expect(fn () => Payment::unguarded(fn () => fullResetPayment($this->owner, ['id' => $this->payment->id])))->toThrow(\LogicException::class);
    expect(Payment::count())->toBe(0);
});
