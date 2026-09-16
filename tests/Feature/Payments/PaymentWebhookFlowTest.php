<?php

use App\Enums\PaymentIntentStatus;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\ServicePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\PlanSwitcher;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    config(['payments.providers.areeba.enabled' => true, 'areeba.webhook_secret' => 'fixture-secret']);
    $this->withHeader('x-webhook-secret', 'fixture-secret');
});

function createWebhookTestCustomer(string $email, string $username): Customer
{
    return Customer::create([
        'username' => $username,
        'email' => $email,
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan']);
}

it('processes a paid provider webhook once and ignores duplicate delivery', function () {
    $customer = createWebhookTestCustomer('webhook@example.com', 'webhook_user');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $snapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd($product->priceIqdAmount(), $customer);

    $intent = PaymentIntent::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => 'areeba',
        'payment_method' => 'card',
        'purpose_type' => 'credit_product',
        'purpose_id' => $product->id,
        'purpose_code' => $product->code,
        'purpose_name' => $product->name,
        'billing_interval' => null,
        'is_recurring' => false,
        'recurring_strategy' => 'none',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => $product->priceIqdAmount(),
        'gross_amount_iqd' => $product->priceIqdAmount(),
        'surcharge_amount_iqd' => 0,
        'provider_fee_amount_iqd' => 0,
        'net_amount_iqd' => $product->priceIqdAmount(),
        'fee_currency_code' => 'IQD',
        'display_currency_code' => $snapshot['display_currency_code'],
        'display_exchange_rate' => $snapshot['display_exchange_rate'],
        'display_amount_raw' => $snapshot['display_amount_raw'],
        'display_amount_rounded' => $snapshot['display_amount_rounded'],
        'display_rounding_step' => $snapshot['display_rounding_step'],
        'display_rounding_mode' => $snapshot['display_rounding_mode'],
        'display_country_code' => $snapshot['display_country_code'],
        'status' => PaymentIntentStatus::PENDING->value,
        'idempotency_key' => (string) Str::uuid(),
        'merchant_transaction_id' => 'AREEBA-TEST-'.strtoupper(Str::random(10)),
    ]);

    $payload = [
        'merchantTransactionId' => $intent->merchant_transaction_id,
        'amount' => $intent->gross_amount_iqd,
        'currency' => 'IQD',
        'transactionStatus' => 'PAID',
        'uuid' => 'AREEBA-UUID-123',
        'purchaseId' => 'AREEBA-PURCHASE-456',
        'transactionType' => 'debit',
        'returnData' => [
            'cardData' => [
                'cardOrigin' => 'local',
            ],
        ],
    ];

    $this->postJson(route('payments.webhooks.areeba'), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'processed']);

    $this->postJson(route('payments.webhooks.areeba'), $payload)
        ->assertOk()
        ->assertJson(['ok' => true, 'status' => 'processed']);

    $intent = $intent->fresh();
    $wallet = $customer->fresh()->wallet()->first();

    expect($intent?->status)->toBe(PaymentIntentStatus::PAID->value)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(PaymentTransaction::query()->where('payment_intent_id', $intent->id)->count())->toBe(1)
        ->and($intent?->creditOrders()->count())->toBe(1)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);
});

function phaseOneLegacyIntent(string $provider = 'areeba'): PaymentIntent
{
    $customer = createWebhookTestCustomer(Str::uuid().'@example.test', 'legacy_'.Str::lower(Str::random(10)));
    $product = CreditProduct::where('code', 'addon_10000')->firstOrFail();

    return PaymentIntent::create([
        'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'provider' => $provider,
        'payment_method' => 'card', 'purpose_type' => 'credit_product', 'purpose_id' => $product->id,
        'base_amount_iqd' => $product->priceIqdAmount(), 'gross_amount_iqd' => $product->priceIqdAmount(),
        'base_currency_code' => 'IQD', 'status' => 'pending', 'idempotency_key' => (string) Str::uuid(),
        'merchant_transaction_id' => (string) Str::uuid(), 'provider_transaction_id' => 'fixture-'.Str::uuid(),
    ]);
}

it('rejects legacy financial callbacks without enabled authenticated delivery', function (string $case) {
    $intent = phaseOneLegacyIntent();
    if ($case === 'disabled') {
        config(['payments.providers.areeba.enabled' => false]);
    } elseif ($case === 'unconfigured') {
        config(['areeba.webhook_secret' => null]);
    } else {
        $this->withHeader('x-webhook-secret', $case === 'unsigned' ? '' : 'wrong-fixture-secret');
    }
    $this->postJson(route('payments.webhooks.areeba'), ['merchantTransactionId' => $intent->merchant_transaction_id,
        'transactionStatus' => 'PAID'])->assertForbidden();
    expect($intent->fresh()->status)->toBe('pending')->and($intent->fresh()->fulfilled_at)->toBeNull()
        ->and(PaymentTransaction::where('payment_intent_id', $intent->id)->count())->toBe(0);
})->with(['disabled', 'unconfigured', 'unsigned', 'invalid']);

it('does not resolve another providers intent even with valid callback authentication', function () {
    $intent = phaseOneLegacyIntent('fib');
    $this->postJson(route('payments.webhooks.areeba'), ['merchantTransactionId' => $intent->merchant_transaction_id,
        'transactionStatus' => 'PAID'])->assertAccepted();
    expect($intent->fresh()->status)->toBe('pending')->and($intent->fresh()->fulfilled_at)->toBeNull()
        ->and(PaymentWebhookEvent::first()->payment_intent_id)->toBeNull();
});

it('rejects mismatched legacy references or monetary evidence', function (string $case) {
    $intent = phaseOneLegacyIntent();
    $payload = ['merchantTransactionId' => $intent->merchant_transaction_id, 'transactionStatus' => 'PAID',
        'uuid' => $intent->provider_transaction_id, 'amount' => $intent->gross_amount_iqd, 'currency' => 'IQD'];
    $payload[$case] = match ($case) {
        'amount' => 1, 'currency' => 'USD', default => 'another-transaction'
    };
    $this->postJson(route('payments.webhooks.areeba'), $payload)->assertStatus(422);
    expect($intent->fresh()->status)->toBe('pending')->and($intent->fresh()->fulfilled_at)->toBeNull()
        ->and(PaymentTransaction::where('payment_intent_id', $intent->id)->count())->toBe(0);
})->with(['uuid', 'amount', 'currency']);

it('does not retain callback authentication headers or banking payloads', function () {
    $intent = phaseOneLegacyIntent();
    $this->postJson(route('payments.webhooks.areeba'), ['merchantTransactionId' => $intent->merchant_transaction_id,
        'transactionStatus' => 'PENDING', 'bankAccount' => 'fixture-sensitive-bank-data'])->assertOk();
    $stored = PaymentWebhookEvent::first()->toJson();
    expect($stored)->not->toContain('fixture-secret')->not->toContain('fixture-sensitive-bank-data')
        ->and(PaymentWebhookEvent::first()->headers)->toBe([]);
});
