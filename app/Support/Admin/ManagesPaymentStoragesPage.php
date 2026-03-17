<?php

namespace App\Support\Admin;

use App\Models\CustomerStorageSubscription;
use App\Models\StoragePlan;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesPaymentStoragesPage
{
    use InteractsWithPaymentAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'sort', keep: true)]
    public string $sortColumn = 'sort_order';

    #[Url(as: 'dir', keep: true)]
    public string $sortDirection = 'asc';

    public int $perPage = 10;

    public ?int $editingStorageId = null;

    public string $code = '';
    public string $name = '';
    public $quotaMb = '';
    public $priceUsd = '';
    public bool $isActive = true;
    public $sortOrder = 0;

    public ?int $deleteStorageId = null;
    public string $deleteStorageLabel = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->sortColumn = 'sort_order';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['sort_order', 'name', 'quota_mb', 'price_usd', 'active_subscribers', 'estimated_revenue', 'historical_assignments'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['quota_mb', 'price_usd', 'active_subscribers', 'estimated_revenue', 'historical_assignments'], true)
            ? 'desc'
            : 'asc';
    }

    protected function storageFormRules(): array
    {
        return [
            'code' => 'required|string|max:40|alpha_dash|unique:storage_plans,code,' . ($this->editingStorageId ?? 'NULL') . ',id',
            'name' => 'required|string|max:80',
            'quotaMb' => 'required|integer|min:1',
            'priceUsd' => 'required|numeric|min:0',
            'sortOrder' => 'nullable|integer|min:0|max:65535',
        ];
    }

    #[Computed]
    public function topStats(): array
    {
        $activeSubscribers = (int) CustomerStorageSubscription::query()
            ->where('status', 'active')
            ->count();

        $estimatedRevenue = (float) $this->storageBaseQuery()
            ->get()
            ->sum(fn ($plan) => (float) ($plan->estimated_revenue ?? 0));

        return [
            'plans' => (int) StoragePlan::query()->count(),
            'active_plans' => (int) StoragePlan::query()->where('is_active', true)->count(),
            'active_subscribers' => $activeSubscribers,
            'estimated_revenue' => $estimatedRevenue,
        ];
    }

    #[Computed]
    public function maxQuotaMb(): int
    {
        return (int) ($this->storageBaseQuery()->max('storage_plans.quota_mb') ?? 0);
    }

    protected function storageBaseQuery(): Builder
    {
        $activeSubscribers = CustomerStorageSubscription::query()
            ->where('status', 'active')
            ->groupBy('storage_plan_id')
            ->selectRaw('storage_plan_id')
            ->selectRaw('COUNT(*) as active_subscribers');

        $historicalAssignments = CustomerStorageSubscription::query()
            ->groupBy('storage_plan_id')
            ->selectRaw('storage_plan_id')
            ->selectRaw('COUNT(*) as historical_assignments')
            ->selectRaw('MAX(created_at) as last_assigned_at');

        $query = StoragePlan::query()
            ->leftJoinSub($activeSubscribers, 'storage_active_subscribers', fn ($join) => $join->on('storage_active_subscribers.storage_plan_id', '=', 'storage_plans.id'))
            ->leftJoinSub($historicalAssignments, 'storage_assignments', fn ($join) => $join->on('storage_assignments.storage_plan_id', '=', 'storage_plans.id'))
            ->select('storage_plans.*')
            ->selectRaw('COALESCE(storage_active_subscribers.active_subscribers, 0) as active_subscribers')
            ->selectRaw('COALESCE(storage_assignments.historical_assignments, 0) as historical_assignments')
            ->selectRaw('storage_assignments.last_assigned_at as last_assigned_at')
            ->selectRaw('COALESCE(storage_plans.price_usd, 0) * COALESCE(storage_active_subscribers.active_subscribers, 0) as estimated_revenue');

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('storage_plans.name', 'like', "%{$search}%")
                    ->orWhere('storage_plans.code', 'like', "%{$search}%");
            });
        }

        if ($this->statusFilter === 'active') {
            $query->where('storage_plans.is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('storage_plans.is_active', false);
        }

        $column = match ($this->sortColumn) {
            'name' => 'storage_plans.name',
            'quota_mb' => 'storage_plans.quota_mb',
            'price_usd' => 'storage_plans.price_usd',
            'active_subscribers' => 'active_subscribers',
            'estimated_revenue' => 'estimated_revenue',
            'historical_assignments' => 'historical_assignments',
            default => 'storage_plans.sort_order',
        };

        return $query
            ->orderBy($column, $this->sortDirection)
            ->orderBy('storage_plans.name');
    }

    #[Computed]
    public function storagePlans()
    {
        return $this->storageBaseQuery()->paginate($this->perPage);
    }

    public function openCreateStorageModal(): void
    {
        $this->resetStorageForm();
        $this->dispatch('payments-storage:modal-show', id: 'paymentStorageModal');
    }

    public function openEditStorageModal(int $storageId): void
    {
        $plan = StoragePlan::query()->findOrFail($storageId);

        $this->editingStorageId = $plan->id;
        $this->code = (string) $plan->code;
        $this->name = (string) $plan->name;
        $this->quotaMb = (int) ($plan->quota_mb ?? 0);
        $this->priceUsd = (string) ((float) ($plan->price_usd ?? 0));
        $this->isActive = (bool) $plan->is_active;
        $this->sortOrder = (int) ($plan->sort_order ?? 0);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-storage:modal-show', id: 'paymentStorageModal');
    }

    public function saveStoragePlan(): void
    {
        $validated = $this->validate($this->storageFormRules());

        $plan = $this->editingStorageId
            ? StoragePlan::query()->findOrFail($this->editingStorageId)
            : new StoragePlan();
        $plan->fill([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'quota_mb' => (int) $validated['quotaMb'],
            'price_usd' => (float) $validated['priceUsd'],
            'is_active' => (bool) $this->isActive,
            'sort_order' => (int) ($validated['sortOrder'] ?? 0),
        ]);
        $plan->save();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingStorageId ? 'Storage plan updated successfully.' : 'Storage plan created successfully.'
        );

        $this->resetStorageForm();
        $this->dispatch('payments-storage:modal-hide', id: 'paymentStorageModal');
    }

    public function toggleStorageStatus(int $storageId): void
    {
        $plan = StoragePlan::query()->findOrFail($storageId);
        $plan->update(['is_active' => !$plan->is_active]);

        $this->dispatch(
            'alert',
            type: 'success',
            message: $plan->is_active ? 'Storage plan activated successfully.' : 'Storage plan deactivated successfully.'
        );
    }

    public function confirmDeleteStorage(int $storageId): void
    {
        $plan = StoragePlan::query()->findOrFail($storageId);

        $this->deleteStorageId = $plan->id;
        $this->deleteStorageLabel = $plan->name;

        $this->dispatch('payments-storage:modal-show', id: 'paymentStorageDeleteModal');
    }

    public function deleteStoragePlan(): void
    {
        $plan = StoragePlan::query()->findOrFail($this->deleteStorageId);

        if ($plan->subscriptions()->exists()) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: 'This storage plan has customer subscriptions. Deactivate it instead of deleting.'
            );

            return;
        }

        $plan->delete();
        $this->resetDeleteState();
        $this->dispatch('payments-storage:modal-hide', id: 'paymentStorageDeleteModal');
        $this->dispatch('alert', type: 'success', message: 'Storage plan deleted successfully.');
    }

    public function resetStorageForm(): void
    {
        $this->editingStorageId = null;
        $this->code = '';
        $this->name = '';
        $this->quotaMb = '';
        $this->priceUsd = '';
        $this->isActive = true;
        $this->sortOrder = 0;
        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function resetDeleteState(): void
    {
        $this->deleteStorageId = null;
        $this->deleteStorageLabel = '';
    }
}
