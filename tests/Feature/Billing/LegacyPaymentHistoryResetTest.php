<?php

use App\Domain\Payments\Models\Payment;
use App\Models\AdminAuditEvent;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\ServicePlan;
use App\Models\User;
use App\Services\Billing\LegacyPaymentHistoryReset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    Http::preventStrayRequests();
    Mail::fake();
});

function resetAttempt(): Payment
{
    $customer = Customer::withoutEvents(fn () => Customer::create(['username' => 'reset_'.Str::random(8),
        'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]));

    $payment = Payment::create(['uuid' => Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'status' => 'failed', 'internal_status' => 'failed',
        'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 1000, 'currency' => 'IQD',
        'created_at' => '2020-01-01 00:00:00']);
    $payment->forceFill(['created_at' => '2020-01-01 00:00:00'])->save();

    return $payment;
}

function resetOptions(array $replace = []): array
{
    $operator = User::forceCreate(['name' => 'Reset fixture operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture',
        'status' => 1, 'admin_capabilities' => ['admin.finance', 'admin.reconcile']]);
    $review = app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00');

    return array_replace(['--before' => '2021-01-01 00:00:00', '--execute' => true, '--confirm' => 'RESET-LEGACY-PAYMENT-HISTORY',
        '--review-hash' => $review['review_hash'], '--admin' => $operator->id, '--reason' => 'Approved synthetic fixture cleanup only.', '--workers-stopped' => true], $replace);
}

it('dry runs exact abandoned attempts without deleting or auditing', function () {
    $payment = resetAttempt();
    $event = DB::table('payment_events')->insertGetId(['payment_id' => $payment->id, 'provider' => 'fib', 'event_type' => 'failed', 'source' => 'fixture']);
    $this->artisan('billing:reset-legacy-payment-history', ['--before' => '2021-01-01 00:00:00', '--dry-run' => true])->assertSuccessful();
    $review = app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00');
    expect($review['candidate_delete_ids']['payments'])->toBe([$payment->id])
        ->and($review['candidate_delete_ids']['payment_events'])->toBe([$event])
        ->and(Payment::count())->toBe(1)->and(DB::table('payment_events')->count())->toBe(1)
        ->and(AdminAuditEvent::count())->toBe(0)->and(DB::table('subscription_credit_allocations')->count())->toBe(0);
});

it('refuses active paid subscriptions and unresolved payments', function (bool $pending) {
    $payment = resetAttempt();
    if ($pending) {
        $payment->update(['status' => 'pending', 'internal_status' => 'pending']);
    } else {
        CustomerServiceSubscription::create(['customer_id' => $payment->customer_id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'), 'status' => 'active', 'starts_at' => now()]);
    }
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertFailed();
    expect($payment->fresh())->not->toBeNull()->and(AdminAuditEvent::count())->toBe(0);
})->with([false, true]);

it('deletes children first while preserving accounts wallets ledgers jobs files and allocations', function () {
    $payment = resetAttempt();
    DB::table('payment_events')->insert(['payment_id' => $payment->id, 'provider' => 'fib', 'event_type' => 'failed', 'source' => 'fixture']);
    $owner = Customer::create(['username' => 'retained', 'email' => 'retained@example.test', 'password' => 'fixture', 'status' => 1]);
    CreditWallet::where('customer_id', $owner->id)->where('wallet_type', 'app')->update(['addon_balance_credits' => 77, 'balance_credits' => $owner->wallet->subscription_balance_credits + 77]);
    MlJob::create(['id' => Str::uuid(), 'customer_id' => $owner->id, 'status' => 'done']);
    CustomerFile::create(['customer_id' => $owner->id, 'purpose' => 'render', 'disk' => 's3', 'path' => 'fixture/private.wav', 'size_bytes' => 10]);
    $tables = ['customers', 'credit_wallets', 'credit_ledgers', 'ml_jobs', 'customer_files', 'customer_service_subscriptions', 'subscription_credit_allocations'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
    $deleted = [];
    DB::listen(function ($query) use (&$deleted) {
        if (str_starts_with(strtolower($query->sql), 'delete ')) {
            $deleted[] = $query->sql;
        }
    });
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertSuccessful();
    expect($deleted)->toHaveCount(2)->and($deleted[0])->toContain('payment_events')->and($deleted[1])->toContain('payments');
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    expect(AdminAuditEvent::where('action', 'billing.reset_legacy_payment_history')->count())->toBe(1);
    Http::assertNothingSent();
});

it('retains attempts with balances or ledger provenance and any referenced purchase', function () {
    $payment = resetAttempt();
    CreditWallet::create(CreditWallet::defaultAttributes($payment->customer_id, 'app', now()));
    CreditWallet::where('customer_id', $payment->customer_id)->update(['balance_credits' => 77, 'addon_balance_credits' => 77]);
    $review = app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00');
    expect($review['candidate_delete_ids']['payments'])->toBe([])->and($review['retained_dependency_ids']['payments'])->toBe([$payment->id]);
});

it('requires operator capabilities confirmation and an unchanged review', function (string $failure) {
    resetAttempt();
    $options = resetOptions();
    if ($failure === 'confirmation') {
        unset($options['--confirm']);
    } elseif ($failure === 'changed') {
        resetAttempt();
    } else {
        User::whereKey($options['--admin'])->update(['admin_capabilities' => ['admin.read']]);
    }
    if ($failure === 'capability') {
        expect(fn () => Artisan::call('billing:reset-legacy-payment-history', $options))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    } else {
        $this->artisan('billing:reset-legacy-payment-history', $options)->assertFailed();
    }
    expect(Payment::count())->toBe($failure === 'changed' ? 2 : 1)->and(AdminAuditEvent::count())->toBe(0);
})->with(['confirmation', 'changed', 'capability']);

it('refuses a production environment without touching its configured database', function () {
    app()->instance('env', 'production');
    $this->artisan('billing:reset-legacy-payment-history', ['--before' => '2021-01-01 00:00:00', '--dry-run' => true])->assertFailed();
});

it('rolls back deletion when operator audit cannot persist', function () {
    $payment = resetAttempt();
    $options = resetOptions();
    AdminAuditEvent::creating(fn () => throw new RuntimeException('fixture audit failure'));
    expect(fn () => Artisan::call('billing:reset-legacy-payment-history', $options))->toThrow(RuntimeException::class, 'fixture audit failure');
    expect($payment->fresh())->not->toBeNull();
});

it('orders legacy intent children safely and retains provider schedule evidence', function () {
    $payment = resetAttempt();
    $intent = DB::table('payment_intents')->insertGetId(['uuid' => Str::uuid(), 'customer_id' => $payment->customer_id,
        'provider' => 'fake', 'purpose_type' => 'service_plan', 'status' => 'failed', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid(), 'created_at' => '2020-01-01 00:00:00']);
    DB::table('payment_transactions')->insert(['payment_intent_id' => $intent, 'provider' => 'fake', 'transaction_type' => 'charge', 'status' => 'failed']);
    DB::table('payment_webhook_events')->insert(['payment_intent_id' => $intent, 'provider' => 'fake', 'event_type' => 'failed', 'event_key' => Str::uuid()->toString()]);
    $review = app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00');
    expect($review['candidate_delete_ids']['payment_intents'])->toBe([$intent]);
    DB::table('payment_intents')->where('id', $intent)->update(['provider_schedule_ref' => 'retained-synthetic-schedule']);
    expect(app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00')['candidate_delete_ids']['payment_intents'])->toBe([]);
    DB::table('payment_intents')->where('id', $intent)->update(['provider_schedule_ref' => null]);
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertSuccessful();
    foreach (['payment_intents', 'payment_transactions', 'payment_webhook_events'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('retains new physical and logical dependencies instead of relying on null-on-delete', function (bool $foreignKey) {
    $payment = resetAttempt();
    \Illuminate\Support\Facades\Schema::create('reset_retained_fixture', function ($table) use ($foreignKey) {
        $table->id();
        $table->unsignedBigInteger('payment_id')->nullable();
        if ($foreignKey) {
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        }
    });
    try {
        DB::table('reset_retained_fixture')->insert(['payment_id' => $payment->id]);
        $review = app(LegacyPaymentHistoryReset::class)->inspect('2021-01-01 00:00:00');
        expect($review['candidate_delete_ids']['payments'])->toBe([]);
        $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertSuccessful();
        expect(DB::table('reset_retained_fixture')->value('payment_id'))->toBe($payment->id)->and($payment->fresh())->not->toBeNull();
    } finally {
        \Illuminate\Support\Facades\Schema::drop('reset_retained_fixture');
    }
})->with([false, true]);

it('refuses active paid storage and retains paid fulfilled evidence without fabrication', function (bool $storage) {
    $payment = resetAttempt();
    if ($storage) {
        $plan = \App\Models\StoragePlan::create(['code' => 'reset-paid-fixture', 'name' => 'Paid storage fixture', 'quota_mb' => 1024, 'price_iqd' => 1000, 'is_active' => true]);
        \App\Models\CustomerStorageSubscription::create(['customer_id' => $payment->customer_id, 'storage_plan_id' => $plan->id, 'status' => 'active']);
    } else {
        $payment->update(['status' => 'paid', 'internal_status' => 'applied', 'paid_at' => now(), 'fulfilled_at' => now()]);
    }
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertFailed();
    expect($payment->fresh())->not->toBeNull()->and(DB::table('subscription_credit_allocations')->count())->toBe(0);
})->with([false, true]);

it('refuses unresolved internal state even when the public payment status is closed', function () {
    $payment = resetAttempt();
    DB::table('payments')->where('id', $payment->id)->update(['internal_status' => 'paid_pending_application']);
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertFailed();
    expect(Payment::count())->toBe(1)->and(AdminAuditEvent::count())->toBe(0);
});

it('refuses a paid legacy intent that has not been applied', function () {
    $payment = resetAttempt();
    DB::table('payment_intents')->insert(['uuid' => Str::uuid(), 'customer_id' => $payment->customer_id,
        'provider' => 'fake', 'purpose_type' => 'service_plan', 'status' => 'paid', 'base_amount_iqd' => 1000, 'gross_amount_iqd' => 1000,
        'idempotency_key' => Str::uuid(), 'merchant_transaction_id' => Str::uuid(), 'paid_at' => now(), 'created_at' => '2020-01-01 00:00:00']);
    $this->artisan('billing:reset-legacy-payment-history', resetOptions())->assertFailed();
    expect(DB::table('payment_intents')->count())->toBe(1)->and(Payment::count())->toBe(1);
});
