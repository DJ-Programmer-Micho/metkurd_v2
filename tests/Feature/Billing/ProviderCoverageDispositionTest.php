<?php

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\Cutover\ProviderObligationInventory;
use App\Services\Billing\PaymentDomainCutover;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\CutoverIdentityFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    $this->seed();
    $this->travelTo(now()->setDate(2026, 9, 30)->startOfDay());
    $owner = Customer::create(['username' => 'evidence_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture']);
    $this->payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $owner->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'recurring', 'provider_object_type' => 'subscription',
        'status' => 'paid', 'internal_status' => 'applied', 'paid_at' => now()->subDay(), 'fulfilled_at' => now()->subDay(),
        'fib_subscription_id' => 'fixture-subscription', 'provider_subscription_status' => 'CANCELLED',
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 12000, 'currency' => 'IQD',
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => ServicePlan::where('code', 'student')->value('id'), 'meta' => []]);
    $this->subscription = CustomerServiceSubscription::create(['customer_id' => $owner->id, 'payment_id' => $this->payment->id,
        'service_plan_id' => $this->payment->purchasable_id, 'source' => 'fib', 'provider_ref' => 'fixture-subscription',
        'status' => 'active', 'auto_renew' => false, 'canceled_at' => now()->subHours(2),
        'starts_at' => '2026-09-29 16:42:26', 'ends_at' => '2026-10-29 16:42:26',
        'meta' => ['period_ends_at' => '2026-10-29T19:42:26+03:00']]);
    $this->payload = ['id' => 'fixture-subscription', 'status' => 'CANCELLED',
        'monetaryValue' => ['amount' => 12000, 'currency' => 'IQD'],
        'activeUntil' => 1795970535998, 'lastPaymentAt' => 1790700135998];
    $this->payment->update(['status_response' => $this->payload, 'last_status_checked_at' => now()->subHour()]);
    $this->event = app(PaymentEventRecorder::class)->record($this->payment, [
        'event_type' => 'provider_status_ignored', 'source' => 'fib_subscription_callback',
        'before_status' => 'paid', 'after_status' => 'paid', 'payload' => $this->payload,
        'processed_at' => now()->subHour(), 'meta' => ['provider_object_type' => 'subscription', 'requested_status' => 'canceled']]);
});

function coverageRequest($test): array
{
    return ['payment_id' => $test->payment->id, 'customer_id' => $test->payment->customer_id,
        'provider_subscription_id' => $test->payment->fib_subscription_id,
        'subscription_kind' => 'service', 'subscription_id' => $test->subscription->id,
        'coverage_start' => '2026-09-29T19:42:15.998+03:00', 'coverage_end' => '2026-11-29T19:42:15.998+03:00',
        'evidence_event_id' => $test->event->id, 'coverage_confirmed' => true, 'review_reference' => 'MERCHANT-FIXTURE-REVIEW'];
}
function coverageAdmin($test, array $caps = ['admin.read', 'admin.finance', 'admin.reconcile']): User
{
    $admin = User::forceCreate(['name' => 'Coverage reviewer', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => $caps]);
    $test->actingAs($admin, 'admin');

    return $admin;
}
function coverageSnapshot(): array
{
    $tables = ['credit_wallets', 'credit_ledgers', 'customer_service_subscriptions', 'customer_storage_subscriptions',
        'payments', 'payment_events', 'payment_intents', 'credit_orders', 'subscription_credit_allocations', 'admin_audit_events'];

    return collect($tables)->mapWithKeys(fn ($t) => [$t => hash('sha256', DB::table($t)->orderBy('id')->get()->toJson())])->all();
}
function approveCoverage($test): array
{
    return app(\App\Services\Billing\ProviderCoverageDispositions::class)->approve(coverageRequest($test), (string) Str::uuid(), 'Merchant confirmed the entire evidenced term.');
}
function executeCoverageCutover($test, $admin): array
{
    CutoverIdentityFixture::install('production');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate(['time' => time()]);
    $service = app(PaymentDomainCutover::class);
    $review = $service->review('production', $admin->id);
    expect($review['blockers'])->toBe([]);

    return $service->execute('production', $review['review_hash'], 'Isolated approved coverage cutover fixture.', true, $admin->id, true, true);
}
it('reviews without writes and approves only provenance without changing source financial rows', function () {
    coverageAdmin($this);
    $service = app(\App\Services\Billing\ProviderCoverageDispositions::class);
    $before = coverageSnapshot();
    $id = (string) Str::uuid();
    $request = coverageRequest($this);
    $service->review($request, $id, 'Merchant confirmed the entire evidenced term.');
    expect(coverageSnapshot())->toBe($before);
    $result = $service->approve($request, $id, 'Merchant confirmed the entire evidenced term.');
    foreach ($before as $table => $hash) {
        if ($table !== 'admin_audit_events') {
            expect(coverageSnapshot()[$table])->toBe($hash, $table);
        }
    }
    expect(DB::table('admin_audit_events')->count())->toBe(1);
    expect($service->approve($request, $id, 'Merchant confirmed the entire evidenced term.'))->toBe($result);
    expect(DB::table('provider_coverage_dispositions')->count())->toBe(1);
    expect(DB::table('admin_audit_events')->count())->toBe(1);
    expect($service->approvedFor($this->payment->fresh())?->id)->toBe($result['disposition_id']);
    Http::assertNothingSent();
});
it('retains coverage through cutover with exact millisecond expiry and no new financial authority', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    $approved = approveCoverage($this);
    $before = coverageSnapshot();
    $result = executeCoverageCutover($this, $admin);
    $sub = $this->subscription->fresh();
    $customer = Customer::findOrFail($sub->customer_id);
    $resolver = app(\App\Services\Billing\CustomerBillingStateService::class);
    expect($resolver->resolveActiveServiceSubscription($customer)?->id)->toBe($sub->id)
        ->and($resolver->servicePlanState($customer)['access_type'])->toBe('legacy_provider_coverage')
        ->and($resolver->servicePlanState($customer)['cancelable'])->toBeFalse()
        ->and(app(\App\Services\Mcp\CustomerMcpAccessService::class)->eligible($customer))->toBeTrue()
        ->and($sub->payment_id)->toBeNull()->and($sub->source)->toBe('fib')->and($sub->auto_renew)->toBeFalse()
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('provider_coverage_dispositions')->value('status'))->toBe('retained');
    $policy = app(\App\Services\Billing\SubscriptionCyclePolicy::class);
    expect($policy->isCurrent($sub))->toBeFalse()->and($policy->calendarAllocation($sub, now()))->toBeNull();
    foreach (['credit_wallets', 'credit_ledgers', 'credit_orders', 'payment_intents', 'subscription_credit_allocations'] as $table) {
        expect(coverageSnapshot()[$table])->toBe($before[$table], $table);
    }
    $end = \Carbon\Carbon::parse('2026-11-29T19:42:15.998+03:00');
    $this->travelTo($end->copy()->subMillisecond());
    expect($resolver->resolveActiveServiceSubscription($customer)?->id)->toBe($sub->id);
    $this->travelTo($end);
    expect($resolver->resolveActiveServiceSubscription($customer)?->id)->not->toBe($sub->id)
        ->and($resolver->servicePlanState($customer)['current_plan']->is_free)->toBeTrue()
        ->and(app(\App\Services\Mcp\CustomerMcpAccessService::class)->eligible($customer))->toBeFalse();
    Http::assertNothingSent();
});
it('refuses unsafe evidence identity dates and approval inputs', function ($fault) {
    coverageAdmin($this);
    $request = coverageRequest($this);
    match ($fault) {
        'active' => $this->payment->update(['provider_subscription_status' => 'ACTIVE']),
        'callback' => $this->event->update(['event_type' => 'callback_received']),
        'local only' => $this->event->delete(),
        'wrong payment' => $request['payment_id'] = 999999,
        'wrong customer' => $request['customer_id'] = 999999,
        'wrong reference' => $request['provider_subscription_id'] = 'another-reference',
        'wrong subscription' => $request['subscription_id'] = 999999,
        'wrong evidence' => $request['evidence_event_id'] = 999999,
        'malformed' => $request['coverage_end'] = 'tomorrow',
        'reversed' => $request['coverage_end'] = '2026-09-01T00:00:00+03:00',
        'shortened' => $request['coverage_end'] = '2026-10-29T19:42:26+03:00',
        'unapproved' => $request['coverage_confirmed'] = false,
        'review absent' => $request['review_reference'] = '',
        'unpaid' => $this->payment->update(['paid_at' => null, 'fulfilled_at' => null]),
        'review required' => $this->payment->update(['review_required_at' => now()]),
    };
    expect(fn () => app(\App\Services\Billing\ProviderCoverageDispositions::class)->approve($request, (string) Str::uuid(), 'Fixture must refuse unsafe approval.'))->toThrow(\Exception::class);
    expect(DB::table('provider_coverage_dispositions')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['active', 'callback', 'local only', 'wrong payment', 'wrong customer', 'wrong reference', 'wrong subscription', 'wrong evidence', 'malformed', 'reversed', 'shortened', 'unapproved', 'review absent', 'unpaid', 'review required']);
it('requires both fresh capabilities and an active Admin', function ($fault) {
    $admin = coverageAdmin($this, $fault === 'finance' ? ['admin.reconcile'] : ['admin.finance']);
    if ($fault === 'inactive') {
        $admin->forceFill(['status' => 0, 'admin_capabilities' => AdminAccess::CAPABILITIES])->save();
    }
    expect(fn () => approveCoverage($this))->toThrow(\Exception::class);
    expect(DB::table('provider_coverage_dispositions')->count())->toBe(0);
})->with(['finance', 'reconcile', 'inactive']);
it('refuses changed replay and invalidates an approval after source evidence changes', function () {
    coverageAdmin($this);
    $service = app(\App\Services\Billing\ProviderCoverageDispositions::class);
    $id = (string) Str::uuid();
    $request = coverageRequest($this);
    $service->approve($request, $id, 'Merchant confirmed the entire evidenced term.');
    expect(fn () => $service->approve($request, $id, 'Changed operation reason is forbidden.'))->toThrow(\Exception::class);
    $this->payment->update(['amount' => 999]);
    expect($service->approvedFor($this->payment->fresh()))->toBeNull();
    expect(app(ProviderObligationInventory::class)->inspect('production', ['customer_service_subscriptions' => [['id' => $this->subscription->id]]])['blockers'])->not->toBeEmpty();
});
it('does not trust a tampered approved coverage record', function () {
    coverageAdmin($this);
    approveCoverage($this);
    DB::table('provider_coverage_dispositions')->update(['coverage_end' => '2027-11-29T16:42:15.998+00:00']);
    expect(app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($this->payment->fresh()))->toBeNull();
});

function fakeHistoryFixture($test): array
{
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $test->payment->customer_id,
        'provider' => 'fake', 'payment_method' => 'fake', 'purpose_type' => 'service_plan', 'purpose_id' => $test->payment->purchasable_id,
        'status' => 'paid', 'paid_at' => now(), 'fulfilled_at' => now(), 'is_recurring' => 1, 'recurring_strategy' => 'manual_renewal',
        'base_amount_iqd' => 12000, 'gross_amount_iqd' => 12000, 'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid(),
        'provider_payment_id' => 'FAKE-1234567890123456', 'provider_transaction_id' => 'FAKE-TX-12345678901234',
        'response_payload' => json_encode(['provider' => 'fake', 'result' => 'paid', 'mode' => 'instant_fake'])]);
    $order = \App\Models\CreditOrder::create(['customer_id' => $test->payment->customer_id, 'payment_intent_id' => $intent,
        'service_plan_id' => $test->payment->purchasable_id, 'source_type' => 'service_plan', 'order_type' => 'subscription',
        'status' => 'paid', 'provider' => 'fake', 'payment_method' => 'fake', 'credits_amount' => 1000, 'base_amount_iqd' => 12000]);
    $parent = DB::table('payment_transactions')->insertGetId(['payment_intent_id' => $intent, 'provider' => 'fake', 'transaction_type' => 'initiate', 'status' => 'paid']);
    DB::table('payment_transactions')->insert(['payment_intent_id' => $intent, 'parent_transaction_id' => $parent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'paid']);
    DB::table('credit_ledgers')->insert(['customer_id' => $test->payment->customer_id, 'wallet_type' => 'app', 'type' => 'grant',
        'related_type' => \App\Models\PaymentIntent::class, 'related_id' => $intent, 'direction' => 'credit', 'amount' => 1, 'credits_delta' => 1, 'balance_after' => 1]);

    return [$intent, $order];
}
it('preserves the corroborated fake intent order transactions and ledger without a remote obligation', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    [$intent, $order] = fakeHistoryFixture($this);
    approveCoverage($this);
    $before = coverageSnapshot();
    $transactions = DB::table('payment_transactions')->orderBy('id')->get()->toJson();
    $inventory = (new \App\Services\Billing\CutoverInventoryReader(DB::connection()))->inspect();
    expect($inventory['legacy_intents']['unresolved_or_scheduled'])->toBe(0)
        ->and($inventory['unlinked_paid_plan_orders']['rows'])->toBe(0)
        ->and($inventory['retained_financial_legacy']['order_ids'])->toBe([$order->id]);
    $obligations = app(ProviderObligationInventory::class)->inspect('production', ['customer_service_subscriptions' => [['id' => $this->subscription->id]]]);
    expect($obligations['summary']['financial_legacy_preserved'])->toBe(1)->and($obligations['blockers'])->toBe([]);
    executeCoverageCutover($this, $admin);
    foreach (['credit_wallets', 'credit_ledgers', 'credit_orders', 'payment_intents', 'subscription_credit_allocations'] as $table) {
        expect(coverageSnapshot()[$table])->toBe($before[$table], $table);
    }
    expect(DB::table('payment_transactions')->orderBy('id')->get()->toJson())->toBe($transactions)
        ->and($order->fresh()->payment_intent_id)->toBe($intent)
        ->and(\App\Models\CreditOrder::currentBillingPeriod()->count())->toBe(0);
});
it('does not infer safe fake history from a recurring flag alone or conflicting remote identity', function ($fault) {
    [$intent] = fakeHistoryFixture($this);
    $patch = match ($fault) {
        'remote schedule' => ['provider_schedule_ref' => 'real-remote'],
        'remote customer' => ['provider_customer_ref' => 'real-customer'],
        'payment method' => ['payment_method' => 'fib'],
        'provider response' => ['response_payload' => '{}'],
        'unfulfilled' => ['fulfilled_at' => null],
    };
    DB::table('payment_intents')->where('id', $intent)->update($patch);
    $report = app(ProviderObligationInventory::class)->inspect('production', []);
    expect($report['summary']['financial_legacy_preserved'])->toBe(0)
        ->and(collect($report['items'])->firstWhere('table', 'payment_intents')['classification'])->toBe('unresolved_remote_obligation');
})->with(['remote schedule', 'remote customer', 'payment method', 'provider response', 'unfulfilled']);
it('keeps expired DRAFT and non_cancelable objects blocked without manufacturing cancellation', function ($nonCancelable) {
    $this->payment->update(['status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
        'provider_subscription_status' => 'DRAFT', 'paid_at' => null, 'fulfilled_at' => null,
        'status_response' => ['id' => 'fixture-subscription', 'status' => 'DRAFT'],
        'meta' => ['provider_cancellation' => ['result' => $nonCancelable ? 'non_cancelable' : 'unknown', 'provider_status' => 'DRAFT'],
            'validUntil' => '2026-09-01T00:00:00Z']]);
    $this->event->update(['payload' => $this->payment->status_response]);
    $report = app(ProviderObligationInventory::class)->inspect('production', []);
    expect(collect($report['items'])->firstWhere('payment_id', $this->payment->id)['classification'])->toBe('unresolved_remote_obligation');
    Http::assertNothingSent();
})->with([false, true]);
it('keeps a no-disposition manifest blocked and dry run read only', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    CutoverIdentityFixture::install('production');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate(['time' => time()]);
    $before = coverageSnapshot();
    $service = app(PaymentDomainCutover::class);
    $review = $service->review('production', $admin->id);
    expect($review['blockers'])->not->toBeEmpty()->and(coverageSnapshot())->toBe($before);
    expect(fn () => $service->execute('production', $review['review_hash'], 'Must refuse unresolved retained coverage.', true, $admin->id, true, true))->toThrow(\App\Services\Billing\PaymentHistoryResetRefused::class);
    expect(coverageSnapshot())->toBe($before);
});
it('rolls back cutover if the retained coverage fingerprint changes inside the transaction', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    approveCoverage($this);
    CutoverIdentityFixture::install('production');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate(['time' => time()]);
    $service = app(PaymentDomainCutover::class);
    $review = $service->review('production', $admin->id);
    $before = coverageSnapshot();
    $injected = false;
    DB::listen(function ($query) use (&$injected) {
        if (! $injected && str_starts_with($query->sql, 'delete from "payments"')) {
            $injected = true;
            DB::table('provider_coverage_dispositions')->update(['coverage_end' => '2027-11-29T16:42:15.998+00:00']);
        }
    });
    expect(fn () => $service->execute('production', $review['review_hash'], 'Must roll back changed preservation evidence.', true, $admin->id, true, true))->toThrow(\App\Services\Billing\PaymentHistoryResetRefused::class);
    expect($injected)->toBeTrue()->and(coverageSnapshot())->toBe($before)
        ->and(DB::table('provider_coverage_dispositions')->value('status'))->toBe('approved');
});
it('exposes only bounded translated read-only Admin evidence and respects evidence capabilities', function ($locale) {
    $admin = coverageAdmin($this);
    approveCoverage($this);
    app()->setLocale($locale);
    $workspace = app(\App\Support\Admin\AdminBillingWorkspace::class);
    $evidence = $workspace->evidence($this->subscription);
    expect($evidence['provider_coverage']['original_payment_id'])->toBe($this->payment->id)
        ->and($evidence['provider_coverage'])->not->toHaveKeys(['snapshot', 'reason', 'provider_subscription_id', 'source_hash']);
    $html = \Illuminate\Support\Facades\Blade::render('<x-admin-billing-evidence :evidence="$evidence" />', compact('evidence'));
    expect($html)->toContain(__('admin_billing.legacy_provider_coverage'), __('admin_billing.approved_coverage'), '<bdi>')
        ->not->toContain('wire:click', 'MERCHANT-FIXTURE-REVIEW', 'fixture-subscription');
    $admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    expect($workspace->evidence($this->subscription))->toBe(['restricted' => true]);
})->with(['en', 'ar', 'ku']);
it('supports explicit CLI dry run execute and immutable replay', function () {
    $admin = coverageAdmin($this);
    $options = ['payment' => $this->payment->id, '--customer' => $this->payment->customer_id,
        '--provider-subscription' => $this->payment->fib_subscription_id, '--subscription-kind' => 'service', '--subscription' => $this->subscription->id,
        '--evidence-event' => $this->event->id, '--coverage-start' => '2026-09-29T19:42:15.998+03:00', '--coverage-end' => '2026-11-29T19:42:15.998+03:00',
        '--coverage-confirmed' => true, '--review-reference' => 'MERCHANT-FIXTURE-REVIEW', '--operation' => (string) Str::uuid(),
        '--admin' => $admin->id, '--reason' => 'Merchant confirmed the entire evidenced term.'];
    $before = coverageSnapshot();
    $this->artisan('billing:disposition-provider-coverage', $options)->assertSuccessful();
    expect(coverageSnapshot())->toBe($before);
    $this->artisan('billing:disposition-provider-coverage', $options + ['--execute' => true])->assertSuccessful();
    $this->artisan('billing:disposition-provider-coverage', $options + ['--execute' => true])->assertSuccessful();
    expect(DB::table('provider_coverage_dispositions')->count())->toBe(1)->and(DB::table('admin_audit_events')->count())->toBe(1);
});

it('preserves existing allocation history without authorizing another recurring allocation', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    DB::table('subscription_credit_allocations')->insert(['customer_id' => $this->payment->customer_id,
        'subscription_id' => $this->subscription->id, 'payment_id' => null, 'cycle_key' => 'legacy-retained-fixture',
        'allocation_type' => 'provider_collection', 'cycle_started_at' => now()->subDay(), 'paid_through' => now()->addMonth(), 'status' => 'applied']);
    approveCoverage($this);
    $before = coverageSnapshot();
    executeCoverageCutover($this, $admin);
    expect(coverageSnapshot()['subscription_credit_allocations'])->toBe($before['subscription_credit_allocations']);
    $policy = app(\App\Services\Billing\SubscriptionCyclePolicy::class);
    expect($policy->calendarAllocation($this->subscription->fresh(), now()->addMonth()))->toBeNull();
});
it('does not detach allocation evidence to make an approved coverage manifest executable', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    DB::table('subscription_credit_allocations')->insert(['customer_id' => $this->payment->customer_id,
        'subscription_id' => $this->subscription->id, 'payment_id' => $this->payment->id, 'cycle_key' => 'immutable-fixture',
        'allocation_type' => 'provider_collection', 'cycle_started_at' => now()->subDay(), 'status' => 'applied']);
    approveCoverage($this);
    CutoverIdentityFixture::install('production');
    $review = app(PaymentDomainCutover::class)->review('production', $admin->id);
    expect(implode(' ', $review['blockers']))->toContain('retained evidence cannot be detached');
});
it('supports retained storage coverage through the same bounded authority', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    $this->subscription->delete();
    $plan = \App\Models\StoragePlan::create(['code' => 'coverage-fixture', 'name' => 'Coverage fixture', 'quota_mb' => 2048, 'price_iqd' => 12000, 'price_usd' => 8, 'is_active' => true]);
    $this->payment->update(['purchasable_type' => \App\Models\StoragePlan::class, 'purchasable_id' => $plan->id]);
    $sub = \App\Models\CustomerStorageSubscription::create(['customer_id' => $this->payment->customer_id,
        'payment_id' => $this->payment->id, 'storage_plan_id' => $plan->id, 'source' => 'fib',
        'provider_ref' => $this->payment->fib_subscription_id, 'status' => 'active', 'auto_renew' => false,
        'starts_at' => '2026-09-29', 'ends_at' => '2026-10-29']);
    $request = array_replace(coverageRequest($this), ['subscription_kind' => 'storage', 'subscription_id' => $sub->id]);
    app(\App\Services\Billing\ProviderCoverageDispositions::class)->approve($request, (string) Str::uuid(), 'Merchant confirmed the entire storage term.');
    executeCoverageCutover($this, $admin);
    $resolver = app(\App\Services\Billing\CustomerBillingStateService::class);
    $customer = Customer::findOrFail($sub->customer_id);
    expect($resolver->resolveActiveStorageSubscription($customer)?->id)->toBe($sub->id)
        ->and($resolver->storageQuotaState($customer)['cancelable'])->toBeFalse()
        ->and(app(\App\Services\Billing\SubscriptionCyclePolicy::class)->isCurrent($sub->fresh()))->toBeFalse();
    $this->travelTo(\Carbon\Carbon::parse('2026-11-29T19:42:15.998+03:00'));
    expect($resolver->resolveActiveStorageSubscription($customer)?->id)->not->toBe($sub->id);
});
it('does not resurrect retained coverage when replaced or superseded', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    approveCoverage($this);
    executeCoverageCutover($this, $admin);
    $this->subscription->refresh()->update(['status' => 'ended', 'meta' => ['superseded_at' => now()->toIso8601String()]]);
    $customer = Customer::findOrFail($this->subscription->customer_id);
    expect(app(\App\Services\Billing\CustomerBillingStateService::class)->resolveActiveServiceSubscription($customer)?->id)->not->toBe($this->subscription->id);
});
it('keeps the paid plan concurrency limit during retained coverage', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    $plan = ServicePlan::findOrFail($this->payment->purchasable_id);
    $plan->update(['concurrent_jobs_limit' => 7]);
    $concurrency = app(\App\Services\Plans\PlanConcurrencyService::class);
    $concurrency->flushCache();
    approveCoverage($this);
    executeCoverageCutover($this, $admin);
    expect($concurrency->allowedConcurrentJobsForCustomer(Customer::findOrFail($this->payment->customer_id)))->toBe(7);
    $concurrency->flushCache();
});

it('keeps approval identity stable when a database reorders JSON object keys', function () {
    coverageAdmin($this);
    approveCoverage($this);
    $row = \App\Models\ProviderCoverageDisposition::firstOrFail();
    $snapshot = $row->snapshot;
    $snapshot['payment'] = array_reverse($snapshot['payment'], true);
    $row->update(['snapshot' => array_reverse($snapshot, true)]);
    expect(app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($this->payment->fresh())?->id)->toBe($row->id);
});
it('replays completed approval after processing retirement without recreating a Payment', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    $request = coverageRequest($this);
    $operation = (string) Str::uuid();
    $reason = 'Merchant confirmed the entire evidenced term.';
    $service = app(\App\Services\Billing\ProviderCoverageDispositions::class);
    $result = $service->approve($request, $operation, $reason);
    executeCoverageCutover($this, $admin);
    $before = coverageSnapshot();
    expect($service->approve($request, $operation, $reason))->toBe($result)->and(coverageSnapshot())->toBe($before);
});
it('cannot revive retained coverage behind newer subscription history', function () {
    $admin = coverageAdmin($this, AdminAccess::CAPABILITIES);
    approveCoverage($this);
    executeCoverageCutover($this, $admin);
    $newer = $this->subscription->fresh()->replicate();
    $newer->forceFill(['source' => 'system', 'provider_ref' => null, 'status' => 'ended', 'service_plan_id' => ServicePlan::where('is_free', true)->value('id')])->save();
    $customer = Customer::findOrFail($this->subscription->customer_id);
    expect(app(\App\Services\Billing\CustomerBillingStateService::class)->resolveActiveServiceSubscription($customer)?->id)->not->toBe($this->subscription->id);
});
