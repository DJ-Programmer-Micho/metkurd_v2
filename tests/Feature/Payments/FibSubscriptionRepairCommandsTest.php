<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditLedger;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Notifications\Payments\TelegramSubscriptionLifecycleAlert;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    app()->setLocale('en');

    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.callback_base_url', 'https://metkurd.test');
    config()->set('fib.callback_secret', 'fib-callback-secret');
    config()->set('fib.callback_secret_header', 'x-callback-secret');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
    config()->set('services.telegram-bot-api.groups.checkout', '-5210001111111');
    config()->set('services.telegram-bot-api.groups.payment', '-1000002222222');

    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function fibRepairCustomer(string $prefix = 'fib_repair'): Customer
{
    return Customer::create([
        'username' => $prefix.'_'.Str::lower(Str::random(8)),
        'email' => $prefix.'-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

function fibRepairStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq'.$path;
}

function fibRepairSubscriptionStatusResponse(string $subscriptionId, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'title' => 'MET KURD Subscription',
        'description' => 'Recurring checkout',
        'monetaryValue' => [
            'amount' => '25000',
            'currency' => 'IQD',
        ],
        'interval' => 'P1M',
        'trialPeriod' => null,
        'status' => $status,
        'paymentStatus' => 'PAID',
        'validUntil' => '2026-06-01T10:15:00Z',
        'activeUntil' => '2026-07-01T10:15:00Z',
        'lastPaymentAt' => '2026-06-01T10:05:00Z',
        'appLink' => 'https://fib.iq/app/'.$subscriptionId,
    ], $overrides);
}

function fibRepairPayment(Customer $customer, ServicePlan $plan, string $subscriptionId, array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'REPAIR-'.strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => $subscriptionId,
        'amount' => $plan->priceIqdForCycle('monthly'),
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'purchase_snapshot' => [
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
            'renewal_strategy' => 'provider_schedule',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $plan->id,
    ], $overrides));
}

function fibRepairPromoteCustomerToPremium(Customer $customer, ServicePlan $plan): CustomerServiceSubscription
{
    return app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'admin_manual_fix',
        'billing_cycle' => 'monthly',
        'reset_wallet_balances' => true,
    ]);
}

it('dry runs fib subscription cancellation without calling the provider cancel endpoint', function () {
    Http::preventStrayRequests();

    $customer = fibRepairCustomer('cancel_dry_run');
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $premiumPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $oldPayment = fibRepairPayment($customer, $studentPlan, 'fib-old-student-dry-123');
    $newPayment = fibRepairPayment($customer, $premiumPlan, 'fib-new-premium-dry-456');

    Http::fake([
        fibRepairStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-old-student-dry-123') => Http::response(
            fibRepairSubscriptionStatusResponse('fib-old-student-dry-123', 'ACTIVE'),
            200
        ),
    ]);

    $this->artisan('payments:fib:cancel-subscription', [
        'payment' => $oldPayment->id,
        '--customer' => $customer->id,
        '--reason' => 'admin_superseded_by_premium',
        '--superseded-by' => $newPayment->id,
    ])->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->url() === fibRepairStageUrl('/protected/v1/subscriptions/fib-old-student-dry-123/cancel'));

    $oldPayment = $oldPayment->fresh();

    expect($oldPayment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($oldPayment->internal_status)->toBe(PaymentInternalStatus::AWAITING_CUSTOMER_ACTION)
        ->and($oldPayment->canceled_at)->toBeNull()
        ->and(data_get($oldPayment->meta, 'superseded_by_payment_id'))->toBeNull();
});

it('executes fib subscription cancellation and marks the old row superseded without a refund', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-06-16 10:00:00');

    $customer = fibRepairCustomer('cancel_execute');
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $premiumPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $oldPayment = fibRepairPayment($customer, $studentPlan, 'fib-old-student-exec-123');
    $newPayment = fibRepairPayment($customer, $premiumPlan, 'fib-new-premium-exec-456');

    $serviceSubscription = CustomerServiceSubscription::create([
        'customer_id' => $customer->id,
        'payment_id' => $oldPayment->id,
        'service_plan_id' => $studentPlan->id,
        'status' => 'active',
        'source' => 'fib',
        'provider_ref' => $oldPayment->providerReference(),
        'starts_at' => now()->subMonth(),
        'auto_renew' => true,
        'renewal_strategy' => 'provider_schedule',
        'cycle_started_on' => now()->subMonth()->toDateString(),
        'cycle_ends_on' => now()->addDays(10)->toDateString(),
        'next_renewal_on' => now()->addDays(10)->toDateString(),
    ]);

    Http::fake([
        fibRepairStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-old-student-exec-123') => Http::response(
            fibRepairSubscriptionStatusResponse('fib-old-student-exec-123', 'ACTIVE'),
            200
        ),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-old-student-exec-123/cancel') => Http::response(null, 204),
    ]);

    $this->artisan('payments:fib:cancel-subscription', [
        'payment' => $oldPayment->id,
        '--customer' => $customer->id,
        '--reason' => 'admin_superseded_by_premium',
        '--superseded-by' => $newPayment->id,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === fibRepairStageUrl('/protected/v1/subscriptions/fib-old-student-exec-123/cancel'));

    $oldPayment = $oldPayment->fresh();
    $serviceSubscription = $serviceSubscription->fresh();

    expect($oldPayment->status)->toBe(PaymentStatus::CANCELED)
        ->and($oldPayment->internal_status)->toBe(PaymentInternalStatus::CANCELED)
        ->and($oldPayment->canceled_at)->not->toBeNull()
        ->and(data_get($oldPayment->meta, 'superseded_by_payment_id'))->toBe($newPayment->id)
        ->and(data_get($oldPayment->meta, 'superseded_by_fib_subscription_id'))->toBe($newPayment->fib_subscription_id)
        ->and(data_get($oldPayment->meta, 'cancel_reason'))->toBe('admin_superseded_by_premium')
        ->and(data_get($oldPayment->cancel_response, 'result'))->toBe('cancel_requested')
        ->and(PaymentEvent::query()
            ->where('payment_id', $oldPayment->id)
            ->where('event_type', 'operator_subscription_canceled')
            ->exists())->toBeTrue();

    expect($serviceSubscription->status)->toBe('ended')
        ->and($serviceSubscription->auto_renew)->toBeFalse()
        ->and($serviceSubscription->canceled_at)->not->toBeNull();
});

it('refuses to cancel the latest active-looking fib subscription without force', function () {
    Http::fake();

    $customer = fibRepairCustomer('cancel_current_block');
    $premiumPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $payment = fibRepairPayment($customer, $premiumPlan, 'fib-current-active-block-123');

    $this->artisan('payments:fib:cancel-subscription', [
        'payment' => $payment->id,
        '--customer' => $customer->id,
    ])->assertFailed();

    Http::assertNothingSent();

    expect($payment->fresh()->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION);
});

it('dry runs fib subscription reconciliation without refilling credits or canceling the superseded row', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-06-16 11:00:00');

    $customer = fibRepairCustomer('reconcile_dry_run');
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $premiumPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $activePremium = fibRepairPromoteCustomerToPremium($customer, $premiumPlan);
    $supersededPayment = fibRepairPayment($customer, $studentPlan, 'fib-superseded-dry-123');
    $targetPayment = fibRepairPayment($customer, $premiumPlan, 'fib-target-dry-456');

    $ledgerCountBefore = CreditLedger::query()->count();
    $appWalletBefore = $customer->fresh()->wallet()->firstOrFail()->only([
        'balance_credits',
        'subscription_balance_credits',
        'addon_balance_credits',
        'current_cycle_key',
    ]);
    $apiWalletBefore = $customer->fresh()->apiWallet()->firstOrFail()->only([
        'balance_credits',
        'subscription_balance_credits',
        'addon_balance_credits',
        'current_cycle_key',
    ]);

    Http::fake([
        fibRepairStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-target-dry-456') => Http::response(
            fibRepairSubscriptionStatusResponse('fib-target-dry-456', 'ACTIVE'),
            200
        ),
    ]);

    $this->artisan('payments:fib:reconcile-subscription', [
        'payment' => $targetPayment->id,
        '--customer' => $customer->id,
        '--manual-correction-already-applied' => true,
        '--keep-provider-active' => true,
        '--cancel-superseded' => $supersededPayment->id,
    ])->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->url() === fibRepairStageUrl('/protected/v1/subscriptions/fib-superseded-dry-123/cancel'));

    $targetPayment = $targetPayment->fresh();
    $activePremium = $activePremium->fresh();
    $customer = $customer->fresh();

    expect($targetPayment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($targetPayment->internal_status)->toBe(PaymentInternalStatus::AWAITING_CUSTOMER_ACTION)
        ->and($targetPayment->fulfilled_at)->toBeNull()
        ->and($activePremium->payment_id)->toBeNull()
        ->and(CreditLedger::query()->count())->toBe($ledgerCountBefore)
        ->and($customer->wallet()->firstOrFail()->only(['balance_credits', 'subscription_balance_credits', 'addon_balance_credits', 'current_cycle_key']))->toBe($appWalletBefore)
        ->and($customer->apiWallet()->firstOrFail()->only(['balance_credits', 'subscription_balance_credits', 'addon_balance_credits', 'current_cycle_key']))->toBe($apiWalletBefore);
});

it('reconciles a provider-paid premium subscription without duplicate credits and can cancel the superseded row', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-06-16 12:00:00');

    $customer = fibRepairCustomer('reconcile_execute');
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $premiumPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $activePremium = fibRepairPromoteCustomerToPremium($customer, $premiumPlan);
    $supersededPayment = fibRepairPayment($customer, $studentPlan, 'fib-superseded-exec-123');
    $targetPayment = fibRepairPayment($customer, $premiumPlan, 'fib-target-exec-456');

    $ledgerCountBefore = CreditLedger::query()->count();
    $appWalletBefore = $customer->fresh()->wallet()->firstOrFail()->only([
        'balance_credits',
        'subscription_balance_credits',
        'addon_balance_credits',
        'current_cycle_key',
    ]);
    $apiWalletBefore = $customer->fresh()->apiWallet()->firstOrFail()->only([
        'balance_credits',
        'subscription_balance_credits',
        'addon_balance_credits',
        'current_cycle_key',
    ]);

    Http::fake([
        fibRepairStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-target-exec-456') => Http::response(
            fibRepairSubscriptionStatusResponse('fib-target-exec-456', 'ACTIVE', [
                'lastPaymentAt' => '2026-06-16T11:55:00Z',
                'activeUntil' => '2026-07-16T11:55:00Z',
            ]),
            200
        ),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-superseded-exec-123') => Http::response(
            fibRepairSubscriptionStatusResponse('fib-superseded-exec-123', 'ACTIVE'),
            200
        ),
        fibRepairStageUrl('/protected/v1/subscriptions/fib-superseded-exec-123/cancel') => Http::response(null, 204),
    ]);

    $this->artisan('payments:fib:reconcile-subscription', [
        'payment' => $targetPayment->id,
        '--customer' => $customer->id,
        '--manual-correction-already-applied' => true,
        '--keep-provider-active' => true,
        '--cancel-superseded' => $supersededPayment->id,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === fibRepairStageUrl('/protected/v1/subscriptions/fib-superseded-exec-123/cancel'));
    Http::assertNotSent(fn ($request) => $request->url() === fibRepairStageUrl('/protected/v1/subscriptions/fib-target-exec-456/cancel'));

    $targetPayment = $targetPayment->fresh();
    $supersededPayment = $supersededPayment->fresh();
    $activePremium = $activePremium->fresh();
    $customer = $customer->fresh();

    expect($targetPayment->status)->toBe(PaymentStatus::PAID)
        ->and($targetPayment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($targetPayment->fulfilled_at)->not->toBeNull()
        ->and($targetPayment->paid_at?->copy()->utc()->toIso8601String())->toBe('2026-06-16T08:55:00+00:00')
        ->and(data_get($targetPayment->meta, 'manual_reconciliation'))->toBeTrue()
        ->and(data_get($targetPayment->meta, 'manual_correction_already_applied'))->toBeTrue()
        ->and(data_get($targetPayment->meta, 'no_credit_refill'))->toBeTrue()
        ->and(data_get($targetPayment->meta, 'old_superseded_payment_id'))->toBe($supersededPayment->id)
        ->and(PaymentEvent::query()
            ->where('payment_id', $targetPayment->id)
            ->where('event_type', 'operator_subscription_reconciled')
            ->exists())->toBeTrue();

    expect($supersededPayment->status)->toBe(PaymentStatus::CANCELED)
        ->and($supersededPayment->internal_status)->toBe(PaymentInternalStatus::CANCELED)
        ->and(data_get($supersededPayment->cancel_response, 'result'))->toBe('cancel_requested')
        ->and(data_get($supersededPayment->meta, 'superseded_by_payment_id'))->toBe($targetPayment->id);

    expect($activePremium->payment_id)->toBe($targetPayment->id)
        ->and($activePremium->source)->toBe(PaymentProvider::FIB->value)
        ->and($activePremium->provider_ref)->toBe($targetPayment->providerReference())
        ->and($activePremium->auto_renew)->toBeTrue()
        ->and($activePremium->renewal_strategy)->toBe('provider_schedule')
        ->and(data_get($activePremium->meta, 'manual_reconciliation'))->toBeTrue()
        ->and(Carbon::parse((string) data_get($activePremium->meta, 'provider_last_payment_at'))->utc()->toIso8601String())->toBe('2026-06-16T11:55:00+00:00');

    expect(CreditLedger::query()->count())->toBe($ledgerCountBefore)
        ->and($customer->wallet()->firstOrFail()->only(['balance_credits', 'subscription_balance_credits', 'addon_balance_credits', 'current_cycle_key']))->toBe($appWalletBefore)
        ->and($customer->apiWallet()->firstOrFail()->only(['balance_credits', 'subscription_balance_credits', 'addon_balance_credits', 'current_cycle_key']))->toBe($apiWalletBefore);

    Notification::assertSentOnDemand(
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification): bool {
            $title = strtolower((string) data_get($notification->toArray(new AnonymousNotifiable), 'title', ''));

            return str_contains($title, 'reconciled manually');
        }
    );
});
