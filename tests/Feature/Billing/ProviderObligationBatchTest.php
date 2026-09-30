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
    expect(DB::table('provider_obligation_reviews')->count())->toBe(0);
    $export = $this->batch->merchantExport($packet);
    expect(json_encode($export))->not->toContain('customer_id', 'example.test', 'monetaryValue', 'status_response');
    $result = batchExecute($this, $packet);
    expect($result['observations'][0]['outcome'])->toBe('confirmed_retired')
        ->and(batchPreservedTables())->toBe($before);
    $record = DB::table('provider_obligation_reviews')->first();
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
    expect(DB::table('provider_obligation_reviews')->count())->toBe(0);
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
    expect(DB::table('provider_obligation_reviews')->count())->toBe(1);
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
    expect($item['category'])->not->toBe('confirmed_retired')->and($item['proposed_actions'])->toBe([]);
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
        ->and(DB::table('provider_obligation_reviews')->count())->toBe(1)
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
    expect($item['category'])->not->toBe('confirmed_retired')->and($item['proposed_actions'])->toBe([]);
});

it('can refresh stale DRAFT observations but cannot attest them away', function () {
    $draft = batchDraft($this);
    app(PaymentEventRecorder::class)->record($draft, ['event_type' => 'callback_received', 'source' => 'fixture']);
    $item = collect($this->batch->review('production')['review']['items'])->firstWhere('payment_id', $draft->id);
    expect($item['category'])->toBe('conflict')->and($item['proposed_actions'])->toBe(['draft_get']);
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
