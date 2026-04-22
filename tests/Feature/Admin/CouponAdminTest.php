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
        ->set('selectedServicePlanCodes', ['pro', 'student'])
        ->set('selectedBillingCycles', ['monthly', 'yearly'])
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
        ->set('selectedBillingCycles', ['monthly'])
        ->call('saveCoupon')
        ->assertHasNoErrors();

    $coupon->refresh();

    expect($coupon->name)->toBe('Welcome 55')
        ->and((string) $coupon->discount_value)->toStartWith('55')
        ->and($coupon->is_active)->toBeFalse()
        ->and($coupon->duration_type?->value)->toBe('forever')
        ->and($coupon->applies_to_billing_cycles)->toBe(['monthly']);
});

it('surfaces unsupported legacy recurring durations until the admin selects a provider-compatible option', function () {
    $admin = couponAdminUser();

    $this->actingAs($admin, 'admin');

    $coupon = Coupon::query()->create([
        'code' => 'LEGACY3',
        'name' => 'Legacy 3 Cycles',
        'is_active' => true,
        'is_public' => true,
        'is_stackable' => false,
        'discount_type' => 'percent',
        'discount_value' => 25,
        'target_type' => CouponTargetType::PLAN_SUBSCRIPTION,
        'duration_type' => 'first_n_cycles',
        'duration_cycles' => 3,
    ]);

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->call('openEditCouponModal', $coupon->id)
        ->assertSee('legacy recurring duration')
        ->assertSee('fixed recurring amount')
        ->call('saveCoupon')
        ->assertHasErrors(['durationType']);

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->call('openEditCouponModal', $coupon->id)
        ->set('durationType', 'forever')
        ->call('saveCoupon')
        ->assertHasNoErrors();

    $coupon->refresh();

    expect($coupon->duration_type?->value)->toBe('forever')
        ->and($coupon->duration_cycles)->toBeNull();
});

it('keeps add-on coupons one-time and hides recurring-only behavior', function () {
    $admin = couponAdminUser();

    $this->actingAs($admin, 'admin');

    $product = \App\Models\CreditProduct::query()->where('is_active', true)->firstOrFail();

    Livewire::test('admin::pages.payments.adm-payments-coupons')
        ->call('openCreateCouponModal')
        ->set('targetType', 'addon_credits')
        ->assertSee('One-time add-on coupon')
        ->assertDontSee('Supported recurring duration')
        ->set('code', 'ADDON25')
        ->set('name', 'Addon 25')
        ->set('discountType', 'percent')
        ->set('discountValue', '25')
        ->set('selectedAddonCodes', [$product->code])
        ->set('durationType', 'first_n_cycles')
        ->set('durationCycles', '3')
        ->set('selectedBillingCycles', ['monthly'])
        ->call('saveCoupon')
        ->assertHasNoErrors();

    $coupon = Coupon::query()->where('code', 'ADDON25')->firstOrFail();

    expect($coupon->target_type)->toBe(CouponTargetType::ADDON_CREDITS)
        ->and($coupon->duration_type?->value)->toBe('once')
        ->and($coupon->duration_cycles)->toBeNull()
        ->and($coupon->applies_to_billing_cycles)->toBeNull();
});
