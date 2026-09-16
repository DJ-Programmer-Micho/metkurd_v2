<?php

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\AdminAuditEvent;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\PaymentHistoryReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    $this->seed();
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->owner = Customer::create(['username' => 'reset_'.Str::random(8), 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'email_verify' => 1, 'phone_verify' => 1]);
    $this->operator = User::forceCreate(['name' => 'Reset operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture',
        'status' => 1, 'admin_capabilities' => ['admin.finance', 'admin.reconcile']]);
    $this->old = historyResetPayment($this->owner, ['created_at' => '2020-01-01 00:00:00']);
    $this->keep = historyResetPayment($this->owner, ['created_at' => '2026-09-01 00:00:00',
        'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => '2026-09-01 00:00:00']);
});

function historyResetPayment(Customer $customer, array $attributes = []): Payment
{
    return Payment::create(array_merge(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'provider_object_type' => 'payment',
        'status' => 'canceled', 'internal_status' => 'canceled', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
        'amount' => 1000, 'currency' => 'IQD', 'purchasable_type' => ServicePlan::class,
        'purchasable_id' => ServicePlan::where('code', 'pro')->value('id')], $attributes));
}

function historyResetOptions($test, array $replace = []): array
{
    app()->maintenanceMode()->activate(['time' => time()]);
    $review = app(PaymentHistoryReset::class)->review();

    return array_replace(['--execute' => true,
        '--review-hash' => $review['review_hash'], '--confirm' => 'RESET-PAYMENT-HISTORY',
        '--admin' => $test->operator->id, '--reason' => 'Reviewed synthetic reset for regression coverage.', '--workers-stopped' => true], $replace);
}

it('defaults to a read-only review with latest active selection and deterministic frozen identity', function () {
    $this->keep->update(['qr_code' => 'PRIVATE-QR', 'status_response' => ['secret' => 'PRIVATE-SECRET']]);
    $before = app(PaymentHistoryReset::class)->review();
    $this->artisan('billing:reset-payment-history')->expectsOutputToContain($this->keep->uuid)
        ->expectsOutputToContain('DRY RUN')->assertSuccessful();
    expect(app(PaymentHistoryReset::class)->review())->toBe($before)
        ->and($before['keep']['uuid'])->toBe((string) $this->keep->uuid)
        ->and($before['delete_ids']['payments'])->toBe([$this->old->id])
        ->and(json_encode($before))->not->toContain('PRIVATE-SECRET', 'PRIVATE-QR')
        ->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves the selected payment and events byte for byte and deletes only reviewed history', function () {
    $this->keep->update(['status' => 'pending', 'internal_status' => 'requires_review', 'review_required_at' => now(), 'fulfilled_at' => null,
        'qr_code' => 'PRIVATE-QR', 'readable_code' => 'PRIVATE-CODE', 'provider_links' => ['app' => 'https://fib.iq/test']]);
    $keptEvent = DB::table('payment_events')->insertGetId(['payment_id' => $this->keep->id, 'provider' => 'fib', 'event_type' => 'created', 'source' => 'fixture']);
    DB::table('payment_events')->insert(['payment_id' => $this->old->id, 'provider' => 'fib', 'event_type' => 'failed', 'source' => 'fixture']);
    DB::table('payment_events')->insert(['payment_id' => null, 'provider' => 'fib', 'event_type' => 'orphan', 'source' => 'fixture']);
    $payment = $this->keep->fresh()->getAttributes();
    $event = (array) DB::table('payment_events')->find($keptEvent);
    $options = historyResetOptions($this);
    $this->artisan('billing:reset-payment-history', $options)->assertSuccessful();
    expect(Payment::count())->toBe(1)->and($this->keep->fresh()->getAttributes())->toBe($payment)
        ->and((array) DB::table('payment_events')->find($keptEvent))->toBe($event)
        ->and(DB::table('payment_events')->count())->toBe(1)
        ->and(app(PaymentCheckoutState::class)->blocks($this->keep->fresh()))->toBeTrue()
        ->and(AdminAuditEvent::where('action', 'billing.reset_payment_history')->count())->toBe(1);
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect($this->keep->fresh()->getAttributes())->toBe($payment)->and(AdminAuditEvent::count())->toBe(1);
    Http::assertNothingSent();
});

it('retains financial and normalized history detaching only the approved payment ids', function () {
    $this->old->update(['status' => 'paid', 'internal_status' => 'applied', 'paid_at' => '2020-01-01', 'fulfilled_at' => '2020-01-01']);
    $order = CreditOrder::create(['customer_id' => $this->owner->id, 'payment_id' => $this->old->id, 'order_type' => 'subscription',
        'status' => 'paid', 'credits_amount' => 1000, 'base_amount_iqd' => 1000, 'meta' => ['revenue_excluded' => false]]);
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->owner->id, 'payment_id' => $this->old->id,
        'service_plan_id' => $this->old->purchasable_id, 'status' => 'ended', 'starts_at' => '2020-01-01', 'ends_at' => '2020-02-01', 'auto_renew' => false]);
    \App\Models\MlJob::create(['id' => Str::uuid(), 'customer_id' => $this->owner->id, 'status' => 'done']);
    \App\Models\CustomerFile::create(['customer_id' => $this->owner->id, 'purpose' => 'render', 'disk' => 's3', 'path' => 'fixture.wav', 'size_bytes' => 10]);
    $before = app(PaymentHistoryReset::class)->review();
    expect($before['blockers'])->toBe([]);
    $orderBefore = $order->fresh()->getAttributes();
    $orderBefore['payment_id'] = null;
    $subBefore = $sub->fresh()->getAttributes();
    $subBefore['payment_id'] = null;
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    $after = app(PaymentHistoryReset::class)->review();
    expect($order->fresh()->getAttributes())->toBe($orderBefore)->and($sub->fresh()->getAttributes())->toBe($subBefore);
    foreach (['customers', 'credit_wallets', 'credit_ledgers', 'ml_jobs', 'customer_files', 'service_plans', 'tool_action_prices'] as $table) {
        if (isset($before['fingerprints'][$table])) {
            expect($after['fingerprints'][$table])->toBe($before['fingerprints'][$table]);
        }
    }
    expect(AdminAuditEvent::first()->after_state['detach_payment_links']['credit_orders'][0]['payment_id'])->toBe($this->old->id);
    Http::assertNothingSent();
});

it('requires every execution guard and fresh active capabilities', function (string $guard) {
    $options = historyResetOptions($this);
    if ($guard === 'maintenance') {
        app()->maintenanceMode()->deactivate();
    } elseif ($guard === 'inactive') {
        $this->operator->forceFill(['status' => 0])->save();
    } elseif ($guard === 'finance') {
        $this->operator->forceFill(['admin_capabilities' => ['admin.reconcile']])->save();
    } elseif ($guard === 'reconcile') {
        $this->operator->forceFill(['admin_capabilities' => ['admin.finance']])->save();
    } elseif ($guard === 'wrong hash') {
        $options['--review-hash'] = str_repeat('0', 64);
    } elseif ($guard === 'dry-run') {
        $options['--dry-run'] = true;
    } elseif ($guard === 'short reason') {
        $options['--reason'] = '   ';
    } else {
        unset($options['--'.$guard]);
    }
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(Payment::count())->toBe(2)->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with(['review-hash', 'confirm', 'admin', 'reason', 'workers-stopped', 'maintenance', 'inactive', 'finance', 'reconcile', 'wrong hash', 'dry-run', 'short reason']);

it('rejects changed rows counts selected identity and configuration after review', function (string $change) {
    $options = historyResetOptions($this);
    match ($change) {
        'kept' => $this->keep->update(['readable_code' => 'changed']),
        'old' => $this->old->update(['amount' => 9900]),
        'new payment' => historyResetPayment($this->owner),
        'event' => DB::table('payment_events')->insert(['payment_id' => $this->old->id, 'provider' => 'fib', 'event_type' => 'new', 'source' => 'fixture']),
        'wallet' => DB::table('credit_wallets')->where('customer_id', $this->owner->id)->update(['addon_balance_credits' => 15]),
        'different keep' => $this->old->update(['created_at' => now(), 'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()]),
        'deleted keep' => $this->keep->delete(),
        'database identity' => app()->instance('env', 'local'),
    };
    $before = DB::table('payments')->orderBy('id')->get()->toJson();
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(DB::table('payments')->orderBy('id')->get()->toJson())->toBe($before)->and(AdminAuditEvent::count())->toBe(0);
})->with(['kept', 'old', 'new payment', 'event', 'wallet', 'different keep', 'deleted keep', 'database identity']);

it('blocks obligations allocation claims and unapproved foreign or logical dependencies', function (string $dependency) {
    $sub = $this->owner->activeServiceSubscription()->firstOrFail();
    match ($dependency) {
        'current subscription' => $sub->update(['payment_id' => $this->old->id]),
        'metadata subscription' => $sub->update(['meta' => ['payment_id' => $this->old->id]]),
        'future historical term' => CustomerServiceSubscription::create(['customer_id' => $this->owner->id,
            'service_plan_id' => $this->old->purchasable_id, 'payment_id' => $this->old->id, 'status' => 'ended', 'ends_at' => now()->addMonth()]),
        'allocation' => \App\Models\SubscriptionCreditAllocation::create(['customer_id' => $this->owner->id, 'subscription_id' => $sub->id,
            'payment_id' => $this->old->id, 'cycle_key' => 'retained', 'allocation_type' => 'initial', 'cycle_started_at' => now()]),
        'provider draft' => $this->old->update(['fib_subscription_id' => 'remote-fixture', 'provider_subscription_status' => 'DRAFT']),
        'provider active' => $this->old->update(['fib_subscription_id' => 'remote-fixture', 'provider_subscription_status' => 'ACTIVE']),
        'paid unapplied' => $this->old->update(['status' => 'paid', 'internal_status' => 'paid_pending_application', 'paid_at' => now()]),
        'financial review' => $this->old->update(['internal_status' => 'requires_review', 'review_required_at' => now()]),
        'coverage' => $this->old->update(['active_until' => now()->addMonth()]),
        'payload coverage' => $this->old->update(['meta' => ['paidThrough' => now()->addMonth()->toIso8601String()]]),
        'hidden paid evidence' => $this->old->update(['callback_payload' => ['paymentStatus' => 'PAID']]),
        'pending cancellation' => $sub->update(['payment_id' => $this->old->id, 'status' => 'ended', 'canceled_at' => now(), 'ends_at' => now()->addMonth()]),
        'other nullable fk' => DB::table('ad_conversion_events')->insert(['event_name' => 'Purchase', 'payment_id' => $this->old->id, 'customer_id' => $this->owner->id, 'transaction_id' => 'fixture', 'dedupe_key' => Str::uuid()]),
        'polymorphic ledger' => DB::table('credit_ledgers')->insert(['customer_id' => $this->owner->id, 'wallet_type' => 'app',
            'type' => 'grant', 'related_type' => Payment::class, 'related_id' => $this->old->id, 'direction' => 'credit', 'amount' => 1, 'credits_delta' => 1, 'balance_after' => 1]),
    };
    $review = app(PaymentHistoryReset::class)->review();
    expect($review['blockers'])->not->toBeEmpty();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
    expect(Payment::count())->toBe(2)->and(AdminAuditEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with(['current subscription', 'metadata subscription', 'future historical term', 'allocation', 'provider draft', 'provider active',
    'paid unapplied', 'financial review', 'coverage', 'payload coverage', 'hidden paid evidence', 'pending cancellation', 'other nullable fk', 'polymorphic ledger']);

it('clears resolved legacy children in leaf order but retains every CreditOrder', function () {
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fake',
        'purpose_type' => 'service_plan', 'status' => 'failed', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]);
    $parent = DB::table('payment_transactions')->insertGetId(['payment_intent_id' => $intent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'failed']);
    $child = DB::table('payment_transactions')->insertGetId(['payment_intent_id' => $intent, 'parent_transaction_id' => $parent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'failed']);
    DB::table('payment_webhook_events')->insert(['payment_intent_id' => $intent, 'provider' => 'fake', 'event_type' => 'failed', 'processing_status' => 'processed', 'event_key' => Str::uuid()]);
    $order = CreditOrder::create(['customer_id' => $this->owner->id, 'order_type' => 'adjustment', 'status' => 'paid', 'credits_amount' => 30, 'meta' => ['revenue_excluded' => true]]);
    expect(app(PaymentHistoryReset::class)->review()['transaction_delete_order'])->toBe([$child, $parent]);
    $before = $order->fresh()->getAttributes();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    foreach (['payment_intents', 'payment_transactions', 'payment_webhook_events'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect($order->fresh()->getAttributes())->toBe($before);
});

it('blocks a retained CreditOrder intent link rather than clearing an unapproved column', function () {
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fake',
        'purpose_type' => 'service_plan', 'status' => 'paid', 'fulfilled_at' => now()->subYear(), 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]);
    $order = CreditOrder::create(['customer_id' => $this->owner->id, 'payment_intent_id' => $intent, 'order_type' => 'subscription', 'status' => 'paid']);
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
    expect($order->fresh()->payment_intent_id)->toBe($intent)->and(DB::table('payment_intents')->count())->toBe(1);
});

it('rolls back the entire manifest and reset when audit or post-mutation verification fails', function (bool $afterDelete) {
    $options = historyResetOptions($this);
    $before = app(PaymentHistoryReset::class)->review()['fingerprints'];
    if ($afterDelete) {
        DB::unprepared('CREATE TRIGGER reset_fault AFTER DELETE ON payments BEGIN UPDATE credit_wallets SET balance_credits=99; END');
        // Review includes the trigger's target state. The preservation check must detect its side effect.
        $options = historyResetOptions($this);
    } else {
        AdminAuditEvent::creating(fn () => throw new RuntimeException('private failure fixture'));
    }
    try {
        $this->artisan('billing:reset-payment-history', $options)->assertFailed();
        expect(app(PaymentHistoryReset::class)->review()['fingerprints'])->toBe($before);
    } finally {
        if ($afterDelete) {
            DB::unprepared('DROP TRIGGER reset_fault');
        }
    }
})->with([false, true]);

it('removes a historical checkout blocker without bypassing the retained Payment policy', function () {
    $this->old->update(['status' => 'pending', 'internal_status' => 'pending']);
    expect(app(PaymentCheckoutState::class)->blocker($this->owner, ServicePlan::class)->id)->toBe($this->old->id);
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    expect(app(PaymentCheckoutState::class)->blocker($this->owner, ServicePlan::class))->toBeNull();
    $this->keep->update(['status' => 'pending', 'internal_status' => 'requires_review', 'fulfilled_at' => null]);
    expect(app(PaymentCheckoutState::class)->blocker($this->owner, ServicePlan::class)->id)->toBe($this->keep->id);
});

it('retains current subscriptions and allocation claims belonging to the kept Payment', function () {
    $sub = $this->owner->activeServiceSubscription()->firstOrFail();
    $sub->update(['payment_id' => $this->keep->id]);
    $claim = \App\Models\SubscriptionCreditAllocation::create(['customer_id' => $this->owner->id, 'subscription_id' => $sub->id,
        'payment_id' => $this->keep->id, 'cycle_key' => 'keep-current', 'allocation_type' => 'initial', 'cycle_started_at' => now()]);
    $beforeSub = $sub->fresh()->getAttributes();
    $beforeClaim = $claim->fresh()->getAttributes();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    expect($sub->fresh()->getAttributes())->toBe($beforeSub)->and($claim->fresh()->getAttributes())->toBe($beforeClaim);
    Http::assertNothingSent();
});

it('archives locally retired provider references and detaches only historical storage payment links', function () {
    $this->old->update(['fib_subscription_id' => 'retired-fixture', 'provider_subscription_status' => 'CANCELLED']);
    $id = DB::table('customer_storage_subscriptions')->insertGetId(['customer_id' => $this->owner->id,
        'storage_plan_id' => DB::table('storage_plans')->value('id'), 'payment_id' => $this->old->id,
        'provider_ref' => 'retired-fixture', 'status' => 'expired', 'ends_at' => '2020-02-01', 'auto_renew' => false,
        'meta' => json_encode(['payment_id' => $this->old->id, 'period_ends_at' => '2020-02-01'])]);
    $before = (array) DB::table('customer_storage_subscriptions')->find($id);
    $before['payment_id'] = null;
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    expect((array) DB::table('customer_storage_subscriptions')->find($id))->toBe($before)
        ->and(AdminAuditEvent::first()->after_state['archived_payments'][0]['provider_subscription_reference'])->toBe('retired-fixture');
    Http::assertNothingSent();
});

it('blocks legacy unresolved state and retained metadata without partial deletion', function (string $case) {
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fake',
        'purpose_type' => 'service_plan', 'status' => 'failed', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid()]);
    $transaction = DB::table('payment_transactions')->insertGetId(['payment_intent_id' => $intent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'failed']);
    match ($case) {
        'pending intent' => DB::table('payment_intents')->where('id', $intent)->update(['status' => 'pending']),
        'provider schedule' => DB::table('payment_intents')->where('id', $intent)->update(['provider_schedule_ref' => 'unknown-schedule']),
        'pending transaction' => DB::table('payment_transactions')->where('id', $transaction)->update(['status' => 'pending']),
        'cycle' => DB::table('payment_transactions')->where('id', $transaction)->update(['parent_transaction_id' => $transaction]),
        'unprocessed webhook' => DB::table('payment_webhook_events')->insert(['payment_intent_id' => $intent, 'provider' => 'fake', 'event_type' => 'unknown', 'processing_status' => 'received', 'event_key' => Str::uuid()]),
        'kept intent metadata' => $this->keep->update(['meta' => ['payment_intent_id' => $intent]]),
    };
    $before = app(PaymentHistoryReset::class)->review();
    expect($before['blockers'])->not->toBeEmpty();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
    expect(app(PaymentHistoryReset::class)->review()['fingerprints'])->toBe($before['fingerprints']);
})->with(['pending intent', 'provider schedule', 'pending transaction', 'cycle', 'unprocessed webhook', 'kept intent metadata']);

it('rejects changed event content or schema even when row counts match', function (bool $schemaChange) {
    $event = DB::table('payment_events')->insertGetId(['payment_id' => $this->keep->id, 'provider' => 'fib', 'event_type' => 'kept', 'source' => 'fixture']);
    $options = historyResetOptions($this);
    if ($schemaChange) {
        DB::unprepared('CREATE INDEX reset_fixture_index ON payment_events (event_type)');
    } else {
        DB::table('payment_events')->where('id', $event)->update(['payload' => '{"changed":true}']);
    }
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(Payment::count())->toBe(2)->and(AdminAuditEvent::count())->toBe(0);
})->with([false, true]);

it('rolls back when audit hooks revoke authorization or change existing audit history', function (bool $changeAudit) {
    $audit = AdminAuditEvent::create(['admin_id' => $this->operator->id, 'action' => 'fixture.previous', 'target_type' => 'fixture', 'target_id' => '1']);
    $options = historyResetOptions($this);
    $before = app(PaymentHistoryReset::class)->review()['fingerprints'];
    AdminAuditEvent::creating(function () use ($changeAudit, $audit) {
        if ($changeAudit) {
            DB::table('admin_audit_events')->where('id', $audit->id)->update(['reason' => 'unexpected change']);
        } else {
            DB::table('users')->where('id', $this->operator->id)->update(['status' => 0]);
        }
    });
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(app(PaymentHistoryReset::class)->review()['fingerprints'])->toBe($before);
})->with([false, true]);

it('discovers undeclared payment links and normalized paid-through metadata', function (bool $logicalLink) {
    if ($logicalLink) {
        \Illuminate\Support\Facades\Schema::create('reset_fixture_dependency', function ($table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->nullable();
        });
        DB::table('reset_fixture_dependency')->insert(['payment_id' => $this->old->id]);
    } else {
        $this->owner->activeServiceSubscription()->firstOrFail()->update(['payment_id' => $this->old->id, 'status' => 'ended',
            'meta' => ['period_ends_at' => now()->addMonth()->toIso8601String()]]);
    }
    expect(app(PaymentHistoryReset::class)->review()['blockers'])->not->toBeEmpty();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
    expect(Payment::count())->toBe(2)->and(AdminAuditEvent::count())->toBe(0);
})->with([false, true]);

it('retains a legacy child of the kept payment even when no other Payments remain', function () {
    $this->old->delete();
    \Illuminate\Support\Facades\Schema::table('payment_intents', fn ($table) => $table->unsignedBigInteger('payment_id')->nullable());
    DB::table('payment_intents')->insert(['uuid' => Str::uuid(), 'customer_id' => $this->owner->id, 'provider' => 'fake',
        'purpose_type' => 'service_plan', 'status' => 'failed', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid(), 'payment_id' => $this->keep->id]);
    expect(app(PaymentHistoryReset::class)->review()['blockers'])->not->toBeEmpty();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
    expect(DB::table('payment_intents')->count())->toBe(1)->and(Payment::count())->toBe(1);
});

it('does not shorten ambiguous UTC provider boundaries using the application timezone', function () {
    config(['app.timezone' => 'Asia/Baghdad']);
    $this->old->update(['status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => '2020-01-01',
        'status_response' => ['activeUntil' => now('UTC')->addHour()->format('Y-m-d H:i:s')]]);
    expect(app(PaymentHistoryReset::class)->review()['blockers'])->not->toBeEmpty();
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertFailed();
});

it('automatically keeps the latest active payment and skips newer closed rows', function (string $closed) {
    $newer = historyResetPayment($this->owner, ['created_at' => now(), 'status' => $closed, 'internal_status' => $closed]);
    expect(app(PaymentHistoryReset::class)->review()['keep']['uuid'])->toBe((string) $this->keep->uuid);
    $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
    expect(Payment::count())->toBe(1)->and($newer->fresh())->toBeNull()->and($this->keep->fresh())->not->toBeNull();
})->with(['canceled', 'expired', 'failed', 'refunded']);

it('uses shared checkout expiry and deterministic id ordering for active selection', function () {
    $expired = historyResetPayment($this->owner, ['created_at' => now(), 'status' => 'pending', 'internal_status' => 'pending',
        'fib_payment_id' => 'expired-fixture', 'valid_until' => now()->subDay(), 'provider_status' => 'UNPAID']);
    expect(app(PaymentHistoryReset::class)->review()['keep']['uuid'])->toBe((string) $this->keep->uuid);
    $tied = historyResetPayment($this->owner, ['created_at' => $this->keep->created_at,
        'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()]);
    expect(app(PaymentHistoryReset::class)->review()['keep']['uuid'])->toBe((string) $tied->uuid);
});

it('refuses without an active payment and never substitutes a closed row', function () {
    $options = historyResetOptions($this);
    $this->keep->update(['status' => 'canceled', 'internal_status' => 'canceled', 'fulfilled_at' => null]);
    $before = DB::table('payments')->get()->toJson();
    $this->artisan('billing:reset-payment-history', ['--dry-run' => true])
        ->expectsOutputToContain('No active Payment exists to retain')->assertFailed();
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(DB::table('payments')->get()->toJson())->toBe($before)->and(AdminAuditEvent::count())->toBe(0);
});

it('rejects an old review hash when a new active payment appears', function () {
    $options = historyResetOptions($this);
    $newer = historyResetPayment($this->owner, ['created_at' => now(), 'status' => 'paid', 'internal_status' => 'applied', 'fulfilled_at' => now()]);
    expect(app(PaymentHistoryReset::class)->review()['keep']['uuid'])->toBe((string) $newer->uuid);
    $this->artisan('billing:reset-payment-history', $options)->assertFailed();
    expect(Payment::count())->toBe(3)->and(AdminAuditEvent::count())->toBe(0);
});

it('limits inventory and execution to the current schema when another schema is visible', function () {
    DB::statement("ATTACH DATABASE ':memory:' AS unrelated");
    try {
        DB::statement('CREATE TABLE unrelated.other_database_only (id INTEGER PRIMARY KEY, value TEXT)');
        DB::table('unrelated.other_database_only')->insert(['id' => 1, 'value' => 'must remain untouched']);
        expect(\Illuminate\Support\Facades\Schema::getTableListing(schemaQualified: false))->toContain('other_database_only');
        $report = app(PaymentHistoryReset::class)->review();
        expect($report['counts'])->not->toHaveKey('other_database_only')
            ->and($report['fingerprints'])->not->toHaveKey('other_database_only');
        $this->artisan('billing:reset-payment-history', historyResetOptions($this))->assertSuccessful();
        expect(DB::table('unrelated.other_database_only')->value('value'))->toBe('must remain untouched');
    } finally {
        // RefreshDatabase rolls back its outer fixture transaction first; SQLite
        // cannot detach a schema while that transaction still owns its writes.
        $fixturePdo = DB::connection()->getPdo();
        $this->beforeApplicationDestroyed(fn () => $fixturePdo->exec('DETACH DATABASE unrelated'));
    }
});

it('prints safe database failure codes without query text bindings or driver payload', function () {
    $previous = new PDOException('PRIVATE-DRIVER-PAYLOAD');
    $previous->errorInfo = ['42S02', 1146, 'PRIVATE-DRIVER-PAYLOAD'];
    $this->mock(PaymentHistoryReset::class)->shouldReceive('review')->once()
        ->andThrow(new \Illuminate\Database\QueryException('mysql', 'select PRIVATE-SQL where secret=?', ['PRIVATE-BINDING'], $previous));
    $exit = \Illuminate\Support\Facades\Artisan::call('billing:reset-payment-history', ['--dry-run' => true]);
    expect($exit)->toBe(1)->and(\Illuminate\Support\Facades\Artisan::output())->toContain('SQLSTATE 42S02', 'driver code 1146')
        ->not->toContain('PRIVATE-DRIVER-PAYLOAD', 'PRIVATE-SQL', 'PRIVATE-BINDING');
});
