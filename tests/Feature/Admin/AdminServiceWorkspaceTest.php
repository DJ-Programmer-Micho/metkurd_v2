<?php

use App\Models\AdminAuditEvent;
use App\Models\LandingToolPage;
use App\Models\PlanEntitlement;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\User;
use App\Models\Voice;
use App\Services\Admin\AdminV2Catalog;
use App\Services\MetKurd\V2\InputBoundary;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminServiceWorkspace;
use App\Support\Landing\PublicProductCatalog;
use Illuminate\Support\Facades\Cache;
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
    Cache::flush();
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $this->operator = User::forceCreate(['name' => 'Service workspace operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $this->actingAs($this->operator, 'admin');
    $this->plan = ServicePlan::where('code', 'student')->firstOrFail();
    $this->action = ToolAction::where('full_code', 'xomni-v2.generate')->firstOrFail();
});

it('renders all scoped pages in each locale without changing catalog or economic records', function (string $locale) {
    $snapshot = fn () => collect(['tools', 'tool_actions', 'plan_entitlements', 'pricing_rules', 'service_plans', 'voices'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    foreach (['services.tools', 'services.entitlements', 'services.pricing', 'services.voices', 'payments.plans', 'landing.tools'] as $page) {
        $response = $this->get(route('admin.'.$page, ['locale' => $locale]))->assertOk();
        $response->assertDontSee('admin_service.');
        expect($response->getContent())->toContain('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"');
    }
    expect($snapshot())->toBe($before);
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('agrees with PublicProductCatalog for active disabled hidden and legacy products', function () {
    $reader = app(AdminServiceWorkspace::class);
    expect($reader->publicStatus($this->action->full_code))->toBe('public');
    LandingToolPage::create(['slug' => 'tts', 'is_active' => false]);
    expect($reader->publicStatus($this->action->full_code))->toBe('hidden')
        ->and(app(PublicProductCatalog::class)->has('apollo-2'))->toBeFalse();
    $old = LandingToolPage::create(['slug' => 'translation', 'is_active' => true]);
    expect($reader->publicStatus('translation.standard'))->toBe('legacy')->and($old->fresh()->is_active)->toBeTrue();
    $this->action->update(['is_active' => false]);
    $row = collect($reader->matrix('app', $this->plan->id)['rows'])->firstWhere('action', $this->action->full_code);
    expect($row['active'])->toBeFalse();
    Livewire::test('admin::pages.services.adm-services-tools')->assertSee(__('admin_service.disabled'))
        ->assertSee(__('admin_service.public_help'))->assertSee('data-service-overview', false);
});

it('shows explicit deny channel fallback and exact runtime character limits separately', function () {
    PlanEntitlement::where('service_plan_id', $this->plan->id)->where('tool_action_id', $this->action->id)->delete();
    $base = ['service_plan_id' => $this->plan->id, 'tool_action_id' => $this->action->id];
    PlanEntitlement::create($base + ['entitlement_channel' => 'all', 'allowed' => true, 'limits' => ['max_chars_per_submit' => 2000]]);
    PlanEntitlement::create($base + ['entitlement_channel' => 'api', 'allowed' => false, 'limits' => ['max_chars_per_submit' => 700]]);
    $reader = app(AdminServiceWorkspace::class);
    $cell = fn ($channel) => collect($reader->matrix($channel, $this->plan->id)['rows'])->firstWhere('action', $this->action->full_code)['cells'][$this->plan->id];
    expect($cell('app'))->toMatchArray(['decision' => 'allow', 'source' => 'all', 'effective' => true, 'characters' => 2000]);
    expect($cell('api'))->toMatchArray(['decision' => 'deny', 'source' => 'api', 'effective' => false, 'characters' => 700]);
    $preview = app(AdminV2Catalog::class)->planCustomer($this->plan);
    expect($cell('app')['characters'])->toBe(app(InputBoundary::class)->characterLimit($preview, $this->action->full_code));
    PlanEntitlement::where($base)->delete();
    expect($cell('app'))->toMatchArray(['decision' => 'missing', 'characters' => 400, 'configured_characters' => null]);
    Http::assertNothingSent();
});

it('opens a channel-specific matrix edit and preserves the shared fallback row until explicit save', function () {
    $base = ['service_plan_id' => $this->plan->id, 'tool_action_id' => $this->action->id];
    PlanEntitlement::where($base)->delete();
    $fallback = PlanEntitlement::create($base + ['entitlement_channel' => 'all', 'allowed' => true, 'limits' => ['max_chars_per_submit' => 2000]]);
    $before = $fallback->fresh()->toArray();
    $component = Livewire::test('admin::pages.services.adm-services-entitlements')
        ->call('openMatrixEntitlement', $this->plan->id, $this->action->id, 'api')
        ->assertSet('editingEntitlementId', null)->assertSet('entitlementChannel', 'api')
        ->assertSet('entitlementAllowed', 'blocked')->assertDispatched('services-entitlements:modal-show');
    $component->call('saveEntitlement')->assertHasErrors('adminChangeReason')->assertSee('admin-validation-summary', false);
    $component->set('adminChangeReason', 'Approved isolated channel denial test.')->set('entitlementMaxCharacters', '700')
        ->call('saveEntitlement')->assertHasNoErrors();
    expect($fallback->fresh()->toArray())->toBe($before)
        ->and(PlanEntitlement::where($base)->where('entitlement_channel', 'api')->first()->allowed)->toBeFalse()
        ->and(AdminAuditEvent::where('reason', 'Approved isolated channel denial test.')->exists())->toBeTrue();
});

it('gates matrix editors and preserves plan scope and allowance authority', function () {
    $snapshot = $this->plan->fresh()->getRawOriginal();
    $summary = app(AdminServiceWorkspace::class)->planSummary($this->plan);
    expect($summary)->toHaveKeys(['families', 'limits', 'api'])
        ->and($summary['limits']['Apollo 2.0v'])->toHaveKeys(['app', 'api'])
        ->and($this->plan->fresh()->getRawOriginal())->toBe($snapshot);
    $this->operator->forceFill(['admin_capabilities' => ['admin.read']])->save();
    Livewire::test('admin::pages.services.adm-services-entitlements')
        ->call('openMatrixEntitlement', $this->plan->id, $this->action->id, 'app')->assertForbidden();
});

it('reports voice catalog plans and preview configuration without exposing references and invalidates after edits', function () {
    $voice = Voice::create(['code' => 'workspace-voice', 'name' => 'Workspace Voice', 'is_active' => true, 'is_public' => false,
        'meta' => ['engine' => 'xomni', 'ref_audio' => 'PRIVATE_REFERENCE_FIXTURE.wav', 'preview_audio' => 'PRIVATE_PREVIEW_FIXTURE.wav']]);
    PlanVoiceAccess::create(['service_plan_id' => $this->plan->id, 'voice_id' => $voice->id, 'is_active' => true]);
    $reader = app(AdminServiceWorkspace::class);
    expect($reader->voiceSummary($voice))->toMatchArray(['omni' => true, 'plans' => [$this->plan->name], 'preview' => true]);
    Livewire::test('admin::pages.services.adm-services-voices')->set('search', 'workspace-voice')
        ->assertSee('workspace-voice')->assertSee($this->plan->name)->assertSee(__('admin_service.preview_configured'))
        ->assertDontSee('PRIVATE_REFERENCE_FIXTURE')->assertDontSee('PRIVATE_PREVIEW_FIXTURE');
    $version = Cache::get('omni-speaker-catalog:version');
    Livewire::test('admin::pages.services.adm-services-voices')->set('adminChangeReason', 'Approved isolated voice status check.')
        ->call('toggleVoiceStatus', $voice->id)->assertHasNoErrors();
    expect(Cache::get('omni-speaker-catalog:version'))->toBeGreaterThan($version)
        ->and($reader->voiceSummary($voice->fresh())['plans'])->toBe([]);
});

it('keeps pricing validation inside the existing editor and does not mutate on invalid prices', function () {
    $before = PricingRule::orderBy('id')->get()->toJson();
    Livewire::test('admin::pages.services.adm-services-pricing')->call('openPricingRuleCreateModal')
        ->set('adminChangeReason', 'Approved isolated invalid price test.')->set('ruleToolActionId', $this->action->id)
        ->set('ruleMetricCode', 'character')->set('ruleAppCreditsPerUnit', '-1')
        ->call('savePricingRule')->assertHasErrors('ruleAppCreditsPerUnit')
        ->assertSee('admin-validation-summary', false)->assertSee(__('admin_service.pricing_help'));
    expect(PricingRule::orderBy('id')->get()->toJson())->toBe($before);
});
