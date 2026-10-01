<?php

use App\Domain\Payments\Actions\SyncFibCheckoutStatus;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Phase 1/2 cases assert safe behavior. Event-volume characterization remains deferred to Phase 3.
beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    Storage::fake('s3');
    Carbon::setTestNow('2026-05-01 10:00:00');
    $this->seed(); // Isolated fixtures only; never the application database.
});

afterEach(function () {
    Carbon::setTestNow();
});

function actionLifecycleFixture(bool $fulfilled = true, bool $compact = false): array
{
    $customer = Customer::create([
        'username' => 'audit_'.Str::lower(Str::random(12)),
        'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => 'fib',
        'purchase_type' => 'plan_subscription',
        'payment_mode' => 'recurring',
        'provider_object_type' => 'subscription',
        'status' => $fulfilled ? 'paid' : 'awaiting_customer_action',
        'internal_status' => $fulfilled ? 'applied' : 'awaiting_customer_action',
        'fulfilled_at' => $fulfilled ? now() : null,
        'local_reference' => (string) Str::uuid(),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => (string) Str::uuid(),
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $plan->id,
        'amount' => 26500,
        'currency' => 'IQD',
        'paid_at' => $fulfilled ? now() : null,
        'last_payment_at' => $fulfilled ? now() : null,
        'active_until' => '2026-06-01 10:00:00',
        'provider_subscription_status' => 'ACTIVE',
        'purchase_snapshot' => ['billing_cycle' => 'monthly', 'name' => $plan->name, 'code' => $plan->code],
        'meta' => $compact ? ['persistence_version' => 2] : null,
    ]);
    $subscription = $fulfilled ? app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fib',
        'provider_ref' => $payment->fib_subscription_id,
        'payment_id' => $payment->id,
        'renewal_strategy' => 'provider_schedule',
        'active_until' => $payment->active_until,
        'provider_last_payment_at' => $payment->last_payment_at->toIso8601String(),
        'billing_cycle' => 'monthly',
        'provider_cycle_key' => $payment->providerRecurringCycleKey(),
    ]) : null;

    actionLifecycleStoredCollection($payment);

    return [$customer, $plan, $payment, $subscription];
}

function actionLifecycleStoredCollection(Payment $payment): void
{
    $payment->refresh();
    $payment->update(['status_response' => [
        'id' => $payment->fib_subscription_id, 'status' => $payment->provider_subscription_status,
        'lastPaymentAt' => $payment->last_payment_at?->toIso8601String(),
        'activeUntil' => $payment->active_until?->toIso8601String(),
    ]]);
}

function actionLifecycleReceipt(Payment $payment, string $start = '2026-06-01 10:00:00', string $end = '2026-07-01 10:00:00', string $status = 'ACTIVE'): void
{
    $payment->update(['last_payment_at' => $start, 'active_until' => $end, 'provider_subscription_status' => $status]);
    actionLifecycleStoredCollection($payment);
}

function actionLifecycleFinancialState(Customer $customer): array
{
    return [
        $customer->fresh()->wallet->getAttributes(), $customer->fresh()->apiWallet->getAttributes(),
        \App\Models\CreditLedger::where('customer_id', $customer->id)->count(),
        \App\Models\SubscriptionCreditAllocation::where('customer_id', $customer->id)->count(),
    ];
}

function actionLifecycleHttp(Payment $payment, string $status = 'ACTIVE', int $cancelCode = 202): void
{
    config(['fib.enabled' => true, 'payments.providers.fib.enabled' => true,
        'fib.profiles.subscription.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.subscription.client_id' => 'fixture-client',
        'fib.profiles.subscription.client_secret' => 'fixture-secret',
        'fib.http.retries' => 1, 'fib.http.retry_sleep_ms' => 1,
        'fib.callback_secret' => '']);
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'expires_in' => 3600]),
        '*/subscriptions/'.$payment->fib_subscription_id => Http::response([
            'id' => $payment->fib_subscription_id, 'status' => $status,
            'amount' => ['amount' => $payment->amount, 'currency' => $payment->currency],
            'lastPaymentAt' => $payment->last_payment_at?->toIso8601String(),
            'activeUntil' => $payment->active_until?->toIso8601String(),
        ]),
        '*/subscriptions/'.$payment->fib_subscription_id.'/cancel' => function () use ($payment, $cancelCode) {
            // Intent and audit precede the remote effect, even if that effect fails.
            expect(data_get($payment->fresh()->meta, 'provider_cancellation.requested_at'))->not->toBeNull();

            return Http::response([], $cancelCode);
        },
    ]);
}

it('commits cancellation before an empty-body POST and preserves access and finances through duplicate requests', function (bool $compact) {
    [$customer, $plan, $payment, $sub] = actionLifecycleFixture(compact: $compact);
    actionLifecycleHttp($payment);
    $state = actionLifecycleFinancialState($customer);
    $service = app(\App\Services\Billing\ScheduleServicePlanCancellation::class);
    $service->handle($customer);
    $service->handle($customer->fresh());
    expect(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('requested')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.provider_cancel_confirmed_at'))->toBeNull()
        ->and($sub->fresh()->auto_renew)->toBeFalse()
        ->and($sub->fresh()->ends_at->equalTo($payment->active_until))->toBeTrue()
        ->and($customer->fresh()->currentServicePlan()->id)->toBe($plan->id)
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
    $requests = Http::recorded(fn ($r) => str_ends_with($r->url(), '/cancel'));
    expect($requests)->toHaveCount(1);
    expect($requests->first()[0]->method())->toBe('POST')->and($requests->first()[0]->data())->toBe([]);
    Carbon::setTestNow('2026-06-01 10:00:00');
    app(\App\Services\Billing\ExpireSubscription::class)->handle($sub);
    expect($customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and($sub->fresh()->status)->toBe('ended')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
})->with([false, true]);

it('retains a failed cancellation intent and confirms it through bounded scheduler retry', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment, 'ACTIVE', 503);
    config(['fib.http.retries' => 3]);
    $state = actionLifecycleFinancialState($customer);
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    expect(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('pending')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.attempts'))->toBe(1)
        ->and($sub->fresh()->status)->toBe('active')
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
    expect(Http::recorded(fn ($r) => str_ends_with($r->url(), '/cancel')))->toHaveCount(1);
    Carbon::setTestNow(now()->addMinutes(6));
    // Simulate provider recovery through a mock on the same authenticated service contract.
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'CANCELLED',
            'lastPaymentAt' => $payment->last_payment_at->toIso8601String(), 'activeUntil' => $payment->active_until->toIso8601String()]));
    $this->artisan('payments:reconcile-fib-cancellations', ['--customer-id' => $customer->id])->assertSuccessful();
    expect(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('confirmed')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.provider_cancel_pending'))->toBeFalse()
        ->and($payment->fresh()->provider_subscription_status)->toBe('CANCELLED')
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
    $count = Http::recorded()->count();
    $this->artisan('payments:reconcile-fib-cancellations')->assertSuccessful();
    app(SyncFibCheckoutStatus::class)->handle($payment, 'scheduled_sub_renewal');
    expect(Http::recorded()->count())->toBe($count);
});

it('uses a callback only as a wakeup and reads authenticated cancelled state while retaining paid access', function () {
    [$customer, $plan, $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment, 'CANCELLED');
    $state = actionLifecycleFinancialState($customer);
    $this->postJson(route('payments.fib.subscription.callback'), ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'])->assertAccepted();
    expect($sub->fresh()->auto_renew)->toBeFalse()
        ->and(data_get($sub->fresh()->meta, 'cancel_source'))->toBe('provider_app')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('confirmed')
        ->and($customer->fresh()->currentServicePlan()->id)->toBe($plan->id)
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/cancel'));
});

it('rejects coverage extension without a new verified collection', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    $state = actionLifecycleFinancialState($customer);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
            'lastPaymentAt' => $payment->last_payment_at->toIso8601String(), 'activeUntil' => '2026-07-01T10:00:00+03:00']));
    app(SyncFibCheckoutStatus::class)->handle($payment);
    expect($payment->fresh()->active_until->equalTo($payment->active_until))->toBeTrue()
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
});

it('expires paid access despite a provider outage and retains a remote termination request', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment, 'ACTIVE', 503);
    $state = actionLifecycleFinancialState($customer);
    Carbon::setTestNow('2026-06-01 10:00:00');
    app(\App\Services\Billing\ExpireSubscription::class)->handle($sub);
    expect($customer->fresh()->currentServicePlan()->code)->toBe('free')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.reason_code'))->toBe('failed_renewal')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.provider_cancel_pending'))->toBeTrue()
        ->and($payment->fresh()->provider_subscription_status)->toBe('ACTIVE')
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
});

it('sends a late collection after cancellation to review without extending access or reactivating', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment);
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    $state = actionLifecycleFinancialState($customer);
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
            'lastPaymentAt' => '2026-06-01T10:00:00+03:00', 'activeUntil' => '2026-07-01T10:00:00+03:00']));
    Carbon::setTestNow('2026-06-01 10:00:00');
    app(SyncFibCheckoutStatus::class)->handle($payment, 'callback', ['id' => $payment->fib_subscription_id]);
    expect($payment->fresh()->internal_status->value)->toBe('requires_review')
        ->and($payment->fresh()->active_until->equalTo($payment->active_until))->toBeTrue()
        ->and($sub->fresh()->auto_renew)->toBeFalse()
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
});

it('enforces customer ownership before recording cancellation', function () {
    [, , $payment, $sub] = actionLifecycleFixture();
    [$other] = actionLifecycleFixture();
    expect(fn () => app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->customerCancel($other, $sub))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(data_get($payment->fresh()->meta, 'provider_cancellation'))->toBeNull();
    Http::assertNothingSent();
});

it('does not send a cancellation when the enclosing plan transaction rolls back', function () {
    [, , $payment] = actionLifecycleFixture();
    expect(function () use ($payment) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($payment) {
            app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->request($payment, 'plan_switch');
            Http::assertNothingSent();
            throw new RuntimeException('Fixture rollback');
        });
    })->toThrow(RuntimeException::class, 'Fixture rollback');
    expect(data_get($payment->fresh()->meta, 'provider_cancellation'))->toBeNull();
    Http::assertNothingSent();
});

it('does not repeat an accepted POST while waiting for authenticated cancellation confirmation', function () {
    [$customer, , $payment] = actionLifecycleFixture();
    actionLifecycleHttp($payment);
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    Carbon::setTestNow(now()->addMinutes(6));
    $this->artisan('payments:reconcile-fib-cancellations')->assertSuccessful();
    expect(Http::recorded(fn ($r) => str_ends_with($r->url(), '/cancel')))->toHaveCount(1)
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('requested')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.provider_cancel_pending'))->toBeTrue();
});

it('retains retryable intent on connection failure without claiming provider confirmation', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()
        ->andThrow(new \Illuminate\Http\Client\ConnectionException('Fixture unavailable'));
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    expect(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('pending')
        ->and(data_get($payment->fresh()->meta, 'provider_cancellation.attempts'))->toBe(1)
        ->and($sub->fresh()->status)->toBe('active');
    Http::assertNothingSent();
});

it('does not cancel a mismatched authenticated provider object', function () {
    [$customer, , $payment] = actionLifecycleFixture();
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => (string) Str::uuid(), 'status' => 'ACTIVE']));
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    expect(data_get($payment->fresh()->meta, 'provider_cancellation.state'))->toBe('pending');
    Http::assertNothingSent();
});

it('renders separate renewal and access statuses in each locale using owned local reads', function (string $locale) {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment);
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    $httpCount = Http::recorded()->count();
    config(['metkurd_v2.enabled' => true]);
    $customer->profile()->create(['first_name' => 'Fixture', 'last_name' => 'Customer']);
    $this->actingAs($customer, 'app');
    foreach (['billing', 'subscription-plans'] as $page) {
        $this->get(route('app.v2.'.$page, ['locale' => $locale]))->assertOk()
            ->assertSee(__('subscription_lifecycle.requested', [], $locale))
            ->assertSee(__('subscription_lifecycle.awaiting_provider', [], $locale))
            ->assertDontSee('subscription_lifecycle.');
    }
    expect(Http::recorded()->count())->toBe($httpCount);
})->with(['en', 'ar', 'ku']);

it('shows paid remote cancellation in Admin review without changing local evidence', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    actionLifecycleHttp($payment, 'ACTIVE', 503);
    app(\App\Services\Billing\ScheduleServicePlanCancellation::class)->handle($customer);
    $operator = \App\Models\User::forceCreate(['name' => 'Fixture operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.finance', 'admin.reconcile']]);
    $this->actingAs($operator, 'admin');
    $reader = app(\App\Services\Admin\AdminOperations::class);
    $count = Http::recorded()->count();
    $state = actionLifecycleFinancialState($customer);
    expect($reader->query('payments', ['queue' => 'payment_review', 'customer' => $customer->id])->pluck('id')->all())->toContain($payment->id)
        ->and(data_get($reader->row($payment->fresh()), 'provider_cancellation.state'))->toBe('pending')
        ->and(data_get($reader->row($sub->fresh()), 'access_status'))->toBe('active')
        ->and(data_get($reader->row($sub->fresh()), 'renewal_status'))->toBe('pending')
        ->and(actionLifecycleFinancialState($customer))->toBe($state)
        ->and(Http::recorded()->count())->toBe($count);
});

it('keeps the replacement authoritative when a superseded provider later reports a new ACTIVE collection', function () {
    [$customer, , $payment, $old] = actionLifecycleFixture();
    actionLifecycleHttp($payment, 'ACTIVE', 503);
    $next = ServicePlan::where('code', 'student')->firstOrFail();
    $new = app(PlanSwitcher::class)->switchServicePlan($customer, $next->id);
    app(\App\Services\Billing\ProviderSubscriptionCancellation::class)->request($payment, 'plan_switch', null, ['subscription_id' => $new->id]);
    $state = actionLifecycleFinancialState($customer);
    expect(app(\App\Services\Billing\SubscriptionLifecycleView::class)->customer($customer)['old_pending'])->toBe('Pro');
    $this->partialMock(FibSubscriptionService::class)->shouldReceive('getStatus')->once()->andReturn(
        FibSubscriptionStatusData::fromArray(['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE',
            'lastPaymentAt' => '2026-06-01T10:00:00+03:00', 'activeUntil' => '2026-07-01T10:00:00+03:00']));
    app(SyncFibCheckoutStatus::class)->handle($payment, 'callback', ['id' => $payment->fib_subscription_id]);
    expect($customer->fresh()->currentServicePlanId())->toBe($next->id)
        ->and($old->fresh()->status)->toBe('ended')
        ->and($payment->fresh()->internal_status->value)->toBe('requires_review')
        ->and(actionLifecycleFinancialState($customer))->toBe($state);
});

it('shows a renewal issue only for persisted rejected evidence and never for network uncertainty', function () {
    [$customer, , $payment, $sub] = actionLifecycleFixture();
    $reader = app(\App\Services\Billing\SubscriptionLifecycleView::class);
    expect($reader->customer($customer)['renewal'])->toBe('renewing');
    $sub->update(['meta' => array_merge($sub->meta, ['provider_status' => 'REJECTED'])]);
    expect($reader->customer($customer)['renewal'])->toBe('issue');
    Http::assertNothingSent();
});
