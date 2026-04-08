<?php

use App\Contracts\Payments\FibGatewayInterface;
use App\Services\Payments\Fib\Data\FibCancelPaymentRequest;
use App\Services\Payments\Fib\Data\FibCreatePaymentRequest;
use App\Services\Payments\Fib\Data\FibRefundPaymentRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config()->set('fib.environment', 'staging');
    config()->set('fib.base_url', 'https://fib.stage.fib.iq');
    config()->set('fib.client_id', 'fib-test-client');
    config()->set('fib.client_secret', 'fib-secret');
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.paths.token', '/auth/realms/{realm}/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.payment_refund', '/protected/v1/payments/{paymentId}/refund');
});

it('creates a fib payment through the dedicated gateway', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments' => Http::response([
            'paymentId' => 'fib-payment-123',
            'status' => 'UNPAID',
            'readableCode' => 'CODE-123',
        ], 201),
    ]);

    $gateway = app(FibGatewayInterface::class);

    $payment = $gateway->createPayment(new FibCreatePaymentRequest(
        amount: 26500,
        currency: 'IQD',
        callbackUrl: 'https://app.test/payments/webhooks/fib',
        description: 'Order #123',
    ));

    expect($payment->paymentId)->toBe('fib-payment-123')
        ->and($payment->status)->toBe('UNPAID')
        ->and($payment->readableCode)->toBe('CODE-123');
});

it('fetches fib payment status through the dedicated gateway', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments/fib-payment-123/status' => Http::response([
            'paymentId' => 'fib-payment-123',
            'status' => 'PAID',
            'paidAt' => '2026-04-01T10:10:00Z',
        ], 200),
    ]);

    $gateway = app(FibGatewayInterface::class);
    $payment = $gateway->getPaymentStatus('fib-payment-123');

    expect($payment->paymentId)->toBe('fib-payment-123')
        ->and($payment->status)->toBe('PAID')
        ->and($payment->paidAt)->not->toBeNull();
});

it('cancels a fib payment through the dedicated gateway', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments/fib-payment-123/cancel' => Http::response(null, 204),
    ]);

    $gateway = app(FibGatewayInterface::class);
    $payment = $gateway->cancelPayment(new FibCancelPaymentRequest(
        paymentId: 'fib-payment-123',
        reason: 'User canceled',
    ));

    expect($payment->paymentId)->toBe('fib-payment-123')
        ->and($payment->status)->toBe('CANCELLED');
});

it('refunds a fib payment through the dedicated gateway', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        'https://fib.stage.fib.iq/protected/v1/payments/fib-payment-123/refund' => Http::response([
            'paymentId' => 'fib-payment-123',
            'status' => 'REFUNDED',
        ], 200),
    ]);

    $gateway = app(FibGatewayInterface::class);
    $payment = $gateway->refundPayment(new FibRefundPaymentRequest(
        paymentId: 'fib-payment-123',
        amount: 26500,
        reason: 'Requested by support',
    ));

    expect($payment->status)->toBe('REFUNDED');
});
