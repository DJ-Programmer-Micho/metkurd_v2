<?php

namespace App\Services\Admin;

use App\Models\ApiJob;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\V2\ApiCatalog;

class AdminV2Catalog
{
    public function planCustomer(ServicePlan $plan): Customer
    {
        // Unsaved read context: no real customer overrides, wallet, or subscription writes.
        $preview = new class extends Customer
        {
            protected $table = 'customers';

            public function currentServicePlan(): ?ServicePlan
            {
                return $this->getRelation('servicePlan');
            }
        };

        return $preview->forceFill(['id' => 0, 'status' => 1])->setRelation('servicePlan', $plan);
    }

    public function rows(ServicePlan $plan, array $sample = []): array
    {
        $catalog = app(ApiCatalog::class);
        $config = app(CustomerApiAccessService::class)->configForPlan($plan);
        $scopes = $catalog->scopesForConfiguration($config['allowed_tools']);
        $customer = $this->planCustomer($plan);
        $rows = [];
        foreach ($catalog->variants() as $variant) {
            $definition = $variant['tool'];
            $tool = Tool::where('code', $definition['legacy_tool'])->first();
            $action = ToolAction::where('full_code', $variant['action'])->first();
            $diagnostics = [];
            if (! $tool) {
                $diagnostics[] = 'tool_missing';
            }
            if (! $action) {
                $diagnostics[] = 'action_missing';
            }
            if ($tool && ! $tool->is_active) {
                $diagnostics[] = 'tool_inactive';
            }
            if ($action && ! $action->is_active) {
                $diagnostics[] = 'action_inactive';
            }
            if (($action && ($action->tool_code !== $definition['legacy_tool'] || $action->action_code !== substr($variant['action'], strlen($definition['legacy_tool']) + 1)))
                || ($definition['legacy_action'] ?? null) !== $variant['action']) {
                $diagnostics[] = 'binding_mismatch';
            }
            // Migration defaults are not approved business prices. Keep the review visible.
            if (($definition['provider_model'] ?? null) === 'model_2' || $variant['service'] === 'transcriptions') {
                $diagnostics[] = 'pricing_review';
            }
            $seconds = (float) ($sample['seconds'] ?? 60);
            $context = array_merge($sample, ['chars' => (int) ($sample['chars'] ?? 100),
                'pages' => (int) ($sample['pages'] ?? 1), 'seconds' => $seconds,
                'minutes' => max(1, (int) ceil($seconds / 60))]);
            $context['metric_code'] = $action?->default_metric_code;
            if ($variant['service'] === 'captions') {
                $context['output_format'] = 'srt';
            }
            if (isset($definition['stems'])) {
                $context = array_merge($context, ['outputs' => $definition['stems'], 'stem_outputs' => $definition['stems'],
                    'separation_mode' => $definition['stems']]);
            }
            $channels = [];
            foreach (['app', 'api'] as $channel) {
                $entitlements = $action ? $plan->planEntitlements()->where('tool_action_id', $action->id)
                    ->whereIn('entitlement_channel', PlanEntitlement::fallbackChannels($channel, true))->exists() : false;
                $rule = $customer->resolvedPricingRuleFor($variant['action'], array_merge($context, ['channel' => $channel]), $channel);
                $allowed = $customer->isAllowed($variant['action'], $channel);
                if (! $entitlements) {
                    $diagnostics[] = $channel.'_entitlement_missing';
                }
                if (! $rule) {
                    $diagnostics[] = $channel.'_price_missing';
                }
                $channels[$channel] = ['entitlement' => $allowed, 'price_exists' => $rule !== null,
                    'credits' => $customer->priceCreditsFor($variant['action'], array_merge($context, ['channel' => $channel]), $channel),
                    'rule_id' => $rule?->id, 'rule_channel' => $rule?->pricing_channel,
                    'rule_plan_id' => $rule?->service_plan_id, 'priority' => $rule?->priority];
            }
            $route = match ($variant['service']) {
                'transcriptions' => 'app.v2.leo', 'captions' => 'app.v2.caption', 'ocr' => 'app.v2.ocr',
                'stem' => 'app.v2.stem', default => 'app.v2.tool',
            };
            $rows[] = array_merge($variant, ['name' => $definition['name'],
                'family' => match ($variant['service']) {
                    'speech' => 'Apollo', 'voice-clone' => 'Vector', 'transcriptions' => 'Leo', 'captions' => 'Caption', 'ocr' => 'OCR', default => 'STEM'
                },
                'tool_code' => $definition['legacy_tool'], 'tool_id' => $tool?->id, 'action_id' => $action?->id,
                'metric' => $action?->default_metric_code, 'route' => $route,
                'path' => '/'.app()->getLocale().'/app-v2/'.$variant['web_service'].'/'.$variant['slug'],
                'active' => (bool) $tool?->is_active && (bool) $action?->is_active,
                'diagnostics' => $diagnostics, 'channels' => $channels, 'context' => $context,
                'scope_allowed' => in_array($variant['scope'], $scopes, true),
                'api_effective' => $config['api_enabled'] && $config['requests_per_minute'] > 0 && $config['concurrent_jobs'] > 0
                    && in_array($variant['scope'], $scopes, true) && $channels['api']['entitlement']]);
        }

        return $rows;
    }

    public function classification(Tool $tool): string
    {
        if (in_array($tool->code, array_column(array_column(app(ApiCatalog::class)->variants(), 'tool'), 'legacy_tool'), true)) {
            return 'current';
        }
        if (MlJob::where('tool_id', $tool->id)->exists() || MlJob::whereIn('tool_action_id', $tool->actions()->select('id'))->exists()
            || ApiJob::where('tool_code', $tool->code)->exists() || CustomerFile::where('tool_code', $tool->code)->exists()) {
            return 'historical';
        }
        foreach ($tool->actions as $action) {
            if (app(CustomerApiAccessService::class)->scopeForActionCode($action->full_code)) {
                return 'legacy';
            }
        }

        return 'unmapped';
    }
}
