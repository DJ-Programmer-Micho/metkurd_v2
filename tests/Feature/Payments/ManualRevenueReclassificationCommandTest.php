<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\CreditWallet;
use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
});

function manualRevenueCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    return Customer::create([
        'username' => 'manual_rev_'.$suffix,
        'email' => 'manual-rev-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

it('dry runs manual revenue reclassification without mutating local records or calling fib', function () {
    Http::preventStrayRequests();

    $customer = manualRevenueCustomer();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'local_reference' => 'ADMIN-MANUAL-DRY-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'ADMIN-DRY-RUN-001',
        'amount' => 10000,
        'currency' => 'IQD',
        'paid_at' => now(),
        'fulfilled_at' => now(),
        'meta' => [
            'admin_adjustment' => true,
            'ui' => 'admin.customers.register',
        ],
        'purchasable_type' => CreditProduct::class,
        'purchasable_id' => $product->id,
    ]);

    CreditOrder::create([
        'customer_id' => $customer->id,
        'payment_id' => $payment->id,
        'order_type' => 'addon',
        'source_type' => 'credit_product',
        'credit_product_id' => $product->id,
        'status' => 'paid',
        'credits_amount' => 10000,
        'amount_usd' => 10,
        'currency' => 'IQD',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => 10000,
        'original_amount_iqd' => 10000,
        'discount_amount_iqd' => 0,
        'discounted_amount_iqd' => 10000,
        'gross_amount_iqd' => 10000,
        'surcharge_amount_iqd' => 0,
        'provider_fee_amount_iqd' => 0,
        'net_amount_iqd' => 10000,
        'fee_currency_code' => 'IQD',
        'provider' => 'admin_manual',
        'payment_method' => 'admin_manual',
        'provider_ref' => 'ADMIN-ORDER-DRY-001',
        'paid_at' => now(),
    ]);

    $this->artisan('payments:reclassify-manual-revenue', [
        'payment' => $payment->id,
        '--customer' => $customer->id,
        '--reason' => 'internal/company account, no real revenue',
    ])->assertSuccessful()
        ->expectsOutputToContain('dry-run');

    Http::assertNothingSent();

    $payment = $payment->fresh();
    $order = CreditOrder::query()->where('payment_id', $payment->id)->firstOrFail();

    expect($payment->exists)->toBeTrue()
        ->and(data_get($payment->meta, 'revenue_excluded'))->toBeNull()
        ->and($order->exists)->toBeTrue()
        ->and(data_get($order->meta, 'revenue_excluded'))->toBeNull();
});

it('executes manual revenue reclassification with ledger reversal rows and no fib calls', function () {
    Http::preventStrayRequests();

    $customer = manualRevenueCustomer();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    $payment = Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::ADDON_CREDITS,
        'payment_mode' => PaymentMode::ONE_TIME,
        'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::APPLIED,
        'local_reference' => 'ADMIN-MANUAL-EXEC-001',
        'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'ADMIN-EXEC-001',
        'amount' => 10000,
        'currency' => 'IQD',
        'paid_at' => now(),
        'fulfilled_at' => now(),
        'meta' => [
            'admin_adjustment' => true,
            'ui' => 'admin.customers.register',
        ],
        'purchasable_type' => CreditProduct::class,
        'purchasable_id' => $product->id,
    ]);

    $order = CreditOrder::create([
        'customer_id' => $customer->id,
        'payment_id' => $payment->id,
        'order_type' => 'addon',
        'source_type' => 'credit_product',
        'credit_product_id' => $product->id,
        'status' => 'paid',
        'credits_amount' => 10000,
        'amount_usd' => 10,
        'currency' => 'IQD',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => 10000,
        'original_amount_iqd' => 10000,
        'discount_amount_iqd' => 0,
        'discounted_amount_iqd' => 10000,
        'gross_amount_iqd' => 10000,
        'surcharge_amount_iqd' => 0,
        'provider_fee_amount_iqd' => 0,
        'net_amount_iqd' => 10000,
        'fee_currency_code' => 'IQD',
        'provider' => 'admin_manual',
        'payment_method' => 'admin_manual',
        'provider_ref' => 'ADMIN-ORDER-EXEC-001',
        'paid_at' => now(),
    ]);

    CreditWallet::query()->updateOrCreate(
        [
            'customer_id' => $customer->id,
            'wallet_type' => CreditWallet::TYPE_APP,
        ],
        [
            'balance_credits' => 10000,
            'subscription_balance_credits' => 0,
            'addon_balance_credits' => 10000,
        ],
    );

    $this->artisan('payments:reclassify-manual-revenue', [
        'payment' => $payment->id,
        '--customer' => $customer->id,
        '--reason' => 'internal/company account, no real revenue',
        '--reverse-credits' => true,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertNothingSent();

    $payment = $payment->fresh();
    $order = $order->fresh();
    $wallet = CreditWallet::query()
        ->where('customer_id', $customer->id)
        ->where('wallet_type', CreditWallet::TYPE_APP)
        ->firstOrFail();

    $reversal = CreditLedger::query()
        ->where('customer_id', $customer->id)
        ->where('type', 'manual_revenue_reversal')
        ->first();

    expect($payment->exists)->toBeTrue()
        ->and(data_get($payment->meta, 'revenue_excluded'))->toBeTrue()
        ->and(data_get($payment->meta, 'billing_source'))->toBe('admin_manual_grant')
        ->and($order->exists)->toBeTrue()
        ->and(data_get($order->meta, 'revenue_excluded'))->toBeTrue()
        ->and((int) $wallet->addon_balance_credits)->toBe(0)
        ->and((int) $wallet->balance_credits)->toBe(0)
        ->and($reversal)->not->toBeNull()
        ->and((int) $reversal->credits_delta)->toBe(-10000)
        ->and($reversal->related_id)->toBe((string) $order->id);
});
