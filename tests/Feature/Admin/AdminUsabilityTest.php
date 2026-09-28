<?php

use App\Models\Customer;
use App\Models\PlanEntitlement;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\MetKurd\V2\InputBoundary;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $this->admin = User::forceCreate(['name' => 'cleanup', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->admin, 'admin');
    $this->customer = Customer::create(['username' => 'cleanup_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1]);
});

it('renders the Admin fallback avatar without a profile and has no global reason input', function () {
    foreach (['customers.list', 'services.tools', 'services.entitlements', 'customers.register'] as $page) {
        $html = $this->get(route('admin.'.$page, ['locale' => 'en']))->assertOk()->getContent();
        expect($html)->toContain(__('admin_shell.brand'), 'header-profile-user')->not->toContain('id="admin-change-reason"', 'id="admin-credit-reason"');
    }
});

it('keeps customer detail and focused billing free of the register table and puts actions in modals', function () {
    $html = $this->get(route('admin.customers.register', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk()->getContent();
    expect($html)->not->toContain('Register Table')->toContain('id="customer-action-1"', 'id="customer-action-2"', 'id="agreement-create"', 'modal-dialog-scrollable');
    $this->get(route('admin.customers.detail', ['locale' => 'en', 'customer' => $this->customer->id]))->assertOk()->assertDontSee('Register Table');
    $this->get(route('admin.customers.register', ['locale' => 'en']))->assertOk()->assertSee('Register Table');
});

it('retains fresh capability and backend reason requirements with visible validation', function () {
    $component = Livewire::test('admin::pages.services.adm-services-entitlements');
    $component->call('saveEntitlement')->assertHasErrors('adminChangeReason')->assertSee('admin-validation-summary', false);
    $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    Livewire::test('admin::pages.services.adm-services-entitlements')->set('adminChangeReason', 'Approved fixture change.')->call('saveEntitlement')->assertForbidden();
});

it('edits the existing entitlement character limit and enforces separate App and API values', function () {
    $plan = $this->customer->currentServicePlan();
    $action = ToolAction::where('full_code', 'xomni.generate')->firstOrFail();
    $row = PlanEntitlement::updateOrCreate(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'app'], ['allowed' => true, 'limits' => ['max_chars_per_submit' => 400, 'preserved' => 7]]);
    Livewire::test('admin::pages.services.adm-services-entitlements')->call('openEntitlementEditModal', $row->id)
        ->set('adminChangeReason', 'Approved plan character limit change.')->set('entitlementMaxCharacters', '2000')->call('saveEntitlement')->assertHasNoErrors();
    expect($row->fresh()->limits)->toBe(['max_chars_per_submit' => 2000, 'preserved' => 7]);
    PlanEntitlement::updateOrCreate(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'api'], ['allowed' => true, 'limits' => ['max_chars_per_submit' => 600]]);
    $boundary = app(InputBoundary::class);
    expect($boundary->characterLimit($this->customer->fresh(), $action->full_code))->toBe(2000)
        ->and($boundary->characterLimit($this->customer->fresh(), $action->full_code, 'api'))->toBe(600);
    $boundary->text($this->customer->fresh(), $action->full_code, ['text' => str_repeat('a', 2000), 'language' => 'en']);
    expect(fn () => $boundary->text($this->customer->fresh(), $action->full_code, ['text' => str_repeat('a', 601), 'language' => 'en'], false, 'api'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('bounds every leaderboard query even when the client tampers with the limit', function (int $limit, int $expected) {
    $component = Livewire::test('admin::pages.customers.adm-customers-ranking')->set('rankingLimit', $limit);
    expect($component->instance()->boundedRankingLimit())->toBe($expected);
    $queries = [];
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $component->call('$refresh')->assertOk();
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'order by') && str_contains($sql, 'limit '.$expected))->count())->toBeGreaterThanOrEqual(2);
})->with([[20, 20], [40, 40], [60, 60], [80, 80], [100, 100], [1000000, 20], [-1, 20]]);

it('does not grant capabilities based on Admin numeric identity', function () {
    $primary = User::find(1);
    if ($primary) {
        $primary->forceFill(['status' => 1, 'admin_capabilities' => ['admin.read']])->save();
        $this->actingAs($primary, 'admin');
        expect(\Illuminate\Support\Facades\Gate::forUser($primary)->allows('admin.finance'))->toBeFalse();
    }
    $this->artisan('admin:capabilities', ['user' => (string) $this->admin->id, 'capabilities' => AdminAccess::CAPABILITIES, '--reason' => 'Authorized isolated capability fixture.'])->assertSuccessful();
    expect($this->admin->fresh()->admin_capabilities)->toBe(AdminAccess::CAPABILITIES);
});
