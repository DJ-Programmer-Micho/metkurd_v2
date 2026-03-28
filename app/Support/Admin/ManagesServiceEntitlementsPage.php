<?php

namespace App\Support\Admin;

use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesServiceEntitlementsPage
{
    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'action', keep: true)]
    public string $actionFilter = 'all';

    #[Url(as: 'entitlement', keep: true)]
    public string $entitlementFilter = 'all';

    public int $perPage = 10;

    public ?int $editingEntitlementId = null;
    public ?int $entitlementServicePlanId = null;
    public ?int $entitlementToolActionId = null;
    public string $entitlementAllowed = 'allowed';
    public string $entitlementLimitsJson = '';

    public ?int $entitlementIdPendingDelete = null;
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

    public function updatedEntitlementFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->actionFilter = 'all';
        $this->entitlementFilter = 'all';
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
        $allowedCount = (int) PlanEntitlement::query()->where('allowed', true)->count();
        $totalCount = (int) PlanEntitlement::query()->count();

        return [
            'entitlements' => $totalCount,
            'allowed_entitlements' => $allowedCount,
            'blocked_entitlements' => max(0, $totalCount - $allowedCount),
            'plans_with_entitlements' => (int) PlanEntitlement::query()->distinct('service_plan_id')->count('service_plan_id'),
        ];
    }

    protected function entitlementsBaseQuery(): Builder
    {
        $query = PlanEntitlement::query()
            ->with(['servicePlan:id,code,name', 'toolAction.tool:id,code,name']);

        if ($this->planFilter !== 'all') {
            $query->where('service_plan_id', (int) $this->planFilter);
        }

        if ($this->actionFilter !== 'all') {
            $query->where('tool_action_id', (int) $this->actionFilter);
        }

        if ($this->entitlementFilter === 'allowed') {
            $query->where('allowed', true);
        } elseif ($this->entitlementFilter === 'blocked') {
            $query->where('allowed', false);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->whereHas('toolAction', function (Builder $actionQuery) use ($search) {
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
            ->orderByDesc('allowed')
            ->orderBy('service_plan_id')
            ->orderBy('tool_action_id');
    }

    #[Computed]
    public function entitlements()
    {
        return $this->entitlementsBaseQuery()->paginate($this->perPage);
    }

    public function openEntitlementCreateModal(): void
    {
        $this->resetEntitlementForm();
        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementModal');
    }

    public function openEntitlementEditModal(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::query()->findOrFail($entitlementId);

        $this->resetValidation();
        $this->editingEntitlementId = $entitlement->id;
        $this->entitlementServicePlanId = (int) $entitlement->service_plan_id;
        $this->entitlementToolActionId = (int) $entitlement->tool_action_id;
        $this->entitlementAllowed = $entitlement->allowed ? 'allowed' : 'blocked';
        $this->entitlementLimitsJson = $entitlement->limits ? (string) json_encode($entitlement->limits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';

        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementModal');
    }

    public function saveEntitlement(): void
    {
        $this->validate([
            'entitlementServicePlanId' => ['required', 'integer', Rule::exists('service_plans', 'id')],
            'entitlementToolActionId' => ['required', 'integer', Rule::exists('tool_actions', 'id')],
            'entitlementAllowed' => ['required', Rule::in(['allowed', 'blocked'])],
            'entitlementLimitsJson' => ['nullable', 'string'],
        ]);

        $duplicate = PlanEntitlement::query()
            ->where('service_plan_id', $this->entitlementServicePlanId)
            ->where('tool_action_id', $this->entitlementToolActionId)
            ->when($this->editingEntitlementId, fn ($query) => $query->whereKeyNot($this->editingEntitlementId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'entitlementServicePlanId' => __('This plan already has an entitlement row for the selected action.'),
            ]);
        }

        $limits = $this->decodeJsonField($this->entitlementLimitsJson, 'entitlementLimitsJson');

        $entitlement = $this->editingEntitlementId
            ? PlanEntitlement::query()->findOrFail($this->editingEntitlementId)
            : new PlanEntitlement();

        $entitlement->fill([
            'service_plan_id' => $this->entitlementServicePlanId,
            'tool_action_id' => $this->entitlementToolActionId,
            'allowed' => $this->entitlementAllowed === 'allowed',
            'limits' => $limits ?: null,
        ]);

        $entitlement->save();

        $this->dispatch('alert', type: 'success', message: $this->editingEntitlementId ? __('Plan entitlement updated successfully.') : __('Plan entitlement created successfully.'));
        $this->dispatch('services-entitlements:modal-hide', id: 'serviceEntitlementModal');
        $this->resetEntitlementForm();
    }

    public function toggleEntitlementAllowed(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::query()->findOrFail($entitlementId);
        $entitlement->update(['allowed' => !$entitlement->allowed]);

        $this->dispatch('alert', type: 'success', message: $entitlement->allowed ? __('Entitlement marked as allowed.') : __('Entitlement blocked.'));
    }

    public function confirmEntitlementDelete(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::query()
            ->with(['servicePlan:id,name', 'toolAction:id,full_code'])
            ->findOrFail($entitlementId);

        $this->entitlementIdPendingDelete = $entitlement->id;
        $this->deleteLabel = ($entitlement->servicePlan?->name ?? 'Plan') . ' / ' . ($entitlement->toolAction?->full_code ?? 'Action');

        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementDeleteModal');
    }

    public function performDelete(): void
    {
        if ($this->entitlementIdPendingDelete) {
            PlanEntitlement::query()->findOrFail($this->entitlementIdPendingDelete)->delete();
            $this->dispatch('alert', type: 'success', message: __('Plan entitlement deleted successfully.'));
        }

        $this->dispatch('services-entitlements:modal-hide', id: 'serviceEntitlementDeleteModal');
        $this->resetDeleteState();
    }

    public function resetEntitlementForm(): void
    {
        $this->resetValidation();
        $this->editingEntitlementId = null;
        $this->entitlementServicePlanId = null;
        $this->entitlementToolActionId = null;
        $this->entitlementAllowed = 'allowed';
        $this->entitlementLimitsJson = '';
    }

    public function resetDeleteState(): void
    {
        $this->entitlementIdPendingDelete = null;
        $this->deleteLabel = '';
    }

    public function statusBadgeClasses(bool $state): string
    {
        return $state ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning';
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
                $field => __('Please enter a valid JSON object.'),
            ]);
        }

        return $decoded;
    }
}
