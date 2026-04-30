<?php

namespace App\Support\Admin;

use App\Domain\Payments\Enums\PaymentMode;
use App\Models\CustomerStorageSubscription;
use App\Models\StoragePlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
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
    public string $paymentMode = 'recurring';
    public array $billingIntervals = ['monthly'];
    public $quotaMb = '';
    public $priceIqd = '';
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
        $allowed = ['sort_order', 'name', 'quota_mb', 'price_iqd', 'active_subscribers', 'estimated_revenue', 'historical_assignments'];

        if (!in_array($column, $allowed, true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, ['quota_mb', 'price_iqd', 'active_subscribers', 'estimated_revenue', 'historical_assignments'], true)
            ? 'desc'
            : 'asc';
    }

    protected function storageFormRules(): array
    {
        return [
            'code' => 'required|string|max:40|alpha_dash|unique:storage_plans,code,' . ($this->editingStorageId ?? 'NULL') . ',id',
            'name' => 'required|string|max:80',
            'paymentMode' => 'required|string|in:one_time,recurring',
            'billingIntervals' => 'required|array|min:1',
            'billingIntervals.*' => 'required|string|in:monthly,yearly',
            'quotaMb' => 'required|integer|min:1',
            'priceIqd' => 'required|integer|min:0',
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
        $priceIqdSql = $this->effectiveCatalogAmountSql('storage_plans', 'price_iqd', 'price_usd');
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
            ->selectRaw("{$priceIqdSql} as price_iqd_effective")
            ->selectRaw('COALESCE(storage_active_subscribers.active_subscribers, 0) as active_subscribers')
            ->selectRaw('COALESCE(storage_assignments.historical_assignments, 0) as historical_assignments')
            ->selectRaw('storage_assignments.last_assigned_at as last_assigned_at')
            ->selectRaw("({$priceIqdSql}) * COALESCE(storage_active_subscribers.active_subscribers, 0) as estimated_revenue");

        $search = trim($this->search);
        $hasPaymentModeColumn = $this->tableHasColumn('storage_plans', 'payment_mode');
        $hasBillingIntervalsColumn = $this->tableHasColumn('storage_plans', 'billing_intervals');
        $normalizedSearch = strtolower($search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $hasPaymentModeColumn, $hasBillingIntervalsColumn, $normalizedSearch) {
                $builder
                    ->where('storage_plans.name', 'like', "%{$search}%")
                    ->orWhere('storage_plans.code', 'like', "%{$search}%");

                if ($hasPaymentModeColumn) {
                    $builder->orWhere('storage_plans.payment_mode', 'like', "%{$search}%");
                }

                if ($hasBillingIntervalsColumn && in_array($normalizedSearch, ['monthly', 'yearly'], true)) {
                    $builder->orWhereJsonContains('storage_plans.billing_intervals', $normalizedSearch);
                }
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
            'price_iqd' => 'price_iqd_effective',
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
        $this->paymentMode = $plan->checkoutPaymentModeValue();
        $this->billingIntervals = $plan->billingIntervals();
        $this->quotaMb = (int) ($plan->quota_mb ?? 0);
        $this->priceIqd = (string) ((int) $plan->priceIqdAmount());
        $this->isActive = (bool) $plan->is_active;
        $this->sortOrder = (int) ($plan->sort_order ?? 0);
        $this->resetErrorBag();
        $this->resetValidation();

        $this->dispatch('payments-storage:modal-show', id: 'paymentStorageModal');
    }

    public function saveStoragePlan(): void
    {
        $validated = $this->validate($this->storageFormRules());
        $billingIntervals = $this->normalizeStorageBillingIntervals($validated['billingIntervals'] ?? []);
        $priceIqd = max(0, (int) $validated['priceIqd']);

        $plan = $this->editingStorageId
            ? StoragePlan::query()->findOrFail($this->editingStorageId)
            : new StoragePlan();
        $originalMode = $plan->checkoutPaymentMode();
        $payload = [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'quota_mb' => (int) $validated['quotaMb'],
            'price_usd' => $this->usdReferenceAmount($priceIqd),
            'is_active' => (bool) $this->isActive,
            'sort_order' => (int) ($validated['sortOrder'] ?? 0),
        ];

        if ($this->tableHasColumn('storage_plans', 'price_iqd')) {
            $payload['price_iqd'] = $priceIqd;
        }

        if ($this->tableHasColumn('storage_plans', 'payment_mode')) {
            $payload['payment_mode'] = PaymentMode::fromValue(
                $validated['paymentMode'] ?? null,
                PaymentMode::RECURRING
            )->value;
        }

        if ($this->tableHasColumn('storage_plans', 'billing_intervals')) {
            $payload['billing_intervals'] = $billingIntervals;
        }

        $plan->fill($payload);
        $plan->save();
        $updatedMode = $plan->checkoutPaymentMode();

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->editingStorageId ? __('Storage plan updated successfully.') : __('Storage plan created successfully.')
        );

        if ($this->editingStorageId && $originalMode !== $updatedMode) {
            Log::warning('Storage plan payment mode changed. Existing subscriptions are not modified; only future checkouts use the new mode.', [
                'storage_plan_id' => (int) $plan->id,
                'storage_plan_code' => (string) $plan->code,
                'previous_mode' => $originalMode->value,
                'new_mode' => $updatedMode->value,
            ]);

            $this->dispatch(
                'alert',
                type: 'warning',
                message: __('Payment mode changes affect only future purchases. Existing subscriptions and payment records stay unchanged.')
            );
        }

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
            message: $plan->is_active ? __('Storage plan activated successfully.') : __('Storage plan deactivated successfully.')
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
                message: __('This storage plan has customer subscriptions. Deactivate it instead of deleting.')
            );

            return;
        }

        $plan->delete();
        $this->resetDeleteState();
        $this->dispatch('payments-storage:modal-hide', id: 'paymentStorageDeleteModal');
        $this->dispatch('alert', type: 'success', message: __('Storage plan deleted successfully.'));
    }

    public function resetStorageForm(): void
    {
        $this->editingStorageId = null;
        $this->code = '';
        $this->name = '';
        $this->paymentMode = PaymentMode::RECURRING->value;
        $this->billingIntervals = ['monthly'];
        $this->quotaMb = '';
        $this->priceIqd = '';
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

    protected function normalizeStorageBillingIntervals(array $intervals): array
    {
        $allowed = ['monthly', 'yearly'];
        $normalized = collect($intervals)
            ->map(fn ($interval) => strtolower(trim((string) $interval)))
            ->filter(fn (string $interval) => in_array($interval, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['monthly'];
    }
}
