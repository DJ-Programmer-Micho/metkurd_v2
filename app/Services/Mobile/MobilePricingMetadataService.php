<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Models\CustomerPricingRule;
use App\Models\PricingRule;
use App\Models\ToolAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class MobilePricingMetadataService
{
    public function __construct(
        protected MobileAppCatalog $apps,
    ) {}

    /**
     * @return array{
     *     version:?string,
     *     currency:string,
     *     rules:array<int, array<string, mixed>>
     * }
     */
    public function forCustomer(Customer $customer): array
    {
        $actions = $this->allowedMobileActions($customer);
        $rules = [];
        $latestUpdatedAt = null;
        $planId = (int) ($customer->currentServicePlanId() ?? 0);

        foreach ($actions as $action) {
            foreach ($this->customerRulesForAction($customer, $action) as $rule) {
                $rules[] = $this->serializeRule($rule, $action);
                $latestUpdatedAt = $this->latestTimestamp($latestUpdatedAt, $rule->updated_at);
            }

            foreach ($this->defaultRulesForAction($action, $planId) as $rule) {
                $rules[] = $this->serializeRule($rule, $action);
                $latestUpdatedAt = $this->latestTimestamp($latestUpdatedAt, $rule->updated_at);
            }
        }

        return [
            'version' => $latestUpdatedAt?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'currency' => 'credits',
            'rules' => $rules,
        ];
    }

    /**
     * @return Collection<int, ToolAction>
     */
    protected function allowedMobileActions(Customer $customer): Collection
    {
        $mobileToolCodes = collect($this->apps->all())
            ->flatMap(fn (array $app): array => array_values((array) ($app['tool_codes'] ?? [])))
            ->map(fn (string $toolCode): string => strtolower(trim($toolCode)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ToolAction::query()
            ->with('tool:code,is_active')
            ->whereIn('tool_code', $mobileToolCodes)
            ->where('is_active', true)
            ->whereHas('tool', fn ($query) => $query->where('is_active', true))
            ->orderBy('tool_code')
            ->orderBy('full_code')
            ->get()
            ->filter(fn (ToolAction $action): bool => $customer->isAllowed((string) $action->full_code, \App\Models\PlanEntitlement::CHANNEL_MOBILE))
            ->values();
    }

    /**
     * @return Collection<int, CustomerPricingRule>
     */
    protected function customerRulesForAction(Customer $customer, ToolAction $action): Collection
    {
        $now = now();

        return CustomerPricingRule::query()
            ->where('customer_id', (int) $customer->id)
            ->where('tool_action_id', (int) $action->id)
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->whereIn('pricing_channel', PricingRule::fallbackChannels(PricingRule::CHANNEL_MOBILE))
            ->orderByRaw(
                'CASE WHEN pricing_channel = ? THEN 0 WHEN pricing_channel = ? THEN 1 ELSE 2 END',
                [PricingRule::CHANNEL_MOBILE, PricingRule::CHANNEL_ALL]
            )
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, PricingRule>
     */
    protected function defaultRulesForAction(ToolAction $action, int $planId): Collection
    {
        $now = now();
        $query = PricingRule::query()
            ->where('tool_action_id', (int) $action->id)
            ->where('is_active', true)
            ->where(function ($builder) use ($now) {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($builder) use ($planId) {
                if ($planId > 0) {
                    $builder->whereNull('service_plan_id')
                        ->orWhere('service_plan_id', $planId);

                    return;
                }

                $builder->whereNull('service_plan_id');
            });

        $query->whereIn('pricing_channel', PricingRule::fallbackChannels(PricingRule::CHANNEL_MOBILE));

        if ($planId > 0) {
            $query->orderByRaw('CASE WHEN service_plan_id = ? THEN 0 ELSE 1 END', [$planId]);
        }

        return $query
            ->orderByRaw(
                'CASE WHEN pricing_channel = ? THEN 0 WHEN pricing_channel = ? THEN 1 ELSE 2 END',
                [PricingRule::CHANNEL_MOBILE, PricingRule::CHANNEL_ALL]
            )
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeRule(PricingRule|CustomerPricingRule $rule, ToolAction $action): array
    {
        $metricCode = $this->normalizeMetricCode((string) ($rule->metric_code ?: $action->default_metric_code ?: 'unit'));
        $creditsPerUnit = $this->normalizeNumber($rule->credits_per_unit);
        $minimumCredits = (int) ($rule->minimum_credits ?? 0);

        return [
            'tool_code' => (string) $action->tool_code,
            'tool_action' => (string) $action->full_code,
            'metric_code' => $metricCode,
            'unit_label' => $this->unitLabelForMetric($metricCode),
            'billing_unit' => $this->normalizeNumber($rule->unit_size),
            'credits_per_unit' => $creditsPerUnit,
            'min_billable_units' => $this->minimumBillableUnits($creditsPerUnit, $minimumCredits, (string) ($rule->rule_type ?? 'unit')),
            'rounding_mode' => (string) ($rule->rounding_mode ?? 'ceil'),
            'rounding_step' => $this->normalizeNumber($rule->rounding_step),
            'client_formula_hint' => $this->formulaHint((string) ($rule->rule_type ?? 'unit')),
            'minimum_credits' => $minimumCredits,
            'maximum_credits' => $this->normalizeNumber(
                data_get((array) ($rule->config ?? []), 'maximum_credits', data_get((array) ($rule->config ?? []), 'max_credits'))
            ),
            'conditions' => $this->normalizedConditions((array) ($rule->conditions ?? [])),
        ];
    }

    protected function normalizeMetricCode(string $metricCode): string
    {
        return match (strtolower(trim($metricCode))) {
            'character', 'characters', 'char', 'chars' => 'chars',
            'minute', 'minutes', 'min' => 'minutes',
            'second', 'seconds', 'sec' => 'seconds',
            'page', 'pages' => 'pages',
            'file', 'files' => 'files',
            'stem_output', 'stem_outputs', 'output_stem' => 'stem_outputs',
            default => strtolower(trim($metricCode)),
        };
    }

    protected function unitLabelForMetric(string $metricCode): string
    {
        return match ($metricCode) {
            'chars' => 'characters',
            'minutes' => 'minutes',
            'seconds' => 'seconds',
            'pages' => 'pages',
            'files' => 'files',
            'stem_outputs' => 'output stems',
            default => 'units',
        };
    }

    protected function minimumBillableUnits(int|float|null $creditsPerUnit, int $minimumCredits, string $ruleType): ?int
    {
        if ($ruleType === 'free') {
            return 0;
        }

        if ($minimumCredits <= 0) {
            return 1;
        }

        if ($creditsPerUnit === null || $creditsPerUnit <= 0) {
            return 1;
        }

        return max(1, (int) ceil($minimumCredits / (float) $creditsPerUnit));
    }

    protected function formulaHint(string $ruleType): string
    {
        return match (strtolower(trim($ruleType))) {
            'free' => '0',
            'fixed' => 'minimum_credits',
            default => 'max(minimum_credits, apply_rounding((input_size / billing_unit) * credits_per_unit, rounding_mode, rounding_step))',
        };
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>|null
     */
    protected function normalizedConditions(array $conditions): ?array
    {
        return $conditions !== [] ? $conditions : null;
    }

    protected function latestTimestamp(?CarbonInterface $current, mixed $candidate): ?CarbonInterface
    {
        if (! $candidate instanceof CarbonInterface) {
            return $current;
        }

        if (! $current instanceof CarbonInterface) {
            return $candidate;
        }

        return $candidate->greaterThan($current) ? $candidate : $current;
    }

    protected function normalizeNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $float = (float) $value;

        return fmod($float, 1.0) === 0.0
            ? (int) $float
            : round($float, 4);
    }
}
