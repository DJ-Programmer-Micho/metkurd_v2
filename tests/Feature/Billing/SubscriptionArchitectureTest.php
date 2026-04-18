<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\StorageQuotaExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
});

afterEach(function () {
    Carbon::setTestNow();
});

function billingArchitectureCustomer(string $email, string $username): Customer
{
    return Customer::create([
        'username' => $username,
        'email' => $email,
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['usage', 'wallet', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
}

function grantPaidMainPlan(Customer $customer, string $code = 'pro'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan;
}

function grantPaidStoragePlan(Customer $customer, string $code = 'premium-10240'): StoragePlan
{
    $plan = StoragePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
        'provider' => 'fake',
    ]);

    return $plan;
}

it('hides the free plan card when the customer already has an active paid main plan', function () {
    $customer = billingArchitectureCustomer('paid-plan-page@example.com', 'paid_plan_page_user');
    grantPaidMainPlan($customer);

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.subscription-plan.subscription-plan')
        ->assertSee('PRO')
        ->assertDontSee('FREE');
});

it('schedules main plan cancellation for period end only and keeps paid access until then', function () {
    $customer = billingArchitectureCustomer('cancel-main@example.com', 'cancel_main_user');
    $plan = grantPaidMainPlan($customer);
    $subscription = $customer->fresh()->activeServiceSubscription()->firstOrFail();
    $expectedEnd = $subscription->cycle_ends_on?->copy()->endOfDay();

    $scheduled = app(ScheduleServicePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->canceled_at)->not->toBeNull()
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and($scheduled->ends_at?->toDateTimeString())->toBe($expectedEnd?->toDateTimeString())
        ->and($customer->fresh()->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->subMinute());

    expect(Customer::query()->findOrFail($customer->id)->currentServicePlanId())->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);

    expect($freshCustomer->currentServicePlan()?->code)->toBe('free')
        ->and($freshCustomer->hasPaidServicePlan())->toBeFalse();
});

it('schedules storage cancellation for period end and downgrades entitlement to the free storage plan afterwards', function () {
    $customer = billingArchitectureCustomer('cancel-storage@example.com', 'cancel_storage_user');
    $plan = grantPaidStoragePlan($customer);
    $subscription = $customer->fresh()->activeStorageSubscription()->firstOrFail();
    $expectedEnd = $subscription->cycle_ends_on?->copy()->endOfDay();

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());

    expect($scheduled->canceled_at)->not->toBeNull()
        ->and($scheduled->auto_renew)->toBeFalse()
        ->and($scheduled->ends_at?->toDateTimeString())->toBe($expectedEnd?->toDateTimeString())
        ->and((int) ($customer->fresh()->currentStoragePlan()?->id ?? 0))->toBe($plan->id);

    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);
    $state = $freshCustomer->storageQuotaState();

    expect($freshCustomer->currentStoragePlan()?->code)->toBe('free-512')
        ->and((int) ($state['current_limit_mb'] ?? 0))->toBe(512);
});

it('keeps existing files intact after a storage downgrade makes the account over quota', function () {
    Storage::fake('s3');

    $customer = billingArchitectureCustomer('storage-preserve@example.com', 'storage_preserve_user');
    grantPaidStoragePlan($customer);

    $storage = app(CustomerOutputStorage::class);
    $path = 'renders/test/preserved.txt';

    $storage->saveTextToS3($customer->id, $path, 'preserve me', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 700 * 1024 * 1024]
    );

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());
    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $freshCustomer = Customer::query()->findOrFail($customer->id);
    $state = $freshCustomer->storageQuotaState();

    expect($state['over_quota'])->toBeTrue()
        ->and(Storage::disk('s3')->exists($path))->toBeTrue()
        ->and(CustomerFile::query()->where('customer_id', $customer->id)->where('path', $path)->where('status', 'active')->exists())->toBeTrue();
});

it('blocks new uploads while the downgraded storage account is over quota and allows them again after usage is reduced', function () {
    Storage::fake('s3');

    $customer = billingArchitectureCustomer('storage-block@example.com', 'storage_block_user');
    grantPaidStoragePlan($customer);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 700 * 1024 * 1024]
    );

    $scheduled = app(ScheduleStoragePlanCancellation::class)->handle($customer->fresh());
    Carbon::setTestNow($scheduled->ends_at?->copy()->addSecond());

    $storage = app(CustomerOutputStorage::class);

    expect(fn () => $storage->saveTextToS3($customer->id, 'renders/test/blocked.txt', 'blocked', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]))->toThrow(StorageQuotaExceededException::class);

    CustomerUsage::query()
        ->where('customer_id', $customer->id)
        ->update(['storage_used_bytes' => 100 * 1024 * 1024]);

    $saved = $storage->saveTextToS3($customer->id, 'renders/test/restored.txt', 'restored', [
        'tool' => 'tran',
        'purpose' => 'render',
    ]);

    expect(Storage::disk('s3')->exists('renders/test/restored.txt'))->toBeTrue()
        ->and((int) ($saved['bytes'] ?? 0))->toBeGreaterThan(0);
});

it('shows the storage cancellation warning with the current usage, current limit, and future limit values', function () {
    $customer = billingArchitectureCustomer('storage-warning@example.com', 'storage_warning_user');
    grantPaidStoragePlan($customer);

    CustomerUsage::query()->updateOrCreate(
        ['customer_id' => $customer->id],
        ['storage_used_bytes' => 9728 * 1024 * 1024]
    );

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.storage-plan.storage-plan')
        ->call('openCancelConfirm')
        ->assertSee(__('Current usage:'))
        ->assertSee(number_format(9728) . ' MB')
        ->assertSee(number_format(10240) . ' MB')
        ->assertSee(number_format(512) . ' MB')
        ->assertSee('uploads and storage-growing actions will be blocked');
});
