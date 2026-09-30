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
use App\Services\Billing\ProviderSubscriptionCancellation;
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

function obligationSnapshot(): array
{
    $result = [];
    foreach (['credit_wallets', 'credit_ledgers', 'customer_service_subscriptions', 'customer_storage_subscriptions',
        'payments', 'payment_events', 'payment_intents', 'credit_orders', 'subscription_credit_allocations', 'admin_audit_events'] as $table) {
        $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
    }

    return $result;
}

function obligationReview($test): array
{
    return app(ProviderObligationInventory::class)->inspect('production', [
        'customer_service_subscriptions' => [['id' => $test->subscription->id]],
    ]);
}

it('recognizes legacy ignored GET cancellation and retains the full provider coverage blocker without writes', function () {
    $before = obligationSnapshot();
    $confirmation = app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh());
    expect($confirmation['confirmed'])->toBeTrue()->and($confirmation['evidence_event_id'])->toBe($this->event->id);
    $item = collect(obligationReview($this)['items'])->firstWhere('payment_id', $this->payment->id);
    expect($item['classification'])->toBe('valid_coverage_to_preserve')
        ->and($item['reason'])->toBe('confirmed_renewal_stop_with_remaining_coverage')
        ->and($item['cancellation_evidence']['observed_active_until'])->toStartWith('2026-11-29T')
        ->and(obligationReview($this)['blockers'])->not->toBeEmpty()
        ->and(obligationSnapshot())->toBe($before);
    Http::assertNothingSent();
});

it('refuses incomplete conflicting or stale cancellation evidence', function (string $fault) {
    match ($fault) {
        'callback only' => $this->event->update(['event_type' => 'callback_received']),
        'local status only' => $this->event->delete(),
        'POST only' => [$this->event->delete(), $this->payment->update(['meta' => ['provider_cancellation' => ['state' => 'requested', 'result' => 'cancel_requested']]])],
        'payload identity' => $this->payload['id'] = 'other-subscription',
        'event identity' => $this->event->update(['fib_subscription_id' => 'other-subscription']),
        'merchant identity' => $this->event->update(['local_reference' => 'other-reference']),
        'money' => $this->payload['monetaryValue']['amount'] = 1,
        'malformed timestamp' => $this->payload['activeUntil'] = 'tomorrow',
        'impossible term' => $this->payload['activeUntil'] = 1790700135000,
        'future observation' => $this->payment->update(['last_status_checked_at' => now()->addDay()]),
        'stale observation' => $this->payment->update(['last_status_checked_at' => now()]),
        'active' => [$this->payload['status'] = 'ACTIVE', $this->payment->update(['provider_subscription_status' => 'ACTIVE'])],
        'draft' => [$this->payload['status'] = 'DRAFT', $this->payload['activeUntil'] = null, $this->payload['lastPaymentAt'] = null,
            $this->payment->update(['status' => 'awaiting_customer_action', 'internal_status' => 'awaiting_customer_action',
                'provider_subscription_status' => 'DRAFT', 'paid_at' => null, 'fulfilled_at' => null])],
        'newer callback' => app(PaymentEventRecorder::class)->record($this->payment, ['event_type' => 'callback_received',
            'source' => 'fib_subscription_callback', 'payload' => ['subscriptionStatus' => 'ACTIVE']]),
        'newer bad GET' => app(PaymentEventRecorder::class)->record($this->payment, ['event_type' => 'provider_status_checked',
            'source' => 'fib_subscription_callback', 'payload' => ['id' => 'other-subscription', 'status' => 'CANCELLED']]),
    };
    if ($this->event->exists) {
        $this->event->update(['payload' => $this->payload]);
    }
    $this->payment->update(['status_response' => $this->payload]);
    $before = obligationSnapshot();
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse()
        ->and(obligationReview($this)['summary']['retired_confirmed_cancelled'])->toBe(0)
        ->and(obligationReview($this)['blockers'])->not->toBeEmpty()->and(obligationSnapshot())->toBe($before);
    Http::assertNothingSent();
})->with(['callback only', 'local status only', 'POST only', 'payload identity', 'event identity', 'merchant identity',
    'money', 'malformed timestamp', 'impossible term', 'future observation', 'stale observation', 'active', 'draft', 'newer callback', 'newer bad GET']);

it('parses provider milliseconds exactly in UTC and Baghdad without rewriting the local October term', function (string $zone) {
    config(['app.timezone' => $zone]);
    $status = FibSubscriptionStatusData::fromArray($this->payload);
    expect($status->activeUntil->copy()->utc()->format('Y-m-d H:i:s.v'))->toBe('2026-11-29 16:42:15.998')
        ->and($status->lastPaymentAt->copy()->utc()->format('Y-m-d H:i:s.v'))->toBe('2026-09-29 16:42:15.998')
        ->and($status->activeUntil->timezoneName)->toBe($zone)
        ->and($status->activeUntil->copy()->setTimezone('Asia/Baghdad')->format('Y-m-d H:i:s.v'))->toBe('2026-11-29 19:42:15.998');
})->with(['UTC', 'Asia/Baghdad']);

it('only retires confirmed cancellation after every known coverage boundary is finished', function () {
    $this->travelTo(now()->setDate(2026, 12, 1));
    expect(obligationReview($this)['summary']['retired_confirmed_cancelled'])->toBe(1)
        ->and(obligationReview($this)['blockers'])->toBe([]);
});

it('keeps production dry run read only and a blocked hash cannot execute', function () {
    CutoverIdentityFixture::install('production');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $operator = User::forceCreate(['name' => 'Fixture operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $before = obligationSnapshot();
    $review = app(PaymentDomainCutover::class)->review('production', $operator->id);
    expect($review['blockers'])->not->toBeEmpty()->and(obligationSnapshot())->toBe($before);
    app()->maintenanceMode()->activate(['time' => time()]);
    $this->artisan('billing:cutover-reset-payment-domain', ['--target' => 'production', '--execute' => true,
        '--review-hash' => $review['review_hash'], '--confirm' => 'RESET-V2-PRODUCTION-BILLING-DOMAIN',
        '--admin' => $operator->id, '--reason' => 'Fixture must preserve existing coverage.', '--workers-stopped' => true,
        '--backup-confirmed' => true, '--restore-confirmed' => true])->assertFailed();
    expect(obligationSnapshot())->toBe($before);
    Http::assertNothingSent();
});

function cancellationReviewOptions($test): array
{
    $admin = User::forceCreate(['name' => 'Fixture reviewer', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.finance', 'admin.reconcile']]);
    $test->reviewer = $admin;

    return ['payment' => $test->payment->id, '--customer' => $test->payment->customer_id,
        '--provider-subscription' => $test->payment->fib_subscription_id, '--admin' => $admin->id,
        '--operation' => (string) Str::uuid(), '--reason' => 'Review fixture cancellation without paid-term correction.'];
}

it('provides an operator read-only preview without provider calls or canonical backfill', function () {
    $options = cancellationReviewOptions($this);
    $before = obligationSnapshot();
    $this->artisan('payments:review-fib-cancellation', $options + ['--dry-run' => true])->assertSuccessful();
    expect(obligationSnapshot())->toBe($before);
    Http::assertNothingSent();
});

it('audits fresh GET confirmation idempotently without changing paid dates wallets ledgers orders or historical subscriptions', function () {
    $options = cancellationReviewOptions($this) + ['--execute' => true];
    $historical = $this->subscription->replicate();
    $historical->forceFill(['status' => 'ended', 'ends_at' => now()->subYear(), 'auto_renew' => true])->save();
    $history = $historical->fresh()->getRawOriginal();
    $before = obligationSnapshot();
    $dates = $this->subscription->getRawOriginal();
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()
        ->andReturn(FibSubscriptionStatusData::fromArray($this->payload));
    $this->artisan('payments:review-fib-cancellation', $options)->assertSuccessful();
    $this->artisan('payments:review-fib-cancellation', $options)->assertSuccessful();
    $after = obligationSnapshot();
    foreach (['credit_wallets', 'credit_ledgers', 'credit_orders', 'subscription_credit_allocations', 'payment_intents', 'customer_storage_subscriptions'] as $table) {
        expect($after[$table])->toBe($before[$table]);
    }
    foreach (['status', 'source', 'payment_id', 'starts_at', 'ends_at', 'canceled_at', 'cycle_started_on', 'cycle_ends_on', 'next_renewal_on'] as $column) {
        expect($this->subscription->fresh()->getRawOriginal($column))->toBe($dates[$column] ?? null);
    }
    expect($this->payment->fresh()->active_until)->toBeNull()->and($this->payment->fresh()->last_payment_at)->toBeNull()
        ->and($historical->fresh()->getRawOriginal())->toBe($history)
        ->and($this->payment->fresh()->status->value)->toBe('paid')
        ->and(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeTrue()
        ->and(DB::table('admin_operations')->where('status', 'completed')->count())->toBe(1)
        ->and(DB::table('admin_audit_events')->where('action', 'payment.cancellation_evidence_review')->count())->toBe(1)
        ->and(obligationReview($this)['blockers'])->not->toBeEmpty();
    Http::assertNothingSent(); // Service mock supplies GET; no real transport or cancellation POST.
});

it('never discards the longer retained provider boundary when a later GET shortens its observation', function () {
    $options = cancellationReviewOptions($this) + ['--execute' => true];
    $payload = array_replace($this->payload, ['activeUntil' => '2026-10-29T16:42:15.998Z']);
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()
        ->andReturn(FibSubscriptionStatusData::fromArray($payload));
    $this->artisan('payments:review-fib-cancellation', $options)->assertSuccessful();
    expect(data_get($this->payment->fresh()->meta, 'provider_cancellation.retained_active_until'))->toStartWith('2026-11-29T');
    $this->travelTo(now()->setDate(2026, 11, 15));
    expect(obligationReview($this)['summary']['valid_coverage_to_preserve'])->toBe(1)
        ->and(obligationReview($this)['blockers'])->not->toBeEmpty();
});

it('uses the real authenticated GET client without sending a cancellation POST', function () {
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true,
        'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client',
        'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 1, 'fib.http.retry_sleep_ms' => 1]);
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$this->payment->fib_subscription_id => Http::response($this->payload),
    ]);
    $this->artisan('payments:review-fib-cancellation', cancellationReviewOptions($this) + ['--execute' => true])->assertSuccessful();
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_ends_with($request->url(), '/subscriptions/'.$this->payment->fib_subscription_id)
        && $request->hasHeader('Authorization', 'Bearer fixture-token'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
});

it('does not let canonical context overrule a newer unverified callback', function () {
    $this->payment->update(['meta' => ['provider_cancellation' => ['state' => 'confirmed',
        'provider_ref' => $this->payment->fib_subscription_id, 'provider_cancel_pending' => false,
        'provider_cancel_confirmed_at' => now()->subHour()->toIso8601String(), 'result' => 'already_canceled']]]);
    app(PaymentEventRecorder::class)->record($this->payment, ['event_type' => 'callback_received',
        'source' => 'fib_subscription_callback', 'payload' => ['subscriptionStatus' => 'ACTIVE']]);
    expect(app(ProviderSubscriptionCancellation::class)->confirmation($this->payment->fresh())['confirmed'])->toBeFalse();
});

it('refuses unsafe operator confirmation and preserves financial records', function (string $fault) {
    $options = cancellationReviewOptions($this) + ['--execute' => true];
    match ($fault) {
        'inactive' => $this->reviewer->update(['status' => 0]),
        'finance missing' => $this->reviewer->forceFill(['admin_capabilities' => ['admin.reconcile']])->save(),
        'reconcile missing' => $this->reviewer->forceFill(['admin_capabilities' => ['admin.finance']])->save(),
        'wrong customer' => $options['--customer'] = 999999,
        'wrong reference' => $options['--provider-subscription'] = 'other-reference',
        'no reason' => $options['--reason'] = '',
        'no operation' => $options['--operation'] = '',
        'active provider' => $this->payload['status'] = 'ACTIVE',
        'malformed provider time' => $this->payload['activeUntil'] = 'tomorrow',
        'mismatched provider' => $this->payload['id'] = 'other-subscription',
        'provider failure' => null,
    };
    $before = obligationSnapshot();
    if (in_array($fault, ['active provider', 'malformed provider time', 'mismatched provider', 'provider failure'], true)) {
        $mock = $this->mock(FibSubscriptionService::class)->shouldReceive('getStatus')->once();
        $fault === 'provider failure' ? $mock->andThrow(new RuntimeException('PRIVATE PROVIDER DETAIL'))
            : $mock->andReturn(FibSubscriptionStatusData::fromArray($this->payload));
    } else {
        $this->mock(FibSubscriptionService::class)->shouldNotReceive('getStatus');
    }
    $this->artisan('payments:review-fib-cancellation', $options)->doesntExpectOutputToContain('PRIVATE PROVIDER DETAIL')->assertFailed();
    $after = obligationSnapshot();
    // A failed operator execution may append its sanitized failure audit, never financial writes.
    unset($before['admin_audit_events'], $after['admin_audit_events']);
    expect($after)->toBe($before);
    Http::assertNothingSent();
})->with(['inactive', 'finance missing', 'reconcile missing', 'wrong customer', 'wrong reference', 'no reason', 'no operation',
    'active provider', 'malformed provider time', 'mismatched provider', 'provider failure']);
