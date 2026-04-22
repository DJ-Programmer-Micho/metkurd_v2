<?php

use App\Enums\CouponTargetType;
use App\Models\Coupon;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
});

function couponAdminUser(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'name' => 'Coupon Admin',
            'email' => 'coupon-admin@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

it('renders the admin coupon management page', function () {
    $admin = couponAdminUser();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->assertSee('Checkout Coupons')
        ->assertSee('Recurring FIB limitation');
});

it('allows an admin to create and update coupons', function () {
    $admin = couponAdminUser();

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->call('openCreateCouponModal')
        ->set('code', 'WELCOME50')
        ->set('name', 'Welcome 50')
        ->set('description', 'Introductory subscription discount')
        ->set('discountType', 'percent')
        ->set('discountValue', '50')
        ->set('targetType', 'plan_subscription')
        ->set('appliesToCodesCsv', 'PRO, STUDENT')
        ->set('appliesToBillingCyclesCsv', 'monthly, yearly')
        ->set('durationType', 'forever')
        ->set('maxTotalUses', '100')
        ->set('maxUsesPerCustomer', '1')
        ->set('minimumAmountIqd', '5000')
        ->set('startsAtLocal', '2026-04-22T10:00')
        ->set('endsAtLocal', '2026-05-22T10:00')
        ->call('saveCoupon')
        ->assertHasNoErrors();

    $coupon = Coupon::query()->where('code', 'WELCOME50')->firstOrFail();

    expect($coupon->name)->toBe('Welcome 50')
        ->and($coupon->target_type)->toBe(CouponTargetType::PLAN_SUBSCRIPTION)
        ->and($coupon->applies_to_codes)->toBe(['PRO', 'STUDENT'])
        ->and($coupon->applies_to_billing_cycles)->toBe(['monthly', 'yearly'])
        ->and((int) ($coupon->max_total_uses ?? 0))->toBe(100)
        ->and((int) ($coupon->max_uses_per_customer ?? 0))->toBe(1)
        ->and((int) ($coupon->minimum_amount_iqd ?? 0))->toBe(5000);

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->call('openEditCouponModal', $coupon->id)
        ->set('name', 'Welcome 55')
        ->set('discountValue', '55')
        ->set('isActive', false)
        ->set('durationType', 'first_n_cycles')
        ->set('durationCycles', '3')
        ->call('saveCoupon')
        ->assertHasNoErrors();

    $coupon->refresh();

    expect($coupon->name)->toBe('Welcome 55')
        ->and((string) $coupon->discount_value)->toStartWith('55')
        ->and($coupon->is_active)->toBeFalse()
        ->and($coupon->duration_type?->value)->toBe('first_n_cycles')
        ->and((int) ($coupon->duration_cycles ?? 0))->toBe(3);
});
