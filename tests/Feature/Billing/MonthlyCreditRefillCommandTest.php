<?php

use App\Models\CreditMonthlyGrant;
use App\Models\Customer;
use App\Models\ServicePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function refillCustomer(): Customer
{
    return Customer::create([
        'username' => 'credit_refill_' . Str::lower(Str::random(8)),
        'email' => 'credit-refill-' . Str::lower(Str::random(8)) . '@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);
}

it('refills monthly credits once per due cycle and remains idempotent for the same month', function () {
    Carbon::setTestNow('2026-04-30 10:00:00');
    $customer = refillCustomer()->fresh(['wallet', 'activeServiceSubscription.servicePlan']);

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-04')
        ->exists())->toBeTrue();

    Carbon::setTestNow('2026-05-29 12:00:00');
    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-05')
        ->exists())->toBeFalse();

    Carbon::setTestNow('2026-05-30 12:00:00');

    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
    ])->assertSuccessful();

    $freePlanCredits = (int) ServicePlan::query()->where('code', 'free')->value('monthly_credits');
    $wallet = $customer->fresh()->wallet()->firstOrFail();

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-05')
        ->count())->toBe(1)
        ->and((int) ($wallet->subscription_balance_credits ?? 0))->toBe($freePlanCredits)
        ->and((string) optional($wallet->cycle_started_on)->toDateString())->toBe('2026-05-30');

    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
    ])->assertSuccessful();

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-05')
        ->count())->toBe(1);
});

it('uses last valid month day when the anniversary day does not exist', function () {
    Carbon::setTestNow('2026-01-31 09:00:00');
    $customer = refillCustomer();

    Carbon::setTestNow('2026-02-27 12:00:00');
    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
    ])->assertSuccessful();

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-02')
        ->exists())->toBeFalse();

    Carbon::setTestNow('2026-02-28 12:00:00');
    $this->artisan('credits:refill-monthly', [
        '--customer' => $customer->id,
    ])->assertSuccessful();

    expect(CreditMonthlyGrant::query()
        ->where('customer_id', $customer->id)
        ->where('year_month', '2026-02')
        ->exists())->toBeTrue();
});

