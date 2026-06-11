<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Exceptions\FibApiException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Notifications\Landing\TelegramPayment;
use App\Notifications\Payments\TelegramSubscriptionLifecycleAlert;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use App\Services\Payments\PaymentFeeCalculator;
use Carbon\Carbon;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    Notification::fake();

    fibFlowConfigure();
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function fibFlowConfigure(): void
{
    app()->setLocale('en');

    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.callback_base_url', 'https://metkurd.test');
    config()->set('fib.callback_secret', 'fib-callback-secret');
    config()->set('fib.callback_secret_header', 'x-callback-secret');
    config()->set('fib.payment.category', 'ECOMMERCE');
    config()->set('fib.payment.expires_in', 'PT1H');
    config()->set('fib.payment.refundable_for', 'PT48H');
    config()->set('fib.subscription.expires_in', 'PT1H');
    config()->set('fib.subscription.trial_period', null);
    config()->set('fib.subscription.hourly_testing_enabled', false);
    config()->set('fib.subscription.intervals.monthly', 'P1M');
    config()->set('fib.subscription.intervals.yearly', 'P1Y');
    config()->set('fib.subscription.intervals.hourly', 'PT1H');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
    config()->set('services.telegram-bot-api.groups.checkout', '-5210001111111');
    config()->set('services.telegram-bot-api.groups.payment', '-1000002222222');
}

function fibFlowEnableHourlyTesting(): void
{
    config()->set('fib.subscription.hourly_testing_enabled', true);
    config()->set('fib.subscription.intervals.hourly', 'PT1H');
}

function fibFlowCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "fib_user_{$suffix}",
        'email' => $email ?? "fib-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function fibFlowStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq'.$path;
}

function fibFlowCreateResponse(string $paymentId, array $overrides = []): array
{
    return array_merge([
        'paymentId' => $paymentId,
        'readableCode' => 'CODE-123',
        'qrCode' => 'data:image/png;base64,fake-qr',
        'personalAppLink' => 'https://fib.iq/personal/'.$paymentId,
        'businessAppLink' => 'https://fib.iq/business/'.$paymentId,
        'corporateAppLink' => 'https://fib.iq/corporate/'.$paymentId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ], $overrides);
}

function fibFlowStatusResponse(string $paymentId, string $status, array $overrides = []): array
{
    return array_merge([
        'paymentId' => $paymentId,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'amount' => [
            'amount' => '26500',
            'currency' => 'IQD',
        ],
    ], $overrides);
}

function fibFlowSubscriptionCreateResponse(string $subscriptionId, array $overrides = []): array
{
    return array_merge([
        'subscriptionId' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'qrCode' => 'data:image/png;base64,fake-subscription-qr',
        'appLink' => 'https://fib.iq/app/'.$subscriptionId,
        'validUntil' => '2026-05-01T10:15:00Z',
    ], $overrides);
}

function fibFlowSubscriptionStatusResponse(string $subscriptionId, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'title' => 'MET KURD Subscription',
        'description' => 'Recurring checkout',
        'monetaryValue' => [
            'amount' => '26500',
            'currency' => 'IQD',
        ],
        'interval' => 'P1M',
        'trialPeriod' => null,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'activeUntil' => '2026-06-01T10:15:00Z',
        'lastPaymentAt' => '2026-05-01T10:05:00Z',
        'appLink' => 'https://fib.iq/app/'.$subscriptionId,
    ], $overrides);
}

function fibFlowGrantPaidPlan(Customer $customer): ServicePlan
{
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

function fibFlowSyntheticPayment(Customer $customer, array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::PENDING,
        'internal_status' => PaymentInternalStatus::PENDING,
        'local_reference' => 'SYN-'.strtoupper(Str::random(10)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'fib-synthetic-'.Str::lower(Str::random(12)),
        'amount' => 1000,
        'currency' => 'IQD',
    ], $overrides));
}

it('skips telegram payment notifications safely when checkout and payment groups are not configured', function () {
    config()->set('services.telegram-bot-api.groups.checkout', '');
    config()->set('services.telegram-bot-api.groups.payment', '');

    $customer = fibFlowCustomer();
    \Stevebauman\Location\Facades\Location::shouldReceive('get')->andReturn(false);

    app(\App\Support\TelegramSubscriptionLifecycleNotifier::class)->sendCheckout(
        'FIB recurring checkout created',
        ['Type' => 'service_subscription']
    );

    \App\Support\TelegramPaymentNotifier::send(
        $customer,
        'Subscription Plan',
        'Pro',
        ['Reference' => 'TEST-REF-001']
    );

    Notification::assertNothingSent();
});

it('creates a plan subscription checkout and stores fib subscription details', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => function ($request) {
            return str_contains((string) data_get($request->data(), 'client_id'), 'subscription')
                ? Http::response(['access_token' => 'fib-subscription-access-token', 'expires_in' => 60], 200)
                : Http::response(['access_token' => 'fib-access-token', 'expires_in' => 60], 200);
        },
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly')->fresh();
    $quote = app(PaymentFeeCalculator::class)->quote('fib', $plan->priceIqdForCycle('yearly'));

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and((int) round((float) $payment->amount))->toBe((int) $quote['gross_amount_iqd'])
        ->and($payment->fib_subscription_id)->toBe('fib-plan-sub-123')
        ->and($payment->fib_payment_id)->toBeNull()
        ->and($payment->provider_interval)->toBe('P1Y')
        ->and($payment->readable_code)->toBe('SUB-CODE-123')
        ->and($payment->valid_until)->not->toBeNull()
        ->and(data_get($payment->create_payload, 'monetaryValue.currency'))->toBe('IQD')
        ->and((int) data_get($payment->purchase_snapshot, 'fee_quote.net_amount_iqd'))->toBe($plan->priceIqdForCycle('yearly'))
        ->and(data_get($payment->purchase_snapshot, 'renewal_strategy'))->toBe('provider_schedule');

    Http::assertSent(function ($request) use ($quote) {
        if ($request->url() !== fibFlowStageUrl('/protected/v1/subscriptions')) {
            return true;
        }

        return data_get($request->data(), 'monetaryValue.amount') === (string) $quote['gross_amount_iqd']
            && data_get($request->data(), 'monetaryValue.currency') === 'IQD'
            && data_get($request->data(), 'interval') === 'P1Y'
            && filled(data_get($request->data(), 'statusCallbackUrl'));
    });
});

it('routes plan subscription checkout-created telegram notifications to TELEGRAM_GROUP_CHK', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $expectedCheckoutGroup = (string) config('services.telegram-bot-api.groups.checkout');

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => function ($request) {
            return str_contains((string) data_get($request->data(), 'client_id'), 'subscription')
                ? Http::response(['access_token' => 'fib-subscription-access-token', 'expires_in' => 60], 200)
                : Http::response(['access_token' => 'fib-access-token', 'expires_in' => 60], 200);
        },
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-telegram-checkout-123'),
            201
        ),
    ]);

    app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    Notification::assertSentOnDemand(
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification, array $channels, $notifiable) use ($expectedCheckoutGroup): bool {
            $title = strtolower((string) data_get($notification->toArray(new AnonymousNotifiable), 'title', ''));

            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && str_contains($title, 'checkout created')
                && (string) $notifiable->routeNotificationFor('telegram') === $expectedCheckoutGroup;
        }
    );
});

it('creates an hourly test plan subscription checkout when hourly billing is enabled', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-subscription-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-plan-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly')->fresh();
    $quote = app(PaymentFeeCalculator::class)->quote('fib', $plan->priceIqdForCycle('monthly'));

    expect(data_get($payment->purchase_snapshot, 'billing_cycle'))->toBe('hourly')
        ->and($payment->provider_interval)->toBe('PT1H')
        ->and((int) round((float) $payment->amount))->toBe((int) $quote['gross_amount_iqd'])
        ->and(data_get($payment->purchase_snapshot, 'testing_cycle.testing_only'))->toBeTrue();

    Http::assertSent(function ($request) use ($quote) {
        if ($request->url() !== fibFlowStageUrl('/protected/v1/subscriptions')) {
            return true;
        }

        return data_get($request->data(), 'interval') === 'PT1H'
            && data_get($request->data(), 'monetaryValue.amount') === (string) $quote['gross_amount_iqd'];
    });
});

it('routes storage subscription checkout-created telegram notifications to TELEGRAM_GROUP_CHK', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();
    $expectedCheckoutGroup = (string) config('services.telegram-bot-api.groups.checkout');

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => function ($request) {
            return str_contains((string) data_get($request->data(), 'client_id'), 'subscription')
                ? Http::response(['access_token' => 'fib-subscription-access-token', 'expires_in' => 60], 200)
                : Http::response(['access_token' => 'fib-access-token', 'expires_in' => 60], 200);
        },
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-telegram-checkout-123'),
            201
        ),
    ]);

    app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    Notification::assertSentOnDemand(
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification, array $channels, $notifiable) use ($expectedCheckoutGroup): bool {
            $payload = $notification->toArray(new AnonymousNotifiable);
            $title = strtolower((string) data_get($payload, 'title', ''));
            $type = (string) data_get($payload, 'details.Type', '');

            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && str_contains($title, 'checkout created')
                && $type === 'storage_subscription'
                && (string) $notifiable->routeNotificationFor('telegram') === $expectedCheckoutGroup;
        }
    );
});

it('routes addon checkout-created telegram notifications to TELEGRAM_GROUP_CHK', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();
    $expectedCheckoutGroup = (string) config('services.telegram-bot-api.groups.checkout');

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-telegram-checkout-123'),
            201
        ),
    ]);

    app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);

    Notification::assertSentOnDemand(
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification, array $channels, $notifiable) use ($expectedCheckoutGroup): bool {
            $payload = $notification->toArray(new AnonymousNotifiable);
            $title = strtolower((string) data_get($payload, 'title', ''));
            $type = (string) data_get($payload, 'details.Type', '');

            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && str_contains($title, 'checkout created')
                && $type === 'addon_credits'
                && (string) $notifiable->routeNotificationFor('telegram') === $expectedCheckoutGroup;
        }
    );
});

it('marks the local subscription checkout as failed when fib subscription creation fails', function () {
    Log::spy();
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response([
            'traceId' => 'beed4fde9ac0f37f935ff34f60694a7e',
            'errors' => [[
                'code' => 'CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION',
                'title' => 'Subscription credentials rejected',
                'detail' => 'The configured client is not enabled for subscription APIs.',
            ]],
        ], 400),
    ]);

    expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly'))
        ->toThrow(FibApiException::class);

    $payment = Payment::query()->latest('id')->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fib_subscription_id)->toBeNull()
        ->and($payment->status_reason)->toContain('CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION')
        ->and($payment->status_reason)->toContain('traceId: beed4fde9ac0f37f935ff34f60694a7e')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_subscription_create_failed')
            ->exists())->toBeTrue();

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'FIB provider request failed'
                && data_get($context, 'event') === 'subscription_create_failed'
                && data_get($context, 'profile') === 'subscription'
                && data_get($context, 'provider_object_type') === 'subscription'
                && data_get($context, 'client_id') === 'fib-subscription-client'
                && data_get($context, 'trace_id') === 'beed4fde9ac0f37f935ff34f60694a7e'
                && data_get($context, 'error_codes') === ['CLIENT_CANNOT_BE_USED_FOR_SUBSCRIPTION'];
        })
        ->once();
});

it('updates storage subscription state from a validated callback and fulfills it exactly once', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-storage-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202)->assertJson([
        'ok' => true,
        'status' => 'accepted',
    ]);

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CustomerStorageSubscription::query()
            ->where('payment_id', $payment->id)
            ->where('storage_plan_id', $plan->id)
            ->exists())->toBeTrue()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'callback_processed')
            ->count())->toBe(1);

    Notification::assertSentOnDemand(
        TelegramPayment::class,
        function (TelegramPayment $notification, array $channels, $notifiable): bool {
            $payload = $notification->toArray(new AnonymousNotifiable);

            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && (string) data_get($payload, 'payment_type', '') === 'Storage Plan'
                && (string) $notifiable->routeNotificationFor('telegram') === (string) config('services.telegram-bot-api.groups.payment');
        }
    );

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee('Congrats! Your subscription was confirmed successfully.');
});

it('updates subscription state from a manual status refresh and redirects back to the fib checkout page', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-refresh-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-refresh-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-refresh-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->post(route('payments.fib.refresh', ['locale' => 'en', 'payment' => $payment]))
        ->assertRedirect(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('Success')
        ->assertSee('Congrats! Your subscription was confirmed successfully.');
});

it('renders automatic polling on pending fib checkout pages and keeps manual refresh as a fallback', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-live-page-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('data-payment-status-polling="active"', false)
        ->assertSee('data-payment-status-endpoint="', false)
        ->assertSee('data-payment-status-interval="5000"', false)
        ->assertSee('Automatic check is active.')
        ->assertSee('Refresh Status')
        ->assertSee('Complete Subscription In FIB');
});

it('auto-updates addon checkout status through the shared fib status endpoint without manual refresh', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-status-endpoint-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-status-endpoint-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-status-endpoint-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);

    $this->actingAs($customer, 'app')
        ->getJson(route('payments.fib.status', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertJson([
            'status' => 'paid',
            'is_terminal' => true,
            'is_success' => true,
        ])
        ->assertJsonPath('redirect_url', route('app.home', ['locale' => 'en']));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('auto-updates storage subscription checkout status through the shared fib status endpoint without manual refresh', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-status-endpoint-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-status-endpoint-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-storage-status-endpoint-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->actingAs($customer, 'app')
        ->getJson(route('payments.fib.status', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertJson([
            'status' => 'paid',
            'is_terminal' => true,
            'is_success' => true,
        ])
        ->assertJsonPath('redirect_url', route('app.home', ['locale' => 'en']));

    expect(CustomerStorageSubscription::query()
        ->where('payment_id', $payment->id)
        ->where('storage_plan_id', $plan->id)
        ->exists())->toBeTrue();
});

it('auto-updates plan subscription checkout status quickly via status polling even when callback is delayed', function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-delayed-callback-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-plan-delayed-callback-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-plan-delayed-callback-123', 'UNPAID', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-plan-delayed-callback-123', 'ACTIVE', [
                'activeUntil' => '2026-06-01T10:15:00Z',
                'lastPaymentAt' => '2026-05-01T10:05:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->getJson(route('payments.fib.status', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertJson([
            'status' => 'awaiting_customer_action',
            'is_terminal' => false,
            'is_success' => false,
        ])
        ->assertJsonPath('redirect_url', null);

    Carbon::setTestNow(now()->addSeconds(6));

    $this->actingAs($customer, 'app')
        ->getJson(route('payments.fib.status', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertJson([
            'status' => 'paid',
            'is_terminal' => true,
            'is_success' => true,
        ])
        ->assertJsonPath('redirect_url', route('app.home', ['locale' => 'en']));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);
});

it('ignores duplicate callbacks without double-fulfilling addon credits', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-duplicate-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-duplicate-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-duplicate-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payload = ['id' => $payment->fib_payment_id, 'status' => 'PAID'];
    $headers = ['x-callback-secret' => 'fib-callback-secret'];

    $this->postJson(route('payments.fib.callback'), $payload, $headers)->assertStatus(202);
    $this->postJson(route('payments.fib.callback'), $payload, $headers)->assertStatus(202);

    $payment = $payment->fresh();
    $wallet = $customer->fresh()->wallet()->first();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'payment_fulfilled')->count())->toBe(1)
        ->and(PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'callback_processed')->count())->toBe(1)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);

    Notification::assertSentOnDemandTimes(TelegramPayment::class, 1);
    Notification::assertSentOnDemand(
        TelegramPayment::class,
        function (TelegramPayment $notification, array $channels, $notifiable): bool {
            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && (string) $notifiable->routeNotificationFor('telegram') === (string) config('services.telegram-bot-api.groups.payment');
        }
    );
});

it('marks a late mismatched paid plan checkout for review instead of overriding the current subscription', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-student-review-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-student-review-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-student-review-123', 'ACTIVE', [
                'activeUntil' => '2026-06-01T10:15:00Z',
                'lastPaymentAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $studentPlan->id, 'monthly');

    app(PlanSwitcher::class)->switchServicePlan($customer->fresh(), $proPlan->id, [
        'provider' => 'admin_manual',
        'billing_cycle' => 'monthly',
        'provider_ref' => 'ADMIN-PRO-LOCK',
        'payment_method' => 'admin_manual',
    ]);

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202);

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::REQUIRES_REVIEW)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($payment->review_required_at)->not->toBeNull()
        ->and((string) $payment->mismatch_reason)->toContain((string) $proPlan->name)
        ->and((string) $payment->mismatch_reason)->toContain((string) $studentPlan->name)
        ->and($customer->fresh()->currentServicePlanId())->toBe($proPlan->id)
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'payment_requires_review')
            ->exists())->toBeTrue();
});

it('reconciles missed one-time fib callbacks and fulfills the payment safely', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-reconcile-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-reconcile-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-reconcile-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payment->forceFill([
        'last_status_checked_at' => now()->subMinutes(20),
    ])->save();

    $this->artisan('payments:reconcile-fib-payments', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])->assertSuccessful();

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('skips applied review and terminal one-time rows while reconciling only unresolved checkouts', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-reconcile-mixed-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-reconcile-mixed-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-reconcile-mixed-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $unresolved = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);

    $appliedByStatus = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-applied-status-123',
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $appliedByFulfillment = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-applied-fulfilled-123',
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::PAID_PENDING_APPLICATION,
        'paid_at' => now()->subHour(),
        'fulfilled_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $reviewRequired = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-review-123',
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'review_required_at' => now()->subMinutes(10),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $canceled = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-canceled-123',
        'status' => PaymentStatus::CANCELED,
        'internal_status' => PaymentInternalStatus::CANCELED,
        'canceled_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $expired = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-expired-123',
        'status' => PaymentStatus::EXPIRED,
        'internal_status' => PaymentInternalStatus::EXPIRED,
        'expired_at' => now()->subHour(),
        'failed_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $failed = fibFlowSyntheticPayment($customer, [
        'fib_payment_id' => 'fib-declined-123',
        'status' => PaymentStatus::FAILED,
        'internal_status' => PaymentInternalStatus::FAILED,
        'provider_payment_status' => 'DECLINED',
        'failed_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(30),
    ]);

    $appliedCheckedAt = $appliedByStatus->last_status_checked_at?->toIso8601String();
    $fulfilledCheckedAt = $appliedByFulfillment->last_status_checked_at?->toIso8601String();
    $reviewCheckedAt = $reviewRequired->last_status_checked_at?->toIso8601String();
    $canceledCheckedAt = $canceled->last_status_checked_at?->toIso8601String();
    $expiredCheckedAt = $expired->last_status_checked_at?->toIso8601String();
    $failedCheckedAt = $failed->last_status_checked_at?->toIso8601String();

    $this->artisan('payments:reconcile-fib-payments', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])
        ->expectsOutputToContain('Candidates scanned: 7')
        ->expectsOutputToContain('Skipped applied: 2')
        ->expectsOutputToContain('Skipped review-required: 1')
        ->expectsOutputToContain('Skipped terminal: 3')
        ->expectsOutputToContain('Processed unresolved: 1')
        ->expectsOutputToContain('Updated: 1')
        ->expectsOutputToContain('Failed: 0')
        ->assertSuccessful();

    expect($unresolved->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and($unresolved->fresh()->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($unresolved->fresh()->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $unresolved->id)->count())->toBe(1);

    expect($appliedByStatus->fresh()->last_status_checked_at?->toIso8601String())->toBe($appliedCheckedAt)
        ->and($appliedByFulfillment->fresh()->last_status_checked_at?->toIso8601String())->toBe($fulfilledCheckedAt)
        ->and($reviewRequired->fresh()->last_status_checked_at?->toIso8601String())->toBe($reviewCheckedAt)
        ->and($canceled->fresh()->last_status_checked_at?->toIso8601String())->toBe($canceledCheckedAt)
        ->and($expired->fresh()->last_status_checked_at?->toIso8601String())->toBe($expiredCheckedAt)
        ->and($failed->fresh()->last_status_checked_at?->toIso8601String())->toBe($failedCheckedAt)
        ->and(PaymentEvent::query()->where('payment_id', $appliedByStatus->id)->where('event_type', 'provider_status_checked')->exists())->toBeFalse()
        ->and(PaymentEvent::query()->where('payment_id', $reviewRequired->id)->where('event_type', 'provider_status_checked')->exists())->toBeFalse()
        ->and(PaymentEvent::query()->where('payment_id', $failed->id)->where('event_type', 'provider_status_checked')->exists())->toBeFalse();
});

it('fulfills a successful plan subscription checkout', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-plan-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-plan-fulfill-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-plan-fulfill-123', 'ACTIVE', [
                'interval' => 'P1Y',
                'activeUntil' => '2027-05-01T10:15:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'yearly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_plan_confirmation')->fresh();

    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->where('service_plan_id', $plan->id)
        ->firstOrFail();

    expect($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id)
        ->and($subscription->renewal_strategy)->toBe('provider_schedule')
        ->and($subscription->auto_renew)->toBeTrue()
        ->and(CreditOrder::query()
            ->where('payment_id', $payment->id)
            ->where('source_type', 'service_plan')
            ->exists())->toBeTrue();

    Notification::assertSentOnDemand(
        TelegramPayment::class,
        function (TelegramPayment $notification, array $channels, $notifiable): bool {
            $payload = $notification->toArray(new AnonymousNotifiable);

            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && (string) data_get($payload, 'payment_type', '') === 'Subscription Plan'
                && (string) $notifiable->routeNotificationFor('telegram') === (string) config('services.telegram-bot-api.groups.payment');
        }
    );

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee('Success');
});

it('extends a fulfilled hourly subscription when fib reports a successful renewal', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-renew-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-renew-sub-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-renew-sub-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-renew-sub-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T12:00:00Z',
                'lastPaymentAt' => '2026-05-01T11:00:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_initial_confirmation')->fresh();

    Carbon::setTestNow('2026-05-01 11:05:00');

    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_renewal_confirmation')->fresh();
    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->latest('id')
        ->firstOrFail();

    expect($payment->active_until?->toIso8601String())->toContain('2026-05-01T12:00:00')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->auto_renew)->toBeTrue()
        ->and(data_get($subscription->meta, 'period_ends_at'))->toContain('2026-05-01T12:00:00')
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id)
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'service_subscription_renewed')
            ->exists())->toBeTrue();

    Notification::assertSentOnDemand(
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification, ...$args): bool {
            $title = strtolower((string) data_get($notification->toArray(new AnonymousNotifiable), 'title', ''));

            return str_contains($title, 'renewed');
        }
    );
});

it('detects recurring renewal through the dedicated renewal reconciliation and records sync evidence', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-reconcile-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-reconcile-sub-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-reconcile-sub-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-reconcile-sub-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T12:00:00Z',
                'lastPaymentAt' => '2026-05-01T11:00:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_reconcile_initial')->fresh();

    Carbon::setTestNow('2026-05-01 11:05:00');

    $this->artisan('payments:reconcile-fib-subscription-renewals', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])->assertSuccessful();

    $payment = $payment->fresh();
    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->latest('id')
        ->firstOrFail();
    $renewalEvents = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'service_subscription_renewed')
        ->get();

    expect($payment->active_until?->toIso8601String())->toContain('2026-05-01T12:00:00')
        ->and($payment->last_payment_at?->toIso8601String())->toContain('2026-05-01T11:00:00')
        ->and(data_get($payment->meta, 'subscription_lifecycle.sync_source'))->toBe('scheduled_subscription_renewal_reconciliation')
        ->and(data_get($subscription->meta, 'provider_lifecycle_sync_source'))->toBe('scheduled_subscription_renewal_reconciliation')
        ->and($renewalEvents->contains(function (PaymentEvent $event): bool {
            return str_contains((string) data_get($event->meta, 'period_ends_at', ''), '2026-05-01T12:00:00');
        }))->toBeTrue();
});

it('marks repeated permanent subscription lookup failures for review and stops further provider polling', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-subscription-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-review-404'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-review-404') => Http::response([
            'traceId' => 'trace-storage-review-404',
            'errors' => [[
                'code' => 'NOT_FOUND_ERROR',
                'title' => 'Subscription not found',
                'detail' => 'No subscription exists for this identifier.',
            ]],
        ], 404),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment->forceFill([
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'provider_subscription_status' => 'DRAFT',
        'last_status_checked_at' => now()->subMinutes(30),
    ])->save();

    foreach (range(1, 3) as $attempt) {
        $this->artisan('payments:reconcile-fib-subscriptions', [
            '--customer-id' => $customer->id,
            '--chunk' => 25,
            '--stale-minutes' => 0,
        ])->assertSuccessful();
    }

    $payment = $payment->fresh();
    $failureEvents = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->get();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::REQUIRES_REVIEW)
        ->and($payment->review_required_at)->not->toBeNull()
        ->and($payment->fulfilled_at)->toBeNull()
        ->and((string) $payment->mismatch_reason)->toContain('NOT_FOUND')
        ->and((int) data_get($payment->meta, 'latest_sync_failure_count'))->toBe(3)
        ->and((string) data_get($payment->meta, 'latest_sync_failure.fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and($failureEvents->count())->toBe(1)
        ->and((string) data_get($failureEvents->first()?->payload, 'fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and((string) data_get($failureEvents->first()?->payload, 'safe_message'))->toContain('Do not fulfill automatically');

    Http::preventStrayRequests();

    $this->artisan('payments:reconcile-fib-subscriptions', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])->assertSuccessful();
});

it('keeps applied recurring subscriptions out of checkout reconciliation and records renewal failures separately', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-renewal-failure-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-renewal-failure-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-renewal-failure-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push([
                'traceId' => 'trace-renewal-not-found-123',
                'errors' => [[
                    'code' => 'NOT_FOUND_ERROR',
                    'title' => 'Subscription not found',
                    'detail' => 'No subscription exists for this identifier.',
                ]],
            ], 404)
            ->push([
                'traceId' => 'trace-renewal-not-found-123',
                'errors' => [[
                    'code' => 'NOT_FOUND_ERROR',
                    'title' => 'Subscription not found',
                    'detail' => 'No subscription exists for this identifier.',
                ]],
            ], 404)
            ->push([
                'traceId' => 'trace-renewal-not-found-123',
                'errors' => [[
                    'code' => 'NOT_FOUND_ERROR',
                    'title' => 'Subscription not found',
                    'detail' => 'No subscription exists for this identifier.',
                ]],
            ], 404),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_reconcile_initial')->fresh();
    $payment->forceFill([
        'last_status_checked_at' => now()->subMinutes(30),
    ])->save();

    Http::preventStrayRequests();

    $this->artisan('payments:reconcile-fib-subscriptions', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])
        ->expectsOutputToContain('Candidates scanned: 1')
        ->expectsOutputToContain('Skipped applied: 1')
        ->expectsOutputToContain('Skipped review-required: 0')
        ->expectsOutputToContain('Skipped terminal: 0')
        ->expectsOutputToContain('Processed unresolved: 0')
        ->expectsOutputToContain('Updated: 0')
        ->expectsOutputToContain('Failed: 0')
        ->assertSuccessful();

    expect(PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->count())->toBe(0);

    foreach (range(1, 3) as $attempt) {
        $payment->forceFill([
            'last_status_checked_at' => now()->subMinutes(30),
        ])->save();

        $this->artisan('payments:reconcile-fib-subscription-renewals', [
            '--customer-id' => $customer->id,
            '--chunk' => 25,
            '--stale-minutes' => 0,
        ])->assertSuccessful();
    }

    $payment = $payment->fresh();
    $renewalFailureEvents = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_renewal_sync_failed')
        ->get();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::APPLIED)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($payment->review_required_at)->toBeNull()
        ->and(data_get($payment->meta, 'latest_sync_failure'))->toBeNull()
        ->and((int) data_get($payment->meta, 'latest_renewal_sync_failure_count'))->toBe(3)
        ->and((string) data_get($payment->meta, 'latest_renewal_sync_failure.fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_status_sync_failed')
            ->count())->toBe(0)
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'payment_requires_review')
            ->count())->toBe(0)
        ->and($renewalFailureEvents->count())->toBe(1)
        ->and((string) $renewalFailureEvents->first()?->source)->toBe('scheduled_subscription_renewal_reconciliation')
        ->and((string) data_get($renewalFailureEvents->first()?->payload, 'fib_error_code'))->toBe('NOT_FOUND_ERROR');
});

it('keeps renewal reconciliation events and notifications idempotent across repeated sync runs', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-reconcile-dedupe-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-reconcile-dedupe-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-reconcile-dedupe-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-reconcile-dedupe-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T12:00:00Z',
                'lastPaymentAt' => '2026-05-01T11:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-reconcile-dedupe-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T12:00:00Z',
                'lastPaymentAt' => '2026-05-01T11:00:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_reconcile_dedupe_initial')->fresh();

    Carbon::setTestNow('2026-05-01 11:05:00');

    $this->artisan('payments:reconcile-fib-subscription-renewals', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])->assertSuccessful();

    $this->artisan('payments:reconcile-fib-subscription-renewals', [
        '--customer-id' => $customer->id,
        '--chunk' => 25,
        '--stale-minutes' => 0,
    ])->assertSuccessful();

    $renewalEvents = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'service_subscription_renewed')
        ->get();

    expect($renewalEvents->count())->toBe(2)
        ->and($renewalEvents->filter(function (PaymentEvent $event): bool {
            return str_contains((string) data_get($event->meta, 'period_ends_at', ''), '2026-05-01T12:00:00');
        })->count())->toBe(1);

    $renewalAlerts = Notification::sent(
        new AnonymousNotifiable,
        TelegramSubscriptionLifecycleAlert::class,
        function (TelegramSubscriptionLifecycleAlert $notification, ...$args): bool {
            $title = strtolower((string) data_get($notification->toArray(new AnonymousNotifiable), 'title', ''));

            return str_contains($title, 'renewed');
        }
    );

    expect($renewalAlerts->count())->toBeGreaterThanOrEqual(1);
});

it('syncs provider-side cancellation changes into local recurring entitlement state', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-provider-cancel-sync-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-provider-cancel-sync-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-provider-cancel-sync-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-provider-cancel-sync-123', 'CANCELED', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'provider_cancel_sync_initial')->fresh();

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'CANCELED'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202);

    $payment = $payment->fresh();
    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->latest('id')
        ->firstOrFail();

    expect($subscription->status)->toBe('active')
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->ends_at)->not->toBeNull()
        ->and(data_get($subscription->meta, 'cancel_source'))->toBe('provider_app')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'service_subscription_cancel_at_period_end')
            ->exists())->toBeTrue();
});

it('downgrades a fulfilled hourly service subscription to free after a failed renewal reaches the period end', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-failed-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-failed-sub-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-failed-sub-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-failed-sub-123', 'FAILED', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_initial_paid')->fresh();

    Carbon::setTestNow('2026-05-01 11:05:00');

    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_failed_renewal')->fresh();
    $subscription = CustomerServiceSubscription::query()
        ->where('payment_id', $payment->id)
        ->latest('id')
        ->firstOrFail();

    expect($subscription->status)->toBe('ended')
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($customer->fresh()->currentServicePlan()?->code)->toBe('free')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'service_subscription_ended')
            ->exists())->toBeTrue();
});

it('keeps hourly plan access until the exact cancellation boundary and then falls back to free', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-cancel-plan-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-cancel-plan-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-cancel-plan-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-cancel-plan-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-cancel-plan-123/cancel') => Http::response(null, 204),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_cancel_plan_paid')->fresh();

    $scheduled = app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->ends_at?->format('Y-m-d H:i:s'))->toBe('2026-05-01 11:00:00')
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow('2026-05-01 10:59:00');
    expect(Customer::query()->findOrFail($customer->id)->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow('2026-05-01 11:00:01');
    expect(Customer::query()->findOrFail($customer->id)->currentServicePlan()?->code)->toBe('free');
});

it('keeps hourly storage access until the exact cancellation boundary and then falls back to free storage', function () {
    Http::preventStrayRequests();
    fibFlowEnableHourlyTesting();
    Carbon::setTestNow('2026-05-01 10:00:00');

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-hourly-cancel-storage-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-cancel-storage-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-cancel-storage-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-hourly-cancel-storage-123', 'ACTIVE', [
                'interval' => 'PT1H',
                'activeUntil' => '2026-05-01T11:00:00Z',
                'lastPaymentAt' => '2026-05-01T10:00:00Z',
            ]), 200),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-hourly-cancel-storage-123/cancel') => Http::response(null, 204),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id, 'hourly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'hourly_cancel_storage_paid')->fresh();

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->ends_at?->format('Y-m-d H:i:s'))->toBe('2026-05-01 11:00:00')
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id);

    Carbon::setTestNow('2026-05-01 11:00:01');
    expect(Customer::query()->findOrFail($customer->id)->currentStoragePlan()?->code)->toBe('free-512');
});

it('fulfills a successful storage subscription checkout', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-storage-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-storage-fulfill-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-storage-fulfill-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_storage_confirmation')->fresh();

    $subscription = CustomerStorageSubscription::query()
        ->where('payment_id', $payment->id)
        ->where('storage_plan_id', $plan->id)
        ->firstOrFail();

    expect($payment->purchase_type)->toBe(PurchaseType::STORAGE_SUBSCRIPTION)
        ->and($payment->payment_mode)->toBe(PaymentMode::RECURRING)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::SUBSCRIPTION)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id)
        ->and($subscription->renewal_strategy)->toBe('provider_schedule')
        ->and($subscription->auto_renew)->toBeTrue();
});

it('fulfills a successful addon credits payment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    fibFlowGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/payments') => Http::response(
            fibFlowCreateResponse('fib-addon-fulfill-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/payments/fib-addon-fulfill-123/status') => Http::response(
            fibFlowStatusResponse('fib-addon-fulfill-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreateAddonPayment::class)->handle($customer->fresh(), $product->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_addon_confirmation')->fresh();
    $wallet = $customer->fresh()->wallet()->first();

    expect($payment->purchase_type)->toBe(PurchaseType::ADDON_CREDITS)
        ->and($payment->payment_mode)->toBe(PaymentMode::ONE_TIME)
        ->and($payment->provider_object_type)->toBe(PaymentProviderObjectType::PAYMENT)
        ->and($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee($product->name)
        ->assertSee('Success');
});

it('cancels a fib subscription checkout and keeps it unfulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-cancel-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-cancel-sub-123/cancel') => Http::response(null, 204),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-cancel-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-cancel-sub-123', 'CANCELED', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->actingAs($customer, 'app')
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertRedirect(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::CANCELED)
        ->and($payment->canceled_at)->not->toBeNull()
        ->and($payment->fulfilled_at)->toBeNull()
        ->and(data_get($payment->cancel_response, 'result'))->toBe('already_canceled')
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'provider_cancel_skipped')
            ->exists())->toBeTrue();
});

it('keeps unpaid subscription checkouts awaiting customer action without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-unpaid-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-unpaid-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-unpaid-sub-123', 'UNPAID', [
                'lastPaymentAt' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_unpaid_confirmation')->fresh();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($plan->id);
});

it('does not fulfill subscriptions from ACTIVE status when no provider payment evidence exists yet', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-active-without-payment-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-active-without-payment-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-active-without-payment-123', 'ACTIVE', [
                'lastPaymentAt' => null,
                'activeUntil' => '2026-06-01T10:15:00Z',
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'active_without_payment_evidence')->fresh();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($plan->id)
        ->and(CustomerServiceSubscription::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('marks first-payment rejected subscriptions as failed without fulfillment side effects', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-first-payment-rejected-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-first-payment-rejected-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-first-payment-rejected-123', 'REJECTED', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'first_payment_rejected')->fresh();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($payment->paid_at)->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($plan->id)
        ->and(CustomerServiceSubscription::query()->where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'payment_fulfilled')
            ->exists())->toBeFalse();

    $paymentAlerts = Notification::sent(
        new AnonymousNotifiable,
        TelegramPayment::class
    );

    expect($paymentAlerts->count())->toBe(0);
});

it('marks expired subscription checkouts as expired without fulfillment', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-expired-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-expired-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-expired-sub-123', 'EXPIRED', [
                'lastPaymentAt' => null,
                'activeUntil' => null,
            ]),
            200
        ),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'test_expired_confirmation')->fresh();

    expect($payment->status)->toBe(PaymentStatus::EXPIRED)
        ->and($payment->expired_at)->not->toBeNull()
        ->and($payment->fulfilled_at)->toBeNull()
        ->and(CustomerStorageSubscription::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('prevents one customer from accessing another customers fib checkout', function () {
    Http::preventStrayRequests();

    $owner = fibFlowCustomer('owner@example.com', 'fib_owner');
    $other = fibFlowCustomer('other@example.com', 'fib_other');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-private-sub-123'),
            201
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($owner, $plan->id, 'monthly');

    $this->actingAs($other, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();

    $this->actingAs($other, 'app')
        ->post(route('payments.fib.refresh', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();

    $this->actingAs($other, 'app')
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertForbidden();
});

it('polls the fib checkout page until the subscription becomes paid and fulfilled', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-poll-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-poll-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-poll-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.payments.fib-payment', ['payment' => $payment])
        ->call('pollStatus')
        ->assertSee('Redirecting you to your app home');

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fulfilled_at)->not->toBeNull()
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);
});

it('reflects callback-confirmed fib subscriptions on the page without another manual refresh request', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-callback-page-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-callback-page-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-callback-page-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app');

    $component = Livewire::test('app::pages.payments.fib-payment', ['payment' => $payment]);

    $this->postJson(
        route('payments.fib.subscription.callback'),
        ['id' => $payment->fib_subscription_id, 'status' => 'ACTIVE'],
        ['x-callback-secret' => 'fib-callback-secret'],
    )->assertStatus(202);

    Http::assertSentCount(3);

    $component
        ->call('pollStatus')
        ->assertSee('Redirecting you to your app home');

    Http::assertSentCount(3);

    expect($payment->fresh()->fulfilled_at)->not->toBeNull();
});

it('renders a localized app home redirect after successful subscription confirmation', function () {
    Http::preventStrayRequests();
    app()->setLocale('ar');

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-ar-home-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-ar-home-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-ar-home-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'localized_home_redirect')->fresh();

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'ar', 'payment' => $payment]))
        ->assertOk()
        ->assertSee(route('app.home', ['locale' => 'ar']))
        ->assertSee('Redirecting you to your app home');
});

it('stops automatic polling once the fib checkout reaches a terminal state', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-terminal-page-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-terminal-page-sub-123') => Http::response(
            fibFlowSubscriptionStatusResponse('fib-terminal-page-sub-123', 'ACTIVE'),
            200
        ),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');
    $payment = app(ConfirmFibPayment::class)->handle($payment, 'terminal_page_assertion')->fresh();

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('data-payment-status-polling="stopped"', false)
        ->assertDontSee('Refresh Status');
});

it('hides the cancel button on the checkout page when the known subscription state is not cancelable', function () {
    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => \App\Domain\Payments\Enums\PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'HIDE-CANCEL-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-hide-cancel-123',
        'amount' => $plan->priceIqdForCycle('monthly'),
        'currency' => 'IQD',
        'provider_subscription_status' => 'CANCELED',
        'purchase_snapshot' => [
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $plan->id,
    ]);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertDontSee('Cancel Subscription Checkout');
});

it('handles illegal fib subscription cancel transitions gracefully without exposing a raw exception page', function () {
    Http::preventStrayRequests();
    Log::spy();

    $customer = fibFlowCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-illegal-cancel-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-illegal-cancel-sub-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-illegal-cancel-sub-123', 'ACTIVE', [
                'activeUntil' => '2026-06-01T10:15:00Z',
                'lastPaymentAt' => '2026-05-01T10:05:00Z',
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-illegal-cancel-sub-123', 'CANCELED', [
                'activeUntil' => '2026-06-01T10:15:00Z',
                'lastPaymentAt' => '2026-05-01T10:05:00Z',
            ]), 200),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-illegal-cancel-sub-123/cancel') => Http::response([
            'traceId' => '4f32622afbb5ff73bce7ac002c07db01',
            'errors' => [[
                'code' => 'ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION',
                'title' => 'Illegal transition',
                'detail' => 'The current provider status can no longer be canceled.',
            ]],
        ], 400),
    ]);

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id);

    $this->actingAs($customer, 'app')
        ->followingRedirects()
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('Cancellation has already been scheduled.')
        ->assertDontSee('RequestException')
        ->assertDontSee('ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION');

    $payment = $payment->fresh();

    expect(data_get($payment->cancel_response, 'result'))->toBe('already_scheduled')
        ->and(data_get($payment->cancel_response, 'trace_id'))->toBe('4f32622afbb5ff73bce7ac002c07db01')
        ->and(data_get($payment->cancel_response, 'error_codes'))->toBe(['ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION']);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'FIB provider request failed'
                && data_get($context, 'event') === 'subscription_cancel_failed'
                && data_get($context, 'trace_id') === '4f32622afbb5ff73bce7ac002c07db01'
                && data_get($context, 'error_codes') === ['ILLEGAL_SUBSCRIPTION_STATUS_TRANSITION'];
        })
        ->once();
});

it('treats already canceled fib subscriptions safely and does not call the provider cancel endpoint again', function () {
    Http::preventStrayRequests();

    $customer = fibFlowCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    Http::fake([
        fibFlowStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        fibFlowStageUrl('/protected/v1/subscriptions') => Http::response(
            fibFlowSubscriptionCreateResponse('fib-already-canceled-sub-123'),
            201
        ),
        fibFlowStageUrl('/protected/v1/subscriptions/fib-already-canceled-sub-123') => Http::sequence()
            ->push(fibFlowSubscriptionStatusResponse('fib-already-canceled-sub-123', 'CANCELED', [
                'activeUntil' => null,
                'lastPaymentAt' => null,
            ]), 200)
            ->push(fibFlowSubscriptionStatusResponse('fib-already-canceled-sub-123', 'CANCELED', [
                'activeUntil' => null,
                'lastPaymentAt' => null,
            ]), 200),
    ]);

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->followingRedirects()
        ->post(route('payments.fib.cancel', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('This subscription is already canceled.');

    Http::assertNotSent(fn ($request) => $request->url() === fibFlowStageUrl('/protected/v1/subscriptions/fib-already-canceled-sub-123/cancel'));

    expect(data_get($payment->fresh()->cancel_response, 'result'))->toBe('already_canceled');
});
