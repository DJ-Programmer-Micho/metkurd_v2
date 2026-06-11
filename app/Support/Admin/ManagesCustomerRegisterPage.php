<?php

namespace App\Support\Admin;

use App\Enums\PaymentRecurringStrategy;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerRegisterPage
{
    use InteractsWithCustomerAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'country', keep: true)]
    public string $countryFilter = 'all';

    #[Url(as: 'joined', keep: true)]
    public string $joinedFilter = 'all';

    #[Url(as: 'customer', keep: true)]
    public string $customerFilter = 'all';

    public int $perPage = 12;

    public string $servicePlanAdjustmentId = '';

    public string $servicePlanBillingCycle = 'monthly';

    public string $servicePlanProviderRef = '';

    public string $servicePlanAdjustmentNote = '';

    public string $storagePlanAdjustmentId = '';

    public string $storagePlanBillingCycle = 'monthly';

    public string $storagePlanProviderRef = '';

    public string $storagePlanAdjustmentNote = '';

    public string $addonProductAdjustmentId = '';

    public string $addonProviderRef = '';

    public string $addonAdjustmentNote = '';

    public function mount(): void
    {
        if ($this->customerFilter === 'all') {
            return;
        }

        $customer = Customer::query()->find((int) $this->customerFilter);

        $this->prefillManualAdjustmentsFromCustomer($customer);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCountryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedJoinedFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->planFilter = 'all';
        $this->countryFilter = 'all';
        $this->joinedFilter = 'all';
        $this->customerFilter = 'all';
        $this->resetManualAdjustmentForms();
        $this->resetPage();
    }

    #[Computed]
    public function topStats(): array
    {
        return [
            'customers' => (int) Customer::query()->count(),
            'new_30d' => (int) Customer::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'profiles' => (int) Customer::query()->whereHas('profile')->count(),
            'countries' => (int) $this->customerCountryOptions->count(),
        ];
    }

    protected function registerBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery();

        $this->applyCustomerSearch($query, $this->search);
        $this->applyCustomerStatusFilter($query, $this->statusFilter);
        $this->applyPlanFilter($query, $this->planFilter);
        $this->applyCountryFilter($query, $this->countryFilter);
        $this->applyJoinedWindowFilter($query, $this->joinedFilter);

        return $query
            ->orderByDesc('customers.created_at')
            ->orderBy('customers.username');
    }

    #[Computed]
    public function registrationCustomers()
    {
        return $this->registerBaseQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function registerServicePlanOptions()
    {
        return ServicePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'monthly_credits',
                'price_iqd_monthly',
                'price_iqd_yearly',
            ]);
    }

    #[Computed]
    public function registerStoragePlanOptions()
    {
        return StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'quota_mb',
                'price_iqd',
            ]);
    }

    #[Computed]
    public function registerAddonOptions()
    {
        return CreditProduct::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'credits_amount',
                'price_iqd',
            ]);
    }

    #[Computed]
    public function selectedCustomer()
    {
        if ($this->customerFilter === 'all') {
            return null;
        }

        return $this->customersOverviewQuery()
            ->withCount(['customerFiles', 'serviceSubscriptions', 'storageSubscriptions'])
            ->with([
                'creditOrders' => fn ($orderQuery) => $this->scopePaidOrders($orderQuery)->latest()->limit(6),
                'creditOrders.servicePlan:id,code,name',
                'creditOrders.creditProduct:id,code,name,credits_amount',
                'serviceSubscriptions' => fn ($subscriptionQuery) => $subscriptionQuery->with(['servicePlan:id,code,name', 'previousServicePlan:id,code,name'])->latest()->limit(6),
                'activeStorageSubscription' => fn ($subscriptionQuery) => $subscriptionQuery->select(
                    'customer_storage_subscriptions.id',
                    'customer_storage_subscriptions.customer_id',
                    'customer_storage_subscriptions.storage_plan_id',
                    'customer_storage_subscriptions.status',
                    'customer_storage_subscriptions.source',
                    'customer_storage_subscriptions.cycle_started_on',
                    'customer_storage_subscriptions.cycle_ends_on',
                    'customer_storage_subscriptions.next_renewal_on',
                    'customer_storage_subscriptions.auto_renew',
                    'customer_storage_subscriptions.starts_at',
                    'customer_storage_subscriptions.ends_at'
                ),
                'activeStorageSubscription.storagePlan:id,code,name,quota_mb',
                'storageSubscriptions' => fn ($subscriptionQuery) => $subscriptionQuery->with(['storagePlan:id,code,name,quota_mb'])->latest()->limit(6),
                'mlJobs' => fn ($jobQuery) => $this->scopeJobs($jobQuery)->with(['tool:id,code,name', 'toolAction:id,tool_code,action_code,full_code,name'])->latest()->limit(5),
                'payments' => fn ($paymentQuery) => $paymentQuery
                    ->select([
                        'id',
                        'uuid',
                        'customer_id',
                        'provider',
                        'purchase_type',
                        'payment_mode',
                        'provider_object_type',
                        'status',
                        'internal_status',
                        'amount',
                        'currency',
                        'purchase_snapshot',
                        'provider_status',
                        'provider_payment_status',
                        'provider_subscription_status',
                        'fib_payment_id',
                        'fib_subscription_id',
                        'mismatch_reason',
                        'paid_at',
                        'fulfilled_at',
                        'review_required_at',
                        'last_callback_received_at',
                        'last_status_checked_at',
                        'created_at',
                    ])
                    ->latest()
                    ->limit(12),
            ])
            ->find((int) $this->customerFilter);
    }

    public function focusCustomer(int $customerId): void
    {
        $this->customerFilter = (string) $customerId;
        $customer = Customer::query()->find($customerId);

        $this->prefillManualAdjustmentsFromCustomer($customer);
    }

    public function clearFocusedCustomer(): void
    {
        $this->customerFilter = 'all';
        $this->resetManualAdjustmentForms();
    }

    public function applyServicePlanAdjustment(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'servicePlanAdjustmentId' => 'required|integer',
            'servicePlanBillingCycle' => 'required|string|in:monthly,yearly',
            'servicePlanProviderRef' => 'nullable|string|max:191',
            'servicePlanAdjustmentNote' => 'required|string|min:10|max:500',
        ]);

        $plan = ServicePlan::query()
            ->where('is_active', true)
            ->find((int) $validated['servicePlanAdjustmentId']);

        if (! $plan) {
            $this->dispatch('alert', type: 'error', message: __('Selected service plan is inactive or unavailable.'));

            return;
        }

        $billingCycle = (string) $validated['servicePlanBillingCycle'];

        if (! $plan->supportsBillingInterval($billingCycle)) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: __('This billing cycle is not enabled for the selected service plan.')
            );

            return;
        }

        $providerRef = $this->normalizedAdminProviderRef((string) ($validated['servicePlanProviderRef'] ?? ''), 'ADMIN-PLAN');
        $adminNote = trim((string) $validated['servicePlanAdjustmentNote']);
        $activeUntil = $billingCycle === 'yearly' ? now()->addYear() : now()->addMonth();

        app(PlanSwitcher::class)->switchServicePlan($customer, (int) $plan->id, [
            'provider' => 'admin_manual',
            'provider_ref' => $providerRef,
            'payment_method' => 'admin_manual',
            'billing_cycle' => $billingCycle,
            'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
            'paid_at' => now(),
            'active_until' => $activeUntil,
            'ui' => 'admin.customers.register',
            'admin_adjustment' => true,
            'admin_id' => auth('admin')->id(),
            'admin_note' => $adminNote,
        ]);

        Log::info('Admin applied manual service plan adjustment.', [
            'admin_id' => auth('admin')->id(),
            'customer_id' => $customer->id,
            'service_plan_id' => $plan->id,
            'service_plan_code' => $plan->code,
            'billing_cycle' => $billingCycle,
            'provider_ref' => $providerRef,
        ]);

        $this->servicePlanProviderRef = '';
        $this->servicePlanAdjustmentNote = '';
        $this->servicePlanAdjustmentId = (string) $plan->id;

        $this->dispatch('alert', type: 'success', message: __('Service plan updated successfully for this customer.'));
    }

    public function applyStoragePlanAdjustment(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'storagePlanAdjustmentId' => 'required|integer',
            'storagePlanBillingCycle' => 'required|string|in:monthly,yearly',
            'storagePlanProviderRef' => 'nullable|string|max:191',
            'storagePlanAdjustmentNote' => 'required|string|min:10|max:500',
        ]);

        $plan = StoragePlan::query()
            ->where('is_active', true)
            ->find((int) $validated['storagePlanAdjustmentId']);

        if (! $plan) {
            $this->dispatch('alert', type: 'error', message: __('Selected storage plan is inactive or unavailable.'));

            return;
        }

        $billingCycle = (string) $validated['storagePlanBillingCycle'];

        if (! $plan->supportsBillingInterval($billingCycle)) {
            $this->dispatch(
                'alert',
                type: 'error',
                message: __('This billing cycle is not enabled for the selected storage plan.')
            );

            return;
        }

        $providerRef = $this->normalizedAdminProviderRef((string) ($validated['storagePlanProviderRef'] ?? ''), 'ADMIN-STORAGE');
        $adminNote = trim((string) $validated['storagePlanAdjustmentNote']);
        $activeUntil = $billingCycle === 'yearly' ? now()->addYear() : now()->addMonth();

        app(PlanSwitcher::class)->switchStoragePlan($customer, (int) $plan->id, [
            'provider' => 'admin_manual',
            'provider_ref' => $providerRef,
            'payment_method' => 'admin_manual',
            'billing_cycle' => $billingCycle,
            'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
            'paid_at' => now(),
            'active_until' => $activeUntil,
            'ui' => 'admin.customers.register',
            'admin_adjustment' => true,
            'admin_id' => auth('admin')->id(),
            'admin_note' => $adminNote,
        ]);

        Log::info('Admin applied manual storage plan adjustment.', [
            'admin_id' => auth('admin')->id(),
            'customer_id' => $customer->id,
            'storage_plan_id' => $plan->id,
            'storage_plan_code' => $plan->code,
            'billing_cycle' => $billingCycle,
            'provider_ref' => $providerRef,
        ]);

        $this->storagePlanProviderRef = '';
        $this->storagePlanAdjustmentNote = '';
        $this->storagePlanAdjustmentId = (string) $plan->id;

        $this->dispatch('alert', type: 'success', message: __('Storage plan updated successfully for this customer.'));
    }

    public function applyAddonAdjustment(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'addonProductAdjustmentId' => 'required|integer',
            'addonProviderRef' => 'nullable|string|max:191',
            'addonAdjustmentNote' => 'required|string|min:10|max:500',
        ]);

        $product = CreditProduct::query()
            ->where('is_active', true)
            ->find((int) $validated['addonProductAdjustmentId']);

        if (! $product) {
            $this->dispatch('alert', type: 'error', message: __('Selected addon pack is inactive or unavailable.'));

            return;
        }

        $providerRef = $this->normalizedAdminProviderRef((string) ($validated['addonProviderRef'] ?? ''), 'ADMIN-ADDON');
        $adminNote = trim((string) $validated['addonAdjustmentNote']);

        try {
            app(AddonPurchaseService::class)->purchase($customer, (int) $product->id, [
                'provider' => 'admin_manual',
                'provider_ref' => $providerRef,
                'payment_method' => 'admin_manual',
                'paid_at' => now(),
                'ui' => 'admin.customers.register',
                'admin_adjustment' => true,
                'admin_id' => auth('admin')->id(),
                'admin_note' => $adminNote,
            ]);
        } catch (AuthorizationException $exception) {
            $this->dispatch('alert', type: 'error', message: $exception->getMessage());

            return;
        }

        Log::info('Admin applied manual addon credit adjustment.', [
            'admin_id' => auth('admin')->id(),
            'customer_id' => $customer->id,
            'credit_product_id' => $product->id,
            'credit_product_code' => $product->code,
            'provider_ref' => $providerRef,
        ]);

        $this->addonProviderRef = '';
        $this->addonAdjustmentNote = '';
        $this->addonProductAdjustmentId = (string) $product->id;

        $this->dispatch('alert', type: 'success', message: __('Addon credits added successfully for this customer.'));
    }

    protected function resolveFocusedCustomer(): ?Customer
    {
        $customerId = (int) $this->customerFilter;

        if ($this->customerFilter === 'all' || $customerId <= 0) {
            $this->dispatch('alert', type: 'error', message: __('Select a customer first before applying a manual billing adjustment.'));

            return null;
        }

        $customer = Customer::query()->find($customerId);

        if (! $customer) {
            $this->dispatch('alert', type: 'error', message: __('The selected customer could not be found.'));

            return null;
        }

        return $customer;
    }

    protected function prefillManualAdjustmentsFromCustomer(?Customer $customer): void
    {
        if (! $customer) {
            $this->resetManualAdjustmentForms();

            return;
        }

        $this->servicePlanAdjustmentId = '';
        $this->storagePlanAdjustmentId = '';
        $this->addonProductAdjustmentId = '';

        $servicePlanId = (int) ($customer->activeServiceSubscription?->service_plan_id ?? 0);
        $storagePlanId = (int) ($customer->activeStorageSubscription?->storage_plan_id ?? 0);

        if ($servicePlanId > 0) {
            $this->servicePlanAdjustmentId = (string) $servicePlanId;
        }

        if ($storagePlanId > 0) {
            $this->storagePlanAdjustmentId = (string) $storagePlanId;
        }
    }

    protected function normalizedAdminProviderRef(string $value, string $prefix): string
    {
        $value = trim($value);

        if ($value !== '') {
            return substr($value, 0, 191);
        }

        return $prefix.'-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
    }

    protected function resetManualAdjustmentForms(): void
    {
        $this->servicePlanAdjustmentId = '';
        $this->servicePlanBillingCycle = 'monthly';
        $this->servicePlanProviderRef = '';
        $this->servicePlanAdjustmentNote = '';

        $this->storagePlanAdjustmentId = '';
        $this->storagePlanBillingCycle = 'monthly';
        $this->storagePlanProviderRef = '';
        $this->storagePlanAdjustmentNote = '';

        $this->addonProductAdjustmentId = '';
        $this->addonProviderRef = '';
        $this->addonAdjustmentNote = '';
    }
}
