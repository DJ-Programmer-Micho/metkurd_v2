<?php

namespace App\Support\Admin;

use App\Models\MlJob;
use App\Models\PricingRule;
use App\Models\Tool;
use App\Models\ToolAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesServiceToolsPage
{
    use SecureAdminComponent;
    use ShowsV2Catalog;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'category', keep: true)]
    public string $categoryFilter = 'all';

    #[Url(as: 'metric', keep: true)]
    public string $metricFilter = 'all';

    #[Url(as: 'usage', keep: true)]
    public string $usageFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'sort_order';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'asc';

    public int $perPage = 10;

    public array $expandedTools = [];

    public ?int $editingToolId = null;

    public string $toolCode = '';

    public string $toolName = '';

    public string $toolCategory = '';

    public string $toolNotes = '';

    public int $toolSortOrder = 0;

    public string $toolStatus = 'active';

    public string $toolMetaJson = '';

    public ?int $editingActionId = null;

    public ?int $actionToolId = null;

    public string $actionCode = '';

    public string $actionName = '';

    public string $actionMetricCode = '';

    public string $actionNotes = '';

    public string $actionStatus = 'active';

    public string $actionMetaJson = '';

    public ?int $toolIdPendingDelete = null;

    public ?int $actionIdPendingDelete = null;

    public string $deleteTarget = '';

    public string $deleteLabel = '';

    public array $metricOptions = [
        'character' => 'Character',
        'minute' => 'Minute',
        'page' => 'Page',
        'stem_output' => 'Stem Output',
        'render' => 'Render',
        'request' => 'Request',
    ];

    public function mount(): void
    {
        if (! in_array($this->sortColumn, ['sort_order', 'name', 'usage', 'credits'], true)) {
            $this->sortColumn = 'sort_order';
        }

        if (! in_array($this->sortDirection, ['asc', 'desc'], true)) {
            $this->sortDirection = 'asc';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMetricFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUsageFilter(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        if (! in_array($column, ['sort_order', 'name', 'usage', 'credits'], true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = in_array($column, ['usage', 'credits'], true) ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'statusFilter',
            'categoryFilter',
            'metricFilter',
            'usageFilter',
        ]);

        $this->statusFilter = 'all';
        $this->categoryFilter = 'all';
        $this->metricFilter = 'all';
        $this->usageFilter = 'all';
        $this->sortColumn = 'sort_order';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    #[Computed]
    public function categoryOptions(): array
    {
        return Tool::query()
            ->get()
            ->pluck('meta.category')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    #[Computed]
    public function toolOptions()
    {
        return Tool::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function topStats(): array
    {
        $monthUsage = $this->trackedMlJobsQuery()
            ->where('created_at', '>=', now()->subDays(30));

        return [
            'tools' => (int) Tool::query()->count(),
            'actions' => (int) ToolAction::query()->count(),
            'maintenance' => (int) Tool::query()->where('is_active', false)->count(),
            'runs_30d' => (int) (clone $monthUsage)->count(),
            'credits_30d' => (int) ((clone $monthUsage)->sum('credits_charged') ?? 0),
        ];
    }

    protected function toolsBaseQuery(): Builder
    {
        $lastUsedExpression = 'COALESCE(ml_jobs.finished_at, ml_jobs.started_at, ml_jobs.created_at)';

        $usageStats = $this->trackedMlJobsQuery()
            ->leftJoin('tool_actions', 'tool_actions.id', '=', 'ml_jobs.tool_action_id')
            ->leftJoin('tools as action_tools', 'action_tools.code', '=', 'tool_actions.tool_code')
            ->where(function (Builder $jobQuery) {
                $jobQuery
                    ->whereNotNull('ml_jobs.tool_id')
                    ->orWhereNotNull('action_tools.id');
            })
            ->groupBy(DB::raw('COALESCE(ml_jobs.tool_id, action_tools.id)'))
            ->selectRaw('COALESCE(ml_jobs.tool_id, action_tools.id) as tool_id')
            ->selectRaw('COUNT(*) as usage_events_count')
            ->selectRaw('COALESCE(SUM(COALESCE(ml_jobs.credits_charged, 0)), 0) as total_credits_spent')
            ->selectRaw("MAX({$lastUsedExpression}) as last_used_at");

        $actionUsageStats = $this->trackedMlJobsQuery()
            ->whereNotNull('ml_jobs.tool_action_id')
            ->groupBy('ml_jobs.tool_action_id')
            ->selectRaw('ml_jobs.tool_action_id as tool_action_id')
            ->selectRaw('COUNT(*) as usage_events_count')
            ->selectRaw('COALESCE(SUM(COALESCE(ml_jobs.credits_charged, 0)), 0) as usage_events_total_credits')
            ->selectRaw("MAX({$lastUsedExpression}) as last_used_at");

        $pricingStats = PricingRule::query()
            ->join('tool_actions', 'tool_actions.id', '=', 'pricing_rules.tool_action_id')
            ->where('pricing_rules.is_active', true)
            ->groupBy('tool_actions.tool_code')
            ->selectRaw('tool_actions.tool_code as tool_code')
            ->selectRaw('COUNT(*) as active_pricing_rules_count')
            ->selectRaw('MIN(COALESCE(pricing_rules.credits_per_unit, 0)) as min_credit_cost')
            ->selectRaw('MAX(COALESCE(pricing_rules.credits_per_unit, 0)) as max_credit_cost');

        $query = Tool::query()
            ->leftJoinSub($usageStats, 'usage_stats', fn ($join) => $join->on('usage_stats.tool_id', '=', 'tools.id'))
            ->leftJoinSub($pricingStats, 'pricing_stats', fn ($join) => $join->on('pricing_stats.tool_code', '=', 'tools.code'))
            ->select('tools.*')
            ->selectRaw('COALESCE(usage_stats.usage_events_count, 0) as usage_events_count')
            ->selectRaw('COALESCE(usage_stats.total_credits_spent, 0) as total_credits_spent')
            ->selectRaw('usage_stats.last_used_at as last_used_at')
            ->selectRaw('COALESCE(pricing_stats.active_pricing_rules_count, 0) as active_pricing_rules_count')
            ->selectRaw('pricing_stats.min_credit_cost as min_credit_cost')
            ->selectRaw('pricing_stats.max_credit_cost as max_credit_cost')
            ->withCount(['actions', 'activeActions'])
            ->with([
                'actions' => fn ($actionQuery) => $actionQuery
                    ->leftJoinSub($actionUsageStats, 'action_usage_stats', fn ($join) => $join->on('action_usage_stats.tool_action_id', '=', 'tool_actions.id'))
                    ->select('tool_actions.*')
                    ->selectRaw('COALESCE(action_usage_stats.usage_events_count, 0) as usage_events_count')
                    ->selectRaw('COALESCE(action_usage_stats.usage_events_total_credits, 0) as usage_events_total_credits')
                    ->selectRaw('action_usage_stats.last_used_at as last_used_at')
                    ->orderBy('tool_actions.action_code')
                    ->withCount([
                        'pricingRules as active_pricing_rules_count' => fn ($pricingQuery) => $pricingQuery->where('is_active', true),
                    ])
                    ->withMin(
                        ['pricingRules as min_credit_cost' => fn ($pricingQuery) => $pricingQuery->where('is_active', true)],
                        'credits_per_unit'
                    )
                    ->withMax(
                        ['pricingRules as max_credit_cost' => fn ($pricingQuery) => $pricingQuery->where('is_active', true)],
                        'credits_per_unit'
                    ),
            ]);

        if ($this->statusFilter === 'active') {
            $query->where('tools.is_active', true);
        } elseif ($this->statusFilter === 'maintenance') {
            $query->where('tools.is_active', false);
        }

        if ($this->categoryFilter !== 'all') {
            $query->where('tools.meta->category', $this->categoryFilter);
        }

        if ($this->metricFilter !== 'all') {
            $query->whereHas('actions', function (Builder $actionQuery) {
                $actionQuery->where('default_metric_code', $this->metricFilter);
            });
        }

        if ($this->usageFilter === 'idle') {
            $query->whereRaw('COALESCE(usage_stats.usage_events_count, 0) = 0');
        } elseif ($this->usageFilter === 'used') {
            $query->whereRaw('COALESCE(usage_stats.usage_events_count, 0) > 0');
        } elseif ($this->usageFilter === 'busy') {
            $query->whereRaw('COALESCE(usage_stats.usage_events_count, 0) >= 25');
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('tools.code', 'like', "%{$search}%")
                    ->orWhere('tools.name', 'like', "%{$search}%")
                    ->orWhere('tools.meta->category', 'like', "%{$search}%")
                    ->orWhereHas('actions', function (Builder $actionQuery) use ($search) {
                        $actionQuery
                            ->where('action_code', 'like', "%{$search}%")
                            ->orWhere('full_code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $sortColumn = match ($this->sortColumn) {
            'name' => 'tools.name',
            'usage' => 'usage_events_count',
            'credits' => 'total_credits_spent',
            default => 'tools.sort_order',
        };

        $currentCodes = array_unique(array_column(array_column(app(\App\Services\CustomerApi\V2\ApiCatalog::class)->variants(), 'tool'), 'legacy_tool'));
        if ($currentCodes !== []) {
            $query->orderByRaw('CASE WHEN tools.code IN ('.implode(',', array_fill(0, count($currentCodes), '?')).') THEN 0 ELSE 1 END', array_values($currentCodes));
        }

        return $query
            ->orderBy($sortColumn, $this->sortDirection)
            ->orderBy('tools.name');
    }

    #[Computed]
    public function tools()
    {
        return $this->toolsBaseQuery()->paginate($this->perPage);
    }

    public function toggleExpandedTool(int $toolId): void
    {
        if (in_array($toolId, $this->expandedTools, true)) {
            $this->expandedTools = array_values(array_filter(
                $this->expandedTools,
                fn ($id) => (int) $id !== $toolId
            ));

            return;
        }

        $this->expandedTools[] = $toolId;
    }

    public function openToolCreateModal(): void
    {
        $this->resetToolForm();
        $this->dispatch('services-tools:modal-show', id: 'servicesToolModal');
    }

    public function openToolEditModal(int $toolId): void
    {
        $tool = Tool::query()->findOrFail($toolId);
        $meta = is_array($tool->meta) ? $tool->meta : [];

        $this->resetValidation();
        $this->editingToolId = $tool->id;
        $this->toolCode = (string) $tool->code;
        $this->toolName = (string) $tool->name;
        $this->toolCategory = (string) data_get($meta, 'category', '');
        $this->toolNotes = (string) data_get($meta, 'notes', '');
        $this->toolSortOrder = (int) $tool->sort_order;
        $this->toolStatus = $tool->is_active ? 'active' : 'maintenance';
        $this->toolMetaJson = $this->prettyJson($this->stripKnownMetaKeys($meta, ['category', 'notes']));

        $this->dispatch('services-tools:modal-show', id: 'servicesToolModal');
    }

    public function saveTool(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $tool = $this->editingToolId
            ? Tool::query()->findOrFail($this->editingToolId)
            : new Tool;

        $rules = [
            'toolName' => ['required', 'string', 'min:2', 'max:120'],
            'toolCategory' => ['nullable', 'string', 'max:60'],
            'toolNotes' => ['nullable', 'string', 'max:500'],
            'toolSortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
            'toolStatus' => ['required', Rule::in(['active', 'maintenance'])],
            'toolMetaJson' => ['nullable', 'string'],
        ];

        if (! $this->editingToolId) {
            $rules['toolCode'] = ['required', 'string', 'max:60', 'regex:/^[a-z0-9_][a-z0-9_-]*$/', Rule::unique('tools', 'code')];
        }

        $this->validate($rules, [
            'toolCode.regex' => __('admin_p1.tool_code'),
        ]);

        $meta = $this->decodeJsonField($this->toolMetaJson, 'toolMetaJson');
        $meta['category'] = $this->emptyToNull($this->toolCategory);
        $meta['notes'] = $this->emptyToNull($this->toolNotes);
        $meta = $this->cleanArray($meta);

        $tool->fill([
            'code' => $this->editingToolId ? $tool->code : trim($this->toolCode),
            'name' => trim($this->toolName),
            'is_active' => $this->toolStatus === 'active',
            'sort_order' => (int) $this->toolSortOrder,
            'meta' => $meta ?: null,
        ]);

        $tool->save();

        $this->dispatch('alert', type: 'success', message: $this->editingToolId ? __('Tool updated successfully.') : __('Tool created successfully.'));
        $this->dispatch('services-tools:modal-hide', id: 'servicesToolModal');
        $this->resetToolForm();
    }

    public function toggleToolStatus(int $toolId): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $tool = Tool::query()->findOrFail($toolId);
        $tool->update(['is_active' => ! $tool->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $tool->is_active ? __('Tool moved back to active.') : __('Tool switched to maintenance mode.')
        );
    }

    public function resetToolForm(): void
    {
        $this->resetValidation();
        $this->editingToolId = null;
        $this->toolCode = '';
        $this->toolName = '';
        $this->toolCategory = '';
        $this->toolNotes = '';
        $this->toolSortOrder = 0;
        $this->toolStatus = 'active';
        $this->toolMetaJson = '';
    }

    public function openActionCreateModal(?int $toolId = null): void
    {
        $this->resetActionForm();
        $this->actionToolId = $toolId;
        $this->dispatch('services-tools:modal-show', id: 'servicesToolActionModal');
    }

    public function openActionEditModal(int $actionId): void
    {
        $action = ToolAction::query()->findOrFail($actionId);
        $meta = is_array($action->meta) ? $action->meta : [];

        $toolId = Tool::query()
            ->where('code', $action->tool_code)
            ->value('id');

        $this->resetValidation();
        $this->editingActionId = $action->id;
        $this->actionToolId = $toolId ? (int) $toolId : null;
        $this->actionCode = (string) $action->action_code;
        $this->actionName = (string) $action->name;
        $this->actionMetricCode = (string) $action->default_metric_code;
        $this->actionNotes = (string) data_get($meta, 'notes', '');
        $this->actionStatus = $action->is_active ? 'active' : 'maintenance';
        $this->actionMetaJson = $this->prettyJson($this->stripKnownMetaKeys($meta, ['notes']));

        $this->dispatch('services-tools:modal-show', id: 'servicesToolActionModal');
    }

    public function saveAction(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $rules = [
            'actionToolId' => ['required', 'integer', Rule::exists('tools', 'id')],
            'actionName' => ['required', 'string', 'min:2', 'max:160'],
            'actionMetricCode' => ['required', 'string', 'max:50'],
            'actionNotes' => ['nullable', 'string', 'max:500'],
            'actionStatus' => ['required', Rule::in(['active', 'maintenance'])],
            'actionMetaJson' => ['nullable', 'string'],
        ];

        if (! $this->editingActionId) {
            $rules['actionCode'] = ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'];
        }

        $this->validate($rules, [
            'actionCode.regex' => __('Action code must use lowercase letters, numbers, and underscores only.'),
        ]);

        $tool = Tool::query()->findOrFail((int) $this->actionToolId);
        $action = $this->editingActionId
            ? ToolAction::query()->findOrFail($this->editingActionId)
            : new ToolAction;

        $actionCode = $this->editingActionId ? $action->action_code : trim($this->actionCode);
        $fullCode = "{$tool->code}.{$actionCode}";

        $duplicate = ToolAction::query()
            ->where('full_code', $fullCode)
            ->when($this->editingActionId, fn ($query) => $query->whereKeyNot($this->editingActionId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'actionCode' => __('This tool/action code combination already exists.'),
            ]);
        }

        $meta = $this->decodeJsonField($this->actionMetaJson, 'actionMetaJson');
        $meta['notes'] = $this->emptyToNull($this->actionNotes);
        $meta = $this->cleanArray($meta);

        $action->fill([
            'tool_code' => $this->editingActionId ? $action->tool_code : $tool->code,
            'action_code' => $actionCode,
            'full_code' => $this->editingActionId ? $action->full_code : $fullCode,
            'name' => trim($this->actionName),
            'default_metric_code' => trim($this->actionMetricCode),
            'is_active' => $this->actionStatus === 'active',
            'meta' => $meta ?: null,
        ]);

        $action->save();

        $this->dispatch('alert', type: 'success', message: $this->editingActionId ? __('Tool action updated successfully.') : __('Tool action created successfully.'));
        $this->dispatch('services-tools:modal-hide', id: 'servicesToolActionModal');
        $this->resetActionForm();
    }

    public function toggleActionStatus(int $actionId): void
    {
        $this->authorizeAdminChange('admin.catalog');

        $action = ToolAction::query()->findOrFail($actionId);
        $action->update(['is_active' => ! $action->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $action->is_active ? __('Action moved back to active.') : __('Action switched to maintenance mode.')
        );
    }

    public function confirmToolDelete(int $toolId): void
    {
        $tool = Tool::query()->withCount('actions')->findOrFail($toolId);

        $this->resetValidation();
        $this->toolIdPendingDelete = $tool->id;
        $this->actionIdPendingDelete = null;
        $this->deleteTarget = 'tool';
        $this->deleteLabel = $tool->name;

        if ($tool->actions_count > 0) {
            $this->dispatch('alert', type: 'warning', message: __('Remove or reassign all actions before deleting this tool.'));

            return;
        }

        $this->dispatch('services-tools:modal-show', id: 'servicesToolDeleteModal');
    }

    public function confirmActionDelete(int $actionId): void
    {
        $action = ToolAction::query()
            ->withCount([
                'mlJobs as usage_events_count' => fn ($jobQuery) => $jobQuery->where('status', '!=', 'deleted'),
            ])
            ->findOrFail($actionId);

        if ($action->usage_events_count > 0) {
            $this->dispatch('alert', type: 'warning', message: __('This action has usage history and cannot be deleted.'));

            return;
        }

        $this->resetValidation();
        $this->actionIdPendingDelete = $action->id;
        $this->toolIdPendingDelete = null;
        $this->deleteTarget = 'action';
        $this->deleteLabel = $action->name;
        $this->dispatch('services-tools:modal-show', id: 'servicesToolDeleteModal');
    }

    public function performDelete(): void
    {
        $this->authorizeAdminChange('admin.catalog');

        if ($this->deleteTarget === 'tool' && $this->toolIdPendingDelete) {
            app(\App\Services\Admin\AdminCatalogDeletion::class)->delete(Tool::query()->findOrFail($this->toolIdPendingDelete));
            $this->dispatch('alert', type: 'success', message: __('Tool deleted successfully.'));
        }

        if ($this->deleteTarget === 'action' && $this->actionIdPendingDelete) {
            DB::transaction(function () {
                app(\App\Services\Admin\AdminCatalogDeletion::class)->delete(ToolAction::query()->findOrFail($this->actionIdPendingDelete));
            });

            $this->dispatch('alert', type: 'success', message: __('Tool action deleted successfully.'));
        }

        $this->dispatch('services-tools:modal-hide', id: 'servicesToolDeleteModal');
        $this->resetDeleteState();
    }

    public function resetActionForm(): void
    {
        $this->resetValidation();
        $this->editingActionId = null;
        $this->actionToolId = null;
        $this->actionCode = '';
        $this->actionName = '';
        $this->actionMetricCode = '';
        $this->actionNotes = '';
        $this->actionStatus = 'active';
        $this->actionMetaJson = '';
    }

    public function resetDeleteState(): void
    {
        $this->toolIdPendingDelete = null;
        $this->actionIdPendingDelete = null;
        $this->deleteTarget = '';
        $this->deleteLabel = '';
    }

    public function metricLabel(?string $metricCode): string
    {
        if (! $metricCode) {
            return __('Not set');
        }

        return __($this->metricOptions[$metricCode] ?? ucfirst(str_replace('_', ' ', $metricCode)));
    }

    public function statusBadgeClasses(bool $isActive): string
    {
        return $isActive
            ? 'bg-success-subtle text-success'
            : 'bg-warning-subtle text-warning';
    }

    public function statusLabel(bool $isActive): string
    {
        return $isActive ? __('Active') : __('Maintenance');
    }

    public function formatDecimal($value, int $precision = 2): string
    {
        $number = (float) ($value ?? 0);
        $formatted = number_format($number, $precision, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function formatPricingRange($minValue, $maxValue, int $ruleCount): string
    {
        if ($ruleCount <= 0 || $minValue === null || $maxValue === null) {
            return __('No active pricing rule');
        }

        $min = $this->formatDecimal($minValue, 4);
        $max = $this->formatDecimal($maxValue, 4);

        return $min === $max
            ? __(':value credits', ['value' => $min])
            : __(':min - :max credits', ['min' => $min, 'max' => $max]);
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

    protected function cleanArray(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = $this->cleanArray($item);
            }

            if ($item === null || $item === '' || $item === []) {
                continue;
            }

            $clean[$key] = $item;
        }

        return $clean;
    }

    protected function stripKnownMetaKeys(array $meta, array $keys): array
    {
        foreach ($keys as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }

    protected function prettyJson(array $value): string
    {
        if ($value === []) {
            return '';
        }

        return (string) json_encode(\App\Support\Admin\AdminData::redact($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function emptyToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function trackedMlJobsQuery(): Builder
    {
        return MlJob::query()->where('status', '!=', 'deleted');
    }
}
