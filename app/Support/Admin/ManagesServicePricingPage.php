<?php

namespace App\Support\Admin;

use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesServicePricingPage
{
    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'action', keep: true)]
    public string $actionFilter = 'all';

    #[Url(as: 'type', keep: true)]
    public string $ruleTypeFilter = 'all';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'scope', keep: true)]
    public string $scopeFilter = 'all';

    public int $perPage = 10;

    public ?int $editingRuleId = null;
    public ?int $ruleToolActionId = null;
    public ?int $ruleServicePlanId = null;
    public string $ruleType = 'unit';
    public int $rulePriority = 100;
    public string $ruleMetricCode = '';
    public string $ruleUnitSize = '1';
    public string $ruleCreditsPerUnit = '';
    public string $ruleRoundingMode = 'ceil';
    public string $ruleRoundingStep = '1';
    public int $ruleMinimumCredits = 0;
    public string $ruleConditionsJson = '';
    public string $ruleConfigJson = '';
    public string $ruleStatus = 'active';
    public string $ruleStartsAt = '';
    public string $ruleEndsAt = '';

    public ?int $ruleIdPendingDelete = null;
    public string $deleteLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRuleTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedScopeFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->actionFilter = 'all';
        $this->ruleTypeFilter = 'all';
        $this->statusFilter = 'all';
        $this->scopeFilter = 'all';
        $this->resetPage();
    }

    #[Computed]
    public function planOptions()
    {
        return ServicePlan::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function actionOptions()
    {
        return ToolAction::query()
            ->with('tool:id,code,name')
            ->orderBy('tool_code')
            ->orderBy('action_code')
            ->get(['id', 'tool_code', 'action_code', 'full_code', 'name']);
    }

    #[Computed]
    public function topStats(): array
    {
        $planRules = (int) PricingRule::query()->whereNotNull('service_plan_id')->count();
        $totalRules = (int) PricingRule::query()->count();

        return [
            'rules' => $totalRules,
            'active_rules' => (int) PricingRule::query()->where('is_active', true)->count(),
            'plan_rules' => $planRules,
            'global_rules' => max(0, $totalRules - $planRules),
        ];
    }

    protected function pricingRulesBaseQuery(): Builder
    {
        $query = PricingRule::query()
            ->with(['toolAction.tool:id,code,name', 'servicePlan:id,code,name']);

        if ($this->planFilter !== 'all') {
            $query->where('service_plan_id', (int) $this->planFilter);
        }

        if ($this->actionFilter !== 'all') {
            $query->where('tool_action_id', (int) $this->actionFilter);
        }

        if ($this->ruleTypeFilter !== 'all') {
            $query->where('rule_type', $this->ruleTypeFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($this->scopeFilter === 'global') {
            $query->whereNull('service_plan_id');
        } elseif ($this->scopeFilter === 'plan') {
            $query->whereNotNull('service_plan_id');
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('metric_code', 'like', "%{$search}%")
                    ->orWhere('rule_type', 'like', "%{$search}%")
                    ->orWhereHas('toolAction', function (Builder $actionQuery) use ($search) {
                        $actionQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('full_code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('servicePlan', function (Builder $planQuery) use ($search) {
                        $planQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        return $query
            ->orderByDesc('is_active')
            ->orderByDesc('priority')
            ->orderByDesc('id');
    }

    #[Computed]
    public function pricingRules()
    {
        return $this->pricingRulesBaseQuery()->paginate($this->perPage);
    }

    public function openPricingRuleCreateModal(): void
    {
        $this->resetRuleForm();
        $this->dispatch('services-pricing:modal-show', id: 'servicePricingRuleModal');
    }

    public function openPricingRuleEditModal(int $ruleId): void
    {
        $rule = PricingRule::query()->findOrFail($ruleId);
        $this->resetValidation();
        $this->editingRuleId = $rule->id;
        $this->ruleToolActionId = (int) $rule->tool_action_id;
        $this->ruleServicePlanId = $rule->service_plan_id ? (int) $rule->service_plan_id : null;
        $this->ruleType = (string) $rule->rule_type;
        $this->rulePriority = (int) $rule->priority;
        $this->ruleMetricCode = (string) $rule->metric_code;
        $this->ruleUnitSize = (string) $rule->unit_size;
        $this->ruleCreditsPerUnit = $rule->credits_per_unit !== null ? (string) $rule->credits_per_unit : '';
        $this->ruleRoundingMode = (string) $rule->rounding_mode;
        $this->ruleRoundingStep = (string) $rule->rounding_step;
        $this->ruleMinimumCredits = (int) $rule->minimum_credits;
        $this->ruleConditionsJson = $rule->conditions ? (string) json_encode($rule->conditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        $this->ruleConfigJson = $rule->config ? (string) json_encode($rule->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        $this->ruleStatus = $rule->is_active ? 'active' : 'inactive';
        $this->ruleStartsAt = $rule->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ruleEndsAt = $rule->ends_at?->format('Y-m-d\TH:i') ?? '';

        $this->dispatch('services-pricing:modal-show', id: 'servicePricingRuleModal');
    }

    public function savePricingRule(): void
    {
        $rules = [
            'ruleToolActionId' => ['required', 'integer', Rule::exists('tool_actions', 'id')],
            'ruleServicePlanId' => ['nullable', 'integer', Rule::exists('service_plans', 'id')],
            'ruleType' => ['required', Rule::in(['free', 'fixed', 'unit', 'matrix'])],
            'rulePriority' => ['required', 'integer', 'min:0', 'max:1000'],
            'ruleMetricCode' => ['required', 'string', 'max:50'],
            'ruleUnitSize' => ['required', 'numeric', 'gt:0'],
            'ruleCreditsPerUnit' => ['nullable', 'numeric', 'min:0'],
            'ruleRoundingMode' => ['required', Rule::in(['none', 'ceil', 'floor', 'nearest'])],
            'ruleRoundingStep' => ['required', 'numeric', 'gt:0'],
            'ruleMinimumCredits' => ['required', 'integer', 'min:0'],
            'ruleConditionsJson' => ['nullable', 'string'],
            'ruleConfigJson' => ['nullable', 'string'],
            'ruleStatus' => ['required', Rule::in(['active', 'inactive'])],
            'ruleStartsAt' => ['nullable', 'date'],
            'ruleEndsAt' => ['nullable', 'date', 'after_or_equal:ruleStartsAt'],
        ];

        if ($this->ruleType !== 'free') {
            $rules['ruleCreditsPerUnit'][] = 'required';
        }

        $this->validate($rules);

        $rule = $this->editingRuleId
            ? PricingRule::query()->findOrFail($this->editingRuleId)
            : new PricingRule();

        $conditions = $this->decodeJsonField($this->ruleConditionsJson, 'ruleConditionsJson');
        $config = $this->decodeJsonField($this->ruleConfigJson, 'ruleConfigJson');

        $rule->fill([
            'tool_action_id' => $this->ruleToolActionId,
            'service_plan_id' => $this->ruleServicePlanId ?: null,
            'rule_scope' => $this->ruleServicePlanId ? 'plan' : 'global',
            'rule_type' => $this->ruleType,
            'priority' => $this->rulePriority,
            'metric_code' => trim($this->ruleMetricCode),
            'unit_size' => (float) $this->ruleUnitSize,
            'credits_per_unit' => $this->ruleType === 'free' ? 0 : (float) $this->ruleCreditsPerUnit,
            'rounding_mode' => $this->ruleRoundingMode,
            'rounding_step' => (float) $this->ruleRoundingStep,
            'minimum_credits' => $this->ruleMinimumCredits,
            'conditions' => $conditions ?: null,
            'config' => $config ?: null,
            'is_active' => $this->ruleStatus === 'active',
            'starts_at' => $this->ruleStartsAt !== '' ? $this->ruleStartsAt : null,
            'ends_at' => $this->ruleEndsAt !== '' ? $this->ruleEndsAt : null,
        ]);

        $rule->save();

        $this->dispatch('alert', type: 'success', message: $this->editingRuleId ? 'Pricing rule updated successfully.' : 'Pricing rule created successfully.');
        $this->dispatch('services-pricing:modal-hide', id: 'servicePricingRuleModal');
        $this->resetRuleForm();
    }

    public function togglePricingRuleStatus(int $ruleId): void
    {
        $rule = PricingRule::query()->findOrFail($ruleId);
        $rule->update(['is_active' => !$rule->is_active]);
        $this->dispatch('alert', type: 'success', message: $rule->is_active ? 'Pricing rule activated.' : 'Pricing rule deactivated.');
    }

    public function confirmPricingRuleDelete(int $ruleId): void
    {
        $rule = PricingRule::query()->with('toolAction:id,name,full_code')->findOrFail($ruleId);
        $this->ruleIdPendingDelete = $rule->id;
        $this->deleteLabel = $rule->toolAction?->full_code ?? ('Rule #' . $rule->id);
        $this->dispatch('services-pricing:modal-show', id: 'servicePricingDeleteModal');
    }

    public function performDelete(): void
    {
        if ($this->ruleIdPendingDelete) {
            PricingRule::query()->findOrFail($this->ruleIdPendingDelete)->delete();
            $this->dispatch('alert', type: 'success', message: 'Pricing rule deleted successfully.');
        }

        $this->dispatch('services-pricing:modal-hide', id: 'servicePricingDeleteModal');
        $this->resetDeleteState();
    }

    public function resetRuleForm(): void
    {
        $this->resetValidation();
        $this->editingRuleId = null;
        $this->ruleToolActionId = null;
        $this->ruleServicePlanId = null;
        $this->ruleType = 'unit';
        $this->rulePriority = 100;
        $this->ruleMetricCode = '';
        $this->ruleUnitSize = '1';
        $this->ruleCreditsPerUnit = '';
        $this->ruleRoundingMode = 'ceil';
        $this->ruleRoundingStep = '1';
        $this->ruleMinimumCredits = 0;
        $this->ruleConditionsJson = '';
        $this->ruleConfigJson = '';
        $this->ruleStatus = 'active';
        $this->ruleStartsAt = '';
        $this->ruleEndsAt = '';
    }

    public function resetDeleteState(): void
    {
        $this->ruleIdPendingDelete = null;
        $this->deleteLabel = '';
    }

    public function statusBadgeClasses(bool $state): string
    {
        return $state ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
    }

    public function formatDecimal($value, int $precision = 2): string
    {
        $number = (float) ($value ?? 0);
        $formatted = number_format($number, $precision, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    protected function decodeJsonField(?string $value, string $field): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'Please enter a valid JSON object.',
            ]);
        }

        return $decoded;
    }
}
