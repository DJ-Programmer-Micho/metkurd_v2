<?php

namespace App\Services\Mcp;

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\V2\ApiCatalog;

/** Configuration diagnostics only: no grants, synthetic customers or wallet creation. */
class Readiness
{
    public function plan(ServicePlan $plan): array
    {
        $config = app(CustomerApiAccessService::class)->configForPlan($plan);
        $catalog = app(ApiCatalog::class);
        $scopes = $catalog->scopesForConfiguration($config['allowed_tools']);
        $actions = [];
        foreach ($catalog->variants() as $variant) {
            $action = ToolAction::where('full_code', $variant['action'])->where('is_active', true)
                ->whereHas('tool', fn ($query) => $query->where('is_active', true))->first();
            $entitlement = $action ? PlanEntitlement::where('service_plan_id', $plan->id)->where('tool_action_id', $action->id)
                ->whereIn('entitlement_channel', PlanEntitlement::fallbackChannels('api', true))
                ->orderByRaw("CASE WHEN entitlement_channel = 'api' THEN 0 ELSE 1 END")->first() : null;
            $rules = $action ? PricingRule::where('tool_action_id', $action->id)->whereIn('pricing_channel', PricingRule::fallbackChannels('api'))
                ->where('is_active', true)->where(fn ($q) => $q->whereNull('service_plan_id')->orWhere('service_plan_id', $plan->id))
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->get() : collect();
            $actions[] = ['action' => $variant['action'], 'scope' => $variant['scope'], 'scope_configured' => in_array($variant['scope'], $scopes, true),
                'entitled' => (bool) $entitlement?->allowed, 'pricing' => $rules->isEmpty() ? 'missing' : 'configured; runtime quote required'];
        }

        return ['plan' => $plan->code, 'active' => (bool) $plan->is_active, 'api_enabled' => $config['api_enabled'],
            'monthly_api_allowance' => (int) $plan->api_monthly_credits, 'requests_per_minute' => $config['requests_per_minute'],
            'concurrent_jobs' => $config['concurrent_jobs'], 'recognized_scopes' => $scopes,
            'missing_family_scopes' => array_values(array_diff($catalog->serviceScopes(), $scopes)), 'actions' => $actions];
    }

    public function customerAction(Customer $customer, string $action, array $context, int $requiredCredits = 1): array
    {
        $customer = $customer->fresh();
        $catalog = app(ApiCatalog::class);
        $scope = $catalog->scopeForAction($action);
        $access = app(CustomerMcpAccessService::class);
        $paid = $access->eligible($customer);
        $allowed = $paid && $catalog->hasAccess($customer) && in_array($scope, $access->scopes($customer), true)
            && $customer->isAllowed($action, 'api');
        $price = $allowed ? $customer->priceCreditsFor($action, $context, 'api') : 0;
        $wallet = CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'api')->first();
        $available = max(0, (int) $wallet?->subscription_balance_credits + (int) $wallet?->addon_balance_credits);

        return ['paid' => $paid, 'allowed' => $allowed, 'pricing_configured' => $price > 0,
            'api_credits_sufficient' => $available >= max($requiredCredits, $price),
            'ready' => $allowed && $price > 0 && $available >= max($requiredCredits, $price)];
    }
}
