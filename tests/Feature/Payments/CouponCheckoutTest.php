<?php

use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Enums\CouponDiscountType;
use App\Enums\CouponDurationType;
use App\Enums\CouponRedemptionStatus;
use App\Enums\CouponTargetType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Coupons\CouponContext;
use App\Services\Coupons\CouponLifecycleService;
use App\Services\Coupons\CouponService;
use App\Services\Payments\PaymentFeeCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    couponFeatureConfigureFib();
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function couponFeatureConfigureFib(): void
{
    app()->setLocale('en');

    config()->set('payments.providers.fib.enabled', true);
    config()->set('fib.enabled', true);
    config()->set('fib.realm', 'fib-online-shop');
    config()->set('fib.profiles.payment.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.payment.client_id', 'fib-test-client');
    config()->set('fib.profiles.payment.client_secret', 'fib-secret');
    config()->set('fib.profiles.subscription.base_url', 'https://fib-stage.fib.iq');
    config()->set('fib.profiles.subscription.client_id', 'fib-subscription-client');
    config()->set('fib.profiles.subscription.client_secret', 'fib-subscription-secret');
    config()->set('fib.callback_base_url', 'https://metkurd.test');
    config()->set('fib.callback_secret', 'fib-callback-secret');
    config()->set('fib.callback_secret_header', 'x-callback-secret');
    config()->set('fib.payment.category', 'ECOMMERCE');
    config()->set('fib.payment.expires_in', 'PT1H');
    config()->set('fib.payment.refundable_for', 'PT48H');
    config()->set('fib.subscription.expires_in', 'PT1H');
    config()->set('fib.subscription.trial_period', null);
    config()->set('fib.subscription.hourly_testing_enabled', false);
    config()->set('fib.subscription.intervals.monthly', 'P1M');
    config()->set('fib.subscription.intervals.yearly', 'P1Y');
    config()->set('fib.subscription.intervals.hourly', 'PT1H');
    config()->set('fib.token_ttl_seconds', 60);
    config()->set('fib.http.timeout', 15);
    config()->set('fib.http.retries', 1);
    config()->set('fib.http.retry_sleep_ms', 1);
    config()->set('fib.paths.token', '/auth/realms/fib-online-shop/protocol/openid-connect/token');
    config()->set('fib.paths.payments', '/protected/v1/payments');
    config()->set('fib.paths.payment_status', '/protected/v1/payments/{paymentId}/status');
    config()->set('fib.paths.payment_cancel', '/protected/v1/payments/{paymentId}/cancel');
    config()->set('fib.paths.subscriptions', '/protected/v1/subscriptions');
    config()->set('fib.paths.subscription_status', '/protected/v1/subscriptions/{subscriptionId}');
    config()->set('fib.paths.subscription_cancel', '/protected/v1/subscriptions/{subscriptionId}/cancel');
}

function couponFeatureCustomer(?string $email = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => 'coupon_'.$suffix,
        'email' => $email ?? 'coupon-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function couponFeatureStageUrl(string $path): string
{
    return 'https://fib-stage.fib.iq'.$path;
}

function couponFeatureGrantPaidPlan(Customer $customer, string $code = 'pro'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(\App\Services\Billing\PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

function couponFeatureFakePlanSubscription(string $subscriptionId = 'fib-plan-coupon-sub-123'): void
{
    Http::fake([
        couponFeatureStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        couponFeatureStageUrl('/protected/v1/subscriptions') => Http::response([
            'subscriptionId' => $subscriptionId,
            'readableCode' => 'SUB-CODE-123',
            'qrCode' => 'data:image/png;base64,fake-subscription-qr',
            'appLink' => 'https://fib.iq/app/'.$subscriptionId,
            'validUntil' => '2026-05-01T10:15:00Z',
        ], 201),
    ]);
}

function couponFeatureFakeAddonPayment(string $paymentId = 'fib-addon-coupon-pay-123'): void
{
    Http::fake([
        couponFeatureStageUrl('/auth/realms/fib-online-shop/protocol/openid-connect/token') => Http::response([
            'access_token' => 'fib-access-token',
            'expires_in' => 60,
        ], 200),
        couponFeatureStageUrl('/protected/v1/payments') => Http::response([
            'paymentId' => $paymentId,
            'readableCode' => 'PAY-CODE-123',
            'qrCode' => 'data:image/png;base64,fake-payment-qr',
            'personalAppLink' => 'https://fib.iq/personal/'.$paymentId,
            'businessAppLink' => 'https://fib.iq/business/'.$paymentId,
            'corporateAppLink' => 'https://fib.iq/corporate/'.$paymentId,
            'validUntil' => '2026-05-01T10:15:00Z',
        ], 201),
    ]);
}

function couponFeatureCreateCoupon(array $overrides = []): Coupon
{
    return Coupon::query()->create(array_merge([
        'code' => 'WELCOME50',
        'name' => 'Welcome 50',
        'description' => 'Test coupon',
        'is_active' => true,
        'is_public' => true,
        'is_stackable' => false,
        'discount_type' => CouponDiscountType::PERCENT,
        'discount_value' => 50,
        'target_type' => CouponTargetType::PLAN_SUBSCRIPTION,
        'supported_payment_methods' => ['fib'],
        'duration_type' => CouponDurationType::FOREVER,
        'currency' => 'IQD',
    ], $overrides));
}

function couponFeaturePlanContext(
    Customer $customer,
    ServicePlan $plan,
    string $cycle = 'monthly',
    string $provider = 'fib',
): CouponContext {
    return new CouponContext(
        customer: $customer,
        purchaseType: PurchaseType::PLAN_SUBSCRIPTION,
        provider: $provider,
        purchasableType: ServicePlan::class,
        purchasableId: (int) $plan->id,
        itemCode: (string) $plan->code,
        originalAmountIqd: $plan->priceIqdForCycle($cycle === 'yearly' ? 'yearly' : 'monthly'),
        billingCycle: $cycle,
        isRecurring: true,
    );
}

function couponFeatureAddonContext(Customer $customer, CreditProduct $product, string $provider = 'fib'): CouponContext
{
    return new CouponContext(
        customer: $customer,
        purchaseType: PurchaseType::ADDON_CREDITS,
        provider: $provider,
        purchasableType: CreditProduct::class,
        purchasableId: (int) $product->id,
        itemCode: (string) $product->code,
        originalAmountIqd: $product->priceIqdAmount(),
    );
}

it('applies a valid coupon to a plan subscription checkout and stores the discounted amounts', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'PRO10',
        'name' => 'Pro Forever 10',
        'discount_type' => CouponDiscountType::PERCENT,
        'discount_value' => 10,
        'applies_to_codes' => ['PRO'],
    ]);

    couponFeatureFakePlanSubscription();

    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly', $coupon->code)->fresh();

    $original = $plan->priceIqdForCycle('monthly');
    $discount = (int) round($original * 0.10);
    $final = $original - $discount;
    $gross = (int) app(PaymentFeeCalculator::class)->quote('fib', $final)['gross_amount_iqd'];

    expect((int) round((float) $payment->original_amount_iqd))->toBe($original)
        ->and((int) round((float) $payment->discount_amount_iqd))->toBe($discount)
        ->and((int) round((float) $payment->discounted_amount_iqd))->toBe($final)
        ->and((int) round((float) $payment->amount))->toBe($gross)
        ->and($payment->coupon_code)->toBe('PRO10')
        ->and(data_get($payment->purchase_snapshot, 'coupon.code'))->toBe('PRO10')
        ->and(CouponRedemption::query()->where('payment_id', $payment->id)->where('status', CouponRedemptionStatus::APPLIED)->exists())->toBeTrue();

    $coupon->refresh();

    expect((int) ($coupon->used_count ?? 0))->toBe(1);

    Http::assertSent(function ($request) use ($gross) {
        if ($request->url() !== couponFeatureStageUrl('/protected/v1/subscriptions')) {
            return true;
        }

        return data_get($request->data(), 'monetaryValue.amount') === (string) $gross
            && data_get($request->data(), 'interval') === 'P1M';
    });
});

it('applies a valid coupon to a storage subscription checkout', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    $plan = StoragePlan::query()->where('code', 'premium-10240')->firstOrFail();
    $plan->update(['price_iqd' => 30000]);
    $coupon = couponFeatureCreateCoupon([
        'code' => 'STORE25',
        'name' => 'Storage 25',
        'discount_type' => CouponDiscountType::PERCENT,
        'discount_value' => 25,
        'target_type' => CouponTargetType::STORAGE_SUBSCRIPTION,
        'applies_to_codes' => [strtoupper($plan->code)],
    ]);

    couponFeatureFakePlanSubscription('fib-storage-coupon-sub-123');

    $payment = app(CreateStorageSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly', $coupon->code)->fresh();

    expect($payment->coupon_code)->toBe('STORE25')
        ->and((int) round((float) $payment->original_amount_iqd))->toBe($plan->priceIqdAmount())
        ->and((int) round((float) $payment->discount_amount_iqd))->toBe((int) round($plan->priceIqdAmount() * 0.25))
        ->and(data_get($payment->purchase_snapshot, 'coupon.code'))->toBe('STORE25');
});

it('applies a valid coupon to an addon one-time checkout', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'ADDON15',
        'name' => 'Addon 15',
        'discount_type' => CouponDiscountType::PERCENT,
        'discount_value' => 15,
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment();

    $payment = app(CreateAddonPayment::class)->handle($customer, $product->id, $coupon->code)->fresh();

    expect($payment->purchase_type)->toBe(PurchaseType::ADDON_CREDITS)
        ->and($payment->coupon_code)->toBe('ADDON15')
        ->and((int) round((float) $payment->original_amount_iqd))->toBe($product->priceIqdAmount())
        ->and((int) round((float) $payment->discounted_amount_iqd))->toBeLessThan($product->priceIqdAmount())
        ->and(data_get($payment->purchase_snapshot, 'coupon.code'))->toBe('ADDON15');
});

it('rejects invalid coupon codes before creating the checkout', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    expect(fn () => app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly', 'MISSING'))
        ->toThrow(ValidationException::class, 'invalid');

    expect(Payment::query()->count())->toBe(0);
});

it('rejects expired coupons', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-22 12:00:00'));

    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $coupon = couponFeatureCreateCoupon([
        'code' => 'OLD50',
        'ends_at' => now()->subDay(),
    ]);

    try {
        app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $plan));
        $this->fail('Expected expired coupon validation failure.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))
            ->toContain('expired');
    }
});

it('rejects inactive coupons', function () {
    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $coupon = couponFeatureCreateCoupon([
        'code' => 'SLEEPING',
        'is_active' => false,
    ]);

    expect(fn () => app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $plan)))
        ->toThrow(ValidationException::class, 'inactive');
});

it('allows first-time subscriber coupons only for eligible customers', function () {
    $newCustomer = couponFeatureCustomer('first-time-new@example.com');
    $existingCustomer = couponFeatureCustomer('first-time-existing@example.com');
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $coupon = couponFeatureCreateCoupon([
        'code' => 'FIRSTONLY',
        'first_time_subscribers_only' => true,
        'target_type' => CouponTargetType::PLAN_SUBSCRIPTION,
    ]);

    $preview = app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($newCustomer, $plan));

    expect($preview['code'])->toBe('FIRSTONLY');

    app(\App\Services\Billing\PlanSwitcher::class)->switchServicePlan($existingCustomer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    expect(fn () => app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($existingCustomer->fresh(), $plan)))
        ->toThrow(ValidationException::class, 'first-time');
});

it('enforces plan code restrictions and yearly-only billing cycle restrictions', function () {
    $customer = couponFeatureCustomer();
    $proPlan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $studentPlan = ServicePlan::query()->where('code', 'student')->firstOrFail();

    $coupon = couponFeatureCreateCoupon([
        'code' => 'STUDENTYEAR',
        'applies_to_codes' => ['STUDENT'],
        'applies_to_billing_cycles' => ['yearly'],
    ]);

    expect(fn () => app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $proPlan, 'yearly')))
        ->toThrow(ValidationException::class, 'selected item');

    expect(fn () => app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $studentPlan, 'monthly')))
        ->toThrow(ValidationException::class, 'billing cycle');

    $preview = app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $studentPlan, 'yearly'));

    expect($preview['billing_cycle'])->toBe('yearly');
});

it('tracks coupon duration applicability for first-cycle and first-n-cycle rules', function () {
    $service = app(CouponLifecycleService::class);

    $firstCycle = couponFeatureCreateCoupon([
        'code' => 'FIRSTCYCLE',
        'duration_type' => CouponDurationType::FIRST_CYCLE,
    ]);

    $firstThree = couponFeatureCreateCoupon([
        'code' => 'FIRSTTHREE',
        'duration_type' => CouponDurationType::FIRST_N_CYCLES,
        'duration_cycles' => 3,
    ]);

    expect($service->appliesToCycle($firstCycle, 1))->toBeTrue()
        ->and($service->appliesToCycle($firstCycle, 2))->toBeFalse()
        ->and($service->appliesToCycle($firstThree, 1))->toBeTrue()
        ->and($service->appliesToCycle($firstThree, 3))->toBeTrue()
        ->and($service->appliesToCycle($firstThree, 4))->toBeFalse();
});

it('rejects limited-duration recurring coupons for the current fib subscription checkout', function () {
    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();

    $coupon = couponFeatureCreateCoupon([
        'code' => 'FIRSTMONTH',
        'duration_type' => CouponDurationType::FIRST_CYCLE,
    ]);

    try {
        app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $plan));
        $this->fail('Expected recurring coupon compatibility validation failure.');
    } catch (ValidationException $exception) {
        $message = collect($exception->errors())->flatten()->implode(' ');

        expect($message)
            ->toContain('fixed recurring amount')
            ->toContain('forever recurring discount');
    }
});

it('enforces total usage limits across customers', function () {
    Http::preventStrayRequests();

    $firstCustomer = couponFeatureCustomer('usage-limit-a@example.com');
    $secondCustomer = couponFeatureCustomer('usage-limit-b@example.com');
    couponFeatureGrantPaidPlan($firstCustomer);
    couponFeatureGrantPaidPlan($secondCustomer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'ONLYONE',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'max_total_uses' => 1,
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-limit-1');
    app(CreateAddonPayment::class)->handle($firstCustomer, $product->id, $coupon->code);

    couponFeatureFakeAddonPayment('fib-addon-limit-2');

    expect(fn () => app(CreateAddonPayment::class)->handle($secondCustomer, $product->id, $coupon->code))
        ->toThrow(ValidationException::class, 'usage limit');

    expect((int) $coupon->fresh()->used_count)->toBe(1);
});

it('enforces per-customer coupon limits for one-time add-on payments', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer('per-customer@example.com');
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'ONCEPERUSER',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'max_uses_per_customer' => 1,
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-user-1');
    app(CreateAddonPayment::class)->handle($customer, $product->id, $coupon->code);

    couponFeatureFakeAddonPayment('fib-addon-user-2');

    expect(fn () => app(CreateAddonPayment::class)->handle($customer, $product->id, $coupon->code))
        ->toThrow(ValidationException::class, 'maximum number');
});

it('renders the applied coupon summary on the fib payment page', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'SHOWSAVE',
        'name' => 'Show Save',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-page-1');

    $payment = app(CreateAddonPayment::class)->handle($customer, $product->id, $coupon->code);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('Coupon')
        ->assertSee('SHOWSAVE')
        ->assertSee('Discounted Amount');
});

it('hides coupon input on fib checkout when only areeba coupons are eligible', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    couponFeatureCreateCoupon([
        'code' => 'AREEBAONLY',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'supported_payment_methods' => ['areeba'],
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-areeba-only');
    $payment = app(CreateAddonPayment::class)->handle($customer, $product->id);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertDontSee('Apply Coupon')
        ->assertDontSee('Coupons are validated server-side');
});

it('shows coupon input on fib checkout when a fib-eligible coupon exists', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    couponFeatureCreateCoupon([
        'code' => 'FIBONLY',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'supported_payment_methods' => ['fib'],
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-fib-only');
    $payment = app(CreateAddonPayment::class)->handle($customer, $product->id);

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertSee('Coupon Code')
        ->assertSee('Apply Coupon');
});

it('accepts coupons configured for both fib and areeba contexts', function () {
    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'BOTHWAYS',
        'target_type' => CouponTargetType::PLAN_SUBSCRIPTION,
        'duration_type' => CouponDurationType::FOREVER,
        'supported_payment_methods' => ['fib', 'areeba'],
        'applies_to_codes' => [strtoupper($plan->code)],
    ]);

    $fibPreview = app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $plan, 'monthly', 'fib'));
    $areebaPreview = app(CouponService::class)->preview($coupon->code, couponFeaturePlanContext($customer, $plan, 'monthly', 'areeba'));

    expect($fibPreview['code'])->toBe('BOTHWAYS')
        ->and($areebaPreview['code'])->toBe('BOTHWAYS');
});

it('rejects coupons when the selected payment method is not eligible', function () {
    $customer = couponFeatureCustomer();
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'FIBMETHOD',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'supported_payment_methods' => ['fib'],
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    expect(fn () => app(CouponService::class)->preview($coupon->code, couponFeatureAddonContext($customer, $product, 'areeba')))
        ->toThrow(ValidationException::class, 'payment method');
});

it('removes coupon input from the old pre-checkout plan and storage modals', function () {
    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);

    $this->actingAs($customer, 'app')
        ->get(route('subscription-plan', ['locale' => 'en']))
        ->assertOk()
        ->assertDontSee('Enter coupon code')
        ->assertDontSee('Coupon Code');

    $this->actingAs($customer, 'app')
        ->get(route('storage-plan', ['locale' => 'en']))
        ->assertOk()
        ->assertDontSee('Enter coupon code')
        ->assertDontSee('Coupon Code');
});

it('applies coupon from the fib payment page by creating a new checkout with updated totals', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    couponFeatureGrantPaidPlan($customer);
    $product = CreditProduct::query()->where('is_active', true)->firstOrFail();
    $coupon = couponFeatureCreateCoupon([
        'code' => 'PAGE10',
        'target_type' => CouponTargetType::ADDON_CREDITS,
        'duration_type' => CouponDurationType::ONCE,
        'discount_type' => CouponDiscountType::PERCENT,
        'discount_value' => 10,
        'supported_payment_methods' => ['fib'],
        'applies_to_codes' => [strtoupper($product->code)],
    ]);

    couponFeatureFakeAddonPayment('fib-addon-page-base');
    $payment = app(CreateAddonPayment::class)->handle($customer, $product->id)->fresh();
    $initialCount = Payment::query()->count();

    couponFeatureFakeAddonPayment('fib-addon-page-updated');

    Livewire::actingAs($customer, 'app')
        ->test('app::pages.payments.fib-payment', ['payment' => $payment])
        ->set('couponCode', $coupon->code)
        ->call('applyCoupon');

    $newPayment = Payment::query()->latest('id')->first();

    expect($newPayment)->not->toBeNull()
        ->and($newPayment->id)->not->toBe($payment->id)
        ->and(Payment::query()->count())->toBe($initialCount + 1)
        ->and($newPayment->coupon_code)->toBe('PAGE10')
        ->and((int) round((float) $newPayment->discount_amount_iqd))->toBeGreaterThan(0)
        ->and((int) round((float) $newPayment->discounted_amount_iqd))->toBeLessThan((int) round((float) $newPayment->original_amount_iqd));
});

it('hides fib recurring coupon input when available recurring coupons are provider-incompatible', function () {
    Http::preventStrayRequests();

    $customer = couponFeatureCustomer();
    $plan = ServicePlan::query()->where('code', 'pro')->firstOrFail();
    couponFeatureCreateCoupon([
        'code' => 'FIRSTONLYFIB',
        'target_type' => CouponTargetType::PLAN_SUBSCRIPTION,
        'duration_type' => CouponDurationType::FIRST_CYCLE,
        'supported_payment_methods' => ['fib'],
        'applies_to_codes' => [strtoupper($plan->code)],
    ]);

    couponFeatureFakePlanSubscription('fib-sub-plan-incompatible');
    $payment = app(CreatePlanSubscriptionPayment::class)->handle($customer, $plan->id, 'monthly');

    $this->actingAs($customer, 'app')
        ->get(route('payments.fib.show', ['locale' => 'en', 'payment' => $payment]))
        ->assertOk()
        ->assertDontSee('Apply Coupon')
        ->assertDontSee('Coupons are validated server-side');
});
