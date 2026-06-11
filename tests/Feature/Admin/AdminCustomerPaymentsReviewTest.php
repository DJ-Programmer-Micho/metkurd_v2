<?php

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
});

function adminPaymentsReviewAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'name' => 'Admin Payments Review',
            'email' => 'admin-payments-review@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

function adminPaymentsReviewCustomer(): Customer
{
    $suffix = Str::lower(Str::random(8));

    return Customer::create([
        'username' => 'cust_'.$suffix,
        'email' => 'cust-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

it('shows review-required fib payments in the admin customer support view', function () {
    $admin = adminPaymentsReviewAdmin();
    $customer = adminPaymentsReviewCustomer();
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    Payment::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'provider' => PaymentProvider::FIB,
        'purchase_type' => PurchaseType::PLAN_SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING,
        'provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'status' => PaymentStatus::PAID,
        'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'local_reference' => 'ADMIN-REVIEW-'.strtoupper(Str::random(8)),
        'idempotency_key' => (string) Str::uuid(),
        'fib_subscription_id' => 'fib-admin-review-123',
        'amount' => $studentPlan->priceIqdForCycle('monthly'),
        'currency' => 'IQD',
        'provider_subscription_status' => 'ACTIVE',
        'mismatch_reason' => 'Customer currently has Pro but this FIB payment was created for Student.',
        'review_required_at' => now(),
        'paid_at' => now(),
        'purchase_snapshot' => [
            'code' => $studentPlan->code,
            'name' => $studentPlan->name,
            'billing_cycle' => 'monthly',
        ],
        'purchasable_type' => ServicePlan::class,
        'purchasable_id' => $studentPlan->id,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.customers.adm-customers-register')
        ->set('customerFilter', (string) $customer->id)
        ->assertSee('FIB Payment Ledger')
        ->assertSee('Requires Review')
        ->assertSee('Customer currently has Pro but this FIB payment was created for Student.');
});
