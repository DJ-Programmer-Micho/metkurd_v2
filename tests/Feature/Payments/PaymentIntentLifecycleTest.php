<?php

use App\Enums\PaymentIntentStatus;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentIntentLifecycleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    Cache::flush();

    config()->set('fib.environment', 'staging');
    config()->set('fib.base_url', 'https://fib.stage.fib.iq');
    config()->set('fib.client_id', 'fib-test-client');
    config()->set('fib.client_secret', 'fib-secret');
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.paths.token', '/auth/realms/{realm}/protocol/openid-connect/token');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
});

it('cancels a pending fib payment intent through the lifecycle service', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments/fib-payment-123/cancel' => Http::response([
            'paymentId' => 'fib-payment-123',
            'status' => 'CANCELLED',
        ], 200),
    ]);

    $customer = Customer::create([
        'username' => 'fib_cancel_user',
        'email' => 'fib-cancel@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    $intent = PaymentIntent::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => 'fib',
        'payment_method' => 'fib',
        'purpose_type' => 'service_plan',
        'purpose_id' => 1,
        'purpose_code' => 'pro',
        'purpose_name' => 'Pro',
        'billing_interval' => 'monthly',
        'is_recurring' => true,
        'recurring_strategy' => 'manual_renewal',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => 26500,
        'gross_amount_iqd' => 26500,
        'surcharge_amount_iqd' => 0,
        'provider_fee_amount_iqd' => 265,
        'net_amount_iqd' => 26235,
        'fee_currency_code' => 'IQD',
        'status' => PaymentIntentStatus::REQUIRES_ACTION->value,
        'idempotency_key' => (string) Str::uuid(),
        'merchant_transaction_id' => 'FIB-CANCEL-' . strtoupper(Str::random(10)),
        'provider_payment_id' => 'fib-payment-123',
        'meta' => [
            'fee_breakdown' => [
                'provider' => 'fib',
                'card_origin' => 'unknown',
                'percent' => 1.0,
                'fixed_iqd' => 0,
                'pass_to_customer' => false,
            ],
            'checkout_action' => [
                'readable_code' => 'CODE-123',
            ],
        ],
    ]);

    $updatedIntent = app(PaymentIntentLifecycleService::class)->cancel($intent, [
        'reason' => 'Customer canceled from modal.',
        'source' => 'feature_test',
    ]);

    expect($updatedIntent->status)->toBe(PaymentIntentStatus::CANCELED->value)
        ->and($updatedIntent->canceled_at)->not->toBeNull()
        ->and($updatedIntent->checkoutAction())->toBe([])
        ->and($updatedIntent->transactions()->count())->toBe(1);
});
