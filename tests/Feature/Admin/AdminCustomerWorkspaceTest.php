<?php

use App\Models\AdminAuditEvent;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\Admin\AdminFinancialCorrections;
use App\Services\Admin\AdminServiceAgreements;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\Mcp\CustomerMcpAccessService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminCustomerWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(12, 0));
    $this->operator = User::forceCreate(['name' => 'Customer workspace operator', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->operator, 'admin');
    $this->customer = Customer::create(['username' => 'workspace_target', 'email' => 'workspace-target@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->other = Customer::create(['username' => 'other_private_customer', 'email' => 'other-private@example.test', 'password' => 'fixture', 'status' => 0]);
    $this->plan = ServicePlan::where('code', 'pro')->firstOrFail();
});

function customerWorkspaceGrant($test): void
{
    app(AdminFinancialCorrections::class)->plan((string) Str::uuid(), $test->customer->id, $test->plan->id,
        'monthly', 'Approved internal access for an isolated customer fixture.', 'internal_team_account');
}

it('retains register search status country joined and effective-plan filters', function () {
    $this->customer->profile()->create(['first_name' => 'Target', 'country' => 'Iraq']);
    $this->other->profile()->create(['first_name' => 'Other', 'country' => 'Jordan']);
    $this->other->forceFill(['created_at' => now()->subMonths(6)])->save();
    customerWorkspaceGrant($this);
    $component = Livewire::test('admin::pages.customers.adm-customers-register');
    expect($component->instance()->registrationCustomers->total())->toBe(2);
    foreach (['search' => 'workspace-target@example.test', 'statusFilter' => 'active', 'countryFilter' => 'Iraq', 'joinedFilter' => '7', 'planFilter' => (string) $this->plan->id] as $field => $value) {
        $component->call('resetFilters')->set($field, $value);
        expect($component->instance()->registrationCustomers->pluck('id')->all())->toBe([$this->customer->id]);
    }
    $component->call('resetFilters');
    expect($component->instance()->registrationCustomers->total())->toBe(2);
});

it('renders eight register columns and routes the customer name to detail', function () {
    $response = $this->get(route('admin.customers.register', ['locale' => 'en']))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//tr[@*[name()="wire:key"]="register-row-'.$this->customer->id.'"]/td')->length)->toBe(8);
    $url = route('admin.customers.detail', ['locale' => 'en', 'customer' => $this->customer->id]);
    expect($xpath->query('//a[@href="'.$url.'"]')->length)->toBeGreaterThan(0);
    $response->assertDontSee('id="customer-action-1"', false)->assertDontSee('syncCustomerCreditsToPlan', false);
});

it('shows scoped customer resources and friendly source in every locale without writes', function (string $locale) {
    customerWorkspaceGrant($this);
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->update(['balance_credits' => 123456]);
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['balance_credits' => 7890]);
    $before = DB::table('credit_wallets')->get()->toJson();
    $response = $this->get(route('admin.customers.detail', ['locale' => $locale, 'customer' => $this->customer->id]))->assertOk();
    $response->assertSee('123,456')->assertSee('7,890')->assertSee(__('admin_customer.source_grant'))
        ->assertDontSee('other-private@example.test')->assertDontSee('Register Table')->assertDontSee('admin_customer.');
    expect($response->getContent())->toContain('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"');
    expect(DB::table('credit_wallets')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('bounds and scopes recent jobs and audit and never hydrates generated content', function () {
    $action = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    foreach (range(1, 8) as $index) {
        MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $this->customer->id,
            'tool_id' => $action->tool_id, 'tool_action_id' => $action->id, 'status' => $index === 8 ? 'failed' : 'queued',
            'input' => ['text' => 'PRIVATE_PHASE2_INPUT'], 'output' => ['text' => 'PRIVATE_PHASE2_OUTPUT'], 'created_at' => now()->subMinutes($index)]);
        AdminAuditEvent::create(['admin_id' => $this->operator->id, 'action' => 'customer.status', 'target_type' => Customer::class,
            'target_id' => $this->customer->id, 'reason' => 'Isolated customer fixture audit.', 'before_state' => [], 'after_state' => []]);
    }
    $foreign = MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $this->other->id,
        'tool_id' => $action->tool_id, 'tool_action_id' => $action->id, 'status' => 'queued', 'input' => [], 'output' => []]);
    $projection = app(AdminCustomerWorkspace::class)->read($this->customer->id);
    expect($projection['jobs'])->toHaveCount(5)->and($projection['audit'])->toHaveCount(3)
        ->and($projection['job_counts'])->toBe(['active' => 7, 'queued' => 7, 'completed' => 0, 'attention' => 1]);
    foreach ($projection['jobs'] as $job) {
        expect($job['customer_id'])->toBe($this->customer->id)->and($job)->not->toHaveKeys(['input', 'output']);
    }
    Livewire::test('admin::pages.operations.adm-operations', ['customer' => $this->customer->id])
        ->set('customerFilter', (string) $this->other->id)->assertDontSee($foreign->id)
        ->assertDontSee('PRIVATE_PHASE2_INPUT')->assertDontSee('PRIVATE_PHASE2_OUTPUT');
    Http::assertNothingSent();
});

it('projects API and MCP through existing authorities and explains gates independently', function () {
    $this->plan->update(['api_enabled' => true, 'api_allowed_tools' => ['v2:speech'], 'api_requests_per_minute' => 60, 'api_concurrent_jobs' => 2]);
    $action = ToolAction::where('full_code', 'xomni.generate')->firstOrFail();
    PlanEntitlement::updateOrCreate(['service_plan_id' => $this->plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'api'], ['allowed' => true]);
    customerWorkspaceGrant($this);
    config(['mcp.enabled' => true, 'customer_api.v2_enabled' => false]);
    $result = app(AdminCustomerWorkspace::class)->read($this->customer->id);
    expect($result['api_allowed'])->toBe(app(ApiCatalog::class)->hasAccess($this->customer->fresh()))
        ->and($result['api_gate'])->toBeFalse()->and($result['mcp_available'])->toBeTrue();
    expect(app(CustomerMcpAccessService::class)->scopes($this->customer->fresh()))->toContain('v2:speech');
    config(['mcp.enabled' => false]);
    expect(app(AdminCustomerWorkspace::class)->read($this->customer->id)['mcp_reason'])->toBe('feature_disabled');
    config(['mcp.enabled' => true]);
    $this->customer->update(['status' => 0]);
    expect(app(AdminCustomerWorkspace::class)->read($this->customer->id)['mcp_reason'])->toBe('paid_plan_required');
    Http::assertNothingSent();
});

it('shows agreement permissions inclusive expiry and overrides without changing the public plan', function () {
    $before = $this->plan->getRawOriginal();
    app(AdminServiceAgreements::class)->record((string) Str::uuid(), $this->customer->id, $this->plan->id,
        '2026-09-27', '2027-03-31', null, 'WORKSPACE-FIXTURE', 'Approved agreement fixture for customer workflow.', 3456, 7890, 5);
    $workspace = app(AdminCustomerWorkspace::class)->read($this->customer->id);
    expect($workspace['plan']['source_label'])->toBe(__('admin_customer.source_agreement'))
        ->and($workspace['plan']['expiry'])->toBe('2027-03-31')->and($workspace['app_concurrency'])->toBe(5)
        ->and($this->plan->fresh()->getRawOriginal())->toBe($before);
    $response = $this->get(route('admin.customers.register', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk();
    $response->assertSee(__('admin_customer.base_plan'))->assertSee(__('admin_customer.agreement_permissions'))
        ->assertSee('id="agreement-create"', false)->assertSee('id="agreement-adjust"', false)->assertSee(__('admin_customer.review'));
});

it('keeps modal capability checks reasons and validation visible', function () {
    $this->operator->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $response = $this->get(route('admin.customers.register', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    foreach (['applyServicePlanAdjustment', 'applyPaidSubscriptionReconciliation', 'applyStoragePlanAdjustment', 'applyAddonAdjustment', 'syncCustomerCreditsToPlan'] as $method) {
        $buttons = $xpath->query('//*[@data-admin-method="'.$method.'"]');
        expect($buttons->length)->toBe(1)->and($buttons->item(0)->hasAttribute('disabled'))->toBeTrue();
    }
    $this->operator->forceFill(['admin_capabilities' => AdminAccess::CAPABILITIES])->save();
    $component = Livewire::withQueryParams(['customer' => $this->customer->id])->test('admin::pages.customers.adm-customers-register');
    $ids = $component->get('adminIntentIds');
    $component->set('agreementPlanId', (string) $this->plan->id)->set('agreementReason', '')
        ->call('recordServiceAgreement')->assertHasErrors('agreementReason')->assertSee('admin-validation-summary', false);
    expect($component->get('adminIntentIds'))->toBe($ids);
});

it('summarizes only this customers current-period payment and opens a prefilled modal without mutating it', function () {
    $makePayment = fn ($customer) => \App\Domain\Payments\Models\Payment::create([
        'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'provider' => 'fib',
        'purchase_type' => 'addon_credits', 'payment_mode' => 'one_time', 'provider_object_type' => 'payment',
        'status' => 'paid', 'internal_status' => 'applied', 'amount' => 1000, 'currency' => 'IQD',
        'idempotency_key' => (string) Str::uuid(), 'fulfilled_at' => now(),
        'local_reference' => 'WORKSPACE-'.Str::uuid(),
        'status_response' => ['secret' => 'PRIVATE_PROVIDER_TOKEN'],
    ]);
    $old = $makePayment($this->customer);
    $old->forceFill(['created_at' => now()->addDay()])->save();
    AdminAuditEvent::create(['admin_id' => $this->operator->id, 'action' => \App\Services\Billing\BillingReportingBoundary::ACTION,
        'target_type' => \App\Services\Billing\PaymentDomainCutover::class, 'target_id' => (string) Str::uuid(),
        'reason' => 'Isolated reporting-boundary fixture.', 'after_state' => ['reporting_boundary' => [
            'starts_at' => now()->toDateTimeString(), 'credit_order_id' => 0, 'payment_id' => $old->id,
        ]]]);
    expect(app(AdminCustomerWorkspace::class)->read($this->customer->id)['latest_payment'])->toBeNull();
    $payment = $makePayment($this->customer);
    $makePayment($this->other);
    $snapshot = $payment->fresh()->getRawOriginal();
    $projection = app(AdminCustomerWorkspace::class)->read($this->customer->id);
    expect($projection['latest_payment']['id'])->toBe($payment->id)
        ->and(json_encode($projection))->not->toContain('PRIVATE_PROVIDER_TOKEN');
    $component = Livewire::withQueryParams(['customer' => $this->customer->id])->test('admin::pages.customers.adm-customers-register');
    $component->call('prefillPaidSubscriptionReconciliation', $payment->id)
        ->assertDispatched('admin:modal-show', id: 'customer-action-2')
        ->assertSet('paidReconciliationPaymentId', (string) $payment->id);
    expect($payment->fresh()->getRawOriginal())->toBe($snapshot);
    Http::assertNothingSent();
});
