<?php

use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\User;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\PaymentMethodCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    Cache::flush();
    $this->seed(); // Existing isolated SQLite fixtures, never the application database.
    $admin = User::forceCreate(['name' => 'Consumer check', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => \App\Support\Admin\AdminAccess::CAPABILITIES]);
    $admin->profile()->create(['first_name' => 'Consumer', 'last_name' => 'Check']);
    $this->actingAs($admin, 'admin');
    $this->consumer = Customer::create(['username' => 'consumer_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($this->consumer, 'app');
});

it('consumes Admin service plan edits in the real subscription component after refresh', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    Livewire::test('admin::pages.payments.adm-payments-plans')->call('openEditPlanModal', $plan->id)
        ->set('adminChangeReason', 'Isolated consumer connection verification')->set('name', 'Connected Service Fixture')
        ->call('savePlan')->assertHasNoErrors();
    $app = Livewire::test('app::pages.subscription-plan.subscription-plan');
    expect(collect($app->get('plans'))->firstWhere('id', $plan->id)['name'])->toBe('Connected Service Fixture');
    Livewire::test('admin::pages.payments.adm-payments-plans')->set('adminChangeReason', 'Isolated availability verification')->call('togglePlanStatus', $plan->id)->assertHasNoErrors();
    expect(collect(Livewire::test('app::pages.subscription-plan.subscription-plan')->get('plans'))->pluck('id'))->not->toContain($plan->id);
    Http::assertNothingSent();
});

it('consumes Admin storage catalog edits in the real storage purchase component', function () {
    $plan = StoragePlan::where('is_active', true)->firstOrFail();
    Livewire::test('admin::pages.payments.adm-payments-storages')->call('openEditStorageModal', $plan->id)
        ->set('adminChangeReason', 'Isolated consumer connection verification')->set('name', 'Connected Storage Fixture')
        ->call('saveStoragePlan')->assertHasNoErrors();
    expect(collect(Livewire::test('app::pages.storage-plan.storage-plan')->get('plans'))->firstWhere('id', $plan->id)['name'])->toBe('Connected Storage Fixture');
    Http::assertNothingSent();
});

it('consumes Admin credit products for an eligible customer without creating a payment', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    \App\Models\CustomerServiceSubscription::create(['customer_id' => $this->consumer->id, 'service_plan_id' => $plan->id, 'source' => 'fixture', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    Livewire::test('admin::pages.payments.adm-payments-addons')->call('openEditProductModal', $product->id)
        ->set('adminChangeReason', 'Isolated consumer connection verification')->set('name', 'Connected Credit Fixture')
        ->call('saveProduct')->assertHasNoErrors();
    expect(collect(Livewire::test('app::pages.addon-credits.addon-credits')->get('products'))->firstWhere('id', $product->id)['name'])->toBe('Connected Credit Fixture');
    expect(\App\Domain\Payments\Models\Payment::count())->toBe(0);
    Http::assertNothingSent();
});

it('invalidates a warm payment catalog when Admin changes checkout visibility', function () {
    config(['payments.providers.fake.enabled' => true, 'payments.fake_enabled' => true]);
    $catalog = app(PaymentMethodCatalog::class);
    $catalog->flushCache();
    expect(collect($catalog->checkoutOptions('service_plan'))->pluck('code'))->toContain('fake');
    $method = PaymentMethod::where('code', 'fake')->firstOrFail();
    Livewire::test('admin::pages.payments.adm-payments-methods')->set('adminChangeReason', 'Isolated checkout visibility verification')->call('toggleMethodVisibility', $method->id)->assertHasNoErrors();
    expect(collect($catalog->checkoutOptions('service_plan'))->pluck('code'))->not->toContain('fake');
    Http::assertNothingSent();
});

it('uses the Admin exchange rate in the customer currency resolver', function () {
    Livewire::test('admin::pages.payments.adm-payments-currencies')->call('openEditModal', 'USD')
        ->set('adminChangeReason', 'Isolated exchange rate consumer verification')->set('rate', '0.001')
        ->set('effectiveAt', now()->subMinute()->format('Y-m-d\TH:i'))->set('expiresAt', '')
        ->call('saveRate')->assertHasNoErrors();
    $display = app(BillingCurrencyService::class)->convertBaseAmount(10000, 'USD');
    expect((float) $display['rounded_amount'])->toBe(10.0);
    expect(\App\Models\CurrencyExchangeRate::where('base_currency_code', 'IQD')->where('quote_currency_code', 'USD')->where('is_current', true)->count())->toBe(1);
    Livewire::test('admin::pages.payments.adm-payments-currencies')->set('adminChangeReason', 'Isolated currency availability verification')
        ->call('deactivateRate', 'USD')->assertHasNoErrors();
    expect(app(BillingCurrencyService::class)->currentDerivedRateForQuote('USD'))->toBeNull();
    Http::assertNothingSent();
});

it('uses an Admin saved coupon in the real checkout validator', function () {
    Livewire::test('admin::pages.payments.adm-payments-coupons')->call('openCreateCouponModal')
        ->set('adminChangeReason', 'Isolated coupon consumer verification')->set('code', 'CONSUMER10')->set('name', 'Consumer fixture')
        ->set('discountType', 'percent')->set('discountValue', '10')->set('targetType', 'plan_subscription')
        ->set('selectedPaymentMethods', ['fib'])->set('selectedServicePlanCodes', ['pro'])
        ->set('selectedBillingCycles', ['monthly'])->set('durationType', 'forever')
        ->call('saveCoupon')->assertHasNoErrors();
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $context = new \App\Services\Coupons\CouponContext($this->consumer, \App\Domain\Payments\Enums\PurchaseType::PLAN_SUBSCRIPTION, 'fib', 'service_plan', $plan->id, 'pro', 10000, 'monthly');
    $quote = app(\App\Services\Coupons\CouponService::class)->preview('CONSUMER10', $context);
    expect($quote['final_amount_iqd'])->toBe(9000);
    Http::assertNothingSent();
});

it('opens validates creates and edits tools without changing their immutable identity', function () {
    $editor = Livewire::test('admin::pages.services.adm-services-tools')->call('openToolCreateModal')
        ->assertDispatched('services-tools:modal-show')->set('adminChangeReason', 'Isolated tool editor verification');
    $editor->call('saveTool')->assertHasErrors(['toolCode', 'toolName']);
    $editor->set('toolCode', 'ux-fixture')->set('toolName', 'UX Tool')->call('saveTool')->assertHasNoErrors()->assertDispatched('services-tools:modal-hide');
    $tool = \App\Models\Tool::where('code', 'ux-fixture')->firstOrFail();
    $editor->call('openToolEditModal', $tool->id)->set('toolCode', 'forged-identity')->set('toolName', 'Updated UX Tool')
        ->call('saveTool')->assertHasNoErrors();
    expect($tool->fresh()->name)->toBe('Updated UX Tool')->and($tool->fresh()->code)->toBe('ux-fixture');
    $editor->call('toggleToolStatus', $tool->id);
    expect($tool->fresh()->is_active)->toBeFalse();
});

it('edits voice visibility and plan access while invalidating the Omni catalog', function () {
    $editor = Livewire::test('admin::pages.services.adm-services-voices')->call('openVoiceCreateModal')
        ->assertDispatched('services-voices:modal-show')->set('adminChangeReason', 'Isolated voice editor verification');
    $editor->call('saveVoice')->assertHasErrors(['voiceCode', 'voiceName']);
    $editor->set('voiceCode', 'ux_fixture')->set('voiceName', 'UX Voice')->set('voiceEngine', 'omni')->call('saveVoice')->assertHasNoErrors();
    $voice = \App\Models\Voice::where('code', 'ux_fixture')->firstOrFail();
    $version = Cache::get('omni-speaker-catalog:version');
    $editor->call('openVoiceEditModal', $voice->id)->set('voiceName', 'Updated UX Voice')->set('voiceVisibility', 'private')->call('saveVoice')->assertHasNoErrors();
    expect($voice->fresh()->is_public)->toBeFalse()->and($voice->fresh()->name)->toBe('Updated UX Voice')
        ->and(Cache::get('omni-speaker-catalog:version'))->toBeGreaterThan($version);
    $version = Cache::get('omni-speaker-catalog:version');
    $editor->call('openAccessCreateModal', $voice->id)->set('accessPlanId', ServicePlan::where('code', 'pro')->value('id'))
        ->call('saveAccess')->assertHasNoErrors();
    $access = \App\Models\PlanVoiceAccess::where('voice_id', $voice->id)->firstOrFail();
    expect(Cache::get('omni-speaker-catalog:version'))->toBeGreaterThan($version);
    $editor->call('openAccessEditModal', $access->id)->set('accessVisibility', 'private')->call('saveAccess')->assertHasNoErrors();
    expect($access->fresh()->is_public)->toBeFalse();
    $editor->call('toggleAccessStatus', $access->id)->call('toggleVoiceStatus', $voice->id);
    expect($access->fresh()->is_active)->toBeFalse()->and($voice->fresh()->is_active)->toBeFalse();
});

it('creates validates and toggles entitlements through the existing scoped editor', function () {
    $plan = ServicePlan::create(['code' => 'ux_plan', 'name' => 'UX Fixture', 'is_active' => true, 'is_free' => false, 'monthly_credits' => 0, 'api_allowed_tools' => []]);
    $action = \App\Models\ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    $editor = Livewire::test('admin::pages.services.adm-services-entitlements')->call('openEntitlementCreateModal')
        ->assertDispatched('services-entitlements:modal-show')->set('adminChangeReason', 'Isolated entitlement editor verification');
    $editor->call('saveEntitlement')->assertHasErrors(['entitlementServicePlanId', 'entitlementToolActionId']);
    $editor->set('entitlementServicePlanId', $plan->id)->set('entitlementToolActionId', $action->id)->set('entitlementChannel', 'api')
        ->set('entitlementLimitsJson', '{"max_pages":5}')->call('saveEntitlement')->assertHasNoErrors();
    $row = \App\Models\PlanEntitlement::where('service_plan_id', $plan->id)->firstOrFail();
    expect($plan->fresh()->api_allowed_tools)->toBe(['v2:ocr'])->and($row->limits)->toBe(['max_pages' => 5]);
    $editor->call('toggleEntitlementAllowed', $row->id)->assertHasNoErrors();
    expect($row->fresh()->allowed)->toBeFalse()->and($plan->fresh()->api_allowed_tools)->toBe([]);
});
