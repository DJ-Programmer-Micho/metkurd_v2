<?php

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    app()->setLocale('en');
});

function billingPageTestCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    $customer = Customer::create([
        'username' => "billing_{$suffix}",
        'email' => "billing-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    $customer->forceFill([
        'phone_verified_at' => now(),
    ])->save();

    CustomerProfile::create([
        'customer_id' => $customer->id,
        'first_name' => 'Billing',
        'last_name' => 'Customer',
        'phone_number' => '+9647701234567',
        'country' => 'IQ',
    ]);

    return $customer->fresh(['profile']);
}

function billingPagePendingPlanCheckout(Customer $customer, ServicePlan $plan, string $couponCode = 'WELCOME50'): Payment
{
    $currency = app(BillingCurrencyService::class);
    $originalAmount = $plan->priceIqdForCycle('monthly');
    $discountAmount = 5000;
    $finalAmount = max(0, $originalAmount - $discountAmount);

    return Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'coupon_code' => $couponCode,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION,
        'local_reference' => 'CHECKOUT-' . strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-sub-' . Str::lower(Str::random(8)),
        'amount' => $finalAmount,
        'currency' => 'IQD',
        'original_amount_iqd' => $originalAmount,
        'discount_amount_iqd' => $discountAmount,
        'discounted_amount_iqd' => $finalAmount,
        'provider_subscription_status' => 'UNPAID',
        'valid_until' => now()->addHour(),
        'active_until' => now()->addDay(),
        'last_payment_at' => now()->subHour(),
        'last_status_checked_at' => now()->subMinutes(2),
        'meta' => [
            'subscription_lifecycle' => [
                'sync_source' => 'scheduled_reconciliation',
                'cancel_source' => 'provider_app',
            ],
        ],
        'purchase_snapshot' => [
            'code' => $plan->code,
            'name' => $plan->name,
            'billing_cycle' => 'monthly',
            'original_amount_iqd' => $originalAmount,
            'discount_amount_iqd' => $discountAmount,
            'amount_iqd' => $finalAmount,
            'base_display' => $currency->priceDataForBaseAmountIqd($finalAmount, $customer),
            'original_display' => $currency->priceDataForBaseAmountIqd($originalAmount, $customer),
            'discount_display' => $currency->priceDataForBaseAmountIqd($discountAmount, $customer),
        ],
    ]);
}

function billingPageAddonOrder(Customer $customer, CreditProduct $product): CreditOrder
{
    $currency = app(BillingCurrencyService::class);
    $priceSnapshot = $currency->priceDataForBaseAmountIqd($product->priceIqdAmount(), $customer);

    return CreditOrder::create([
        'customer_id' => $customer->id,
        'order_type' => 'addon_purchase',
        'source_type' => 'credit_product',
        'credit_product_id' => $product->id,
        'status' => 'paid',
        'credits_amount' => (int) $product->credits_amount,
        'amount_usd' => $priceSnapshot['usd_reference_amount'],
        'currency' => 'IQD',
        'base_currency_code' => 'IQD',
        'base_amount_iqd' => $product->priceIqdAmount(),
        'original_amount_iqd' => $product->priceIqdAmount(),
        'discount_amount_iqd' => 0,
        'discounted_amount_iqd' => $product->priceIqdAmount(),
        'gross_amount_iqd' => $product->priceIqdAmount(),
        'net_amount_iqd' => $product->priceIqdAmount(),
        'display_currency_code' => $priceSnapshot['display_currency_code'],
        'display_exchange_rate' => $priceSnapshot['display_exchange_rate'],
        'display_amount_raw' => $priceSnapshot['display_amount_raw'],
        'display_amount_rounded' => $priceSnapshot['display_amount_rounded'],
        'display_rounding_step' => $priceSnapshot['display_rounding_step'],
        'display_rounding_mode' => $priceSnapshot['display_rounding_mode'],
        'display_country_code' => $priceSnapshot['display_country_code'],
        'provider_ref' => 'ORDER-' . strtoupper(Str::random(8)),
        'paid_at' => now(),
        'meta' => [
            'purpose' => 'addon_credits_topup',
        ],
    ]);
}

it('renders scheduled recurring billing states and the latest checkout summary', function () {
    $customer = billingPageTestCustomer();
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $storagePlan = StoragePlan::query()->where('code', 'pro-5120')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $servicePlan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    app(PlanSwitcher::class)->switchStoragePlan($customer, $storagePlan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());
    app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());

    $checkout = billingPagePendingPlanCheckout($customer->fresh(), $servicePlan);

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.billing', ['locale' => 'en']))
        ->assertOk()
        ->assertSee($servicePlan->name)
        ->assertSee($storagePlan->name)
        ->assertSee('Cancel at period end')
        ->assertSee('Open: 1')
        ->assertSee($checkout->purchase_snapshot['name'])
        ->assertSee('Open Checkout');
});

it('shows recurring discounts and one-time add-on charges in billing activity', function () {
    $customer = billingPageTestCustomer();
    $servicePlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    billingPagePendingPlanCheckout($customer, $servicePlan, 'WELCOME50');
    billingPageAddonOrder($customer, $product);

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.billing', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Plan Subscription Checkout')
        ->assertSee('Recurring - Monthly')
        ->assertSee('Provider status:')
        ->assertSee('Sync source:')
        ->assertSee('Cancellation source:')
        ->assertSee('Coupon: WELCOME50')
        ->assertSee('Original:')
        ->assertSee('Discount:')
        ->assertSee('Add-on Charge')
        ->assertSee('One-time');
});
