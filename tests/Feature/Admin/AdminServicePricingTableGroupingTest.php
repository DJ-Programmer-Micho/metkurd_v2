<?php

use App\Models\PricingRule;
use App\Models\ToolAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

function servicePricingGroupingAdmin(): User
{
    return User::unguarded(function (): User {
        return User::query()->create([
            'admin_capabilities' => \App\Support\Admin\AdminAccess::CAPABILITIES,
            'name' => 'Pricing Group Admin',
            'email' => 'pricing-group-admin-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'Secret123!',
        ]);
    });
}

/**
 * @param  array<string, float|int>  $channels
 * @return array<string, PricingRule>
 */
function createServicePricingGroup(string $metricCode, array $channels, array $overrides = []): array
{
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();
    $rules = [];

    foreach ($channels as $channel => $creditsPerUnit) {
        $rules[$channel] = PricingRule::query()->create(array_merge([
            'tool_action_id' => (int) $action->id,
            'service_plan_id' => null,
            'pricing_channel' => $channel,
            'rule_scope' => 'global',
            'rule_type' => 'unit',
            'priority' => 710,
            'metric_code' => $metricCode,
            'unit_size' => 1,
            'credits_per_unit' => $creditsPerUnit,
            'rounding_mode' => 'ceil',
            'rounding_step' => 1,
            'minimum_credits' => 1,
            'is_active' => true,
            'conditions' => null,
            'config' => null,
        ], $overrides));
    }

    return $rules;
}

it('groups app mobile and api pricing rows into one visual row', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_alpha';

    createServicePricingGroup($metricCode, [
        'app' => 10,
        'mobile' => 10,
        'api' => 8,
    ]);

    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('search', $metricCode)
        ->assertSee('App Price')
        ->assertSee('Mobile Price')
        ->assertSee('API Price');

    expect(substr_count($component->html(), $metricCode))->toBe(1);

    $groups = (fn () => $this->groupedPricingRules->items())->call($component->instance());

    expect($groups)->toHaveCount(1);
});

it('shows grouped app mobile and api prices once for the shared rule', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_beta';

    createServicePricingGroup($metricCode, [
        'app' => 10,
        'mobile' => 11,
        'api' => 8,
    ]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('search', $metricCode)
        ->assertSee('10 credits')
        ->assertSee('11 credits')
        ->assertSee('8 credits');
});

it('shows fallback and not configured labels without adding duplicate legacy channel rows', function () {
    $admin = servicePricingGroupingAdmin();
    $fallbackMetric = 'pricing_group_fallback';
    $missingMetric = 'pricing_group_missing';

    createServicePricingGroup($fallbackMetric, [
        'all' => 9,
    ]);

    createServicePricingGroup($missingMetric, [
        'app' => 7,
    ], ['priority' => 711]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('search', $fallbackMetric)
        ->assertSee('Fallback: 9 credits')
        ->assertSee('Legacy All: 9 credits')
        ->assertDontSee('Legacy All Channels');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('search', $missingMetric)
        ->assertSee('7 credits')
        ->assertSee('Not configured');
});

it('loads grouped app mobile and api prices into the edit modal', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_edit_modal';
    $rules = createServicePricingGroup($metricCode, [
        'app' => 4,
        'mobile' => 5,
        'api' => 6,
    ], ['priority' => 712]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('openPricingRuleEditModal', (int) $rules['app']->id)
        ->assertSet('ruleMetricCode', $metricCode)
        ->assertSet('ruleAppCreditsPerUnit', '4')
        ->assertSet('ruleMobileCreditsPerUnit', '5')
        ->assertSet('ruleApiCreditsPerUnit', '6');
});

it('disables all primary rows in a grouped pricing row', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_disable';
    $rules = createServicePricingGroup($metricCode, [
        'app' => 4,
        'mobile' => 5,
        'api' => 6,
    ], ['priority' => 713]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('togglePricingRuleStatus', (int) $rules['app']->id)
        ->assertHasNoErrors();

    expect(PricingRule::query()->whereIn('id', collect($rules)->pluck('id')->all())->where('is_active', false)->count())
        ->toBe(3);
});

it('deletes grouped primary rows and preserves legacy all fallback rows', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_delete';
    $rules = createServicePricingGroup($metricCode, [
        'app' => 4,
        'mobile' => 5,
        'api' => 6,
        'all' => 9,
    ], ['priority' => 714]);

    $this->actingAs($admin, 'admin');

    Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->call('confirmPricingRuleDelete', (int) $rules['app']->id)
        ->assertSet('ruleIdsPendingDelete', [
            (int) $rules['app']->id,
            (int) $rules['mobile']->id,
            (int) $rules['api']->id,
        ])
        ->call('performDelete')
        ->assertHasNoErrors();

    expect(PricingRule::query()->whereKey((int) $rules['all']->id)->exists())->toBeTrue()
        ->and(PricingRule::query()->whereKey((int) $rules['app']->id)->exists())->toBeFalse()
        ->and(PricingRule::query()->whereKey((int) $rules['mobile']->id)->exists())->toBeFalse()
        ->and(PricingRule::query()->whereKey((int) $rules['api']->id)->exists())->toBeFalse();
});

it('keeps channel filtering grouped without reintroducing duplicate rows', function () {
    $admin = servicePricingGroupingAdmin();
    $metricCode = 'pricing_group_channel_filter';

    createServicePricingGroup($metricCode, [
        'all' => 9,
    ], ['priority' => 715]);

    $this->actingAs($admin, 'admin');

    $component = Livewire::test('admin::pages.services.adm-services-pricing')->set('adminChangeReason', 'Authorized catalog correction for regression verification.')
        ->set('search', $metricCode)
        ->set('channelFilter', 'api')
        ->assertSee('Fallback: 9 credits');

    expect(substr_count($component->html(), $metricCode))->toBe(1);
});
