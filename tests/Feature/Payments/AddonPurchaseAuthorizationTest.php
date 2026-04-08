<?php

use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->seed();
});

function createPaymentTestCustomer(string $email, string $username): Customer
{
    return Customer::create([
        'username' => $username,
        'email' => $email,
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['wallet', 'activeServiceSubscription.servicePlan']);
}

it('blocks add-on purchases for customers on the free plan', function () {
    $customer = createPaymentTestCustomer('free-addon@example.com', 'free_addon_user');
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    expect(fn () => app(AddonPurchaseService::class)->purchase($customer, $product->id))
        ->toThrow(AuthorizationException::class);
});

it('allows add-on purchases for customers with an active paid plan', function () {
    $customer = createPaymentTestCustomer('paid-addon@example.com', 'paid_addon_user');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $product = CreditProduct::query()->where('code', 'addon_10000')->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    $order = app(AddonPurchaseService::class)->purchase($customer->fresh(), $product->id, [
        'provider' => 'fake',
        'payment_method' => 'fake',
    ]);

    $wallet = $customer->fresh()->wallet()->first();

    expect($order->status)->toBe('paid')
        ->and($order->source_type)->toBe('credit_product')
        ->and((int) $order->credits_amount)->toBe((int) $product->credits_amount)
        ->and((int) ($wallet?->addon_balance_credits ?? 0))->toBe((int) $product->credits_amount);
});
