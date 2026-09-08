<?php

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Admin\AdminV2Catalog;
use App\Services\Billing\CreditService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function launchCorrection(): \Illuminate\Database\Migrations\Migration
{
    return require database_path('migrations/2026_09_07_000001_normalize_v2_launch_pricing.php');
}

function launchRegistrations(): void
{
    foreach (['2026_08_15_000000_register_xomni_v2_tool', '2026_08_15_000100_register_vector_v2_tool', '2026_08_16_000100_register_leo_v2_tool', '2026_08_16_000200_register_caption_v2_access'] as $migration) {
        (require database_path('migrations/'.$migration.'.php'))->up();
    }
}

function launchFingerprint(array $tables): array
{
    return collect($tables)->mapWithKeys(function ($table) {
        $rows = DB::table($table)->orderBy('id')->get();

        return [$table => ['count' => $rows->count(), 'hash' => hash('sha256', $rows->toJson())]];
    })->all();
}

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    config(['metkurd_v2.enabled' => false, 'customer_api.v2_enabled' => false]);
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Storage::fake('local');
    Storage::fake('s3');
    $this->seed();

    // Explicit production-shaped legacy fixtures; no application DB or external reads.
    foreach ([
        'xomni.generate' => ['xomni', 'generate', 'character', 20, 15, 1],
        'clone_xomni.generate' => ['clone_xomni', 'generate', 'character', 24, 18, 1],
        'qasr.standard' => ['qasr', 'standard', 'minute', 1100, 825, 1100],
        'caption.standard' => ['caption', 'standard', 'minute', 1300, 975, 1000],
    ] as $code => [$tool, $action, $metric, $rate, $apiRate, $minimum]) {
        Tool::firstOrCreate(['code' => $tool], ['name' => $tool, 'is_active' => true]);
        $model = ToolAction::firstOrCreate(['full_code' => $code], ['tool_code' => $tool, 'action_code' => $action, 'name' => $code, 'default_metric_code' => $metric]);
        DB::table('pricing_rules')->where('tool_action_id', $model->id)->delete();
        foreach (['all', 'app', 'mobile', 'api'] as $channel) {
            PricingRule::create(['tool_action_id' => $model->id, 'pricing_channel' => $channel, 'metric_code' => $metric,
                'unit_size' => 1, 'credits_per_unit' => $channel === 'api' ? $apiRate : $rate,
                'minimum_credits' => $minimum, 'rounding_mode' => 'ceil', 'rounding_step' => 1, 'priority' => 100, 'is_active' => true]);
        }
    }
    $this->legacyPrices = DB::table('pricing_rules')->whereIn('tool_action_id', ToolAction::whereIn('full_code', ['xomni.generate', 'clone_xomni.generate', 'qasr.standard', 'caption.standard'])->pluck('id'))->orderBy('id')->get()->toJson();
    $this->customer = Customer::create(['username' => 'launch_fixture', 'email' => 'launch@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    app(CreditService::class)->addAddonCredits($this->customer->id, 17, ['reference_code' => 'launch-fixture']);

    // Simulate the six-present/three-missing pre-registration catalog in an isolated schema.
    foreach (['xomni-v2', 'vector-v2', 'leo'] as $tool) {
        DB::table('tool_actions')->where('tool_code', $tool)->delete();
        DB::table('tools')->where('code', $tool)->delete();
    }
});

it('registers the three variants then installs explicit approved prices without financial or legacy changes', function () {
    $protected = ['customers', 'credit_wallets', 'credit_ledgers', 'customer_service_subscriptions', 'credit_orders', 'payments', 'ml_jobs', 'customer_files', 'service_plans'];
    $before = launchFingerprint($protected);
    expect($before['credit_wallets']['count'])->toBeGreaterThan(0)
        ->and($before['credit_ledgers']['count'])->toBeGreaterThan(0);
    launchRegistrations();
    $grants = launchFingerprint(['plan_entitlements']);
    launchCorrection()->up();
    expect(launchFingerprint($protected))->toBe($before)
        ->and(launchFingerprint(['plan_entitlements']))->toBe($grants);

    foreach ([
        'xomni-v2.generate' => ['xomni-v2', 'character', 1, ['all' => 20, 'app' => 20, 'mobile' => 20, 'api' => 15]],
        'vector-v2.generate' => ['vector-v2', 'character', 1, ['all' => 24, 'app' => 24, 'mobile' => 24, 'api' => 18]],
        'leo.transcribe' => ['leo', 'minute', 1100, ['all' => 1100, 'app' => 1100, 'mobile' => 1100, 'api' => 825]],
    ] as $code => [$tool, $metric, $minimum, $prices]) {
        $action = ToolAction::where('full_code', $code)->firstOrFail();
        expect($action->tool_code)->toBe($tool)->and($action->tool->is_active)->toBeTrue()->and($action->is_active)->toBeTrue();
        $entitlements = PlanEntitlement::where('tool_action_id', $action->id)->get();
        expect($entitlements)->toHaveCount(4);
        foreach ($entitlements as $grant) {
            expect($grant->entitlement_channel)->toBe('all')->and($grant->allowed)->toBeTrue()->and($grant->limits)->toBeNull();
        }
        $rules = PricingRule::where('tool_action_id', $action->id)->where('is_active', true)->get()->keyBy('pricing_channel');
        expect($rules)->toHaveCount(4);
        foreach ($prices as $channel => $rate) {
            $rule = $rules[$channel];
            expect((float) $rule->credits_per_unit)->toBe((float) $rate)->and($rule->metric_code)->toBe($metric)
                ->and((float) $rule->unit_size)->toBe(1.0)->and((int) $rule->minimum_credits)->toBe($minimum)
                ->and($rule->rounding_mode)->toBe('ceil')->and((float) $rule->rounding_step)->toBe(1.0);
        }
    }
    $legacy = DB::table('pricing_rules')->whereIn('tool_action_id', ToolAction::whereIn('full_code', ['xomni.generate', 'clone_xomni.generate', 'qasr.standard', 'caption.standard'])->pluck('id'))->orderBy('id')->get()->toJson();
    expect($legacy)->toBe($this->legacyPrices);
    $once = launchFingerprint(['pricing_rules']);
    $this->travel(1)->minutes();
    launchCorrection()->up();
    launchCorrection()->down();
    expect(launchFingerprint(['pricing_rules']))->toBe($once);
});

it('normalizes Leo regardless of the order of tied QASR channel rules', function (array $order) {
    $id = ToolAction::where('full_code', 'qasr.standard')->value('id');
    $rules = DB::table('pricing_rules')->where('tool_action_id', $id)->get()->keyBy('pricing_channel');
    DB::table('pricing_rules')->where('tool_action_id', $id)->delete();
    foreach ($order as $channel) {
        $row = (array) $rules[$channel];
        unset($row['id']);
        DB::table('pricing_rules')->insert($row);
    }
    launchRegistrations();
    launchCorrection()->up();
    $rates = PricingRule::where('tool_action_id', ToolAction::where('full_code', 'leo.transcribe')->value('id'))->where('is_active', true)->pluck('credits_per_unit', 'pricing_channel')->map(fn ($value) => (float) $value)->all();
    expect($rates)->toEqual(['all' => 1100.0, 'app' => 1100.0, 'mobile' => 1100.0, 'api' => 825.0]);
})->with([
    'API inserted first' => [['api', 'mobile', 'app', 'all']],
    'API inserted last' => [['all', 'app', 'mobile', 'api']],
    'mixed insertion' => [['mobile', 'api', 'all', 'app']],
]);

it('makes Admin previews agree with actual App API Mobile and fallback customer quotes', function (string $code, int $appQuote, int $apiQuote) {
    launchRegistrations();
    launchCorrection()->up();
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $customer = $this->customer->fresh()->setRelation('servicePlan', $plan);
    $context = ['chars' => 10, 'seconds' => 120, 'minutes' => 2];
    $row = collect(app(AdminV2Catalog::class)->rows($plan, $context))->firstWhere('action', $code);
    expect($row['channels']['app']['credits'])->toBe($appQuote)->toBe($customer->priceCreditsFor($code, $context, 'app'))
        ->and($row['channels']['api']['credits'])->toBe($apiQuote)->toBe($customer->priceCreditsFor($code, $context, 'api'))
        ->and($customer->priceCreditsFor($code, $context, 'mobile'))->toBe($appQuote)
        ->and($customer->priceCreditsFor($code, $context, 'all'))->toBe($appQuote)
        ->and($customer->priceCreditsFor($code, $context, 'unknown-channel'))->toBe($appQuote);
    if ($code === 'leo.transcribe') {
        expect($customer->priceCreditsFor($code, ['minutes' => 1], 'api'))->toBe(1100);
    }
})->with([
    ['xomni-v2.generate', 200, 150], ['vector-v2.generate', 240, 180], ['leo.transcribe', 2200, 1650],
]);

it('disables competing global placeholders and leaves scoped overrides outside the launch normalization', function () {
    launchRegistrations();
    $action = ToolAction::where('full_code', 'xomni-v2.generate')->firstOrFail();
    $duplicate = PricingRule::create(['tool_action_id' => $action->id, 'pricing_channel' => 'all', 'priority' => 999, 'metric_code' => 'character', 'credits_per_unit' => 1, 'is_active' => true]);
    $scoped = PricingRule::create(['tool_action_id' => $action->id, 'service_plan_id' => ServicePlan::where('code', 'student')->value('id'), 'pricing_channel' => 'api', 'metric_code' => 'character', 'credits_per_unit' => 9]);
    $scopedBefore = $scoped->fresh()->getRawOriginal();
    launchCorrection()->up();
    expect($duplicate->fresh()->is_active)->toBeFalse()->and($scoped->fresh()->getRawOriginal())->toBe($scopedBefore);
    $customer = app(AdminV2Catalog::class)->planCustomer(ServicePlan::where('code', 'pro')->firstOrFail());
    expect($customer->priceCreditsFor('xomni-v2.generate', ['chars' => 10], 'all'))->toBe(200);
});

it('rolls the entire correction back if a required registration is missing', function () {
    launchRegistrations();
    DB::table('tool_actions')->where('full_code', 'leo.transcribe')->delete();
    $before = launchFingerprint(['pricing_rules']);
    expect(fn () => launchCorrection()->up())->toThrow(RuntimeException::class);
    expect(launchFingerprint(['pricing_rules']))->toBe($before);
});

it('passes the documented twelve-channel launch policy query after correction', function () {
    launchRegistrations();
    launchCorrection()->up();
    $document = file_get_contents(base_path('docs/metkurd/RELEASE-R1-CHECKS.md'));
    preg_match('/WITH policies AS \(.*?ORDER BY p.action_code,c.channel;/s', $document, $matches);
    expect($matches)->not->toBeEmpty();
    $rows = DB::select($matches[0]);
    expect($rows)->toHaveCount(12);
    foreach ($rows as $row) {
        expect((int) $row->launch_policy_match)->toBe(1);
    }
});

it('does not invent registrations or mutate rows during a pretend run', function () {
    $before = launchFingerprint(['tools', 'tool_actions', 'pricing_rules']);
    DB::connection()->pretend(fn () => launchCorrection()->up());
    expect(launchFingerprint(['tools', 'tool_actions', 'pricing_rules']))->toBe($before);
    expect(fn () => launchCorrection()->up())->toThrow(RuntimeException::class);
});

it('keeps API V2 scope key and entitlement checks explicit while feature flags stay disabled', function () {
    launchRegistrations();
    launchCorrection()->up();
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $plan->update(['api_enabled' => true, 'api_allowed_tools' => [], 'meta' => ['admin_api_scopes' => ['explicit' => [], 'derived' => []]]]);
    $customer = $this->customer->fresh()->setRelation('servicePlan', $plan);
    $catalog = app(ApiCatalog::class);
    $key = new CustomerApiKey(['scopes' => ['v2:speech']]);
    expect(fn () => $catalog->authorize($customer, $key, 'v2:speech', 'xomni-v2.generate'))->toThrow(ApiProblem::class);
    $plan->update(['api_allowed_tools' => ['v2:speech']]);
    $customer = $this->customer->fresh()->setRelation('servicePlan', $plan->fresh());
    $catalog->authorize($customer, $key, 'v2:speech', 'xomni-v2.generate');
    expect(fn () => $catalog->authorize($customer, new CustomerApiKey(['scopes' => []]), 'v2:speech', 'xomni-v2.generate'))->toThrow(ApiProblem::class);
    $plan->update(['is_free' => true]);
    expect(fn () => $catalog->authorize($this->customer->fresh()->setRelation('servicePlan', $plan->fresh()), $key, 'v2:speech', 'xomni-v2.generate'))->toThrow(ApiProblem::class);
    $plan->update(['is_free' => false]);
    ToolAction::where('full_code', 'xomni-v2.generate')->update(['is_active' => false]);
    expect(fn () => $catalog->authorize($this->customer->fresh()->setRelation('servicePlan', $plan->fresh()), $key, 'v2:speech', 'xomni-v2.generate'))->toThrow(ApiProblem::class);
    ToolAction::where('full_code', 'xomni-v2.generate')->update(['is_active' => true]);
    PlanEntitlement::where('service_plan_id', $plan->id)->where('tool_action_id', ToolAction::where('full_code', 'xomni-v2.generate')->value('id'))->update(['allowed' => false]);
    expect(fn () => $catalog->authorize($this->customer->fresh()->setRelation('servicePlan', $plan->fresh()), $key, 'v2:speech', 'xomni-v2.generate'))->toThrow(ApiProblem::class);
    expect(config('metkurd_v2.enabled'))->toBeFalse()->and(config('customer_api.v2_enabled'))->toBeFalse();
});
