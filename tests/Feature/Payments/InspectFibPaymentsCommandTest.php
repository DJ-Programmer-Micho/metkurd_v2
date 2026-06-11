<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    app()->setLocale('en');
    $this->seed();

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
});

function inspectFibCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    $customer = Customer::create([
        'username' => 'inspect_'.$suffix,
        'email' => 'inspect-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    CustomerProfile::create([
        'customer_id' => $customer->id,
        'first_name' => 'Inspect',
        'last_name' => 'Customer',
        'phone_number' => '+9647701234567',
        'country' => 'IQ',
    ]);

    return $customer->fresh(['profile']);
}

function inspectFibAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'name' => 'Inspect Admin',
            'email' => 'inspect-admin@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function inspectFibStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq'.$path;
}

function inspectFibPaymentStatusResponse(string $paymentId, string $status, array $overrides = []): array
{
    return array_merge([
        'paymentId' => $paymentId,
        'status' => $status,
        'validUntil' => '2026-05-01T10:15:00Z',
        'amount' => [
            'amount' => '25000',
            'currency' => 'IQD',
        ],
    ], $overrides);
}

function inspectFibSubscriptionStatusResponse(string $subscriptionId, string $status, array $overrides = []): array
{
    return array_merge([
        'id' => $subscriptionId,
        'readableCode' => 'SUB-CODE-123',
        'title' => 'MET KURD Student',
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

it('shows local customer fib rows and related events', function () {
    $customer = inspectFibCustomer();
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'local_reference' => 'INSPECT-LOCAL-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-local-sub-123',
        'amount' => $plan->priceIqdForCycle('monthly'),
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'mismatch_reason' => 'Recovered local review row.',
        'review_required_at' => now(),
        'paid_at' => now(),
        'purchase_snapshot' => [
            'code' => $plan->code,
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
            'checkout_context' => [
                'current_service_plan_code' => 'free',
                'current_service_plan_name' => 'Free',
            ],
        ],
        'created_at' => '2026-05-08 12:00:00',
        'updated_at' => '2026-05-08 12:00:00',
    ]);

    PaymentEvent::create([
        'payment_id' => $payment->id,
        'provider' => 'fib',
        'event_type' => 'payment_requires_review',
        'source' => 'payment_application_guard',
        'fib_subscription_id' => 'fib-local-sub-123',
        'processed_at' => now(),
    ]);

    $payment->forceFill([
        'created_at' => Carbon::parse('2026-05-08 12:00:00'),
        'updated_at' => Carbon::parse('2026-05-08 12:00:00'),
        'paid_at' => Carbon::parse('2026-05-08 12:05:00'),
    ])->saveQuietly();

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--from' => '2026-05-08',
        '--to' => '2026-05-08',
    ])
        ->expectsOutputToContain('LOCAL FIB ROWS')
        ->expectsOutputToContain('fib-local-sub-123')
        ->expectsOutputToContain('PAYMENT EVENTS')
        ->expectsOutputToContain('paid but requires review')
        ->assertExitCode(0);
});

it('reports no local rows and asks for a provider reference', function () {
    $customer = inspectFibCustomer();

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--from' => '2026-05-08',
    ])
        ->expectsOutputToContain('No local FIB rows found for the requested scope.')
        ->expectsOutputToContain('FIB provider discovery by customer/date is not supported by the current API/docs.')
        ->expectsOutputToContain('Please copy the FIB payment/subscription ID from FIB Business and rerun with --provider-reference=<id>.')
        ->assertExitCode(0);
});

it('finds an existing row by provider reference and refreshes status without fulfilling it', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $plan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'INSPECT-EXISTING-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-existing-sub-123',
        'amount' => $plan->priceIqdForCycle('monthly'),
        'currency' => 'IQD',
        'purchase_snapshot' => [
            'code' => $plan->code,
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
            'checkout_context' => [
                'current_service_plan_code' => 'free',
                'current_service_plan_name' => 'Free',
            ],
        ],
    ]);

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/subscriptions/fib-existing-sub-123') => Http::response(
            inspectFibSubscriptionStatusResponse('fib-existing-sub-123', 'PAID'),
            200
        ),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--fib-subscription-id' => 'fib-existing-sub-123',
    ])
        ->expectsOutputToContain('matched_existing_row')
        ->expectsOutputToContain('status_refreshed')
        ->assertExitCode(0);

    $payment = $payment->fresh();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::PAID_PENDING_APPLICATION)
        ->and($payment->fulfilled_at)->toBeNull();
});

it('does not crash when an existing local subscription row returns provider not found', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::STORAGE_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'INSPECT-STORAGE-404-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-storage-not-found-123',
        'amount' => $plan->priceIqdAmount(),
        'currency' => 'IQD',
        'provider_subscription_status' => 'DRAFT',
        'purchase_snapshot' => [
            'code' => $plan->code,
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
            'checkout_context' => [
                'current_storage_plan_code' => 'free-512',
                'current_storage_plan_name' => 'Free 512 MB',
            ],
        ],
    ]);

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/subscriptions/fib-storage-not-found-123') => Http::response([
            'traceId' => 'trace-storage-not-found-123',
            'errors' => [[
                'code' => 'NOT_FOUND_ERROR',
                'title' => 'Subscription not found',
                'detail' => 'No subscription exists for this id.',
            ]],
        ], 404),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-subscription-id' => 'fib-storage-not-found-123',
    ])
        ->expectsOutputToContain('matched_existing_row_lookup_failed')
        ->expectsOutputToContain('HTTP 404 NOT_FOUND_ERROR')
        ->expectsOutputToContain('storage_subscription')
        ->expectsOutputToContain('recurring')
        ->expectsOutputToContain('This appears to be an uncompleted or unconfirmed FIB checkout/subscription.')
        ->assertExitCode(1);

    $payment = $payment->fresh();
    $failureEvent = PaymentEvent::query()
        ->where('payment_id', $payment->id)
        ->where('event_type', 'provider_status_sync_failed')
        ->latest('id')
        ->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::AWAITING_CUSTOMER_ACTION)
        ->and((int) data_get($payment->meta, 'latest_sync_failure_count'))->toBe(1)
        ->and((string) data_get($payment->meta, 'latest_sync_failure.fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and((string) data_get($failureEvent->payload, 'fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and((string) data_get($failureEvent->payload, 'safe_message'))->toContain('Do not fulfill automatically');
});

it('emits structured json when an existing local row provider refresh fails', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::STORAGE_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'internal_status' => PaymentInternalStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'INSPECT-STORAGE-JSON-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-storage-json-404',
        'amount' => $plan->priceIqdAmount(),
        'currency' => 'IQD',
        'provider_subscription_status' => 'DRAFT',
        'purchase_snapshot' => [
            'code' => $plan->code,
            'name' => $plan->name,
        ],
    ]);

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/subscriptions/fib-storage-json-404') => Http::response([
            'traceId' => 'trace-storage-json-404',
            'errors' => [[
                'code' => 'NOT_FOUND_ERROR',
                'title' => 'Subscription not found',
            ]],
        ], 404),
    ]);

    $exitCode = Artisan::call('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-subscription-id' => 'fib-storage-json-404',
        '--json' => true,
    ]);
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(data_get($report, 'mode'))->toBe('provider_reference_existing_row_lookup_failed')
        ->and(data_get($report, 'provider_lookup.http_status'))->toBe(404)
        ->and(data_get($report, 'provider_lookup.fib_error_code'))->toBe('NOT_FOUND_ERROR')
        ->and(data_get($report, 'provider_lookup.provider_reference_type'))->toBe('subscription')
        ->and(data_get($report, 'provider_lookup.matched_local_row'))->toBeTrue()
        ->and(data_get($report, 'provider_lookup.status_refreshed'))->toBeFalse()
        ->and(data_get($report, 'local_rows.0.purchase_type'))->toBe('storage_subscription')
        ->and(data_get($report, 'local_rows.0.payment_mode'))->toBe('recurring')
        ->and((int) data_get($report, 'local_rows.0.latest_sync_failure_count'))->toBe(1);
});

it('does not create a row for provider lookup unless create missing review is requested', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/payments/fib-missing-pay-123/status') => Http::response(
            inspectFibPaymentStatusResponse('fib-missing-pay-123', 'PAID', [
                'paidAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-payment-id' => 'fib-missing-pay-123',
    ])
        ->expectsOutputToContain('No local payment row matched the requested provider reference.')
        ->expectsOutputToContain('Manual recovery row was not created.')
        ->assertExitCode(0);

    expect(Payment::query()->where('fib_payment_id', 'fib-missing-pay-123')->exists())->toBeFalse();
});

it('creates a review only recovery row and does not duplicate or alter the customer plan', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/subscriptions/fib-recovery-sub-123') => Http::response(
            inspectFibSubscriptionStatusResponse('fib-recovery-sub-123', 'ACTIVE', [
                'title' => 'MET KURD '.$studentPlan->name,
                'monetaryValue' => [
                    'amount' => (string) $studentPlan->priceIqdForCycle('monthly'),
                    'currency' => 'IQD',
                ],
                'lastPaymentAt' => '2026-05-01T10:05:00Z',
            ]),
            200
        ),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-subscription-id' => 'fib-recovery-sub-123',
        '--create-missing-review' => true,
    ])
        ->expectsOutputToContain('created_or_updated_review_row')
        ->assertExitCode(0);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-subscription-id' => 'fib-recovery-sub-123',
        '--create-missing-review' => true,
    ])->assertExitCode(0);

    $payment = Payment::query()->where('fib_subscription_id', 'fib-recovery-sub-123')->firstOrFail();

    expect(Payment::query()->where('fib_subscription_id', 'fib-recovery-sub-123')->count())->toBe(1)
        ->and($payment->internal_status)->toBe(PaymentInternalStatus::REQUIRES_REVIEW)
        ->and($payment->fulfilled_at)->toBeNull()
        ->and($payment->customer_id)->toBe($customer->id)
        ->and($payment->purchase_type)->toBe(PurchaseType::PLAN_SUBSCRIPTION)
        ->and((string) data_get($payment->purchase_snapshot, 'code'))->toBe((string) $studentPlan->code)
        ->and($customer->fresh()->currentServicePlanId())->not->toBe($studentPlan->id)
        ->and(PaymentEvent::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'manual_provider_recovery_created')
            ->count())->toBe(1);
});

it('shows a recovered review row in customer billing and admin support views', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $admin = inspectFibAdmin();
    $plan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/subscriptions/fib-recovery-storage-123') => Http::response(
            inspectFibSubscriptionStatusResponse('fib-recovery-storage-123', 'PAID', [
                'title' => 'MET KURD '.$plan->name,
                'description' => 'Storage recurring checkout',
                'monetaryValue' => [
                    'amount' => (string) $plan->priceIqdAmount(),
                    'currency' => 'IQD',
                ],
            ]),
            200
        ),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-subscription-id' => 'fib-recovery-storage-123',
        '--create-missing-review' => true,
    ])->assertExitCode(0);

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.billing', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Requires Review')
        ->assertSee('Recovered from FIB provider reference but original local checkout record was missing.');

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')
        ->set('customerFilter', (string) $customer->id)
        ->assertSee('FIB Payment Ledger')
        ->assertSee('Requires Review')
        ->assertSee('Recovered from FIB provider reference but original local checkout record was missing.');
});

it('shows invalid request guidance when fib rejects the provider reference', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $providerReference = 'IQ47FIQB004006673010001';

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/payments/'.$providerReference.'/status') => Http::response([
            'message' => 'INVALID_REQUEST',
            'errors' => [
                ['code' => 'INVALID_REQUEST'],
            ],
        ], 400),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-payment-id' => $providerReference,
    ])
        ->expectsOutputToContain('HTTP 400 INVALID_REQUEST')
        ->expectsOutputToContain('This reference looks like a FIB Business transaction/reference ID')
        ->expectsOutputToContain('The provider reference was rejected by FIB. This may not be the API paymentId/subscriptionId expected by the FIB status endpoint.')
        ->assertExitCode(1);
});

it('shows environment mismatch guidance when fib jwt issuer is not configured', function () {
    Http::preventStrayRequests();

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'error_description' => 'Jwt issuer is not configured',
        ], 401),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--fib-payment-id' => 'fib-jwt-mismatch-123',
    ])
        ->expectsOutputToContain('HTTP 401 Jwt issuer is not configured')
        ->expectsOutputToContain('FIB authentication/environment mismatch detected.')
        ->assertExitCode(1);
});

it('shows environment diagnostics and local testing guidance', function () {
    $customer = inspectFibCustomer();

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--provider-reference' => 'IQ47FIQB004006673010001',
        '--local-only' => true,
    ])
        ->expectsOutputToContain('FIB ENVIRONMENT')
        ->expectsOutputToContain('APP_ENV')
        ->expectsOutputToContain('FIB_PAYMENT_BASE_URL_HOST')
        ->expectsOutputToContain('Local testing note: provider lookup only works if your local .env uses valid FIB sandbox credentials')
        ->assertExitCode(0);
});

it('does not call the provider api in local only mode', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--provider-reference' => 'IQ47FIQB004006673010001',
        '--local-only' => true,
    ])
        ->expectsOutputToContain('Local-only mode skipped the FIB provider API lookup')
        ->assertExitCode(0);
});

it('still refuses to create a recovery row when provider lookup fails', function () {
    Http::preventStrayRequests();

    $customer = inspectFibCustomer();
    $providerReference = 'fib-missing-pay-invalid';

    Http::fake([
        inspectFibStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        inspectFibStageUrl('/protected/v1/payments/'.$providerReference.'/status') => Http::response([
            'message' => 'INVALID_REQUEST',
            'errors' => [
                ['code' => 'INVALID_REQUEST'],
            ],
        ], 400),
    ]);

    $this->artisan('payments:inspect-fib', [
        '--customer' => $customer->id,
        '--fib-payment-id' => $providerReference,
        '--create-missing-review' => true,
    ])
        ->expectsOutputToContain('Provider lookup failed; no local recovery row was created.')
        ->assertExitCode(1);

    expect(Payment::query()->where('fib_payment_id', $providerReference)->exists())->toBeFalse();
});
