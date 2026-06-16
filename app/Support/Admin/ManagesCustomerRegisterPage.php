<?php

namespace App\Support\Admin;

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Fib\FibSubscriptionMapper;
use App\Domain\Payments\Fib\FibSubscriptionService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Enums\PaymentRecurringStrategy;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Billing\CreditService;
use App\Services\Billing\ManualServicePlanGrantService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Payments\AddonPurchaseService;
use App\Services\Payments\ManualRevenueReclassificationService;
use App\Support\TelegramSubscriptionLifecycleNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

    public string $servicePlanGrantReason = '';

    public string $servicePlanGrantReasonOther = '';

    public string $servicePlanCreditSyncPolicy = 'safe_top_up_only';

    public string $servicePlanAdjustmentNote = '';

    public string $paidReconciliationPaymentId = '';

    public string $paidReconciliationFibSubscriptionId = '';

    public string $paidReconciliationMode = 'manual_correction_already_applied';

    public string $paidReconciliationReason = '';

    public bool $paidReconciliationStatusOnlyConfirmation = false;

    public string $reviewPaymentId = '';

    public string $reviewCorrectFibSubscriptionId = '';

    public string $reviewCorrectFibPaymentId = '';

    public string $reviewReconnectMode = 'manual_correction_already_applied';

    public string $reviewResolutionReason = '';

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
                'apiWallet:id,customer_id,balance_credits,subscription_balance_credits,addon_balance_credits,lifetime_earned,lifetime_spent,lifetime_refunded,cycle_started_on,cycle_ends_on,last_granted_at,last_charged_at',
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
                        'local_reference',
                        'callback_payload',
                        'status_response',
                        'meta',
                        'fib_payment_id',
                        'fib_subscription_id',
                        'mismatch_reason',
                        'last_payment_at',
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
            'servicePlanGrantReason' => 'required|string|in:internal_team_account,company_account,testing_account,partner_access,founder_admin_access,other',
            'servicePlanGrantReasonOther' => 'nullable|string|max:500',
            'servicePlanCreditSyncPolicy' => 'required|string|in:safe_top_up_only',
            'servicePlanAdjustmentNote' => 'nullable|string|max:500',
        ]);

        if ((string) $validated['servicePlanGrantReason'] === 'other'
            && mb_strlen(trim((string) ($validated['servicePlanGrantReasonOther'] ?? ''))) < 10) {
            $this->addError('servicePlanGrantReasonOther', __('Provide at least 10 characters when "Other" is selected.'));

            return;
        }

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

        $adminNote = $this->buildManualGrantReason(
            (string) $validated['servicePlanGrantReason'],
            trim((string) ($validated['servicePlanGrantReasonOther'] ?? '')),
            trim((string) ($validated['servicePlanAdjustmentNote'] ?? ''))
        );
        $previousAllowances = $this->servicePlanAllowances($customer->currentServicePlan());

        $subscription = app(ManualServicePlanGrantService::class)->grant($customer, $plan, [
            'billing_cycle' => $billingCycle,
            'admin_id' => auth('admin')->id(),
            'reason' => $adminNote,
        ]);

        $syncResult = [
            'changed' => false,
            'app_added_credits' => 0,
            'api_added_credits' => 0,
        ];

        if ((string) $validated['servicePlanCreditSyncPolicy'] === 'safe_top_up_only') {
            $syncResult = app(CreditService::class)->syncCustomerSubscriptionCreditsToPlan($customer->fresh(), $plan, [
                'type' => 'admin_credit_sync',
                'source_type' => 'admin_manual_grant',
                'source_id' => (string) $subscription->id,
                'related_type' => $subscription::class,
                'related_id' => (string) $subscription->id,
                'admin_id' => auth('admin')->id(),
                'admin_note' => $adminNote,
                'billing_cycle' => $billingCycle,
                'description' => sprintf(
                    'Admin manual no-revenue service plan grant for customer %d to %s.',
                    (int) $customer->id,
                    (string) $plan->code
                ),
                'cycle_started_on' => $subscription->cycle_started_on?->toDateString(),
                'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString(),
                'current_cycle_key' => $subscription->cycle_started_on?->format('Y-m') ?? now()->format('Y-m'),
                'current_allowances' => $previousAllowances,
            ]);
        }

        Log::info('Admin applied manual service plan adjustment.', [
            'admin_id' => auth('admin')->id(),
            'customer_id' => $customer->id,
            'service_plan_id' => $plan->id,
            'service_plan_code' => $plan->code,
            'billing_cycle' => $billingCycle,
            'billing_source' => 'admin_manual_grant',
            'revenue_record' => false,
            'app_added_credits' => $syncResult['app_added_credits'],
            'api_added_credits' => $syncResult['api_added_credits'],
        ]);

        $this->servicePlanProviderRef = '';
        $this->servicePlanGrantReason = '';
        $this->servicePlanGrantReasonOther = '';
        $this->servicePlanCreditSyncPolicy = 'safe_top_up_only';
        $this->servicePlanAdjustmentNote = '';
        $this->servicePlanAdjustmentId = (string) $plan->id;

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->creditSyncStatusMessage(
                $syncResult,
                __('Manual plan grant applied successfully without creating a revenue/provider record.')
            )
        );
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

    public function syncCustomerCreditsToPlan(int $customerId): void
    {
        $customer = Customer::query()->find($customerId);

        if (! $customer) {
            $this->dispatch('alert', type: 'error', message: __('The selected customer could not be found.'));

            return;
        }

        $subscription = $customer->activeServiceSubscription()->with('servicePlan')->first();
        $plan = $subscription?->servicePlan;

        if (! $subscription || ! $plan) {
            $this->dispatch('alert', type: 'warning', message: __('Customer does not have an active service plan to sync from.'));

            return;
        }

        $syncResult = app(CreditService::class)->syncCustomerSubscriptionCreditsToPlan($customer->fresh(), $plan, [
            'type' => 'admin_credit_sync',
            'source_type' => 'admin_credit_sync',
            'source_id' => (string) $subscription->id,
            'related_type' => $subscription::class,
            'related_id' => (string) $subscription->id,
            'admin_id' => auth('admin')->id(),
            'description' => sprintf(
                'Admin credit sync for customer %d using current plan %s.',
                (int) $customer->id,
                (string) $plan->code
            ),
            'cycle_started_on' => $subscription->cycle_started_on?->toDateString(),
            'cycle_ends_on' => $subscription->cycle_ends_on?->toDateString(),
            'current_cycle_key' => $subscription->cycle_started_on?->format('Y-m') ?? now()->format('Y-m'),
            'current_allowances' => $this->servicePlanAllowances($plan),
        ]);

        if (! $syncResult['changed']) {
            $this->dispatch('alert', type: 'info', message: __('Customer already has enough subscription credits for the current plan.'));

            return;
        }

        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->creditSyncStatusMessage($syncResult, __('Synced successfully.'))
        );
    }

    public function prefillPaidSubscriptionReconciliation(int $paymentId): void
    {
        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            $this->dispatch('alert', type: 'error', message: __('The selected payment could not be found.'));

            return;
        }

        $this->customerFilter = (string) $payment->customer_id;
        $this->prefillManualAdjustmentsFromCustomer($payment->customer);
        $this->paidReconciliationPaymentId = (string) $payment->id;
        $this->paidReconciliationFibSubscriptionId = (string) ($payment->fib_subscription_id ?? '');
        $this->paidReconciliationMode = 'manual_correction_already_applied';
        $this->paidReconciliationReason = '';
        $this->paidReconciliationStatusOnlyConfirmation = false;
    }

    public function applyPaidSubscriptionReconciliation(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'paidReconciliationPaymentId' => 'required|integer',
            'paidReconciliationFibSubscriptionId' => 'required|string|max:191',
            'paidReconciliationMode' => 'required|string|in:manual_correction_already_applied,apply_fulfillment_once',
            'paidReconciliationReason' => 'required|string|min:10|max:500',
        ]);

        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find((int) $validated['paidReconciliationPaymentId']);

        if (! $payment instanceof Payment) {
            $this->dispatch('alert', type: 'error', message: __('The selected payment could not be found.'));

            return;
        }

        if ((int) $payment->customer_id !== (int) $customer->id) {
            $this->dispatch('alert', type: 'error', message: __('The selected payment does not belong to the focused customer.'));

            return;
        }

        $fibSubscriptionId = trim((string) $validated['paidReconciliationFibSubscriptionId']);
        $mode = (string) $validated['paidReconciliationMode'];
        $reason = trim((string) $validated['paidReconciliationReason']);

        if ($payment->fulfilled_at !== null) {
            if ($mode !== 'manual_correction_already_applied') {
                $this->dispatch('alert', type: 'error', message: __('This payment was already fulfilled. Refusing to run fulfillment again. Use status-only reconciliation if you only need to reconnect local state.'));

                return;
            }

            if (! $this->paidReconciliationStatusOnlyConfirmation) {
                $this->addError('paidReconciliationStatusOnlyConfirmation', __('Confirm that this is a status-only reconciliation for an already fulfilled payment.'));

                return;
            }
        }

        if ($this->normalizePaidReconciliationPayment($payment, $fibSubscriptionId) === null) {
            return;
        }

        $reconciled = $mode === 'manual_correction_already_applied'
            ? $this->reconcilePaidSubscriptionWithoutRefill($payment, $fibSubscriptionId, $reason)
            : $this->reconcilePaidSubscriptionWithFulfillment($payment, $fibSubscriptionId, $reason);

        if (! $reconciled instanceof Payment) {
            return;
        }

        $this->paidReconciliationReason = '';
        $this->paidReconciliationPaymentId = (string) $reconciled->id;
        $this->paidReconciliationFibSubscriptionId = (string) ($reconciled->fib_subscription_id ?? '');
        $this->paidReconciliationStatusOnlyConfirmation = false;

        $message = $mode === 'manual_correction_already_applied'
            ? __('Paid FIB subscription reconnected without a duplicate credit refill.')
            : __('Paid FIB subscription applied successfully with one fulfillment pass.');

        $this->dispatch('alert', type: 'success', message: $message);
    }

    public function repairPaidSubscription(int $paymentId): void
    {
        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            $this->dispatch('alert', type: 'error', message: __('The selected payment could not be found.'));

            return;
        }

        if (! $payment->isProviderPaidButLocallyUnappliedSubscription()) {
            $this->dispatch('alert', type: 'warning', message: __('This payment is not in a repairable provider-paid subscription state.'));

            return;
        }

        $repaired = $this->reconcilePaidSubscriptionWithFulfillment(
            $payment,
            (string) $payment->fib_subscription_id,
            __('Quick repair from the customer payment ledger.')
        );

        if (! $repaired instanceof Payment) {
            return;
        }

        if ($repaired->fulfilled_at !== null || $repaired->isApplied()) {
            $this->dispatch('alert', type: 'success', message: __('Paid FIB subscription repaired and applied successfully.'));

            return;
        }

        if ($repaired->requiresReview()) {
            $this->dispatch('alert', type: 'warning', message: $repaired->reviewMessage() ?? __('The subscription now requires manual review.'));

            return;
        }

        $this->dispatch('alert', type: 'info', message: __('Repair completed without applying entitlement. Please review the payment state again.'));
    }

    public function openReviewPayment(int $paymentId): void
    {
        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            $this->dispatch('alert', type: 'error', message: __('The selected review payment could not be found.'));

            return;
        }

        $this->customerFilter = (string) $payment->customer_id;
        $this->prefillManualAdjustmentsFromCustomer($payment->customer);
        $this->reviewPaymentId = (string) $payment->id;
        $this->reviewCorrectFibSubscriptionId = (string) ($payment->fib_subscription_id ?? '');
        $this->reviewCorrectFibPaymentId = (string) ($payment->fib_payment_id ?? '');
        $this->reviewReconnectMode = 'manual_correction_already_applied';
        $this->reviewResolutionReason = '';
    }

    public function attachCorrectReviewProviderReference(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'reviewPaymentId' => 'required|integer',
            'reviewCorrectFibSubscriptionId' => 'nullable|string|max:191',
            'reviewCorrectFibPaymentId' => 'nullable|string|max:191',
            'reviewReconnectMode' => 'required|string|in:manual_correction_already_applied,apply_fulfillment_once',
            'reviewResolutionReason' => 'required|string|min:10|max:500',
        ]);

        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find((int) $validated['reviewPaymentId']);

        if (! $payment instanceof Payment || (int) $payment->customer_id !== (int) $customer->id) {
            $this->dispatch('alert', type: 'error', message: __('The selected review payment is invalid for this customer.'));

            return;
        }

        $fibSubscriptionId = trim((string) $validated['reviewCorrectFibSubscriptionId']);
        $fibPaymentId = trim((string) $validated['reviewCorrectFibPaymentId']);
        $reason = trim((string) $validated['reviewResolutionReason']);
        $mode = (string) $validated['reviewReconnectMode'];

        if ($fibSubscriptionId === '' && $fibPaymentId === '') {
            $this->addError('reviewCorrectFibSubscriptionId', __('Provide a correct FIB subscription id or FIB payment id.'));
            $this->addError('reviewCorrectFibPaymentId', __('Provide a correct FIB subscription id or FIB payment id.'));

            return;
        }

        if ($payment->isProviderSubscriptionObject() && $fibSubscriptionId === '') {
            $this->addError('reviewCorrectFibSubscriptionId', __('Recurring FIB subscription payments require the correct fib_subscription_id to reconnect safely.'));

            return;
        }

        if ($fibPaymentId !== '' && (string) ($payment->fib_payment_id ?? '') !== $fibPaymentId) {
            $payment->forceFill(['fib_payment_id' => $fibPaymentId])->save();
            $payment = $payment->fresh(['customer', 'purchasable']) ?? $payment;
        }

        if ($payment->isProviderSubscriptionObject()) {
            $reconciled = $mode === 'manual_correction_already_applied'
                ? $this->reconcilePaidSubscriptionWithoutRefill($payment, $fibSubscriptionId, $reason)
                : $this->reconcilePaidSubscriptionWithFulfillment($payment, $fibSubscriptionId, $reason);

            if (! $reconciled instanceof Payment) {
                return;
            }

            $payment = $reconciled;
        } else {
            if ((string) ($payment->fib_payment_id ?? '') !== $fibPaymentId) {
                $payment->forceFill(['fib_payment_id' => $fibPaymentId])->save();
            }

            if ($mode === 'apply_fulfillment_once') {
                $payment = app(ConfirmFibPayment::class)->handle(
                    $payment,
                    'admin_review_reference_attach',
                    is_array($payment->callback_payload) ? $payment->callback_payload : null,
                )->fresh() ?? $payment;
            }
        }

        $meta = array_merge((array) ($payment->meta ?? []), [
            'review_resolution' => [
                'action' => 'attach_correct_provider_reference',
                'closed_at' => now()->toIso8601String(),
                'reason' => $reason,
                'mode' => $mode,
                'fib_subscription_id' => $payment->fib_subscription_id,
                'fib_payment_id' => $payment->fib_payment_id,
            ],
        ]);

        $payment->forceFill([
            'meta' => $meta,
        ])->save();

        app(PaymentEventRecorder::class)->record($payment, [
            'event_type' => 'admin_payment_reference_attached',
            'source' => 'admin_customer_register_review',
            'event_key' => 'admin-payment-reference-attached:'.$payment->id.':'.$mode,
            'before_status' => $payment->status?->value,
            'after_status' => $payment->status?->value,
            'meta' => [
                'reason' => $reason,
                'mode' => $mode,
                'fib_subscription_id' => $payment->fib_subscription_id,
                'fib_payment_id' => $payment->fib_payment_id,
            ],
        ]);

        $this->openReviewPayment((int) $payment->id);
        $this->dispatch('alert', type: 'success', message: __('The correct FIB reference was attached safely and the payment was updated without duplicate fulfillment.'));
    }

    public function markReviewPaymentInvalid(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'reviewPaymentId' => 'required|integer',
            'reviewResolutionReason' => 'required|string|min:10|max:500',
        ]);

        /** @var Payment|null $payment */
        $payment = Payment::query()->find((int) $validated['reviewPaymentId']);

        if (! $payment instanceof Payment || (int) $payment->customer_id !== (int) $customer->id) {
            $this->dispatch('alert', type: 'error', message: __('The selected review payment is invalid for this customer.'));

            return;
        }

        $reason = trim((string) $validated['reviewResolutionReason']);

        DB::transaction(function () use ($payment, $reason) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->forceFill([
                'status' => PaymentStatus::EXPIRED,
                'internal_status' => PaymentInternalStatus::EXPIRED,
                'expired_at' => $locked->expired_at ?? now(),
                'review_required_at' => null,
                'mismatch_reason' => $reason,
                'meta' => array_merge((array) ($locked->meta ?? []), [
                    'review_resolution' => [
                        'action' => 'mark_invalid_expired',
                        'closed_at' => now()->toIso8601String(),
                        'reason' => $reason,
                    ],
                ]),
            ])->save();

            app(PaymentEventRecorder::class)->record($locked, [
                'event_type' => 'admin_payment_marked_invalid',
                'source' => 'admin_customer_register_review',
                'event_key' => 'admin-payment-marked-invalid:'.$locked->id,
                'before_status' => $payment->status?->value,
                'after_status' => $locked->status?->value,
                'meta' => [
                    'reason' => $reason,
                ],
            ]);
        }, 3);

        $this->openReviewPayment((int) $payment->id);
        $this->dispatch('alert', type: 'success', message: __('The review payment was marked invalid/expired and removed from actionable review processing.'));
    }

    public function markReviewPaymentNonRevenue(): void
    {
        $customer = $this->resolveFocusedCustomer();

        if (! $customer) {
            return;
        }

        $validated = $this->validate([
            'reviewPaymentId' => 'required|integer',
            'reviewResolutionReason' => 'required|string|min:10|max:500',
        ]);

        $payment = Payment::query()->find((int) $validated['reviewPaymentId']);

        if (! $payment instanceof Payment || (int) $payment->customer_id !== (int) $customer->id) {
            $this->dispatch('alert', type: 'error', message: __('The selected review payment is invalid for this customer.'));

            return;
        }

        app(ManualRevenueReclassificationService::class)->execute(
            $payment,
            (int) $customer->id,
            'manual_grant',
            trim((string) $validated['reviewResolutionReason']),
            false,
        );

        $payment = $payment->fresh() ?? $payment;

        $payment->forceFill([
            'review_required_at' => null,
            'meta' => array_merge((array) ($payment->meta ?? []), [
                'review_resolution' => [
                    'action' => 'mark_non_revenue_internal',
                    'closed_at' => now()->toIso8601String(),
                    'reason' => trim((string) $validated['reviewResolutionReason']),
                ],
            ]),
        ])->save();

        app(PaymentEventRecorder::class)->record($payment, [
            'event_type' => 'admin_payment_marked_non_revenue',
            'source' => 'admin_customer_register_review',
            'event_key' => 'admin-payment-marked-non-revenue:'.$payment->id,
            'before_status' => $payment->status?->value,
            'after_status' => $payment->status?->value,
            'meta' => [
                'reason' => trim((string) $validated['reviewResolutionReason']),
                'billing_source' => data_get($payment->meta, 'billing_source'),
            ],
        ]);

        $this->openReviewPayment((int) $payment->id);
        $this->dispatch('alert', type: 'success', message: __('The record was reclassified as a no-revenue manual/internal grant without deleting payment history.'));
    }

    protected function normalizePaidReconciliationPayment(Payment $payment, string $fibSubscriptionId): ?Payment
    {
        if ($payment->provider !== PaymentProvider::FIB
            || $payment->purchase_type !== PurchaseType::PLAN_SUBSCRIPTION
            || ($payment->provider_object_type ?? PaymentProviderObjectType::PAYMENT) !== PaymentProviderObjectType::SUBSCRIPTION
            || $payment->resolvedPaymentMode(PaymentMode::ONE_TIME) !== PaymentMode::RECURRING) {
            $this->dispatch('alert', type: 'error', message: __('This action only supports FIB recurring service-plan subscription payments.'));

            return null;
        }

        $existing = Payment::query()
            ->where('fib_subscription_id', $fibSubscriptionId)
            ->where('id', '!=', $payment->id)
            ->first();

        if ($existing instanceof Payment) {
            $this->dispatch('alert', type: 'error', message: __('That FIB subscription id is already linked to another payment record.'));

            return null;
        }

        if ((string) ($payment->fib_subscription_id ?? '') !== $fibSubscriptionId) {
            $payment->forceFill(['fib_subscription_id' => $fibSubscriptionId])->save();
            $payment = $payment->fresh(['customer', 'purchasable']) ?? $payment;
        }

        return $payment;
    }

    protected function reconcilePaidSubscriptionWithFulfillment(Payment $payment, string $fibSubscriptionId, string $reason): ?Payment
    {
        try {
            $payment = $this->normalizePaidReconciliationPayment($payment, $fibSubscriptionId);

            if (! $payment instanceof Payment) {
                return null;
            }

            $repaired = app(ConfirmFibPayment::class)->handle(
                $payment,
                'admin_paid_subscription_reconciliation',
                is_array($payment->callback_payload) ? $payment->callback_payload : null,
            )->fresh();
        } catch (\Throwable $exception) {
            $this->dispatch('alert', type: 'error', message: __('The paid subscription could not be applied: :message', ['message' => $exception->getMessage()]));

            return null;
        }

        if ($repaired instanceof Payment) {
            $this->recordAdminPaidReconciliationMeta($repaired, 'apply_fulfillment_once', $reason);
        }

        return $repaired;
    }

    protected function reconcilePaidSubscriptionWithoutRefill(Payment $payment, string $fibSubscriptionId, string $reason): ?Payment
    {
        $payment = $this->normalizePaidReconciliationPayment($payment, $fibSubscriptionId);

        if (! $payment instanceof Payment) {
            return null;
        }

        $customer = $payment->customer;
        $plan = $payment->purchasable;

        if (! $customer instanceof Customer || ! $plan instanceof ServicePlan) {
            $this->dispatch('alert', type: 'error', message: __('The payment customer or service plan could not be loaded.'));

            return null;
        }

        try {
            $status = app(FibSubscriptionService::class)->getStatusBySubscriptionId($fibSubscriptionId);
        } catch (\Throwable $exception) {
            $this->dispatch('alert', type: 'error', message: __('Provider status lookup failed: :message', ['message' => $exception->getMessage()]));

            return null;
        }

        $subscriptions = app(FibSubscriptionService::class);
        $mapper = app(FibSubscriptionMapper::class);
        $providerStatus = $subscriptions->normalizeProviderStatus($status->status);
        $providerPaymentStatus = $mapper->explicitPaidStatusFromPayloads(
            $status,
            is_array($payment->callback_payload) ? $payment->callback_payload : null,
            is_array($payment->status_response) ? $payment->status_response : null,
        );
        $hasPaidEvidence = $mapper->hasConfirmedPaymentEvidence(
            $status,
            is_array($payment->callback_payload) ? $payment->callback_payload : null,
            is_array($payment->status_response) ? $payment->status_response : null,
        );

        if (! in_array($providerStatus, ['ACTIVE', 'SUBSCRIBED'], true) || ! $hasPaidEvidence) {
            $this->dispatch('alert', type: 'error', message: __('The provider subscription is not in a safe ACTIVE/PAID state for no-refill reconciliation.'));

            return null;
        }

        $activeSubscription = CustomerServiceSubscription::query()
            ->with('servicePlan')
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        $validationError = $this->validateNoRefillReconciliationState($customer, $plan, $activeSubscription);

        if ($validationError !== null) {
            $this->dispatch('alert', type: 'error', message: $validationError);

            return null;
        }

        DB::transaction(function () use ($payment, $customer, $activeSubscription, $status, $providerStatus, $providerPaymentStatus, $reason, $fibSubscriptionId) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            /** @var CustomerServiceSubscription $lockedSubscription */
            $lockedSubscription = CustomerServiceSubscription::query()->lockForUpdate()->findOrFail($activeSubscription->id);

            $paidAt = $lockedPayment->paid_at ?? $status->lastPaymentAt ?? now();
            $providerCycleKey = trim((string) ($status->lastPaymentAt?->copy()->utc()->format('Y-m-d\TH:i:s\Z') ?? ''));
            $providerCycleKey = $providerCycleKey !== ''
                ? 'fib:'.$fibSubscriptionId.':'.$providerCycleKey
                : ($lockedPayment->providerRecurringCycleKey() ?? null);

            $lockedPayment->forceFill([
                'status' => PaymentStatus::PAID,
                'internal_status' => PaymentInternalStatus::APPLIED,
                'provider_status' => $providerStatus,
                'provider_subscription_status' => $providerStatus,
                'provider_payment_status' => $providerPaymentStatus ?? $lockedPayment->provider_payment_status ?? PaymentStatus::PAID->value,
                'paid_at' => $paidAt,
                'fulfilled_at' => $lockedPayment->fulfilled_at ?? now(),
                'review_required_at' => null,
                'mismatch_reason' => null,
                'active_until' => $status->activeUntil ?? $lockedPayment->active_until,
                'last_payment_at' => $status->lastPaymentAt ?? $lockedPayment->last_payment_at,
                'status_response' => $status->raw,
                'last_status_checked_at' => now(),
            ])->save();

            $subscriptionMeta = array_merge((array) ($lockedSubscription->meta ?? []), [
                'billing_source' => 'admin_paid_reconciliation',
                'revenue_record' => true,
                'provider' => 'fib',
                'fib_subscription_id' => $fibSubscriptionId,
                'manual_correction_already_applied' => true,
                'no_credit_refill' => true,
                'admin_id' => auth('admin')->id(),
                'reason' => $reason,
                'provider_status' => $providerStatus,
                'provider_last_payment_at' => $status->lastPaymentAt?->toIso8601String(),
                'provider_cycle_key' => $providerCycleKey,
                'last_provider_sync_at' => now()->toIso8601String(),
            ]);

            $lockedSubscription->forceFill([
                'payment_id' => $lockedPayment->id,
                'source' => PaymentProvider::FIB->value,
                'provider_ref' => $lockedPayment->providerReference(),
                'auto_renew' => true,
                'renewal_strategy' => PaymentRecurringStrategy::PROVIDER_SCHEDULE->value,
                'cycle_started_on' => ($status->lastPaymentAt ?? $lockedSubscription->cycle_started_on)?->toDateString(),
                'cycle_ends_on' => ($status->activeUntil ?? $lockedSubscription->cycle_ends_on)?->toDateString(),
                'next_renewal_on' => ($status->activeUntil ?? $lockedSubscription->next_renewal_on)?->toDateString(),
                'meta' => $subscriptionMeta,
            ])->save();

            $customer->syncResolvedServicePlan($lockedSubscription);
        }, 3);

        $payment = $payment->fresh(['customer', 'purchasable']) ?? $payment;
        $this->recordAdminPaidReconciliationMeta($payment, 'manual_correction_already_applied', $reason);

        app(PaymentEventRecorder::class)->record($payment, [
            'event_type' => 'operator_subscription_reconciled',
            'source' => 'admin_paid_subscription_reconciliation',
            'event_key' => 'admin-paid-reconciliation:'.$payment->id.':no-refill',
            'before_status' => $payment->status?->value,
            'after_status' => $payment->status?->value,
            'meta' => [
                'manual_correction_already_applied' => true,
                'no_credit_refill' => true,
                'admin_id' => auth('admin')->id(),
                'reason' => $reason,
            ],
        ]);

        app(TelegramSubscriptionLifecycleNotifier::class)->send(
            __('FIB subscription reconnected manually without credit refill'),
            [
                'Customer ID' => $payment->customer_id,
                'Payment ID' => $payment->id,
                'Provider ref' => $payment->providerReference(),
                'Reason' => $reason,
            ],
            'Admin paid reconciliation'
        );

        return $payment->fresh(['customer', 'purchasable']) ?? $payment;
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
        $this->servicePlanGrantReason = '';
        $this->servicePlanGrantReasonOther = '';
        $this->servicePlanCreditSyncPolicy = 'safe_top_up_only';
        $this->servicePlanAdjustmentNote = '';
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

        $this->paidReconciliationPaymentId = '';
        $this->paidReconciliationFibSubscriptionId = '';
        $this->paidReconciliationMode = 'manual_correction_already_applied';
        $this->paidReconciliationReason = '';
        $this->paidReconciliationStatusOnlyConfirmation = false;

        $this->reviewPaymentId = '';
        $this->reviewCorrectFibSubscriptionId = '';
        $this->reviewCorrectFibPaymentId = '';
        $this->reviewReconnectMode = 'manual_correction_already_applied';
        $this->reviewResolutionReason = '';
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
        $this->servicePlanGrantReason = '';
        $this->servicePlanGrantReasonOther = '';
        $this->servicePlanCreditSyncPolicy = 'safe_top_up_only';
        $this->servicePlanAdjustmentNote = '';

        $this->paidReconciliationPaymentId = '';
        $this->paidReconciliationFibSubscriptionId = '';
        $this->paidReconciliationMode = 'manual_correction_already_applied';
        $this->paidReconciliationReason = '';
        $this->paidReconciliationStatusOnlyConfirmation = false;

        $this->reviewPaymentId = '';
        $this->reviewCorrectFibSubscriptionId = '';
        $this->reviewCorrectFibPaymentId = '';
        $this->reviewReconnectMode = 'manual_correction_already_applied';
        $this->reviewResolutionReason = '';

        $this->storagePlanAdjustmentId = '';
        $this->storagePlanBillingCycle = 'monthly';
        $this->storagePlanProviderRef = '';
        $this->storagePlanAdjustmentNote = '';

        $this->addonProductAdjustmentId = '';
        $this->addonProviderRef = '';
        $this->addonAdjustmentNote = '';
    }

    /**
     * @return array{app:int,api:int}
     */
    protected function servicePlanAllowances(?ServicePlan $plan): array
    {
        return [
            'app' => max(0, (int) ($plan?->appMonthlyCredits() ?? $plan?->monthly_credits ?? 0)),
            'api' => max(0, (int) ($plan?->apiMonthlyCredits() ?? 0)),
        ];
    }

    protected function creditSyncStatusMessage(array $syncResult, string $prefix): string
    {
        return sprintf(
            '%s %s',
            $prefix,
            __('Added :app app credits and :api API credits.', [
                'app' => number_format((int) ($syncResult['app_added_credits'] ?? 0)),
                'api' => number_format((int) ($syncResult['api_added_credits'] ?? 0)),
            ])
        );
    }

    protected function validateNoRefillReconciliationState(
        Customer $customer,
        ServicePlan $plan,
        ?CustomerServiceSubscription $activeSubscription,
    ): ?string {
        if (! $activeSubscription instanceof CustomerServiceSubscription) {
            return __('No active local service subscription exists. Refusing a no-refill reconciliation.');
        }

        if ((int) $activeSubscription->service_plan_id !== (int) $plan->id) {
            return __('The active local service subscription does not match the target payment plan.');
        }

        if ((int) ($customer->currentServicePlanId() ?? 0) !== (int) $plan->id) {
            return __('The customer current service plan does not match the target payment plan.');
        }

        $appWallet = $customer->wallet()->first();
        $apiWallet = $customer->apiWallet()->first();

        if ((int) ($appWallet?->subscription_balance_credits ?? 0) < $plan->appMonthlyCredits()) {
            return __('The app wallet subscription balance is lower than the plan allowance. Refusing a no-refill reconciliation.');
        }

        if ((int) ($apiWallet?->subscription_balance_credits ?? 0) < $plan->apiMonthlyCredits()) {
            return __('The API wallet subscription balance is lower than the plan allowance. Refusing a no-refill reconciliation.');
        }

        return null;
    }

    #[Computed]
    public function paidReconciliationPreview(): ?array
    {
        $paymentId = (int) $this->paidReconciliationPaymentId;

        if ($paymentId <= 0) {
            return null;
        }

        $payment = Payment::query()
            ->with(['customer.activeServiceSubscription.servicePlan', 'purchasable'])
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            return [
                'missing' => true,
            ];
        }

        $customer = $payment->customer;
        $targetPlan = $payment->purchasable instanceof ServicePlan ? $payment->purchasable : null;
        $currentPlan = $customer?->currentServicePlan();
        $warnings = [];

        if ($payment->fulfilled_at !== null) {
            $warnings[] = __('Already fulfilled. Do not apply again.');
        }

        if ($targetPlan && $currentPlan && (int) $currentPlan->id === (int) $targetPlan->id) {
            $warnings[] = __('No plan switch needed unless reconciling status only.');
        }

        if ($targetPlan && $currentPlan && (int) $currentPlan->id !== (int) $targetPlan->id) {
            $warnings[] = __('This action may change the customer plan.');
        }

        if ($this->paidReconciliationMode === 'manual_correction_already_applied') {
            $warnings[] = __('Wallets will not be refilled.');
        }

        if ($this->paidReconciliationMode === 'apply_fulfillment_once') {
            $warnings[] = __('Wallets may be refilled once.');
        }

        return [
            'payment_id' => $payment->id,
            'customer_display' => $customer ? $this->customerIdentityLabel($customer) : __('Unknown customer'),
            'plan_from_payment' => $targetPlan?->name ?? __('n/a'),
            'current_customer_plan' => $currentPlan?->name ?? __('No active plan'),
            'payment_status' => $payment->status?->value ?? __('n/a'),
            'internal_status' => $payment->internal_status?->value ?? __('n/a'),
            'provider_subscription_status' => $payment->provider_subscription_status ?? $payment->provider_status ?? __('n/a'),
            'fib_subscription_id' => $payment->fib_subscription_id ?: __('n/a'),
            'paid_at' => $payment->paid_at?->format('M d, Y H:i') ?? __('n/a'),
            'fulfilled_at' => $payment->fulfilled_at?->format('M d, Y H:i') ?? __('n/a'),
            'fulfilled' => $payment->fulfilled_at !== null,
            'warnings' => $warnings,
        ];
    }

    #[Computed]
    public function selectedReviewPayment(): ?array
    {
        $paymentId = (int) $this->reviewPaymentId;

        if ($paymentId <= 0) {
            return null;
        }

        $payment = Payment::query()
            ->with(['customer', 'purchasable'])
            ->find($paymentId);

        if (! $payment instanceof Payment) {
            return [
                'missing' => true,
            ];
        }

        $snapshot = $payment->snapshot();

        return [
            'payment_id' => (int) $payment->id,
            'customer' => $payment->customer ? $this->customerIdentityLabel($payment->customer) : __('Unknown customer'),
            'intended_item' => (string) data_get($snapshot, 'name', __('n/a')),
            'local_reference' => (string) ($payment->local_reference ?? __('n/a')),
            'fib_payment_id' => (string) ($payment->fib_payment_id ?? __('n/a')),
            'fib_subscription_id' => (string) ($payment->fib_subscription_id ?? __('n/a')),
            'provider_status' => (string) ($payment->providerStatusLabel() ?? __('n/a')),
            'callback_payload' => $this->encodeJsonTextarea($payment->callback_payload),
            'status_response' => $this->encodeJsonTextarea($payment->status_response),
            'created_at' => $payment->created_at?->format('M d, Y H:i') ?? __('n/a'),
            'paid_at' => $payment->paid_at?->format('M d, Y H:i') ?? __('n/a'),
            'fulfilled_at' => $payment->fulfilled_at?->format('M d, Y H:i') ?? __('n/a'),
            'reason' => $payment->reviewMessage() ?? __('n/a'),
            'requires_open_review' => $payment->requiresOpenReview(),
        ];
    }

    protected function manualGrantReasonOptions(): array
    {
        return [
            'internal_team_account' => __('Internal team account'),
            'company_account' => __('Company account'),
            'testing_account' => __('Testing account'),
            'partner_access' => __('Partner access'),
            'founder_admin_access' => __('Founder/admin access'),
            'other' => __('Other'),
        ];
    }

    protected function encodeJsonTextarea($value): string
    {
        if (! $value) {
            return '';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function buildManualGrantReason(string $reasonCode, string $otherReason = '', string $additionalNote = ''): string
    {
        $reasonLabel = $this->manualGrantReasonOptions()[$reasonCode] ?? __('Manual grant');
        $parts = [$reasonLabel];

        if ($reasonCode === 'other' && $otherReason !== '') {
            $parts[] = $otherReason;
        }

        if ($additionalNote !== '') {
            $parts[] = $additionalNote;
        }

        return trim(implode(' — ', array_filter($parts, fn ($value) => trim((string) $value) !== '')));
    }

    protected function recordAdminPaidReconciliationMeta(Payment $payment, string $mode, string $reason): void
    {
        $payment = $payment->fresh() ?? $payment;
        $meta = array_merge((array) ($payment->meta ?? []), [
            'billing_source' => 'admin_paid_reconciliation',
            'revenue_record' => true,
            'provider' => 'fib',
            'fib_subscription_id' => $payment->fib_subscription_id,
            'manual_correction_already_applied' => $mode === 'manual_correction_already_applied',
            'no_credit_refill' => $mode === 'manual_correction_already_applied',
            'admin_id' => auth('admin')->id(),
            'reason' => $reason,
            'reconciled_at' => now()->toIso8601String(),
        ]);

        $payment->forceFill(['meta' => $meta])->save();

        $subscription = CustomerServiceSubscription::query()
            ->where('payment_id', $payment->id)
            ->latest('id')
            ->first();

        if ($subscription instanceof CustomerServiceSubscription) {
            $subscription->forceFill([
                'meta' => array_merge((array) ($subscription->meta ?? []), [
                    'billing_source' => 'admin_paid_reconciliation',
                    'revenue_record' => true,
                    'provider' => 'fib',
                    'fib_subscription_id' => $payment->fib_subscription_id,
                    'manual_correction_already_applied' => $mode === 'manual_correction_already_applied',
                    'no_credit_refill' => $mode === 'manual_correction_already_applied',
                    'admin_id' => auth('admin')->id(),
                    'reason' => $reason,
                    'last_provider_sync_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }
    }
}
