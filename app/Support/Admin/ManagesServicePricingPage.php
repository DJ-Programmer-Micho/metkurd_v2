<?php

namespace App\Support\Admin;

use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    #[Url(as: 'channel', keep: true)]
    public string $channelFilter = 'all';

    public int $perPage = 10;

    public ?int $editingRuleId = null;

    /** @var array<string, int> */
    public array $editingRuleChannelIds = [];

    public ?int $ruleToolActionId = null;

    public ?int $ruleServicePlanId = null;

    public string $ruleType = 'unit';

    public int $rulePriority = 100;

    public string $ruleMetricCode = '';

    public string $ruleUnitSize = '1';

    public string $ruleAppCreditsPerUnit = '';

    public string $ruleMobileCreditsPerUnit = '';

    public string $ruleApiCreditsPerUnit = '';

    public string $ruleRoundingMode = 'ceil';

    public string $ruleRoundingStep = '1';

    public int $ruleMinimumCredits = 0;

    public string $ruleConditionsJson = '';

    public string $ruleConfigJson = '';

    public string $ruleStatus = 'active';

    public string $ruleStartsAt = '';

    public string $ruleEndsAt = '';

    /** @var array<int, int> */
    public array $ruleIdsPendingDelete = [];

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

    public function updatedChannelFilter(): void
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
        $this->channelFilter = 'all';
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

    protected function pricingRulesBaseQuery(bool $applyStatusFilter = true, bool $applyChannelFilter = true): Builder
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

        if ($applyStatusFilter) {
            if ($this->statusFilter === 'active') {
                $query->where('is_active', true);
            } elseif ($this->statusFilter === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($this->scopeFilter === 'global') {
            $query->whereNull('service_plan_id');
        } elseif ($this->scopeFilter === 'plan') {
            $query->whereNotNull('service_plan_id');
        }

        if ($applyChannelFilter && $this->channelFilter !== 'all') {
            $query->where('pricing_channel', $this->channelFilter);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('metric_code', 'like', "%{$search}%")
                    ->orWhere('rule_type', 'like', "%{$search}%")
                    ->orWhere('pricing_channel', 'like', "%{$search}%")
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

    #[Computed]
    public function groupedPricingRules(): LengthAwarePaginator
    {
        $rules = $this->pricingRulesBaseQuery(applyStatusFilter: false, applyChannelFilter: false)
            ->get();

        $groups = $rules
            ->groupBy(fn (PricingRule $rule) => $rule->groupingSignature())
            ->map(fn (Collection $group) => $this->makeGroupedPricingRuleRow($group))
            ->filter()
            ->filter(fn (array $group) => $this->groupMatchesStatusFilter($group))
            ->filter(fn (array $group) => $this->groupMatchesChannelFilter($group))
            ->sort(function (array $left, array $right): int {
                return [$right['status_sort'], $right['priority'], $right['seed_rule_id']]
                    <=> [$left['status_sort'], $left['priority'], $left['seed_rule_id']];
            })
            ->values();

        return $this->paginateGroupedPricingRules($groups);
    }

    public function openPricingRuleCreateModal(): void
    {
        $this->resetRuleForm();
        $this->dispatch('services-pricing:modal-show', id: 'servicePricingRuleModal');
    }

    public function openPricingRuleEditModal(int $ruleId): void
    {
        $rule = PricingRule::query()->findOrFail($ruleId);
        $group = $this->resolveRuleGroup($rule);
        $rulesByChannel = $group
            ->filter(fn (PricingRule $groupRule) => in_array($groupRule->pricing_channel, $this->editableChannels(), true))
            ->keyBy(fn (PricingRule $groupRule) => PricingRule::normalizeChannel((string) $groupRule->pricing_channel));

        $this->resetValidation();
        $this->editingRuleId = $rule->id;
        $this->editingRuleChannelIds = $rulesByChannel
            ->map(fn (PricingRule $groupRule) => (int) $groupRule->id)
            ->all();
        $this->ruleToolActionId = (int) $rule->tool_action_id;
        $this->ruleServicePlanId = $rule->service_plan_id ? (int) $rule->service_plan_id : null;
        $this->ruleType = (string) $rule->rule_type;
        $this->rulePriority = (int) $rule->priority;
        $this->ruleMetricCode = (string) $rule->metric_code;
        $this->ruleUnitSize = (string) $rule->unit_size;
        $this->ruleAppCreditsPerUnit = $this->formatCreditsInput($rulesByChannel->get(PricingRule::CHANNEL_APP)?->credits_per_unit);
        $this->ruleMobileCreditsPerUnit = $this->formatCreditsInput($rulesByChannel->get(PricingRule::CHANNEL_MOBILE)?->credits_per_unit);
        $this->ruleApiCreditsPerUnit = $this->formatCreditsInput($rulesByChannel->get(PricingRule::CHANNEL_API)?->credits_per_unit);
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
            'ruleAppCreditsPerUnit' => ['nullable', 'numeric', 'min:0'],
            'ruleMobileCreditsPerUnit' => ['nullable', 'numeric', 'min:0'],
            'ruleApiCreditsPerUnit' => ['nullable', 'numeric', 'min:0'],
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
            $rules['ruleAppCreditsPerUnit'][] = 'required';
            $rules['ruleMobileCreditsPerUnit'][] = 'required';
            $rules['ruleApiCreditsPerUnit'][] = 'required';
        }

        $this->validate($rules);

        $conditions = $this->decodeJsonField($this->ruleConditionsJson, 'ruleConditionsJson');
        $config = $this->decodeJsonField($this->ruleConfigJson, 'ruleConfigJson');
        $matchAttributes = $this->ruleMatchAttributes();
        $savedChannelIds = [];

        foreach ($this->channelCreditsPayload() as $channel => $creditsPerUnit) {
            $rule = $this->resolveRuleForChannel($channel, $matchAttributes, $conditions, $config);

            $rule->fill([
                'tool_action_id' => $this->ruleToolActionId,
                'service_plan_id' => $this->ruleServicePlanId ?: null,
                'pricing_channel' => $channel,
                'rule_scope' => $this->ruleServicePlanId ? 'plan' : 'global',
                'rule_type' => $this->ruleType,
                'priority' => $this->rulePriority,
                'metric_code' => trim($this->ruleMetricCode),
                'unit_size' => (float) $this->ruleUnitSize,
                'credits_per_unit' => $creditsPerUnit,
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
            $savedChannelIds[$channel] = (int) $rule->id;
        }

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingRuleId
                ? __('Pricing rules updated successfully for App, Mobile, and API.')
                : __('Pricing rules created successfully for App, Mobile, and API.')
        );
        unset($this->pricingRules, $this->groupedPricingRules, $this->topStats);
        $this->editingRuleChannelIds = $savedChannelIds;
        $this->dispatch('services-pricing:modal-hide', id: 'servicePricingRuleModal');
        $this->resetRuleForm();
    }

    public function togglePricingRuleStatus(int $ruleId): void
    {
        $seed = PricingRule::query()->findOrFail($ruleId);
        $group = $this->resolveRuleGroup($seed);
        $primaryIds = $group
            ->filter(fn (PricingRule $rule) => in_array(PricingRule::normalizeChannel((string) $rule->pricing_channel), $this->editableChannels(), true))
            ->pluck('id')
            ->all();

        if ($primaryIds === []) {
            $this->dispatch('alert', type: 'info', message: __('This group only has a legacy fallback row. Edit it to create App, Mobile, or API pricing rows.'));

            return;
        }

        $nextState = ! $group
            ->filter(fn (PricingRule $rule) => in_array(PricingRule::normalizeChannel((string) $rule->pricing_channel), $this->editableChannels(), true))
            ->contains(fn (PricingRule $rule) => (bool) $rule->is_active);

        PricingRule::query()
            ->whereIn('id', $primaryIds)
            ->update(['is_active' => $nextState]);

        unset($this->pricingRules, $this->groupedPricingRules, $this->topStats);
        $this->dispatch('alert', type: 'success', message: $nextState
            ? __('Pricing rule group activated.')
            : __('Pricing rule group deactivated.'));
    }

    public function confirmPricingRuleDelete(int $ruleId): void
    {
        $rule = PricingRule::query()->with('toolAction:id,name,full_code', 'servicePlan:id,name')->findOrFail($ruleId);
        $group = $this->resolveRuleGroup($rule);

        $this->ruleIdsPendingDelete = $group
            ->filter(fn (PricingRule $groupRule) => in_array(PricingRule::normalizeChannel((string) $groupRule->pricing_channel), $this->editableChannels(), true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $planLabel = $rule->servicePlan?->name ?? __('Global Default');
        $this->deleteLabel = sprintf(
            '%s / %s',
            (string) ($rule->toolAction?->full_code ?? __('Rule #:id', ['id' => $rule->id])),
            $planLabel
        );
        $this->dispatch('services-pricing:modal-show', id: 'servicePricingDeleteModal');
    }

    public function performDelete(): void
    {
        if ($this->ruleIdsPendingDelete !== []) {
            PricingRule::query()->whereIn('id', $this->ruleIdsPendingDelete)->delete();
            unset($this->pricingRules, $this->groupedPricingRules, $this->topStats);
            $this->dispatch('alert', type: 'success', message: __('Pricing rule group deleted successfully.'));
        }

        $this->dispatch('services-pricing:modal-hide', id: 'servicePricingDeleteModal');
        $this->resetDeleteState();
    }

    public function resetRuleForm(): void
    {
        $this->resetValidation();
        $this->editingRuleId = null;
        $this->editingRuleChannelIds = [];
        $this->ruleToolActionId = null;
        $this->ruleServicePlanId = null;
        $this->ruleType = 'unit';
        $this->rulePriority = 100;
        $this->ruleMetricCode = '';
        $this->ruleUnitSize = '1';
        $this->ruleAppCreditsPerUnit = '';
        $this->ruleMobileCreditsPerUnit = '';
        $this->ruleApiCreditsPerUnit = '';
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
        $this->ruleIdsPendingDelete = [];
        $this->deleteLabel = '';
    }

    /**
     * @param  Collection<int, PricingRule>  $group
     * @return array<string, mixed>
     */
    protected function makeGroupedPricingRuleRow(Collection $group): array
    {
        $rulesByChannel = $group
            ->keyBy(fn (PricingRule $rule) => PricingRule::normalizeChannel((string) $rule->pricing_channel));

        $primaryRules = collect(PricingRule::primaryChannels())
            ->mapWithKeys(fn (string $channel) => [$channel => $rulesByChannel->get($channel)])
            ->filter(fn (?PricingRule $rule) => $rule instanceof PricingRule);

        $legacyAllRule = $rulesByChannel->get(PricingRule::CHANNEL_ALL);
        $seedRule = $primaryRules->first() ?? ($legacyAllRule instanceof PricingRule ? $legacyAllRule : $group->first());

        if (! $seedRule instanceof PricingRule) {
            return [];
        }

        $configuredPrimaryCount = $primaryRules->count();
        $activePrimaryCount = $primaryRules->filter(fn (PricingRule $rule) => (bool) $rule->is_active)->count();
        $statusVariant = match (true) {
            $configuredPrimaryCount === 0 => ((bool) ($legacyAllRule?->is_active ?? false) ? 'active' : 'inactive'),
            $activePrimaryCount === 0 => 'inactive',
            $activePrimaryCount === $configuredPrimaryCount => 'active',
            default => 'mixed',
        };

        return [
            'group_key' => $seedRule->groupingSignature(),
            'seed_rule_id' => (int) $seedRule->id,
            'seed_rule' => $seedRule,
            'tool_action' => $seedRule->toolAction,
            'service_plan' => $seedRule->servicePlan,
            'rule_type' => (string) $seedRule->rule_type,
            'metric_code' => (string) $seedRule->metric_code,
            'unit_size' => $seedRule->unit_size,
            'rounding_mode' => (string) $seedRule->rounding_mode,
            'rounding_step' => $seedRule->rounding_step,
            'minimum_credits' => (int) $seedRule->minimum_credits,
            'priority' => (int) $seedRule->priority,
            'conditions' => is_array($seedRule->conditions) ? $seedRule->conditions : [],
            'config' => is_array($seedRule->config) ? $seedRule->config : [],
            'starts_at' => $seedRule->starts_at,
            'ends_at' => $seedRule->ends_at,
            'app_rule' => $rulesByChannel->get(PricingRule::CHANNEL_APP),
            'mobile_rule' => $rulesByChannel->get(PricingRule::CHANNEL_MOBILE),
            'api_rule' => $rulesByChannel->get(PricingRule::CHANNEL_API),
            'legacy_all_rule' => $legacyAllRule instanceof PricingRule ? $legacyAllRule : null,
            'status_variant' => $statusVariant,
            'status_label' => match ($statusVariant) {
                'active' => __('Active'),
                'inactive' => __('Inactive'),
                default => __('Mixed'),
            },
            'status_sort' => match ($statusVariant) {
                'active' => 2,
                'mixed' => 1,
                default => 0,
            },
            'has_active_primary' => $activePrimaryCount > 0,
            'configured_primary_count' => $configuredPrimaryCount,
            'primary_rule_ids' => $primaryRules->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    public function statusBadgeClasses(bool $state): string
    {
        return $state ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
    }

    public function groupedStatusBadgeClasses(string $statusVariant): string
    {
        return match ($statusVariant) {
            'active' => 'bg-success-subtle text-success',
            'inactive' => 'bg-warning-subtle text-warning',
            default => 'bg-info-subtle text-info',
        };
    }

    public function groupedStatusChannelBadges(array $group): array
    {
        $badges = [];

        foreach ([
            PricingRule::CHANNEL_APP => __('App'),
            PricingRule::CHANNEL_MOBILE => __('Mobile'),
            PricingRule::CHANNEL_API => __('API'),
        ] as $channel => $label) {
            $rule = $group[$channel.'_rule'] ?? null;

            if (! $rule instanceof PricingRule) {
                continue;
            }

            $badges[] = [
                'label' => $label.' '.($rule->is_active ? __('active') : __('inactive')),
                'classes' => $rule->is_active ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning',
            ];
        }

        return $badges;
    }

    public function formatDecimal($value, int $precision = 2): string
    {
        $number = (float) ($value ?? 0);
        $formatted = number_format($number, $precision, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function channelBadgeClasses(string $channel): string
    {
        return match (PricingRule::normalizeChannel($channel, PricingRule::CHANNEL_APP)) {
            PricingRule::CHANNEL_API => 'bg-info-subtle text-info',
            PricingRule::CHANNEL_MOBILE => 'bg-primary-subtle text-primary',
            PricingRule::CHANNEL_ALL => 'bg-success-subtle text-success',
            default => 'bg-secondary-subtle text-secondary',
        };
    }

    public function pricingGroupChannelValue(array $group, string $channel): array
    {
        $rule = $group[$channel.'_rule'] ?? null;
        $legacyRule = $group['legacy_all_rule'] ?? null;

        if ($rule instanceof PricingRule) {
            return [
                'label' => __(':value credits', ['value' => $this->formatDecimal($rule->credits_per_unit, 4)]),
                'variant' => 'configured',
            ];
        }

        if ($legacyRule instanceof PricingRule) {
            return [
                'label' => __('Fallback: :value credits', ['value' => $this->formatDecimal($legacyRule->credits_per_unit, 4)]),
                'variant' => 'fallback',
            ];
        }

        return [
            'label' => __('Not configured'),
            'variant' => 'missing',
        ];
    }

    protected function groupMatchesStatusFilter(array $group): bool
    {
        return match ($this->statusFilter) {
            'active' => $group['status_variant'] === 'active',
            'inactive' => $group['status_variant'] === 'inactive',
            default => true,
        };
    }

    protected function groupMatchesChannelFilter(array $group): bool
    {
        if ($this->channelFilter === 'all') {
            return true;
        }

        return ($group[$this->channelFilter.'_rule'] ?? null) instanceof PricingRule
            || ($group['legacy_all_rule'] ?? null) instanceof PricingRule;
    }

    protected function decodeJsonField(?string $value, string $field): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => __('Please enter a valid JSON object.'),
            ]);
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    protected function editableChannels(): array
    {
        return PricingRule::primaryChannels();
    }

    /**
     * @return array<string, mixed>
     */
    protected function ruleMatchAttributes(): array
    {
        return [
            'tool_action_id' => (int) $this->ruleToolActionId,
            'service_plan_id' => $this->ruleServicePlanId ?: null,
            'rule_type' => $this->ruleType,
            'priority' => $this->rulePriority,
            'metric_code' => trim($this->ruleMetricCode),
            'unit_size' => (float) $this->ruleUnitSize,
            'rounding_mode' => $this->ruleRoundingMode,
            'rounding_step' => (float) $this->ruleRoundingStep,
            'minimum_credits' => $this->ruleMinimumCredits,
            'is_active' => $this->ruleStatus === 'active',
            'starts_at' => $this->ruleStartsAt !== '' ? $this->ruleStartsAt : null,
            'ends_at' => $this->ruleEndsAt !== '' ? $this->ruleEndsAt : null,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    protected function channelCreditsPayload(): array
    {
        if ($this->ruleType === 'free') {
            return [
                PricingRule::CHANNEL_APP => 0,
                PricingRule::CHANNEL_MOBILE => 0,
                PricingRule::CHANNEL_API => 0,
            ];
        }

        return [
            PricingRule::CHANNEL_APP => (float) $this->ruleAppCreditsPerUnit,
            PricingRule::CHANNEL_MOBILE => (float) $this->ruleMobileCreditsPerUnit,
            PricingRule::CHANNEL_API => (float) $this->ruleApiCreditsPerUnit,
        ];
    }

    protected function resolveRuleForChannel(string $channel, array $matchAttributes, array $conditions, array $config): PricingRule
    {
        $existingId = (int) ($this->editingRuleChannelIds[$channel] ?? 0);

        if ($existingId > 0) {
            return PricingRule::query()->findOrFail($existingId);
        }

        $query = PricingRule::query()
            ->where('tool_action_id', $matchAttributes['tool_action_id'])
            ->where('pricing_channel', $channel)
            ->where('rule_type', $matchAttributes['rule_type'])
            ->where('priority', $matchAttributes['priority'])
            ->where('metric_code', $matchAttributes['metric_code'])
            ->where('unit_size', $matchAttributes['unit_size'])
            ->where('rounding_mode', $matchAttributes['rounding_mode'])
            ->where('rounding_step', $matchAttributes['rounding_step'])
            ->where('minimum_credits', $matchAttributes['minimum_credits'])
            ->where('is_active', $matchAttributes['is_active']);

        if ($matchAttributes['service_plan_id'] === null) {
            $query->whereNull('service_plan_id');
        } else {
            $query->where('service_plan_id', $matchAttributes['service_plan_id']);
        }

        if ($matchAttributes['starts_at'] === null) {
            $query->whereNull('starts_at');
        } else {
            $query->where('starts_at', $matchAttributes['starts_at']);
        }

        if ($matchAttributes['ends_at'] === null) {
            $query->whereNull('ends_at');
        } else {
            $query->where('ends_at', $matchAttributes['ends_at']);
        }

        $existing = $query
            ->get()
            ->first(fn (PricingRule $rule) => $this->jsonPayloadEquals($rule->conditions, $conditions) && $this->jsonPayloadEquals($rule->config, $config));

        return $existing ?? new PricingRule;
    }

    /**
     * @return Collection<int, PricingRule>
     */
    protected function resolveRuleGroup(PricingRule $seed): Collection
    {
        $query = PricingRule::query()
            ->where('tool_action_id', $seed->tool_action_id)
            ->whereIn('pricing_channel', PricingRule::channels())
            ->where('rule_type', $seed->rule_type)
            ->where('priority', $seed->priority)
            ->where('metric_code', $seed->metric_code)
            ->where('unit_size', $seed->unit_size)
            ->where('rounding_mode', $seed->rounding_mode)
            ->where('rounding_step', $seed->rounding_step)
            ->where('minimum_credits', $seed->minimum_credits);

        if ($seed->service_plan_id === null) {
            $query->whereNull('service_plan_id');
        } else {
            $query->where('service_plan_id', $seed->service_plan_id);
        }

        if ($seed->starts_at === null) {
            $query->whereNull('starts_at');
        } else {
            $query->where('starts_at', $seed->starts_at);
        }

        if ($seed->ends_at === null) {
            $query->whereNull('ends_at');
        } else {
            $query->where('ends_at', $seed->ends_at);
        }

        $group = $query
            ->get()
            ->filter(fn (PricingRule $rule) => $this->jsonPayloadEquals($rule->conditions, $seed->conditions) && $this->jsonPayloadEquals($rule->config, $seed->config))
            ->values();

        if (! $group->contains(fn (PricingRule $rule) => (int) $rule->id === (int) $seed->id)) {
            $group->prepend($seed);
        }

        return $group->unique(fn (PricingRule $rule) => (int) $rule->id)->values();
    }

    protected function jsonPayloadEquals(mixed $left, mixed $right): bool
    {
        return $this->normalizeJsonPayload($left) === $this->normalizeJsonPayload($right);
    }

    protected function normalizeJsonPayload(mixed $payload): string
    {
        return json_encode($this->normalizeJsonArray($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    protected function formatCreditsInput(mixed $value): string
    {
        return $value !== null ? $this->formatDecimal($value, 4) : '';
    }

    protected function normalizeJsonArray(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $normalized = $payload;
        ksort($normalized);

        foreach ($normalized as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeJsonArray($value);
            }
        }

        return $normalized;
    }

    protected function paginateGroupedPricingRules(Collection $groups): LengthAwarePaginator
    {
        $page = $this->getPage();
        $items = $groups->forPage($page, $this->perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $groups->count(),
            $this->perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }
}
