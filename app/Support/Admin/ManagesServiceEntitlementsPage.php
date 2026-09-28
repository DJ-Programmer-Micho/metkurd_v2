<?php

namespace App\Support\Admin;

use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Services\Admin\AdminEntitlementScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesServiceEntitlementsPage
{
    use SecureAdminComponent;
    use ShowsV2Catalog;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'action', keep: true)]
    public string $actionFilter = 'all';

    #[Url(as: 'entitlement', keep: true)]
    public string $entitlementFilter = 'all';

    #[Url(as: 'channel', keep: true)]
    public string $channelFilter = 'all';

    public int $perPage = 10;

    public ?int $editingEntitlementId = null;

    public ?int $entitlementServicePlanId = null;

    public ?int $entitlementToolActionId = null;

    public string $entitlementChannel = PlanEntitlement::CHANNEL_APP;

    public string $entitlementAllowed = 'allowed';

    public string $entitlementLimitsJson = '';

    public string $entitlementMaxCharacters = '';

    #[Url(as: 'matrix')]
    public string $matrixChannel = 'app';

    #[Computed]
    public function entitlementMatrix(): array
    {
        return app(AdminServiceWorkspace::class)->matrix($this->matrixChannel, $this->planFilter === 'all' ? null : (int) $this->planFilter);
    }

    public function openMatrixEntitlement(int $planId, int $actionId, string $channel): void
    {
        AdminAccess::authorize('admin.pricing');
        $this->resetEntitlementForm();
        ServicePlan::findOrFail($planId);
        ToolAction::findOrFail($actionId);
        $channel = in_array($channel, ['app', 'api'], true) ? $channel : 'app';
        $existing = PlanEntitlement::where('service_plan_id', $planId)->where('tool_action_id', $actionId)
            ->where('entitlement_channel', $channel)->first();
        if ($existing) {
            $this->openEntitlementEditModal($existing->id);

            return;
        }
        // Create a channel-specific decision; never silently edit a shared "all" row.
        $this->entitlementServicePlanId = $planId;
        $this->entitlementToolActionId = $actionId;
        $this->entitlementChannel = $channel;
        $this->entitlementAllowed = 'blocked';
        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementModal');
    }

    #[Computed]
    public function supportsCharacterLimit(): bool
    {
        return app(AdminServiceLimits::class)->characterField($this->entitlementToolActionId);
    }

    #[Computed]
    public function sharedServiceLimits(): array
    {
        return app(AdminServiceLimits::class)->reference($this->entitlementToolActionId);
    }

    public function updatedEntitlementToolActionId(): void
    {
        $this->entitlementMaxCharacters = '';
        unset($this->supportsCharacterLimit, $this->sharedServiceLimits);
    }

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

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->actionFilter = 'all';
        $this->entitlementFilter = 'all';
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

        if ($this->channelFilter !== 'all') {
            $query->where('entitlement_channel', $this->channelFilter);
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
                    ->orWhere('entitlement_channel', 'like', "%{$search}%")
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
        $this->entitlementChannel = PlanEntitlement::normalizeChannel((string) ($entitlement->entitlement_channel ?? PlanEntitlement::CHANNEL_APP), PlanEntitlement::CHANNEL_APP, true);
        $this->entitlementAllowed = $entitlement->allowed ? 'allowed' : 'blocked';
        $this->entitlementLimitsJson = $entitlement->limits ? (string) json_encode(AdminData::redact($entitlement->limits), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        $this->entitlementMaxCharacters = (string) data_get($entitlement->limits, 'max_chars_per_submit', '');

        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementModal');
    }

    public function saveEntitlement(): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $this->validate([
            'entitlementServicePlanId' => ['required', 'integer', Rule::exists('service_plans', 'id')],
            'entitlementToolActionId' => ['required', 'integer', Rule::exists('tool_actions', 'id')],
            'entitlementChannel' => ['required', Rule::in(PlanEntitlement::channels(true))],
            'entitlementAllowed' => ['required', Rule::in(['allowed', 'blocked'])],
            'entitlementLimitsJson' => ['nullable', 'string'],
        ]);

        $duplicate = PlanEntitlement::query()
            ->where('service_plan_id', $this->entitlementServicePlanId)
            ->where('tool_action_id', $this->entitlementToolActionId)
            ->where('entitlement_channel', $this->entitlementChannel)
            ->when($this->editingEntitlementId, fn ($query) => $query->whereKeyNot($this->editingEntitlementId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'entitlementServicePlanId' => __('This plan already has an entitlement row for the selected action.'),
            ]);
        }

        $limits = $this->decodeJsonField($this->entitlementLimitsJson, 'entitlementLimitsJson');
        if ($this->supportsCharacterLimit) {
            $this->validate(['entitlementMaxCharacters' => 'nullable|integer|min:1|max:1000000']);
            if ($this->entitlementMaxCharacters !== '') {
                $limits['max_chars_per_submit'] = (int) $this->entitlementMaxCharacters;
            } else {
                unset($limits['max_chars_per_submit']);
            }
        }

        app(AdminEntitlementScopes::class)->mutate($this->editingEntitlementId, [
            'service_plan_id' => $this->entitlementServicePlanId,
            'tool_action_id' => $this->entitlementToolActionId,
            'entitlement_channel' => PlanEntitlement::normalizeChannel($this->entitlementChannel, 'app', true),
            'allowed' => $this->entitlementAllowed === 'allowed',
            'limits' => $limits ?: null,
        ]);

        $this->dispatch('alert', type: 'success', message: $this->editingEntitlementId ? __('Plan entitlement updated successfully.') : __('Plan entitlement created successfully.'));
        $this->dispatch('services-entitlements:modal-hide', id: 'serviceEntitlementModal');
        $this->resetEntitlementForm();
    }

    public function toggleEntitlementAllowed(int $entitlementId): void
    {
        $this->authorizeAdminChange('admin.pricing');

        $entitlement = app(AdminEntitlementScopes::class)->mutate($entitlementId, operation: 'toggle');

        $this->dispatch('alert', type: 'success', message: $entitlement->allowed ? __('Entitlement marked as allowed.') : __('Entitlement blocked.'));
    }

    public function confirmEntitlementDelete(int $entitlementId): void
    {
        $entitlement = PlanEntitlement::query()
            ->with(['servicePlan:id,name', 'toolAction:id,full_code'])
            ->findOrFail($entitlementId);

        $this->entitlementIdPendingDelete = $entitlement->id;
        $this->deleteLabel = ($entitlement->servicePlan?->name ?? 'Plan').' / '.($entitlement->toolAction?->full_code ?? 'Action');

        $this->dispatch('services-entitlements:modal-show', id: 'serviceEntitlementDeleteModal');
    }

    public function performDelete(): void
    {
        $this->authorizeAdminChange('admin.pricing');

        if ($this->entitlementIdPendingDelete) {
            app(AdminEntitlementScopes::class)->mutate($this->entitlementIdPendingDelete, operation: 'delete');
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
        $this->entitlementChannel = PlanEntitlement::CHANNEL_APP;
        $this->entitlementAllowed = 'allowed';
        $this->entitlementLimitsJson = '';
        $this->entitlementMaxCharacters = '';
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

    public function channelBadgeClasses(string $channel): string
    {
        return match (PlanEntitlement::normalizeChannel($channel, PlanEntitlement::CHANNEL_APP, true)) {
            PlanEntitlement::CHANNEL_API => 'bg-info-subtle text-info',
            PlanEntitlement::CHANNEL_MOBILE => 'bg-primary-subtle text-primary',
            default => 'bg-secondary-subtle text-secondary',
        };
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
}
