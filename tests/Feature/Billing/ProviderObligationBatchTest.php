<?php

use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Fib\FibSubscriptionService;
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

beforeEach(function () {
    CutoverIdentityFixture::install('production');
    config(['provider_obligation_review.release_revision' => str_repeat('a', 40)]);
    $this->operator = User::forceCreate(['name' => 'Batch operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->operator, 'admin');
    $this->batch = app(\App\Services\Billing\ProviderObligationBatchReview::class);
    $this->actions = app(\App\Services\Billing\ProviderObligationBatchActions::class);
    $this->operation = (string) Str::uuid();
    $this->reason = 'Reviewed exact private batch and merchant evidence.';
});

function batchDraft($test, string $state = 'DRAFT'): Payment
{
    $payment = $test->payment->replicate();
    $payment->forceFill(['uuid' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'local_reference' => Str::uuid(),
        'fib_subscription_id' => 'batch-'.Str::uuid(), 'status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
        'provider_subscription_status' => $state, 'paid_at' => null, 'fulfilled_at' => null, 'status_response' => null, 'meta' => []])->save();
    $payload = ['id' => $payment->fib_subscription_id, 'status' => $state];
    $payment->update(['status_response' => $payload]);
    app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_status_checked', 'source' => 'fib_subscription_callback',
        'payload' => $payload, 'processed_at' => $payment->last_status_checked_at, 'meta' => ['provider_object_type' => 'subscription']]);

    return $payment->fresh();
}

function batchPacket($test, array $ids, string $action = 'draft_get'): array
{
    $packet = $test->batch->worksheet($test->batch->review('production'));
    foreach ($packet['decisions'] as &$decision) {
        if (in_array($decision['payment_id'], $ids, true) && $decision['action'] === $action) {
            $decision['selected'] = true;
            if ($action === 'coverage_approval') {
                $decision['coverage_confirmed'] = true;
                $decision['review_reference'] = 'MERCHANT-REVIEW-'.$decision['payment_id'];
            }
        }
    }

    return $packet;
}

function batchExecute($test, array $packet): array
{
    $preview = $test->actions->apply($packet, $test->operation, $test->reason);

    return $test->actions->apply($packet, $test->operation, $test->reason, $preview['review_hash'], true);
}

function batchPreservedTables(): array
{
    return collect(['credit_wallets', 'credit_ledgers', 'customer_service_subscriptions', 'customer_storage_subscriptions', 'payments',
        'payment_intents', 'credit_orders', 'subscription_credit_allocations'])->mapWithKeys(fn ($t) => [$t => hash('sha256', DB::table($t)->orderBy('id')->get()->toJson())])->all();
}

it('groups 120 mixed obligations with bounded queries no free rows no HTTP and deterministic manifests', function () {
    for ($i = 0; $i < 120; $i++) {
        batchDraft($this, ['DRAFT', 'ACTIVE', 'TRIAL', 'CANCELLED', 'REJECTED'][$i % 5]);
    }
    DB::enableQueryLog();
    $first = $this->batch->review('production');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThan(22)
        ->and($first)->toBe($this->batch->review('production'))
        ->and($first['review']['counts']['draft_unpaid'])->toBe(24)
        ->and($first['review']['counts']['active_trial'])->toBe(48)
        ->and($first['review']['counts']['confirmed_retired'])->toBe(48)
        ->and($first['review']['counts']['paid_coverage'])->toBe(1)
        ->and(count($first['review']['items']))->toBe(121);
    expect(json_encode($first))->not->toContain('example.test', 'fixture-secret', 'qr_code', 'status_response');
    foreach ($queries as $query) {
        expect(preg_match('/^\s*(insert|update|delete|alter|drop|create|replace)\b/i', $query['query']))->toBe(0);
    }
    Http::assertNothingSent();
});

it('binds database evidence identities source revision and selected decisions', function ($fault) {
    $draft = batchDraft($this);
    $packet = batchPacket($this, [$draft->id]);
    match ($fault) {
        'row changed' => $draft->update(['amount' => 1]),
        'new callback' => app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'callback_received', 'source' => 'fixture']),
        'wrong provider' => $packet['decisions'][1]['provider_subscription_id'] = 'wrong',
        'wrong source identity' => $packet['review']['identity']['configured_schema'] = 'wrong',
        'code revision' => $packet['review']['code']['revision'] = str_repeat('0', 40),
        'classification edited' => $packet['review']['items'][0]['category'] = 'confirmed_retired',
    };
    expect(fn () => batchExecute($this, $packet))->toThrow(\Exception::class);
    expect(DB::table('provider_obligation_reviews')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['row changed', 'new callback', 'wrong provider', 'wrong source identity', 'code revision', 'classification edited']);

it('keeps callback only cancellation and local terminal markers unconfirmed', function ($fault) {
    $draft = batchDraft($this, 'CANCELLED');
    $event = \App\Domain\Payments\Models\PaymentEvent::where('payment_id', $draft->id)->firstOrFail();
    $fault === 'callback' ? $event->update(['event_type' => 'callback_received']) : $event->delete();
    $packet = $this->batch->review('production');
    expect(collect($packet['review']['items'])->firstWhere('payment_id', $draft->id)['category'])->not->toBe('confirmed_retired');
})->with(['callback', 'local']);

it('records bounded GET outcomes without changing financial state and replays without more HTTP', function ($state, $expected) {
    $draft = batchDraft($this);
    $packet = batchPacket($this, [$draft->id]);
    $before = batchPreservedTables();
    $mock = $this->mock(FibSubscriptionService::class);
    $mock->shouldNotReceive('cancel');
    $expectation = $mock->shouldReceive('getStatus')->once();
    $state === 'FAILURE' ? $expectation->andThrow(new RuntimeException('PRIVATE_PROVIDER_RESPONSE'))
        : $expectation->andReturn(FibSubscriptionStatusData::fromArray(['id' => $state === 'WRONG' ? 'wrong-id' : $draft->fib_subscription_id,
            'status' => $state === 'WRONG' ? 'CANCELLED' : $state]));
    $result = batchExecute($this, $packet);
    expect($result['observations'][0]['outcome'])->toBe($expected)->and(batchPreservedTables())->toBe($before)
        ->and(json_encode($result))->not->toContain('PRIVATE_PROVIDER_RESPONSE');
    expect($this->actions->apply($packet, $this->operation, $this->reason, $result['review_hash'], true))->toBe($result);
    expect(DB::table('provider_obligation_reviews')->count())->toBe(1)
        ->and(DB::table('admin_operations')->where('action', \App\Services\Billing\ProviderObligationBatchActions::ACTION)->count())->toBe(1);
    $review = $this->batch->review('production');
    expect(collect($review['review']['items'])->firstWhere('payment_id', $draft->id)['category'] === 'confirmed_retired')->toBe($expected === 'confirmed_retired');
    $cutover = app(ProviderObligationInventory::class)->inspect('production', []);
    expect(collect($cutover['items'])->firstWhere('payment_id', $draft->id)['classification'] === 'retired_confirmed_cancelled')->toBe($expected === 'confirmed_retired');
    $inventory = (new \App\Services\Billing\CutoverInventoryReader(DB::connection()))->inspect();
    expect(in_array($draft->id, $inventory['confirmed_retired_provider_ids'], true))->toBe($expected === 'confirmed_retired');
})->with([['CANCELLED', 'confirmed_retired'], ['REJECTED', 'confirmed_retired'], ['DRAFT', 'unresolved'],
    ['ACTIVE', 'unresolved'], ['TRIAL', 'unresolved'], ['FAILURE', 'unresolved'], ['WRONG', 'unresolved']]);

it('uses only authenticated subscription GET plus OAuth token exchange with real mocked transport', function () {
    $draft = batchDraft($this);
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true, 'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client', 'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 1, 'fib.http.retry_sleep_ms' => 1]);
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$draft->fib_subscription_id => Http::response(['id' => $draft->fib_subscription_id, 'status' => 'CANCELLED'])]);
    batchExecute($this, batchPacket($this, [$draft->id]));
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer fixture-token'));
    Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/openid-connect/token'));
});

it('enforces limits before any provider request and applies serial pacing', function () {
    $ids = [batchDraft($this)->id, batchDraft($this)->id, batchDraft($this)->id];
    $packet = batchPacket($this, $ids);
    config(['provider_obligation_review.max_gets' => 2]);
    expect(fn () => batchExecute($this, $packet))->toThrow(\Exception::class);
    Http::assertNothingSent();
    config(['provider_obligation_review.max_gets' => 25]);
    $paced = new class extends \App\Services\Billing\ProviderObligationBatchActions
    {
        public array $waits = [];

        protected function pause(int $milliseconds): void
        {
            $this->waits[] = $milliseconds;
        }
    };
    $this->actions = $paced;
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->times(3)->andReturnUsing(fn ($p) => FibSubscriptionStatusData::fromArray(['id' => $p->fib_subscription_id, 'status' => 'DRAFT']));
    batchExecute($this, $packet);
    expect($paced->waits)->toHaveCount(2);
    foreach ($paced->waits as $wait) {
        expect($wait)->toBeGreaterThan(0)->toBeLessThanOrEqual(1000);
    }
});

it('approves individually reviewed paid terms with one parent and refuses an invalid item atomically', function ($invalid) {
    $owner = Customer::create(['username' => 'second_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture']);
    $other = $this->payment->replicate();
    $other->forceFill(['customer_id' => $owner->id, 'uuid' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'local_reference' => Str::uuid(), 'fib_subscription_id' => 'second-sub'])->save();
    $payload = array_replace($this->payload, ['id' => 'second-sub', 'activeUntil' => '2026-12-29T19:42:15.998+03:00']);
    $other->update(['status_response' => $payload]);
    $sub = $this->subscription->replicate();
    $sub->forceFill(['customer_id' => $owner->id, 'payment_id' => $other->id, 'provider_ref' => 'second-sub'])->save();
    app(PaymentEventRecorder::class)->record($other, ['event_type' => 'provider_status_ignored', 'source' => 'fib_subscription_callback',
        'payload' => $payload, 'processed_at' => $other->last_status_checked_at, 'meta' => ['provider_object_type' => 'subscription']]);
    $packet = batchPacket($this, [$this->payment->id, $other->id], 'coverage_approval');
    if ($invalid === 'invalid') {
        $packet['decisions'][1]['coverage_confirmed'] = false;
    }
    if ($invalid === 'late failure') {
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with($query->sql, 'insert into "provider_coverage_dispositions"') && ++$inserts === 2) {
                throw new RuntimeException('Injected second-record failure after first insert.');
            }
        });
    }
    $before = batchPreservedTables();
    if ($invalid) {
        expect(fn () => batchExecute($this, $packet))->toThrow(\Exception::class);
        expect(DB::table('provider_coverage_dispositions')->count())->toBe(0);
    } else {
        $result = batchExecute($this, $packet);
        expect($result['coverage'])->toHaveCount(2)
            ->and(DB::table('provider_coverage_dispositions')->pluck('coverage_end')->unique()->count())->toBe(2)
            ->and(app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($this->payment->fresh()))->not->toBeNull()
            ->and(app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($other->fresh()))->not->toBeNull();
        $this->actions->apply($packet, $this->operation, $this->reason, $result['review_hash'], true);
        expect(DB::table('provider_coverage_dispositions')->count())->toBe(2);
        $next = $this->batch->worksheet($this->batch->review('production'));
        expect($next['decisions'])->toBe([]);
    }
    expect(batchPreservedTables())->toBe($before);
    Http::assertNothingSent();
})->with([false, 'invalid', 'late failure']);

function batchMerchantPacket($test, Payment $draft): array
{
    $operation = $test->operation;
    $test->operation = (string) Str::uuid();
    $mock = Mockery::mock(FibSubscriptionService::class);
    app()->instance(FibSubscriptionService::class, $mock);
    $mock->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'DRAFT']));
    batchExecute($test, batchPacket($test, [$draft->id]));
    $test->operation = $operation;
    $packet = batchPacket($test, []);

    return $test->batch->importMerchant($packet, ['manifest_hash' => $packet['review']['manifest_hash'], 'items' => [[
        'payment_id' => $draft->id, 'provider_subscription_id' => $draft->fib_subscription_id,
        'disposition' => 'PERMANENTLY_NON_ACTIVATABLE', 'review_reference' => 'MERCHANT-CASE-FIXTURE',
        'evidence_sha256' => hash('sha256', 'privately retained merchant reply fixture'), 'observed_at' => now()->toIso8601String(),
        'attested' => true, 'no_collection' => true, 'irreversibly_non_activatable' => true,
    ]]]);
}

it('imports exact attested merchant decisions without provider calls and retains the external reference', function () {
    $draft = batchDraft($this);
    $before = batchPreservedTables();
    $packet = batchMerchantPacket($this, $draft);
    expect(DB::table('provider_obligation_reviews')->count())->toBe(1);
    $export = $this->batch->merchantExport($packet);
    expect(json_encode($export))->not->toContain('customer_id', 'example.test', 'monetaryValue', 'status_response');
    $result = batchExecute($this, $packet);
    expect($result['observations'][0]['outcome'])->toBe('confirmed_retired')
        ->and(batchPreservedTables())->toBe($before);
    $record = DB::table('provider_obligation_reviews')->latest('id')->first();
    expect(json_decode($record->evidence, true)['review_reference'])->toBe('MERCHANT-CASE-FIXTURE');
    expect(collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])
        ->firstWhere('payment_id', $draft->id)['classification'])->toBe('retired_confirmed_cancelled');
    Http::assertNothingSent();
});

it('refuses incomplete ambiguous or stale merchant attestations', function ($fault) {
    $draft = batchDraft($this);
    $packet = batchMerchantPacket($this, $draft);
    foreach ($packet['decisions'] as &$decision) {
        if (! $decision['selected']) {
            continue;
        }
        match ($fault) {
            'no attestation' => $decision['attested'] = false,
            'collection uncertain' => $decision['no_collection'] = false,
            'activatable' => $decision['irreversibly_non_activatable'] = false,
            'DRAFT' => $decision['disposition'] = 'DRAFT',
            'ACTIVE' => $decision['disposition'] = 'ACTIVE',
            'missing reference' => $decision['review_reference'] = '',
            'past evidence' => $decision['observed_at'] = now()->subDay()->toIso8601String(),
            'future evidence' => $decision['observed_at'] = now()->addDay()->toIso8601String(),
            'raw payload' => $decision['payload'] = ['secret' => 'never accepted'],
        };
    }
    unset($decision);
    expect(fn () => batchExecute($this, $packet))->toThrow(\Exception::class);
    expect(DB::table('provider_obligation_reviews')->count())->toBe(1);
    Http::assertNothingSent();
})->with(['no attestation', 'collection uncertain', 'activatable', 'DRAFT', 'ACTIVE', 'missing reference', 'past evidence', 'future evidence', 'raw payload']);

it('does not trust a returned arbitrary list wrong object or unrelated review', function ($fault) {
    $draft = batchDraft($this);
    $packet = batchPacket($this, []);
    $returned = ['manifest_hash' => $packet['review']['manifest_hash'], 'items' => [[
        'payment_id' => $draft->id, 'provider_subscription_id' => $draft->fib_subscription_id, 'disposition' => 'CANCELLED',
    ]]];
    match ($fault) {
        'wrong hash' => $returned['manifest_hash'] = str_repeat('0', 64),
        'wrong object' => $returned['items'][0]['provider_subscription_id'] = 'another',
        'duplicate' => $returned['items'][] = $returned['items'][0],
        'PII' => $returned['items'][0]['email'] = 'private@example.test',
    };
    expect(fn () => $this->batch->importMerchant($packet, $returned))->toThrow(\Exception::class);
    Http::assertNothingSent();
})->with(['wrong hash', 'wrong object', 'duplicate', 'PII']);

it('invalidates retired proof when its financial event or audit basis changes', function ($fault) {
    $draft = batchDraft($this);
    batchExecute($this, batchMerchantPacket($this, $draft));
    match ($fault) {
        'payment' => $draft->update(['amount' => 1]),
        'callback' => app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'callback_received', 'source' => 'fixture']),
        'review tamper' => DB::table('provider_obligation_reviews')->update(['evidence' => '{}']),
        'incomplete audit' => DB::table('admin_operations')->where('id', $this->operation)->update(['status' => 'pending']),
    };
    expect(collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id)['category'])->toBe('conflict');
    expect(collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])
        ->firstWhere('payment_id', $draft->id)['classification'])->not->toBe('retired_confirmed_cancelled');
})->with(['payment', 'callback', 'review tamper', 'incomplete audit']);

it('requires fresh capabilities even for completed operation replay and rejects changed UUID intent', function () {
    $draft = batchDraft($this);
    $packet = batchMerchantPacket($this, $draft);
    $result = batchExecute($this, $packet);
    expect(fn () => $this->actions->apply($packet, $this->operation, 'Different reason for same operation.', $result['review_hash'], true))->toThrow(\Exception::class);
    $this->operator->forceFill(['admin_capabilities' => ['admin.read', 'admin.finance']])->save();
    expect(fn () => $this->actions->apply($packet, $this->operation, $this->reason, $result['review_hash'], true))->toThrow(\Exception::class);
    expect(DB::table('provider_obligation_reviews')->count())->toBe(2);
});

it('does not approve unbound draft retirement over historical paid evidence or malformed GET dates', function ($fault) {
    $draft = batchDraft($this, 'CANCELLED');
    if ($fault === 'past paid event') {
        app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'historical_evidence', 'source' => 'fixture', 'after_status' => 'paid']);
    } else {
        $payload = ['id' => $draft->fib_subscription_id, 'status' => 'CANCELLED', 'activeUntil' => 'not-a-date'];
        $draft->update(['status_response' => $payload]);
        \App\Domain\Payments\Models\PaymentEvent::where('payment_id', $draft->id)->update(['payload' => json_encode($payload)]);
    }
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['category'])->not->toBe('confirmed_retired')->and($item['proposed_actions'])->toBe(['remote_retire']);
})->with(['past paid event', 'malformed date']);

it('bounds shared transport retries and hard caps despite permissive configuration', function ($fault) {
    $ids = [batchDraft($this)->id];
    if ($fault === 'hard GET cap') {
        for ($i = 1; $i < 26; $i++) {
            $ids[] = batchDraft($this)->id;
        }
        config(['provider_obligation_review.max_gets' => 1000]);
    } else {
        config(['fib.http.retries' => 1000]);
    }
    expect(fn () => batchExecute($this, batchPacket($this, $ids)))->toThrow(\Exception::class);
    Http::assertNothingSent();
})->with(['hard GET cap', 'transport retries']);

it('excludes ordinary free subscriptions and preserves fake manual intent and order history', function () {
    CustomerServiceSubscription::create(['customer_id' => $this->payment->customer_id,
        'service_plan_id' => ServicePlan::where('code', 'free')->value('id'), 'source' => 'free',
        'status' => 'active', 'starts_at' => now(), 'auto_renew' => false]);
    $intent = DB::table('payment_intents')->insertGetId(['id' => 15, 'uuid' => Str::uuid(), 'customer_id' => $this->payment->customer_id,
        'provider' => 'fake', 'payment_method' => 'fake', 'purpose_type' => 'service_plan', 'purpose_id' => $this->payment->purchasable_id,
        'status' => 'paid', 'paid_at' => now(), 'fulfilled_at' => now(), 'is_recurring' => 1, 'recurring_strategy' => 'manual_renewal',
        'base_amount_iqd' => 12000, 'gross_amount_iqd' => 12000, 'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid(),
        'provider_payment_id' => 'FAKE-1234567890123456', 'provider_transaction_id' => 'FAKE-TX-12345678901234',
        'response_payload' => json_encode(['provider' => 'fake', 'result' => 'paid', 'mode' => 'instant_fake'])]);
    $order = \App\Models\CreditOrder::create(['customer_id' => $this->payment->customer_id, 'payment_intent_id' => $intent,
        'service_plan_id' => $this->payment->purchasable_id, 'source_type' => 'service_plan', 'order_type' => 'subscription',
        'status' => 'paid', 'provider' => 'fake', 'payment_method' => 'fake', 'credits_amount' => 1000, 'base_amount_iqd' => 12000]);
    $before = batchPreservedTables();
    $items = $this->batch->review('production')['review']['items'];
    expect($items)->toHaveCount(2)->and(collect($items)->firstWhere('intent_id', 15)['category'])->toBe('retained_fake_history');
    expect($this->batch->worksheet(['review' => ['items' => [collect($items)->firstWhere('intent_id', 15)]], 'decisions' => []])['decisions'])->toBe([]);
    $inventory = (new \App\Services\Billing\CutoverInventoryReader(DB::connection()))->inspect();
    expect($inventory['retained_financial_legacy']['order_ids'])->toBe([$order->id])
        ->and($inventory['unlinked_paid_plan_orders']['rows'])->toBe(0)->and(batchPreservedTables())->toBe($before);
});

it('writes separate private source and worksheet files and refuses external paths', function () {
    $files = app(\App\Services\Billing\ProviderReviewFiles::class);
    $packet = $this->batch->review('production');
    $source = $files->write($packet, 'source');
    $worksheet = $files->write($this->batch->worksheet($packet), 'worksheet');
    try {
        expect(dirname($source))->toBe(realpath(storage_path('app/private/billing')))
            ->and($source)->not->toBe($worksheet)->and($files->read($source))->toBe($packet);
        expect(fn () => $files->read(base_path('composer.json')))->toThrow(\Exception::class);
        expect(fn () => $files->read('https://example.test/review.json'))->toThrow(\Exception::class);
        $this->artisan('billing:provider-obligations-apply', ['--manifest' => $worksheet, '--merchant-import' => $source,
            '--admin' => $this->operator->id, '--operation' => $this->operation, '--reason' => $this->reason, '--execute' => true])->assertExitCode(1);
        expect(DB::table('provider_obligation_reviews')->count())->toBe(0);
    } finally {
        foreach ([$source, $worksheet] as $path) {
            chmod($path, 0600);
            unlink($path);
        }
    }
    Http::assertNothingSent();
});

it('keeps real cutover blocked until exact batch dispositions pass and retains their audit evidence', function () {
    $draft = batchDraft($this);
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->maintenanceMode()->activate(['time' => time()]);
    $cutover = app(PaymentDomainCutover::class);
    expect($cutover->review('production', $this->operator->id)['blockers'])->not->toBe([]);
    $packet = batchMerchantPacket($this, $draft);
    foreach ($packet['decisions'] as &$decision) {
        if ($decision['action'] === 'coverage_approval') {
            $decision['selected'] = true;
            $decision['coverage_confirmed'] = true;
            $decision['review_reference'] = 'MERCHANT-INDIVIDUAL-PAID-TERM';
        }
    }
    unset($decision);
    batchExecute($this, $packet);
    $review = $cutover->review('production', $this->operator->id);
    expect($review['blockers'])->toBe([]);
    // Isolated SQLite fixture only, never the local reproduction or production database.
    $cutover->execute('production', $review['review_hash'], 'Isolated mixed batch cutover fixture.', true, $this->operator->id, true, true);
    expect(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('provider_obligation_reviews')->count())->toBe(2)
        ->and(DB::table('provider_coverage_dispositions')->value('status'))->toBe('retained')
        ->and(DB::table('admin_operations')->where('id', $this->operation)->value('status'))->toBe('completed');
    Http::assertNothingSent();
});

it('retains prior batch collection evidence when later local state changes', function () {
    $draft = batchDraft($this);
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(FibSubscriptionStatusData::fromArray([
        'id' => $draft->fib_subscription_id, 'status' => 'ACTIVE', 'lastPaymentAt' => now()->subHour()->toIso8601String(),
        'activeUntil' => now()->addMonth()->toIso8601String(),
    ]));
    batchExecute($this, batchPacket($this, [$draft->id]));
    $draft->update(['amount' => 1]); // Invalidates the last review basis, not its recorded collection history.
    $snapshot = \App\Services\Billing\ProviderReviewSnapshot::capture();
    expect($snapshot->unpaidUnbound($draft->fresh()))->toBeFalse();
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['category'])->not->toBe('confirmed_retired')->and($item['proposed_actions'])->toBe(['remote_retire']);
});

it('can refresh stale DRAFT observations but cannot attest them away', function () {
    $draft = batchDraft($this);
    app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'callback_received', 'source' => 'fixture']);
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['category'])->toBe('conflict')->and($item['proposed_actions'])->toBe(['draft_get', 'remote_retire']);
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(FibSubscriptionStatusData::fromArray([
        'id' => $draft->fib_subscription_id, 'status' => 'CANCELLED',
    ]));
    batchExecute($this, batchPacket($this, [$draft->id]));
    expect(collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id)['category'])->toBe('confirmed_retired');
});

it('retains all recognized historical nested collection aliases', function ($key) {
    $draft = batchDraft($this, 'CANCELLED');
    $payload = [];
    data_set($payload, $key, now()->subMonth()->toIso8601String());
    app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'historical_evidence', 'source' => 'fixture', 'payload' => $payload]);
    expect(\App\Services\Billing\ProviderReviewSnapshot::capture()->unpaidUnbound($draft))->toBeFalse();
})->with(['payment.lastPaidAt', 'latestPayment.lastPaidAt', 'subscription.lastPaidAt']);

function remotePacket($test, array $ids): array
{
    $packet = $test->batch->selectRemote($test->batch->review('production'), 25, true);
    $packet['decisions'] = array_values(array_filter($packet['decisions'], fn ($d) => in_array($d['payment_id'], $ids, true)));

    return $packet;
}

it('selects the next bounded remote group without manually editing identities', function () {
    for ($i = 0; $i < 27; $i++) {
        batchDraft($this);
    }
    $review = $this->batch->review('production');
    $packet = $this->batch->selectRemote($review, 25);
    expect($packet['decisions'])->toHaveCount(25)->and($packet)->toBe($this->batch->selectRemote($review, 25));
    expect(fn () => $this->batch->selectRemote($review, 26))->toThrow(\Exception::class);
    $this->actions->apply($packet, $this->operation, 'No Service Available');
    expect(DB::table('provider_obligation_reviews')->count())->toBe(0);
    Http::assertNothingSent();
});

it('remotely verifies closed and DRAFT states without POST and never mutates financial rows', function ($state, $expected) {
    $draft = batchDraft($this);
    $packet = remotePacket($this, [$draft->id]);
    $before = batchPreservedTables();
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->once()->andReturn(FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => $state]));
    $mock->shouldNotReceive('cancel');
    $result = batchExecute($this, $packet);
    expect($result['observations'][0]['outcome'])->toBe($expected)->and(batchPreservedTables())->toBe($before);
    expect($this->actions->apply($packet, $this->operation, $this->reason, $result['review_hash'], true))->toBe($result);
    $next = $this->batch->selectRemote($this->batch->review('production'), 25);
    expect(array_column($next['decisions'], 'payment_id'))->not->toContain($draft->id);
    expect(collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])
        ->firstWhere('payment_id', $draft->id)['classification'] === 'retired_confirmed_cancelled')->toBe($expected === 'confirmed_retired');
})->with([['CANCELLED', 'confirmed_retired'], ['REJECTED', 'confirmed_retired'], ['DRAFT', 'unresolved']]);

it('commits the POST fence after validated GET then confirms ACTIVE or TRIAL cancellation', function ($state) {
    $draft = batchDraft($this, $state);
    $packet = remotePacket($this, [$draft->id]);
    $before = batchPreservedTables();
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->twice()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => $state]),
        FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'CANCELLED']));
    $transactionLevel = DB::transactionLevel();
    $mock->shouldReceive('cancel')->once()->andReturnUsing(function () use ($transactionLevel) {
        $row = DB::table('provider_obligation_reviews')->first();
        expect(json_decode($row->evidence, true)['post_started'])->toBeTrue()->and(DB::transactionLevel())->toBe($transactionLevel);
        expect(DB::table('admin_operations')->where('action', \App\Services\Billing\ProviderRemoteRetirementBatch::PREPARE)->value('status'))->toBe('completed');
    });
    expect(batchExecute($this, $packet)['observations'][0]['outcome'])->toBe('confirmed_retired')
        ->and(batchPreservedTables())->toBe($before);
})->with(['ACTIVE', 'TRIAL']);

it('keeps ambiguous POST blocked and a new reviewed attempt GET-only', function () {
    $draft = batchDraft($this, 'ACTIVE');
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->times(3)->andReturn(FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'ACTIVE']));
    $mock->shouldReceive('cancel')->once()->andThrow(new RuntimeException('PRIVATE AMBIGUOUS RESPONSE'));
    $first = batchExecute($this, remotePacket($this, [$draft->id]));
    expect($first['observations'][0]['outcome'])->toBe('unresolved')->and(json_encode($first))->not->toContain('PRIVATE');
    $this->operation = (string) Str::uuid();
    $second = batchExecute($this, remotePacket($this, [$draft->id]));
    expect($second['observations'][0]['outcome'])->toBe('unresolved');
});

it('resumes persistence failure after POST with GET and never repeats POST', function () {
    $draft = batchDraft($this, 'ACTIVE');
    $packet = remotePacket($this, [$draft->id]);
    $mock = $this->partialMock(FibSubscriptionService::class);
    $active = FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'ACTIVE']);
    $closed = FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'CANCELLED']);
    $mock->shouldReceive('getStatus')->times(4)->andReturn($active, $closed, $closed, $closed);
    $mock->shouldReceive('cancel')->once();
    $fail = true;
    DB::listen(function ($query) use (&$fail) {
        if ($fail && str_starts_with($query->sql, 'insert into "payment_events"') && in_array('provider_retirement_observed', $query->bindings, true)) {
            $fail = false;
            throw new RuntimeException('Injected result persistence failure.');
        }
    });
    $preview = $this->actions->apply($packet, $this->operation, $this->reason);
    expect(fn () => $this->actions->apply($packet, $this->operation, $this->reason, $preview['review_hash'], true))->toThrow(RuntimeException::class);
    expect(json_decode(DB::table('provider_obligation_reviews')->value('evidence'), true)['post_started'])->toBeTrue();
    $this->travel(11)->minutes();
    $result = $this->actions->apply($packet, $this->operation, $this->reason, $preview['review_hash'], true);
    expect($result['observations'][0]['outcome'])->toBe('confirmed_retired');
});

it('requires exact identity and blocks HTTP for synthetic or ambiguous local objects', function ($fault) {
    $draft = batchDraft($this);
    match ($fault) {
        'missing' => $draft->update(['fib_subscription_id' => null]),
        'synthetic' => $draft->update(['meta' => ['revenue_excluded' => true]]),
        'financial review' => $draft->update(['internal_status' => 'requires_review']),
        'subscription mismatch' => $this->subscription->update(['payment_id' => $draft->id]),
    };
    expect(\App\Services\Billing\ProviderReviewSnapshot::capture()->remoteEligible($draft->fresh()))->toBeFalse();
    expect(remotePacket($this, [$draft->id])['decisions'])->toBe([]);
    Http::assertNothingSent();
})->with(['missing', 'synthetic', 'financial review', 'subscription mismatch']);

it('keeps GET failures and wrong returned identities blocked without POST', function ($fault) {
    $draft = batchDraft($this);
    $packet = remotePacket($this, [$draft->id]);
    $mock = $this->partialMock(FibSubscriptionService::class);
    $expectation = $mock->shouldReceive('getStatus')->once();
    $fault === 'network' ? $expectation->andThrow(new RuntimeException('PRIVATE RESPONSE'))
        : $expectation->andReturn(FibSubscriptionStatusData::fromArray(['id' => 'wrong-object', 'status' => 'ACTIVE']));
    $mock->shouldNotReceive('cancel');
    expect(batchExecute($this, $packet)['observations'][0]['outcome'])->toBe('unresolved');
})->with(['network', 'wrong identity']);

it('preserves pre-POST paid dates for individual coverage approval without rewriting access or refilling', function () {
    $this->payment->update(['provider_subscription_status' => 'ACTIVE']);
    $packet = remotePacket($this, [$this->payment->id]);
    $before = batchPreservedTables();
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->twice()->andReturn(
        FibSubscriptionStatusData::fromArray(array_replace($this->payload, ['status' => 'ACTIVE'])),
        FibSubscriptionStatusData::fromArray(['id' => $this->payment->fib_subscription_id, 'status' => 'CANCELLED']));
    $mock->shouldReceive('cancel')->once();
    expect(batchExecute($this, $packet)['observations'][0]['outcome'])->toBe('renewal_stopped');
    $this->operation = (string) Str::uuid();
    $coverage = batchExecute($this, batchPacket($this, [$this->payment->id], 'coverage_approval'));
    expect($coverage['coverage'])->toHaveCount(1)->and(batchPreservedTables())->toBe($before)
        ->and(app(\App\Services\Billing\ProviderCoverageDispositions::class)->approvedFor($this->payment->fresh()))->not->toBeNull();
});

it('sends no reason parameter and disables cancellation transport retry', function () {
    $draft = batchDraft($this, 'ACTIVE');
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true, 'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client', 'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 3, 'fib.http.retry_sleep_ms' => 1]);
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$draft->fib_subscription_id.'/cancel' => Http::response(['error' => 'ambiguous fixture'], 503),
        '*/subscriptions/'.$draft->fib_subscription_id => Http::response(['id' => $draft->fib_subscription_id, 'status' => 'ACTIVE'])]);
    $this->reason = 'No Service Available';
    expect(batchExecute($this, remotePacket($this, [$draft->id]))['observations'][0]['outcome'])->toBe('unresolved');
    $cancel = Http::recorded(fn ($r) => str_ends_with($r->url(), '/cancel'));
    expect($cancel)->toHaveCount(1)->and($cancel->first()[0]->body())->not->toContain('reason', 'No Service Available');
    expect(Http::recorded(fn ($r) => $r->method() === 'GET'))->toHaveCount(2);
});

it('persists authenticated FIB 404 as unresolved batch evidence independently of sync failure recording', function ($remote, $productionProvenance) {
    $draft = batchDraft($this);
    if ($productionProvenance) {
        batchCreationProvenance($draft, 'p.fib.iq');
    }
    $packet = $remote ? remotePacket($this, [$draft->id]) : batchPacket($this, [$draft->id]);
    $before = batchPreservedTables();
    $this->mock(\App\Services\Payments\PaymentSyncFailureService::class)->shouldNotReceive('capture', 'captureRenewalFailure');
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true, 'fib.profiles.subscription.base_url' => 'https://fib.prod.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client', 'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 1, 'fib.http.retry_sleep_ms' => 1]);
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$draft->fib_subscription_id => Http::response([
            'errors' => [['code' => 'NOT_FOUND', 'title' => 'Not found']], 'private_field' => 'MUST-NOT-BE-PERSISTED',
        ], 404)]);
    $result = batchExecute($this, $packet);
    expect($result['observations'][0]['outcome'])->toBe('unresolved')
        ->and($result['observations'][0]['provider_status'])->toBeNull()
        ->and(batchPreservedTables())->toBe($before);
    $row = DB::table('provider_obligation_reviews')->where('original_payment_id', $draft->id)->sole();
    $evidence = json_decode($row->evidence, true);
    expect($row->outcome)->toBe('unresolved')->and($row->provider_status)->toBeNull()
        ->and($evidence['reason'])->toBe('provider_unavailable')
        ->and($evidence['post_started'] ?? false)->toBeFalse()
        ->and(json_encode($row))->not->toContain('MUST-NOT-BE-PERSISTED', 'fixture-token', 'fixture-secret');
    $event = \App\Domain\Payments\Models\PaymentEvent::findOrFail($row->evidence_event_id);
    expect($event->source)->toBe('admin_provider_batch_review')->and(strlen($event->source))->toBeLessThanOrEqual(40)
        ->and($event->payload['outcome'])->toBe('unresolved')->and($event->payload['provider_status'])->toBeNull();
    expect($this->actions->apply($packet, $this->operation, $this->reason, $result['review_hash'], true))->toBe($result);
    Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer fixture-token'));
    expect(Http::recorded(fn ($r) => $r->method() === 'GET'))->toHaveCount(1);
    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/openid-connect/token'));
    $fresh = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($fresh['provider_provenance']['classification'])->toBe($productionProvenance ? 'confirmed_production' : 'unknown_environment')
        ->and($fresh['provider_status'])->toBeNull();
    expect(collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])->firstWhere('payment_id', $draft->id)['classification'])
        ->toBe('unresolved_remote_obligation');
})->with([[true, true], [true, false], [false, true], [false, false]]);

it('never reuses older paid cancellation proof after pending failed or contradictory remote review', function ($fault) {
    $packet = remotePacket($this, [$this->payment->id]);
    $mock = $this->partialMock(FibSubscriptionService::class);
    if ($fault === 'failure') {
        $mock->shouldReceive('getStatus')->once()->andThrow(new RuntimeException('Provider unavailable'));
        $mock->shouldNotReceive('cancel');
    } else {
        $mock->shouldReceive('getStatus')->times($fault === 'active' ? 2 : 1)->andReturn(
            FibSubscriptionStatusData::fromArray(array_replace($this->payload, ['status' => $fault === 'active' ? 'ACTIVE' : 'CANCELLED'])));
        $fault === 'active' ? $mock->shouldReceive('cancel')->once() : $mock->shouldNotReceive('cancel');
    }
    batchExecute($this, $packet);
    if ($fault === 'pending') {
        DB::table('provider_obligation_reviews')->update(['outcome' => 'pending']);
    }
    expect(app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $this->payment->id);
    expect($item['proposed_actions'])->not->toContain('coverage_approval');
    expect(collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])
        ->firstWhere('payment_id', $this->payment->id)['classification'])->not->toBe('retired_confirmed_cancelled');
})->with(['failure', 'active', 'pending']);

it('offers merchant attestation only after the API cannot resolve an exact unpaid DRAFT', function () {
    $draft = batchDraft($this);
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['proposed_actions'])->not->toContain('merchant_attestation');
    $packet = batchMerchantPacket($this, $draft);
    expect(collect($packet['decisions'])->where('action', 'merchant_attestation')->where('selected', true))->toHaveCount(1);
});

it('rechecks permissions and source after GET before committing any POST', function ($fault) {
    $draft = batchDraft($this, 'ACTIVE');
    $packet = remotePacket($this, [$draft->id]);
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->once()->andReturnUsing(function () use ($draft, $fault) {
        if ($fault === 'permission') {
            $this->operator->forceFill(['admin_capabilities' => ['admin.read', 'admin.finance']])->save();
        } else {
            app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'callback_received', 'source' => 'fixture']);
        }

        return FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'ACTIVE']);
    });
    $mock->shouldNotReceive('cancel');
    if ($fault === 'permission') {
        expect(fn () => batchExecute($this, $packet))->toThrow(\Exception::class);
    } else {
        expect(batchExecute($this, $packet)['observations'][0]['outcome'])->toBe('unresolved');
    }
    expect(json_decode(DB::table('provider_obligation_reviews')->value('evidence'), true)['post_started'])->toBeFalse();
})->with(['permission', 'callback']);

it('blocks a second claimant while the first remote object has a live lease', function () {
    $draft = batchDraft($this, 'DRAFT');
    $packet = remotePacket($this, [$draft->id]);
    $preview = $this->actions->apply($packet, $this->operation, $this->reason);
    $mock = $this->partialMock(FibSubscriptionService::class);
    $mock->shouldReceive('getStatus')->once()->andReturnUsing(function () use ($draft, $packet, $preview) {
        expect(fn () => $this->actions->apply($packet, $this->operation, $this->reason, $preview['review_hash'], true))
            ->toThrow(\App\Services\Billing\PaymentHistoryResetRefused::class);

        return FibSubscriptionStatusData::fromArray(['id' => $draft->fib_subscription_id, 'status' => 'DRAFT']);
    });
    $mock->shouldNotReceive('cancel');
    expect($this->actions->apply($packet, $this->operation, $this->reason, $preview['review_hash'], true)['observations'][0]['outcome'])->toBe('unresolved');
});

function batchCreationProvenance(Payment $payment, string $host = 'p-stage.fib.iq'): \App\Domain\Payments\Models\PaymentEvent
{
    $response = ['subscriptionId' => $payment->fib_subscription_id, 'appLink' => 'https://'.$host.'/private-fixture-path?token=private-fixture'];
    $request = ['description' => $payment->local_reference, 'statusCallbackUrl' => 'https://merchant.example.test/callback'];
    $payment->update(['create_response' => $response, 'create_payload' => $request, 'provider_links' => ['app' => $response['appLink']]]);

    return app(PaymentEventRecorder::class)->record($payment, ['event_type' => 'provider_subscription_created',
        'source' => 'customer_checkout', 'payload' => $response, 'meta' => ['create_payload' => $request]]);
}

it('classifies corroborated creation provenance and skips staging remote work without rewriting history', function ($host, $expected) {
    $draft = batchDraft($this);
    $event = batchCreationProvenance($draft, $host);
    $before = batchPreservedTables();
    $packet = $this->batch->review('production');
    $item = collect($packet['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['provider_provenance'])->toBe(['classification' => $expected, 'evidence_event_id' => $event->id, 'creation_host' => $host]);
    $staging = $expected === 'confirmed_test_or_staging';
    expect($item['remote_review_eligible'])->toBe(! $staging)
        ->and($item['category'])->toBe($staging ? 'nonproduction_provider_history' : 'draft_unpaid');
    $cutover = app(ProviderObligationInventory::class)->inspect('production', []);
    expect(collect($cutover['items'])->firstWhere('payment_id', $draft->id)['classification'])
        ->toBe($staging ? 'nonproduction_provider_history' : 'unresolved_remote_obligation');
    if ($staging) {
        expect($item['proposed_actions'])->toBe([])
            ->and(collect($this->batch->selectRemote($packet, 25, true)['decisions'])->pluck('payment_id')->all())->not->toContain($draft->id);
        $inventory = (new \App\Services\Billing\CutoverInventoryReader(DB::connection()))->inspect();
        expect($inventory['nonproduction_unpaid_provider_ids'])->toContain($draft->id)
            ->and($inventory['confirmed_retired_provider_ids'])->not->toContain($draft->id);
    }
    expect(batchPreservedTables())->toBe($before)
        ->and(json_encode($packet))->not->toContain('private-fixture', 'merchant.example.test');
    Http::assertNothingSent();
})->with([['p-stage.fib.iq', 'confirmed_test_or_staging'], ['p.fib.iq', 'confirmed_production']]);

it('fails closed on insufficient or conflicting FIB creation provenance', function ($fault) {
    $draft = batchDraft($this);
    $event = batchCreationProvenance($draft);
    match ($fault) {
        'missing event' => $event->delete(),
        'callback only' => $event->update(['event_type' => 'callback_received']),
        'wrong source' => $event->update(['source' => 'manual']),
        'wrong identity' => $event->update(['fib_subscription_id' => 'other']),
        'wrong local reference' => $event->update(['local_reference' => 'other']),
        'response changed' => $draft->update(['create_response' => ['subscriptionId' => 'other', 'appLink' => 'https://p-stage.fib.iq/private']]),
        'request changed' => $event->update(['meta' => ['create_payload' => ['description' => 'other']]]),
        'duplicate creation' => $event->replicate()->save(),
        'mock' => $draft->update(['meta' => ['mock' => true]]),
        'conflicting link' => $draft->update(['provider_links' => ['app' => 'https://p.fib.iq/private']]),
    };
    config(['fib.environment' => 'staging', 'app.env' => 'production']);
    $snapshot = \App\Services\Billing\ProviderReviewSnapshot::capture();
    expect($snapshot->provenance($draft->fresh())['classification'])->toBe('unknown_environment');
    $item = collect(app(ProviderObligationInventory::class)->inspect('production', [])['items'])->firstWhere('payment_id', $draft->id);
    expect($item['classification'])->toBe('unresolved_remote_obligation');
    Http::assertNothingSent();
})->with(['missing event', 'callback only', 'wrong source', 'wrong identity', 'wrong local reference', 'response changed',
    'request changed', 'duplicate creation', 'mock', 'conflicting link']);

it('does not recognize deceptive or unrecognized FIB checkout hosts', function ($host) {
    $draft = batchDraft($this);
    batchCreationProvenance($draft, $host);
    expect(\App\Services\Billing\ProviderReviewSnapshot::capture()->provenance($draft->fresh())['classification'])->toBe('unknown_environment');
})->with(['p-stage.fib.iq.example.test', 'p-stage.fib.iq@evil.example.test', 'user@p-stage.fib.iq', 'p-stage.fib.iq:8443', 'unknown.example.test']);

it('preserves independent financial and paid coverage blockers for proven staging history', function ($fault, $reason) {
    batchCreationProvenance($this->payment);
    $this->payment->update(['active_until' => null, 'meta' => []]);
    match ($fault) {
        'future coverage' => $this->payment->update(['active_until' => now()->addMonth()]),
        'financial review' => $this->payment->update(['review_required_at' => now()]),
        'missing boundary' => null,
    };
    $provenance = \App\Services\Billing\ProviderReviewSnapshot::capture()->provenance($this->payment->fresh());
    expect($provenance['classification'])->toBe('confirmed_test_or_staging');
    $result = app(ProviderObligationInventory::class)->classify($this->payment->fresh(), ['confirmed' => false, 'observed_active_until' => null], $provenance);
    expect($result[1])->toBe($reason);
    Http::assertNothingSent();
})->with([['future coverage', 'paid_through_boundary_not_finished'], ['financial review', 'financial_review_unresolved'], ['missing boundary', 'paid_coverage_boundary_missing']]);

it('keeps linked future coverage visible for staging and refuses forged production remote selection', function () {
    batchCreationProvenance($this->payment);
    $packet = $this->batch->review('production');
    $item = collect($packet['review']['items'])->firstWhere('payment_id', $this->payment->id);
    expect($item['provider_provenance']['classification'])->toBe('confirmed_test_or_staging')
        ->and($item['operator_action_required'])->toBeTrue()
        ->and($item['reason'])->toBe('retained_subscription_coverage_needs_disposition')
        ->and($item['proposed_actions'])->toBe([])->and($item['remote_review_eligible'])->toBeFalse();
    $packet['decisions'] = [['action' => 'remote_retire', 'selected' => true, 'payment_id' => $this->payment->id,
        'customer_id' => $this->payment->customer_id, 'provider_subscription_id' => $this->payment->fib_subscription_id]];
    expect(fn () => $this->actions->apply($packet, $this->operation, $this->reason))->toThrow(\Exception::class);
    Http::assertNothingSent();
});
