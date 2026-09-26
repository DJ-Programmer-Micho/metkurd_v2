<?php

use App\Models\AdminAuditEvent;
use App\Models\Customer;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\User;
use App\Services\Admin\AdminEntitlementScopes;
use App\Services\Admin\AdminV2Catalog;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('s3');
    Storage::fake('local');
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    $admin = User::forceCreate(['name' => 'P1 operator', 'email' => Str::uuid().'@example.test',
        'password' => 'fixture-password', 'status' => 1, 'admin_capabilities' => AdminAccess::CAPABILITIES]);
    $admin->profile()->create(['first_name' => 'P1', 'last_name' => 'Operator']);
    $this->actingAs($admin, 'admin');
});

function p1Plan(array $attributes = []): ServicePlan
{
    return ServicePlan::create(array_merge(['code' => 'p1_'.Str::random(10), 'name' => 'P1 plan',
        'api_enabled' => true, 'api_requests_per_minute' => 60, 'api_concurrent_jobs' => 2,
        'is_free' => false, 'is_active' => true, 'api_allowed_tools' => []], $attributes));
}

function p1Entitlement(ServicePlan $plan, string $action, string $channel = 'api'): PlanEntitlement
{
    return app(AdminEntitlementScopes::class)->mutate(null, ['service_plan_id' => $plan->id,
        'tool_action_id' => ToolAction::where('full_code', $action)->firstOrFail()->id,
        'entitlement_channel' => $channel, 'allowed' => true]);
}

it('projects all native API identities without writes', function () {
    $before = PricingRule::orderBy('id')->get()->toArray();
    $rows = app(AdminV2Catalog::class)->rows(p1Plan());
    expect(array_column($rows, 'action'))->toBe(['xomni.generate', 'xomni-v2.generate', 'zeta.generate',
        'clone_xomni.generate', 'vector-v2.generate', 'theta.generate', 'leo.transcribe', 'caption.standard', 'ocr.standard', 'harakat.diacritize', 'stem.sep2', 'stem.sep4']);
    expect(array_column($rows, 'family'))->toBe(['Apollo', 'Apollo', 'Zeta', 'Vector', 'Vector', 'Theta', 'Leo', 'Caption', 'OCR', 'OCR', 'STEM', 'STEM'])
        ->and(array_unique(array_filter(array_column($rows, 'scope'))))->toHaveCount(7)
        ->and(array_filter(array_column($rows, 'action_id')))->toHaveCount(12)
        ->and(PricingRule::orderBy('id')->get()->toArray())->toBe($before);
});

it('diagnoses inactive missing and mismatched catalog records without repairing them', function () {
    $plan = p1Plan();
    $action = ToolAction::where('full_code', 'vector-v2.generate')->firstOrFail();
    $action->update(['is_active' => false, 'tool_code' => 'xomni']);
    $rows = collect(app(AdminV2Catalog::class)->rows($plan))->keyBy('action');
    expect($rows['vector-v2.generate']['diagnostics'])->toContain('binding_mismatch', 'action_inactive', 'pricing_review', 'api_entitlement_missing');
    $action->delete();
    Tool::where('code', 'vector-v2')->delete();
    $row = collect(app(AdminV2Catalog::class)->rows($plan))->firstWhere('action', 'vector-v2.generate');
    expect($row['diagnostics'])->toContain('tool_missing', 'action_missing')->and($action->fresh())->toBeNull();
});

it('retains and classifies legacy historical and unmapped rows and accepts hyphens', function () {
    $catalog = app(AdminV2Catalog::class);
    expect($catalog->classification(Tool::where('code', 'xomni')->firstOrFail()))->toBe('current')
        ->and($catalog->classification(Tool::where('code', 'tts')->firstOrFail()))->toBe('legacy');
    Livewire::test('admin::pages.services.adm-services-tools')->set('adminChangeReason', 'Create disposable regression tool.')
        ->set('toolCode', 'p1-hyphen_tool')->set('toolName', 'P1 hyphen tool')->call('saveTool')->assertHasNoErrors();
    $tool = Tool::where('code', 'p1-hyphen_tool')->firstOrFail();
    expect($catalog->classification($tool))->toBe('unmapped');
    $customer = Customer::withoutEvents(fn () => Customer::create(['username' => 'p1fixture', 'email' => 'p1@example.test', 'password' => 'fixture']));
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $tool->id, 'status' => 'deleted']);
    expect($catalog->classification($tool))->toBe('historical')->and($tool->fresh())->not->toBeNull();
});

it('keeps a family scope until the last sibling entitlement is disabled', function (string $first, string $second, string $scope) {
    $plan = p1Plan();
    $sync = app(AdminEntitlementScopes::class);
    $one = p1Entitlement($plan, $first);
    $two = p1Entitlement($plan, $second);
    $sync->mutate($one->id, operation: 'toggle');
    expect($plan->fresh()->api_allowed_tools)->toContain($scope);
    $sync->mutate($two->id, operation: 'toggle');
    expect($plan->fresh()->api_allowed_tools)->not->toContain($scope);
})->with([
    ['xomni.generate', 'xomni-v2.generate', 'v2:speech'],
    ['clone_xomni.generate', 'vector-v2.generate', 'v2:voice-clone'],
    ['stem.sep2', 'stem.sep4', 'v2:stem'],
    ['xomni-v2.generate', 'zeta.generate', 'v2:speech'],
    ['vector-v2.generate', 'theta.generate', 'v2:voice-clone'],
]);

it('derives single service scopes from ApiCatalog', function (string $action, string $scope) {
    $plan = p1Plan();
    p1Entitlement($plan, $action);
    expect($plan->fresh()->api_allowed_tools)->toBe([$scope]);
})->with([['leo.transcribe', 'v2:transcriptions'], ['caption.standard', 'v2:captions'], ['ocr.standard', 'v2:ocr'], ['harakat.diacritize', 'v2:harakat']]);

it('handles plan action and channel moves and preserves deliberate broad scopes', function () {
    $old = p1Plan(['api_allowed_tools' => ['v2:*', 'tts:*', 'v2:ocr']]);
    $new = p1Plan();
    $row = p1Entitlement($old, 'leo.transcribe');
    $sync = app(AdminEntitlementScopes::class);
    $sync->mutate($row->id, ['service_plan_id' => $new->id]);
    expect($old->fresh()->api_allowed_tools)->toBe(['v2:*', 'tts:*', 'v2:ocr'])
        ->and($new->fresh()->api_allowed_tools)->toBe(['v2:transcriptions']);
    $sync->mutate($row->id, ['tool_action_id' => ToolAction::where('full_code', 'ocr.standard')->value('id')]);
    expect($new->fresh()->api_allowed_tools)->toBe(['v2:ocr']);
    $sync->mutate($row->id, ['entitlement_channel' => 'app']);
    expect($new->fresh()->api_allowed_tools)->toBe([]);
    $sync->mutate($row->id, ['entitlement_channel' => 'all']);
    expect($new->fresh()->api_allowed_tools)->toBe(['v2:ocr']);
    $sync->mutate($row->id, operation: 'delete');
    expect($new->fresh()->api_allowed_tools)->toBe([]);
});

it('honors api denial over all fallback and preserves an explicitly owned exact scope', function () {
    $plan = p1Plan(['api_allowed_tools' => ['v2:stem']]);
    p1Entitlement($plan, 'stem.sep2', 'all');
    $api = p1Entitlement($plan, 'stem.sep2');
    app(AdminEntitlementScopes::class)->mutate($api->id, operation: 'toggle');
    expect($plan->fresh()->api_allowed_tools)->toBe(['v2:stem'])
        ->and(data_get($plan->fresh()->meta, 'admin_api_scopes.derived'))->toBe([]);
});

it('rolls entitlement and both plan scopes back if scope persistence fails', function () {
    $old = p1Plan();
    $new = p1Plan();
    $row = p1Entitlement($old, 'ocr.standard');
    ServicePlan::saving(function ($plan) use ($new) {
        if ($plan->id === $new->id) {
            throw new RuntimeException('Injected scope failure');
        }
    });
    expect(fn () => app(AdminEntitlementScopes::class)->mutate($row->id, ['service_plan_id' => $new->id]))->toThrow(RuntimeException::class);
    expect($row->fresh()->service_plan_id)->toBe($old->id)->and($old->fresh()->api_allowed_tools)->toBe(['v2:ocr'])
        ->and($new->fresh()->api_allowed_tools)->toBe([]);
});

it('shows free plan configured and effective access through the runtime service', function () {
    $plan = p1Plan(['is_free' => true, 'api_allowed_tools' => ['v2:*']]);
    p1Entitlement($plan, 'ocr.standard');
    $config = app(CustomerApiAccessService::class)->configForPlan($plan->fresh());
    $row = collect(app(AdminV2Catalog::class)->rows($plan->fresh()))->firstWhere('action', 'ocr.standard');
    expect($plan->api_enabled)->toBeTrue()->and($config['api_enabled'])->toBeFalse()
        ->and($config['requests_per_minute'])->toBe(0)->and($config['concurrent_jobs'])->toBe(0)
        ->and($row['api_effective'])->toBeFalse();
});

it('resolves sample prices with runtime plan fallback channels dates conditions and priorities', function () {
    $plan = p1Plan();
    $action = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    $base = ['tool_action_id' => $action->id, 'service_plan_id' => $plan->id, 'metric_code' => 'page',
        'unit_size' => 1, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'is_active' => true];
    $fallback = PricingRule::create(array_merge($base, ['pricing_channel' => 'all', 'credits_per_unit' => 7, 'priority' => 500]));
    PricingRule::create(array_merge($base, ['pricing_channel' => 'api', 'credits_per_unit' => 3, 'priority' => 600]));
    PricingRule::create(array_merge($base, ['pricing_channel' => 'app', 'credits_per_unit' => 99, 'priority' => 900, 'ends_at' => now()->subDay()]));
    PricingRule::create(array_merge($base, ['pricing_channel' => 'app', 'credits_per_unit' => 88, 'priority' => 950, 'conditions' => ['language' => 'zz']]));
    $row = collect(app(AdminV2Catalog::class)->rows($plan, ['pages' => 2, 'language' => 'ckb']))->firstWhere('action', 'ocr.standard');
    $customer = app(AdminV2Catalog::class)->planCustomer($plan);
    expect($row['channels']['app']['credits'])->toBe(14)->toBe($customer->priceCreditsFor('ocr.standard', ['pages' => 2, 'language' => 'ckb'], 'app'))
        ->and($row['channels']['api']['credits'])->toBe(6)->and($row['channels']['app']['rule_id'])->toBe($fallback->id);
});

it('commits grouped pricing together and rolls back the whole edit on a channel failure', function (bool $fail) {
    $plan = p1Plan();
    $action = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    $component = Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Update grouped fixture pricing.')
        ->set('ruleToolActionId', $action->id)->set('ruleServicePlanId', $plan->id)->set('ruleMetricCode', 'page')
        ->set('ruleAppCreditsPerUnit', '10')->set('ruleMobileCreditsPerUnit', '9')->set('ruleApiCreditsPerUnit', '8');
    $auditCount = AdminAuditEvent::count();
    if ($fail) {
        PricingRule::saving(function ($rule) {
            if ($rule->pricing_channel === 'api') {
                throw new RuntimeException('Injected channel failure');
            }
        });
        try {
            $component->call('savePricingRule');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Injected channel failure');
        }
        expect(PricingRule::where('service_plan_id', $plan->id)->count())->toBe(0)->and(AdminAuditEvent::count())->toBe($auditCount);
    } else {
        $component->call('savePricingRule')->assertHasNoErrors();
        expect(PricingRule::where('service_plan_id', $plan->id)->count())->toBe(3);
    }
})->with([false, true]);

it('loads Admin catalogs and direction on direct and verified Livewire requests', function (string $locale) {
    $response = $this->get('/'.$locale.'/adm/services/tools')->assertOk();
    expect(request()->attributes->get('translation_area'))->toBe('admin');
    $messages = json_decode(file_get_contents(resource_path('lang/admin/'.$locale.'.json')), true);
    expect(__('Services Tools'))->toBe($messages['Services Tools']);
    $response->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false);
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])->map(fn ($value) => html_entity_decode($value, ENT_QUOTES))
        ->first(fn ($value) => data_get(json_decode($value, true), 'memo.name') === 'admin::pages.services.adm-services-tools');
    expect($snapshot)->not->toBeNull();
    $this->withHeaders(['X-Livewire' => 'true', 'Referer' => url('/ar/tools')])->postJson('/livewire/update', ['components' => [[
        'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]])->assertOk();
    expect(app()->getLocale())->toBe($locale)->and(request()->attributes->get('translation_area'))->toBe('admin');
    expect(__('Services Tools'))->toBe($messages['Services Tools']);
    $this->get('/'.$locale.'/tools')->assertOk();
    expect(request()->attributes->get('translation_area'))->toBe('landing');
})->with(['en', 'ar', 'ku']);

it('preserves scope ownership when saving explicit plan configuration and ignores forged ownership metadata', function () {
    $plan = p1Plan(['api_allowed_tools' => ['v2:*'], 'meta' => ['operator_note' => 'keep']]);
    p1Entitlement($plan, 'ocr.standard');
    $component = Livewire::test('admin::pages.payments.adm-payments-plans')
        ->set('adminChangeReason', 'Update explicit fixture plan scopes.')->call('openEditPlanModal', $plan->id);
    expect($component->get('apiAllowedToolsText'))->toBe('v2:*');
    $component->set('apiAllowedToolsText', 'v2:speech')
        ->set('metaJson', '{"operator_note":"keep","admin_api_scopes":{"explicit":["*"],"derived":[]}}')
        ->call('savePlan')->assertHasNoErrors();
    expect($plan->fresh()->api_allowed_tools)->toBe(['v2:speech', 'v2:ocr'])
        ->and(data_get($plan->fresh()->meta, 'admin_api_scopes.explicit'))->toBe(['v2:speech'])
        ->and(data_get($plan->fresh()->meta, 'operator_note'))->toBe('keep');
});

it('moves entitlements through the Livewire editor and synchronizes both plans', function () {
    $old = p1Plan();
    $new = p1Plan();
    $row = p1Entitlement($old, 'ocr.standard');
    Livewire::test('admin::pages.services.adm-services-entitlements')
        ->set('adminChangeReason', 'Move fixture entitlement to another plan.')
        ->call('openEntitlementEditModal', $row->id)->set('entitlementServicePlanId', $new->id)
        ->call('saveEntitlement')->assertHasNoErrors();
    expect($old->fresh()->api_allowed_tools)->toBe([])->and($new->fresh()->api_allowed_tools)->toBe(['v2:ocr']);
});

it('rolls back edits to existing pricing channels and preserves fallback history', function () {
    $plan = p1Plan();
    $action = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();
    foreach (['app', 'mobile', 'api', 'all'] as $channel) {
        PricingRule::create(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id,
            'pricing_channel' => $channel, 'metric_code' => 'page', 'unit_size' => 1, 'credits_per_unit' => 2, 'priority' => 800]);
    }
    $original = PricingRule::where('service_plan_id', $plan->id)->orderBy('id')->get()->toArray();
    $component = Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Edit existing grouped fixture prices.')
        ->call('openPricingRuleEditModal', $original[0]['id'])->set('ruleAppCreditsPerUnit', '20')
        ->set('ruleMobileCreditsPerUnit', '19')->set('ruleApiCreditsPerUnit', '18');
    PricingRule::saving(function ($rule) {
        if ($rule->pricing_channel === 'api') {
            throw new RuntimeException('Injected edit failure');
        }
    });
    try {
        $component->call('savePricingRule');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Injected edit failure');
    }
    expect(PricingRule::where('service_plan_id', $plan->id)->orderBy('id')->get()->toArray())->toBe($original);
});

it('keeps STEM mode output count and duration in the runtime quote context', function () {
    $rows = collect(app(AdminV2Catalog::class)->rows(p1Plan(), ['seconds' => 125, 'outputs' => 999]));
    foreach ([2, 4] as $mode) {
        $row = $rows->firstWhere('action', 'stem.sep'.$mode);
        expect($row['context']['stem_outputs'])->toBe($mode)->and($row['context']['outputs'])->toBe($mode)
            ->and($row['context']['separation_mode'])->toBe($mode)->and($row['context']['minutes'])->toBe(3);
    }
});

it('uses the configured Admin prefix and keeps translated key sets complete', function () {
    app()->instance('aurl', 'operators');
    expect(\App\Support\TranslationArea::forRequest(\Illuminate\Http\Request::create('/ku/operators/home')))->toBe('admin')
        ->and(\App\Support\TranslationArea::forRequest(\Illuminate\Http\Request::create('/ku/tools')))->toBe('landing');
    $english = require resource_path('lang/en/admin_p1.php');
    foreach (['ar', 'ku'] as $locale) {
        $translated = require resource_path('lang/'.$locale.'/admin_p1.php');
        expect(array_keys($translated))->toBe(array_keys($english))->and(array_filter($translated, fn ($text) => trim($text) === ''))->toBe([]);
    }
});
