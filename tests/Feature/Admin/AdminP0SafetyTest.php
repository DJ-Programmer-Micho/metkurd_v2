<?php

use App\Domain\Payments\Actions\InvalidateAdminReviewPayment;
use App\Domain\Payments\Data\FibPaymentStatusData;
use App\Domain\Payments\Data\FibSubscriptionStatusData;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibOneTimePaymentService;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\AdminAuditEvent;
use App\Models\AdminOperation;
use App\Models\CreditLedger;
use App\Models\CreditOrder;
use App\Models\CreditProduct;
use App\Models\CurrencyExchangeRate;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\PaymentMethod;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\Admin\AdminCatalogDeletion;
use App\Services\Admin\AdminFinancialCorrections;
use App\Services\Admin\AdminPaymentReconciliation;
use App\Services\Admin\AdminProviderEvidence;
use App\Services\Billing\CreditService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
});

function p0Admin(array $capabilities = AdminAccess::CAPABILITIES, int $status = 1): User
{
    return User::forceCreate(['name' => 'P0 Operator', 'email' => Str::uuid().'@example.test',
        'password' => 'test-password', 'status' => $status, 'admin_capabilities' => $capabilities]);
}

function p0Customer(): Customer
{
    $customer = Customer::create(['username' => 'p0_'.Str::random(10), 'email' => Str::uuid().'@example.test',
        'password' => 'test-password', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    app(PlanSwitcher::class)->switchServicePlan($customer, ServicePlan::where('code', 'student')->firstOrFail()->id,
        ['provider' => 'fake', 'billing_cycle' => 'monthly']);

    return $customer->fresh();
}

function p0Payment(Customer $customer, $product = null, array $attributes = []): Payment
{
    $product ??= ServicePlan::where('code', 'pro')->firstOrFail();

    return Payment::create(array_merge([
        'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'provider' => PaymentProvider::FIB,
        'purchase_type' => $product instanceof CreditProduct ? PurchaseType::ADDON_CREDITS : ($product instanceof StoragePlan ? PurchaseType::STORAGE_SUBSCRIPTION : PurchaseType::PLAN_SUBSCRIPTION),
        'payment_mode' => PaymentMode::ONE_TIME, 'provider_object_type' => PaymentProviderObjectType::PAYMENT,
        'status' => PaymentStatus::AWAITING_CUSTOMER_ACTION, 'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
        'review_required_at' => now(), 'local_reference' => 'P0-'.Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
        'fib_payment_id' => 'test-payment-'.Str::uuid(), 'amount' => 35000, 'currency' => 'IQD',
        'purchase_snapshot' => ['name' => $product->name, 'code' => $product->code, 'billing_cycle' => 'monthly'],
        'purchasable_type' => $product->getMorphClass(), 'purchasable_id' => $product->id,
    ], $attributes));
}

function p0PaidEvidence(Payment $payment, ?string $candidate = null): FibPaymentStatusData
{
    return FibPaymentStatusData::fromArray(['paymentId' => $candidate ?? $payment->fib_payment_id, 'status' => 'PAID',
        'amount' => ['amount' => $payment->amount, 'currency' => $payment->currency], 'paidAt' => now()->toIso8601String(),
        'localReference' => $payment->local_reference]);
}

it('denies guests and inactive admins at the route and Livewire boundaries', function () {
    $this->get('/en/adm/customers/register')->assertRedirect();
    Livewire::test('admin::pages.customers.adm-customers-register')->assertForbidden();
    $this->actingAs(p0Admin(status: 0), 'admin');
    $this->get('/en/adm/customers/register')->assertForbidden();
    Livewire::test('admin::pages.customers.adm-customers-register')->assertForbidden();
});

it('denies support financial calls before resolving manipulated public target IDs', function () {
    $this->actingAs(p0Admin(['admin.read']), 'admin');
    Livewire::test('admin::pages.customers.adm-customers-register')
        ->set('customerFilter', '999999')->set('servicePlanAdjustmentId', '999999')
        ->call('applyServicePlanAdjustment')->assertForbidden();
    expect(AdminOperation::count())->toBe(0);
});

it('rechecks revoked capabilities and inactive status on an existing component', function () {
    $admin = p0Admin();
    $this->actingAs($admin, 'admin');
    $component = Livewire::test('admin::pages.customers.adm-customers-register');
    $admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $component->call('applyAddonAdjustment')->assertForbidden();
    $admin->forceFill(['status' => 0])->save();
    Livewire::test('admin::pages.services.adm-services-tools')->assertForbidden();
});

it('does not grant financial permissions through mass assignment', function () {
    $admin = p0Admin(['admin.read']);
    $admin->fill(['admin_capabilities' => AdminAccess::CAPABILITIES])->save();
    expect($admin->fresh()->admin_capabilities)->toBe(['admin.read']);
});

it('permits a finance admin and reuses one addon intent after consumption', function () {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $this->actingAs(p0Admin(['admin.finance']), 'admin');
    $id = (string) Str::uuid();
    $apiBefore = $customer->apiWallet()->first()->balance_credits;
    $ordersBefore = CreditOrder::count();
    $service = app(AdminFinancialCorrections::class);
    $one = $service->addon($id, $customer->id, $product->id, 'Approved goodwill add-on grant.');
    app(CreditService::class)->charge($customer->id, 10, 'test_usage');
    $afterSpend = $customer->wallet()->first()->balance_credits;
    $two = $service->addon($id, $customer->id, $product->id, 'Approved goodwill add-on grant.');
    expect($two)->toBe($one)->and(CreditOrder::count())->toBe($ordersBefore + 1)
        ->and($customer->wallet()->first()->balance_credits)->toBe($afterSpend)
        ->and($customer->apiWallet()->first()->balance_credits)->toBe($apiBefore)
        ->and(AdminOperation::find($id)->status)->toBe('completed');
});

it('rejects changed payload or actor under an existing intent', function () {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $this->actingAs(p0Admin(), 'admin');
    $id = (string) Str::uuid();
    $service = app(AdminFinancialCorrections::class);
    $service->addon($id, $customer->id, $product->id, 'Approved customer credit correction.');
    expect(fn () => $service->addon($id, $customer->id, $product->id, 'A different correction reason.'))->toThrow(ValidationException::class);
    $otherProduct = CreditProduct::whereKeyNot($product->id)->firstOrFail();
    expect(fn () => $service->addon($id, $customer->id, $otherProduct->id, 'Approved customer credit correction.'))->toThrow(ValidationException::class);
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => $service->addon($id, $customer->id, $product->id, 'Approved customer credit correction.'))->toThrow(ValidationException::class);
});

it('rolls back a plan grant when credit sync fails and retries the same intent once', function () {
    $customer = p0Customer();
    $oldPlan = $customer->currentServicePlanId();
    $target = ServicePlan::where('code', 'pro')->firstOrFail();
    $count = CustomerServiceSubscription::where('customer_id', $customer->id)->count();
    $balance = $customer->wallet()->first()->balance_credits;
    $ledgerCount = CreditLedger::where('customer_id', $customer->id)->count();
    $this->actingAs(p0Admin(), 'admin');
    $id = (string) Str::uuid();
    $this->mock(CreditService::class)->shouldReceive('syncCustomerSubscriptionCreditsToPlan')->once()->andThrow(new RuntimeException('Injected boundary failure'));
    $service = app(AdminFinancialCorrections::class);
    expect(fn () => $service->plan($id, $customer->id, $target->id, 'monthly', 'Approved partner access correction.'))->toThrow(RuntimeException::class);
    expect($customer->fresh()->currentServicePlanId())->toBe($oldPlan)
        ->and(CustomerServiceSubscription::where('customer_id', $customer->id)->count())->toBe($count)
        ->and(CreditLedger::where('customer_id', $customer->id)->count())->toBe($ledgerCount)
        ->and(AdminOperation::find($id)->status)->toBe('pending');
    app()->forgetInstance(CreditService::class);
    $result = $service->plan($id, $customer->id, $target->id, 'monthly', 'Approved partner access correction.');
    expect($service->plan($id, $customer->id, $target->id, 'monthly', 'Approved partner access correction.'))->toBe($result)
        ->and(CustomerServiceSubscription::where('customer_id', $customer->id)->count())->toBe($count + 1)
        ->and($customer->wallet()->first()->balance_credits)->toBe($balance + $target->appMonthlyCredits());
});

it('does not refill spent credits on a repeated credit-sync intent', function () {
    $customer = p0Customer();
    $this->actingAs(p0Admin(), 'admin');
    $id = (string) Str::uuid();
    $service = app(AdminFinancialCorrections::class);
    $service->credits($id, $customer->id, 'Approved allowance synchronization.');
    app(CreditService::class)->charge($customer->id, 17, 'test_usage');
    $balance = $customer->wallet()->first()->balance_credits;
    $service->credits($id, $customer->id, 'Approved allowance synchronization.');
    expect($customer->wallet()->first()->balance_credits)->toBe($balance);
});

it('rejects a pending credit-sync retry after its target subscription changes', function () {
    $customer = p0Customer();
    $this->actingAs(p0Admin(), 'admin');
    $id = (string) Str::uuid();
    $this->mock(CreditService::class)->shouldReceive('syncCustomerSubscriptionCreditsToPlan')->once()->andThrow(new RuntimeException('Injected sync failure'));
    $service = app(AdminFinancialCorrections::class);
    expect(fn () => $service->credits($id, $customer->id, 'Approved allowance synchronization.'))->toThrow(RuntimeException::class);
    app()->forgetInstance(CreditService::class);
    app(PlanSwitcher::class)->switchServicePlan($customer, ServicePlan::where('code', 'pro')->firstOrFail()->id,
        ['provider' => 'fake', 'billing_cycle' => 'monthly']);
    $count = CreditLedger::count();
    expect(fn () => $service->credits($id, $customer->id, 'Approved allowance synchronization.'))->toThrow(ValidationException::class);
    expect(CreditLedger::count())->toBe($count)->and(AdminOperation::find($id)->status)->toBe('pending');
});

it('excludes future manual addon and storage grants from revenue and retains product links', function () {
    $customer = p0Customer();
    $this->actingAs(p0Admin(), 'admin');
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $service = app(AdminFinancialCorrections::class);
    $addon = $service->addon((string) Str::uuid(), $customer->id, $product->id, 'Approved no-revenue goodwill grant.');
    $storage = $service->storage((string) Str::uuid(), $customer->id, StoragePlan::where('is_active', true)->firstOrFail()->id,
        'monthly', 'Approved no-revenue storage grant.');
    foreach ([$addon['order_id'], $storage['order_id']] as $id) {
        expect(CreditOrder::findOrFail($id)->isRevenueExcluded())->toBeTrue()
            ->and(CreditOrder::revenueIncluded()->whereKey($id)->exists())->toBeFalse();
    }
    expect(CreditOrder::find($addon['order_id'])->credit_product_id)->toBe($product->id);
});

it('counts verified paid addon correction once using matched provider payment evidence', function () {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $payment = p0Payment($customer, $product);
    $this->mock(FibOneTimePaymentService::class)->shouldReceive('getStatusByPaymentId')->once()->with($payment->fib_payment_id)->andReturn(p0PaidEvidence($payment));
    $this->actingAs(p0Admin(), 'admin');
    $id = (string) Str::uuid();
    $service = app(AdminFinancialCorrections::class);
    $result = $service->addon($id, $customer->id, $product->id, 'Verified provider-paid purchase correction.', 'verified_paid', $payment->id);
    $service->addon($id, $customer->id, $product->id, 'Verified provider-paid purchase correction.', 'verified_paid', $payment->id);
    $order = CreditOrder::findOrFail($result['order_id']);
    expect($order->credit_product_id)->toBe($product->id)->and($order->payment_id)->toBe($payment->id)
        ->and($order->isRevenueExcluded())->toBeFalse()->and(CreditOrder::revenueIncluded()->whereKey($order->id)->exists())->toBeTrue()
        ->and(CreditOrder::where('payment_id', $payment->id)->count())->toBe(1);
});

it('preserves normal provider purchase classification and product relationship', function () {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $order = app(AddonPurchaseService::class)->purchase($customer, $product->id, ['provider' => 'fib', 'provider_ref' => 'verified-test-order']);
    expect($order->credit_product_id)->toBe($product->id)->and($order->isRevenueExcluded())->toBeFalse()
        ->and(CreditOrder::revenueIncluded()->whereKey($order->id)->exists())->toBeTrue();
});

it('keeps revenue model and query predicates aligned', function (array $meta, bool $excluded) {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $order = app(AddonPurchaseService::class)->purchase($customer, $product->id, $meta);
    expect($order->isRevenueExcluded())->toBe($excluded)
        ->and(CreditOrder::revenueIncluded()->whereKey($order->id)->exists())->toBe(! $excluded);
})->with([
    'normal' => [[], false], 'explicit exclusion' => [['revenue_excluded' => true], true],
    'false exclusion' => [['revenue_excluded' => false], false], 'non revenue' => [['revenue_record' => false], true],
    'manual source' => [['billing_source' => 'admin_manual_grant'], true], 'internal source' => [['billing_source' => 'internal_non_revenue'], true],
]);

it('invalidates only an eligible locked review and returns the original result on replay', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $admin = p0Admin();
    $this->actingAs($admin, 'admin');
    $id = (string) Str::uuid();
    $action = app(InvalidateAdminReviewPayment::class);
    $action->handle($id, $customer->id, $payment->id, 'Verified unpaid invalid provider record.');
    $action->handle($id, $customer->id, $payment->id, 'Verified unpaid invalid provider record.');
    $event = PaymentEvent::where('event_key', 'admin-invalidate:'.$id)->firstOrFail();
    expect($payment->fresh()->status)->toBe(PaymentStatus::EXPIRED)
        ->and($event->before_status)->toBe(PaymentStatus::AWAITING_CUSTOMER_ACTION->value)
        ->and($event->after_status)->toBe(PaymentStatus::EXPIRED->value)
        ->and(data_get($event->meta, 'admin_id'))->toBe($admin->id)
        ->and(PaymentEvent::where('event_key', 'admin-invalidate:'.$id)->count())->toBe(1);
});

it('rejects protected payment states at final invalidation', function (string $state) {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $this->actingAs(p0Admin(), 'admin');
    $changes = match ($state) {
        'paid' => ['status' => PaymentStatus::PAID],
        'fulfilled' => ['fulfilled_at' => now()],
        'applied' => ['internal_status' => PaymentInternalStatus::APPLIED],
        'paid timestamp' => ['paid_at' => now()],
    };
    $payment->forceFill($changes)->save();
    expect(fn () => app(InvalidateAdminReviewPayment::class)->handle((string) Str::uuid(), $customer->id, $payment->id, 'Attempted invalid review transition.'))->toThrow(ValidationException::class);
    expect($payment->fresh()->status)->not->toBe(PaymentStatus::EXPIRED);
})->with(['paid', 'fulfilled', 'applied', 'paid timestamp']);

it('uses the changed database state after the review dialog was opened', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $this->actingAs(p0Admin(), 'admin');
    $component = Livewire::test('admin::pages.customers.adm-customers-register')->call('openReviewPayment', $payment->id);
    Payment::whereKey($payment->id)->update(['status' => PaymentStatus::PAID->value, 'paid_at' => now()]);
    $component->set('reviewResolutionReason', 'Operator opened this review before payment arrived.')
        ->call('markReviewPaymentInvalid')->assertHasErrors(['reviewPaymentId']);
    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});

it('preserves original reference and payment state on provider lookup failure', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $before = $payment->fresh()->getAttributes();
    $this->actingAs(p0Admin(), 'admin');
    $this->mock(FibOneTimePaymentService::class)->shouldReceive('getStatusByPaymentId')->andThrow(new RuntimeException('Bearer fixture-sensitive-token'));
    expect(fn () => app(AdminPaymentReconciliation::class)->handle((string) Str::uuid(), $customer->id, $payment->id,
        'candidate', 'apply_fulfillment_once', 'Correcting the provider payment reference.'))->toThrow(ValidationException::class);
    expect($payment->fresh()->getAttributes())->toBe($before);
});

it('requires identity evidence as well as paid status and amount for a different provider object', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $this->mock(FibOneTimePaymentService::class)->shouldReceive('getStatusByPaymentId')->andReturn(FibPaymentStatusData::fromArray([
        'paymentId' => 'unrelated-paid-object', 'status' => 'PAID', 'amount' => ['amount' => $payment->amount, 'currency' => 'IQD'],
    ]));
    expect(fn () => app(AdminProviderEvidence::class)->verify($payment, 'unrelated-paid-object'))->toThrow(ValidationException::class);
});

it('commits a verified corrected reference and fulfills once', function () {
    $customer = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $payment = p0Payment($customer, $product);
    $this->actingAs(p0Admin(), 'admin');
    $this->mock(FibOneTimePaymentService::class)->shouldReceive('getStatusByPaymentId')->once()->with('correct-reference')->andReturn(p0PaidEvidence($payment, 'correct-reference'));
    $id = (string) Str::uuid();
    $service = app(AdminPaymentReconciliation::class);
    $service->handle($id, $customer->id, $payment->id, 'correct-reference', 'apply_fulfillment_once', 'Verified corrected payment ownership.');
    $service->handle($id, $customer->id, $payment->id, 'correct-reference', 'apply_fulfillment_once', 'Verified corrected payment ownership.');
    expect($payment->fresh()->fib_payment_id)->toBe('correct-reference')
        ->and($payment->fresh()->fulfilled_at)->not->toBeNull()
        ->and(CreditOrder::where('payment_id', $payment->id)->count())->toBe(1);
});

it('preserves subscription reference when fresh paid evidence is absent', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer, attributes: ['provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING, 'fib_subscription_id' => 'old-subscription', 'callback_payload' => ['paymentStatus' => 'PAID']]);
    $this->actingAs(p0Admin(), 'admin');
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatusBySubscriptionId')->andReturn(FibSubscriptionStatusData::fromArray([
        'id' => 'candidate-subscription', 'status' => 'ACTIVE', 'monetaryValue' => ['amount' => $payment->amount, 'currency' => 'IQD'],
        'localReference' => $payment->local_reference,
    ]));
    expect(fn () => app(AdminPaymentReconciliation::class)->handle((string) Str::uuid(), $customer->id, $payment->id,
        'candidate-subscription', 'apply_fulfillment_once', 'Verify new subscription reference.'))->toThrow(ValidationException::class);
    expect($payment->fresh()->fib_subscription_id)->toBe('old-subscription');
});

it('rejects historical job dependencies that appeared after a safe delete preview', function () {
    $customer = p0Customer();
    $tool = Tool::create(['code' => 'p0_unused', 'name' => 'Unused tool', 'is_active' => true]);
    $action = ToolAction::create(['tool_code' => $tool->code, 'action_code' => 'test', 'full_code' => 'p0_unused.test', 'name' => 'Unused action', 'is_active' => true]);
    $this->actingAs(p0Admin(), 'admin');
    $component = Livewire::test('admin::pages.services.adm-services-tools')
        ->set('adminChangeReason', 'Remove a catalog entry with no dependencies.')->call('confirmActionDelete', $action->id);
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $tool->id, 'tool_action_id' => $action->id, 'status' => 'deleted']);
    $component->call('performDelete')->assertHasErrors(['delete']);
    expect($action->fresh())->not->toBeNull();
});

it('protects pending purchasables and historical product metadata from deletion', function () {
    $customer = p0Customer();
    $product = CreditProduct::create(['code' => 'p0_product', 'name' => 'P0 Pack', 'credits_amount' => 100,
        'price_iqd' => 1000, 'price_usd' => 1, 'is_active' => true]);
    p0Payment($customer, $product);
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => app(AdminCatalogDeletion::class)->delete($product))->toThrow(ValidationException::class);
    expect($product->fresh())->not->toBeNull();
});

it('protects stored artifacts even when no active job remains', function () {
    $customer = p0Customer();
    $tool = Tool::create(['code' => 'p0_artifact', 'name' => 'Stored tool', 'is_active' => false]);
    CustomerFile::create(['customer_id' => $customer->id, 'tool_code' => $tool->code, 'purpose' => 'render',
        'disk' => 's3', 'path' => 'fixture/private.wav', 'size_bytes' => 10, 'status' => 'deleted']);
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => app(AdminCatalogDeletion::class)->delete($tool))->toThrow(ValidationException::class);
});

it('rejects base currency deactivation and rate IDs from a different currency pair', function () {
    $this->actingAs(p0Admin(), 'admin');
    Livewire::test('admin::pages.payments.adm-payments-currencies')->set('adminChangeReason', 'Authorized currency configuration correction.')
        ->call('deactivateRate', 'IQD')->assertHasErrors(['rate']);
    $rate = CurrencyExchangeRate::where('quote_currency_code', '!=', 'IQD')->firstOrFail();
    $other = CurrencyExchangeRate::whereNotIn('quote_currency_code', ['IQD', $rate->quote_currency_code])->firstOrFail();
    $before = $other->getAttributes();
    Livewire::test('admin::pages.payments.adm-payments-currencies')->set('adminChangeReason', 'Authorized currency configuration correction.')
        ->call('openEditModal', $rate->quote_currency_code)->set('editingRateId', $other->id)
        ->call('saveRate')->assertHasErrors(['rate']);
    expect($other->fresh()->getAttributes())->toBe($before);
});

it('removes nested secrets and arbitrary payload fields from operational display', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer, attributes: ['callback_payload' => [
        'status' => 'PENDING', 'nested' => ['authorization' => 'Bearer fixture-token', 'api_secret' => 'fixture-secret'],
        'download' => 'https://example.test/private?signature=fixture-signature', 'unexpected' => 'fixture-private-value',
    ], 'status_response' => ['status' => 'PENDING', 'access_token' => 'fixture-access-token']]);
    $this->actingAs(p0Admin(), 'admin');
    Livewire::test('admin::pages.customers.adm-customers-register')->call('openReviewPayment', $payment->id)
        ->assertDontSee('fixture-token')->assertDontSee('fixture-secret')->assertDontSee('fixture-signature')
        ->assertDontSee('fixture-private-value')->assertDontSee('fixture-access-token');
    expect(AdminData::redact(['nested' => ['clientSecret' => 'fixture-secret'], 'message' => 'Authorization: fixture-token']))
        ->toBe(['nested' => ['clientSecret' => AdminData::REDACTED], 'message' => AdminData::REDACTED]);
});

it('masks provider settings in component state and preserves protected values on a safe edit', function () {
    $method = PaymentMethod::create(['code' => 'p0_method', 'driver' => 'fib', 'name' => 'P0 Method', 'is_active' => false,
        'settings' => ['enabled' => false, 'nested' => ['client_secret' => 'fixture-method-secret']],
        'meta' => ['authorization' => 'fixture-method-authorization']]);
    $this->actingAs(p0Admin(), 'admin');
    $component = Livewire::test('admin::pages.payments.adm-payments-methods')
        ->set('adminChangeReason', 'Update the safe payment method display name.')->call('openEditMethodModal', $method->id);
    expect($component->get('settingsJson'))->not->toContain('fixture-method-secret');
    expect($component->get('metaJson'))->not->toContain('fixture-method-authorization');
    $component->set('name', 'Updated P0 Method')->call('saveMethod')->assertHasNoErrors();
    expect(data_get($method->fresh()->settings, 'nested.client_secret'))->toBe('fixture-method-secret')
        ->and(data_get($method->fresh()->meta, 'authorization'))->toBe('fixture-method-authorization');
    expect(AdminAuditEvent::get()->toJson())->not->toContain('fixture-method-secret')->not->toContain('fixture-method-authorization');
});

it('records the true customer status before and after a capability-authorized change', function () {
    $customer = p0Customer();
    $admin = p0Admin(['admin.customers']);
    $this->actingAs($admin, 'admin');
    Livewire::test('admin::pages.customers.adm-customers-list')->set('adminChangeReason', 'Suspend this account following a verified support request.')
        ->call('toggleCustomerStatus', $customer->id)->assertHasNoErrors();
    $event = AdminAuditEvent::where('target_type', Customer::class)->where('target_id', $customer->id)->latest('id')->firstOrFail();
    expect((int) data_get($event->before_state, 'status'))->toBe(1)
        ->and((int) data_get($event->after_state, 'status'))->toBe(0)
        ->and($event->admin_id)->toBe($admin->id)->and($event->operation_id)->not->toBeNull();
});

it('provisions only explicit supported capabilities through the trusted console', function () {
    $admin = p0Admin(['admin.read'], 0);
    $this->artisan('admin:capabilities', ['user' => $admin->id, 'capabilities' => ['admin.finance'],
        '--reason' => 'Deployment operator approval for finance.'])->assertSuccessful();
    expect($admin->fresh()->admin_capabilities)->toBe(['admin.finance'])->and($admin->fresh()->status)->toBe(0);
    $this->artisan('admin:capabilities', ['user' => $admin->id, 'capabilities' => ['admin.everything'],
        '--reason' => 'Reject an unknown permission.'])->assertFailed();
    expect(AdminAuditEvent::where('action', 'console.capabilities')->count())->toBe(1);
});

it('denies an inactive email login with the correct password', function () {
    $admin = p0Admin(['admin.finance'], 0);
    Livewire::test('admin::auth.signin-one')->set('login', $admin->email)->set('password', 'test-password')
        ->call('signIn')->assertHasErrors(['login']);
    expect(auth('admin')->check())->toBeFalse();
});

it('denies final catalog pricing and reconciliation actions to support despite selected IDs', function (string $component, string $method, array $arguments) {
    $this->actingAs(p0Admin(['admin.read']), 'admin');
    Livewire::test($component)->set('adminChangeReason', 'A supplied reason cannot grant permissions.')
        ->call($method, ...$arguments)->assertForbidden();
})->with([
    ['admin::pages.services.adm-services-tools', 'toggleToolStatus', [1]],
    ['admin::pages.services.adm-services-pricing', 'togglePricingRuleStatus', [1]],
    ['admin::pages.customers.adm-customers-register', 'repairPaidSubscription', [1]],
]);

it('protects pending service and storage purchases at final deletion', function (string $model) {
    $customer = p0Customer();
    $target = $model::create(['code' => 'p0_unused_plan', 'name' => 'P0 unused plan', 'is_active' => true]);
    p0Payment($customer, $target);
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => app(AdminCatalogDeletion::class)->delete($target))->toThrow(ValidationException::class);
    expect($target->fresh())->not->toBeNull();
})->with([[ServicePlan::class], [StoragePlan::class]]);

it('preserves subscription reference state and records a safe failure when provider lookup fails', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer, attributes: ['provider_object_type' => PaymentProviderObjectType::SUBSCRIPTION,
        'payment_mode' => PaymentMode::RECURRING, 'fib_subscription_id' => 'old-subscription']);
    $before = $payment->fresh()->getAttributes();
    $this->mock(FibSubscriptionService::class)->shouldReceive('getStatusBySubscriptionId')->once()
        ->andThrow(new RuntimeException('Bearer fixture-private-token'));
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => app(AdminPaymentReconciliation::class)->handle((string) Str::uuid(), $customer->id, $payment->id,
        'candidate-subscription', 'apply_fulfillment_once', 'Verify this provider correction.'))->toThrow(ValidationException::class);
    expect($payment->fresh()->getAttributes())->toBe($before)
        ->and(AdminAuditEvent::where('action', 'payment.reconcile.failed')->count())->toBe(1)
        ->and(AdminAuditEvent::get()->toJson())->not->toContain('fixture-private-token');
});

it('rejects verified-paid corrections for a refunded payment or another customer', function () {
    $customer = p0Customer();
    $other = p0Customer();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $payment = p0Payment($customer, $product, ['status' => PaymentStatus::REFUNDED]);
    $this->actingAs(p0Admin(), 'admin');
    foreach ([$customer, $other] as $target) {
        expect(fn () => app(AdminFinancialCorrections::class)->addon((string) Str::uuid(), $target->id, $product->id,
            'Review refund and ownership constraints.', 'verified_paid', $payment->id))->toThrow(ValidationException::class);
    }
    expect($payment->fresh()->status)->toBe(PaymentStatus::REFUNDED);
});

it('keeps new P0 translation keys complete in English Arabic and Kurdish', function () {
    $english = require resource_path('lang/en/admin_p0.php');
    foreach (['ar', 'ku'] as $locale) {
        $translated = require resource_path('lang/'.$locale.'/admin_p0.php');
        expect(array_keys($translated))->toBe(array_keys($english));
        foreach ($translated as $text) {
            expect(trim($text))->not->toBe('');
        }
    }
    expect(AdminData::redact('true', 'status_response'))->toBe([]);
});

it('reuses one storage intent without extending its period or adding another order', function () {
    $customer = p0Customer();
    $this->actingAs(p0Admin(), 'admin');
    $plan = StoragePlan::where('is_active', true)->firstOrFail();
    $id = (string) Str::uuid();
    $service = app(AdminFinancialCorrections::class);
    $one = $service->storage($id, $customer->id, $plan->id, 'monthly', 'Approved storage allowance correction.');
    $count = CreditOrder::count();
    $this->travel(2)->days();
    expect($service->storage($id, $customer->id, $plan->id, 'monthly', 'Approved storage allowance correction.'))->toBe($one)
        ->and(CreditOrder::count())->toBe($count);
});

it('allows reconciliation-only operators to explicitly begin another intent', function () {
    $this->actingAs(p0Admin(['admin.reconcile']), 'admin');
    $component = Livewire::test('admin::pages.customers.adm-customers-register');
    $before = $component->get('adminIntentIds');
    $component->call('startNewCorrection')->assertHasNoErrors();
    expect($component->get('adminIntentIds'))->not->toBe($before);
});

it('applies one financial intent once when two independent processes replay it concurrently', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $customer = p0Customer();
    $admin = p0Admin();
    $product = CreditProduct::where('is_active', true)->firstOrFail();
    $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'metkurd-p0-'.Str::uuid().'.sqlite';
    fclose(fopen($database, 'x'));
    $barrier = $database.'.go';
    $ready = [$database.'.ready1', $database.'.ready2'];
    $processes = [];
    $copy = null;
    try {
        // Copy only this in-memory fixture. No configured disk database is opened.
        $copy = new PDO('sqlite:'.$database);
        $copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $copy->beginTransaction();
        $schema = Illuminate\Support\Facades\DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type DESC");
        foreach ($schema as $entry) {
            if ($entry->type !== 'table') {
                continue;
            }
            $copy->exec($entry->sql);
            $rows = Illuminate\Support\Facades\DB::table($entry->name)->get();
            foreach ($rows as $row) {
                $values = (array) $row;
                $columns = implode(',', array_map(fn ($key) => '"'.str_replace('"', '""', $key).'"', array_keys($values)));
                $statement = $copy->prepare('INSERT INTO "'.$entry->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
        }
        foreach ($schema as $entry) {
            if ($entry->type === 'index') {
                $copy->exec($entry->sql);
            }
        }
        $copy->commit();
        $before = (int) $copy->query('SELECT COUNT(*) FROM credit_orders')->fetchColumn();
        $id = (string) Str::uuid();
        foreach ($ready as $signal) {
            $process = new Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/admin-p0-replay-worker.php'),
                $database, (string) $admin->id, (string) $customer->id, (string) $product->id, $id, $barrier, $signal], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => 'bootstrap/cache/p0-isolated-config.php',
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DATABASE_URL' => '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array']);
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while ((! is_file($ready[0]) || ! is_file($ready[1])) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException($process->getErrorOutput().$process->getOutput());
                }
            }
            usleep(10000);
        }
        expect(is_file($ready[0]) && is_file($ready[1]))->toBeTrue();
        touch($barrier);
        $outcomes = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            expect($process->isSuccessful(), $process->getErrorOutput())->toBeTrue();
            if (! is_file($ready[$index].'.result')) {
                throw new RuntimeException($process->getErrorOutput().$process->getOutput());
            }
            $outcomes[] = json_decode(file_get_contents($ready[$index].'.result'), true, flags: JSON_THROW_ON_ERROR);
        }
        expect($outcomes[1])->toBe($outcomes[0])
            ->and((int) $copy->query('SELECT COUNT(*) FROM credit_orders')->fetchColumn())->toBe($before + 1)
            ->and((int) $copy->query("SELECT COUNT(*) FROM admin_operations WHERE status = 'completed'")->fetchColumn())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        unset($statement);
        $copy = null;
        foreach ([$database, $database.'-journal', $barrier, ...$ready, $ready[0].'.result', $ready[1].'.result'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('closes abandoned checkout through the Admin bridge and permits a fresh purchase for every kind', function (string $kind) {
    config(['metkurd_v2.enabled' => true, 'payments.providers.fib.enabled' => true, 'fib.enabled' => true,
        'fib.callback_base_url' => 'https://metkurd.test', 'fib.profiles.payment.base_url' => 'https://fib-stage.fib.iq',
        'fib.profiles.payment.client_id' => 'fixture', 'fib.profiles.payment.client_secret' => 'fixture']);
    $customer = p0Customer();
    $target = match ($kind) {
        'service' => ServicePlan::where('code', 'pro')->firstOrFail(),
        'storage' => StoragePlan::create(['code' => 'abandoned-storage', 'name' => 'Storage fixture', 'quota_mb' => 1024,
            'price_iqd' => 5000, 'payment_mode' => 'one_time', 'billing_intervals' => ['monthly'], 'is_active' => true]),
        default => CreditProduct::where('is_active', true)->firstOrFail(),
    };
    if ($kind === 'service') {
        $target->update(['payment_mode' => 'one_time', 'billing_intervals' => ['monthly']]);
    }
    $payment = p0Payment($customer, $target, ['created_at' => now()->subMonths(4), 'provider_status' => 'DRAFT',
        'provider_object_type' => 'subscription', 'fib_subscription_id' => Str::uuid(), 'provider_subscription_status' => 'DRAFT',
        'create_response' => ['validUntil' => 'unparseable-provider-deadline']]);
    $event = app(\App\Domain\Payments\Support\PaymentEventRecorder::class)->record($payment,
        ['event_type' => 'checkout_created', 'source' => 'fixture', 'payload' => ['status' => 'DRAFT']]);
    $history = $event->fresh()->getAttributes();
    $provider = $payment->fresh()->only(['provider_status', 'provider_subscription_status', 'provider_payment_status', 'create_response', 'fib_subscription_id']);
    $balances = \App\Models\CreditWallet::orderBy('id')->get()->toArray();
    $ledger = CreditLedger::orderBy('id')->get()->toArray();
    $orders = CreditOrder::count();
    $policy = app(\App\Domain\Payments\Support\PaymentCheckoutState::class);
    expect($policy->blocks($payment))->toBeTrue()->and($policy->blocker($customer, $target::class)->id)->toBe($payment->id);
    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment])
        ->assertSee(__('payment_v2.review'))->assertDontSee('markReviewPaymentInvalid', false);
    $this->actingAs(p0Admin(), 'admin');
    $page = Livewire::test('admin::pages.customers.adm-customers-register')->call('openReviewPayment', $payment->id)
        ->assertSee(__('admin_p0.close_checkout'))->assertSee('data-admin-method="markReviewPaymentInvalid"', false)
        ->assertSee(__('admin_p0.close_checkout_impact'));
    $operation = $page->get('adminIntentIds')['invalidate'];
    $page->set('reviewResolutionReason', 'Operator reviewed the abandoned unpaid checkout.')
        ->call('markReviewPaymentInvalid')->assertHasNoErrors()->assertDontSee('data-admin-method="markReviewPaymentInvalid"', false)
        ->call('markReviewPaymentInvalid')->assertHasNoErrors();
    expect($payment->fresh()->status)->toBe(PaymentStatus::EXPIRED)
        ->and($payment->fresh()->internal_status)->toBe(PaymentInternalStatus::EXPIRED)
        ->and($payment->fresh()->review_required_at)->toBeNull()
        ->and($policy->blocks($payment->fresh()))->toBeFalse()
        ->and($policy->blocker($customer, $target::class))->toBeNull()
        ->and($payment->fresh()->only(array_keys($provider)))->toBe($provider)
        ->and($event->fresh()->getAttributes())->toBe($history)
        ->and(Payment::whereKey($payment->id)->exists())->toBeTrue()
        ->and(PaymentEvent::where('event_key', 'admin-invalidate:'.$operation)->count())->toBe(1)
        ->and(AdminOperation::findOrFail($operation)->status)->toBe('completed')
        ->and(AdminAuditEvent::where('operation_id', $operation)->where('action', 'payment.invalidate')->count())->toBe(1)
        ->and(\App\Models\CreditWallet::orderBy('id')->get()->toArray())->toBe($balances)
        ->and(CreditLedger::orderBy('id')->get()->toArray())->toBe($ledger)
        ->and(CreditOrder::count())->toBe($orders);
    $this->actingAs($customer, 'app');
    $destination = match ($kind) {
        'service' => 'subscription-plans', 'storage' => 'storage-plans', default => 'addon-credits'
    };
    Livewire::test('app::v2.pages.account.fib-payment', ['payment' => $payment->fresh()])
        ->call('refreshStatus')->assertSee(__('payment_v2.expired'))->assertSee(__('payment_v2.start_new'))
        ->assertSee('/app-v2/'.$destination, false)->assertDontSee('markReviewPaymentInvalid', false);
    Http::assertNothingSent();
    $client = Mockery::mock(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class);
    $client->shouldReceive('createPayment')->once()->andReturn(\App\Domain\Payments\Data\FibCreatePaymentResponseData::fromArray([
        'paymentId' => Str::uuid(), 'validUntil' => now()->addHour()->utc()->toIso8601String()]));
    app()->instance(\App\Domain\Payments\Fib\FibOneTimePaymentClient::class, $client);
    $new = app(\App\Services\Payments\CustomerPurchaseCheckout::class)->start($customer, $kind, $target->id, 'monthly', 'one_time', 'fib', null);
    expect($new->id)->not->toBe($payment->id)->and($policy->state($new))->toBe('awaiting')->and(Payment::count())->toBe(2);
    Http::assertNothingSent();
})->with(['service', 'storage', 'addon']);

it('requires both fresh active Admin capabilities even when replaying closure', function (array $capabilities, int $status) {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $admin = p0Admin();
    $this->actingAs($admin, 'admin');
    $operation = (string) Str::uuid();
    app(InvalidateAdminReviewPayment::class)->handle($operation, $customer->id, $payment->id, 'Confirmed abandoned local checkout.');
    User::whereKey($admin->id)->update(['admin_capabilities' => json_encode($capabilities), 'status' => $status]);
    expect(fn () => app(InvalidateAdminReviewPayment::class)->handle($operation, $customer->id, $payment->id,
        'Confirmed abandoned local checkout.'))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    $other = p0Payment($customer);
    expect(fn () => app(InvalidateAdminReviewPayment::class)->handle((string) Str::uuid(), $customer->id, $other->id,
        'Confirmed abandoned local checkout.'))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    expect($other->fresh()->requiresReview())->toBeTrue();
    Http::assertNothingSent();
})->with([[['admin.read', 'admin.finance'], 1], [['admin.read', 'admin.reconcile'], 1], [AdminAccess::CAPABILITIES, 0]]);

it('requires a substantive reason and hides closure from an operator lacking finance', function () {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $this->actingAs(p0Admin(['admin.read', 'admin.reconcile']), 'admin');
    Livewire::test('admin::pages.customers.adm-customers-register')->call('openReviewPayment', $payment->id)
        ->assertDontSee('data-admin-method="markReviewPaymentInvalid"', false)->call('markReviewPaymentInvalid')->assertForbidden();
    $this->actingAs(p0Admin(), 'admin');
    expect(fn () => app(InvalidateAdminReviewPayment::class)->handle((string) Str::uuid(), $customer->id, $payment->id, '           '))
        ->toThrow(ValidationException::class);
    expect($payment->fresh()->requiresReview())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects retained paid evidence and current obligations despite a review marker', function (string $evidence) {
    $customer = p0Customer();
    $payment = p0Payment($customer, attributes: ['provider_status' => 'DRAFT']);
    $this->actingAs(p0Admin(), 'admin');
    match ($evidence) {
        'refunded' => $payment->update(['status' => PaymentStatus::REFUNDED]),
        'refund requested' => $payment->update(['internal_status' => PaymentInternalStatus::REFUND_REQUESTED]),
        'coverage' => $payment->update(['active_until' => now()->addMonth()]),
        'active' => $payment->update(['provider_subscription_status' => 'ACTIVE']),
        'collection' => $payment->update(['provider_payment_status' => 'COMPLETED']),
        'conflicting payload' => $payment->update(['provider_payment_status' => 'UNPAID', 'status_response' => ['latestPayment' => ['status' => 'PAID']]]),
        'paid flag' => $payment->update(['status_response' => ['isPaid' => true]]),
        'cancel response' => $payment->update(['cancel_response' => ['paymentStatus' => 'PAID']]),
        'allocation' => \App\Models\SubscriptionCreditAllocation::create(['customer_id' => $customer->id,
            'subscription_id' => $customer->activeServiceSubscription()->firstOrFail()->id, 'payment_id' => $payment->id,
            'cycle_key' => 'retained-fixture', 'allocation_type' => 'initial', 'cycle_started_at' => now()]),
        'future checkout' => $payment->update(['valid_until' => now()->addHour()]),
        'event' => app(\App\Domain\Payments\Support\PaymentEventRecorder::class)->record($payment,
            ['event_type' => 'status_synced', 'source' => 'fixture', 'payload' => ['paymentStatus' => 'PAID']]),
        'linked subscription' => $customer->activeServiceSubscription()->firstOrFail()->update(['payment_id' => $payment->id]),
    };
    expect(app(InvalidateAdminReviewPayment::class)->eligible($payment->fresh()))->toBeFalse();
    expect(fn () => app(InvalidateAdminReviewPayment::class)->handle((string) Str::uuid(), $customer->id, $payment->id,
        'Operator cannot close protected evidence.'))->toThrow(ValidationException::class);
    expect($payment->fresh()->status)->not->toBe(PaymentStatus::EXPIRED);
    Http::assertNothingSent();
})->with(['refunded', 'refund requested', 'coverage', 'active', 'collection', 'conflicting payload', 'paid flag', 'cancel response', 'allocation', 'future checkout', 'event', 'linked subscription']);

it('releases only unused coupon reservations once when closing abandoned checkout', function (string $status) {
    $customer = p0Customer();
    $payment = p0Payment($customer);
    $coupon = \App\Models\Coupon::create(['code' => 'ABANDONED', 'name' => 'Fixture', 'discount_type' => 'percent', 'discount_value' => 10, 'used_count' => 1]);
    $redemption = \App\Models\CouponRedemption::create(['coupon_id' => $coupon->id, 'customer_id' => $customer->id,
        'payment_id' => $payment->id, 'purchase_type' => 'plan_subscription', 'redemption_type' => 'checkout',
        'status' => $status, 'coupon_code' => $coupon->code, 'original_amount_iqd' => 10000, 'final_amount_iqd' => 9000]);
    $this->actingAs(p0Admin(), 'admin');
    $operation = (string) Str::uuid();
    if ($status === 'consumed') {
        expect(fn () => app(InvalidateAdminReviewPayment::class)->handle($operation, $customer->id, $payment->id,
            'Operator confirmed abandoned checkout.'))->toThrow(ValidationException::class);
        expect($redemption->fresh()->status->value)->toBe('consumed')->and((int) $coupon->fresh()->used_count)->toBe(1);
        Http::assertNothingSent();

        return;
    }
    foreach ([1, 2] as $replay) {
        app(InvalidateAdminReviewPayment::class)->handle($operation, $customer->id, $payment->id, 'Operator confirmed abandoned checkout.');
    }
    expect($redemption->fresh()->status->value)->toBe($status === 'consumed' ? 'consumed' : 'released')
        ->and((int) $coupon->fresh()->used_count)->toBe($status === 'consumed' ? 1 : 0);
    Http::assertNothingSent();
})->with(['reserved', 'consumed']);
