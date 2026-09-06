<?php

namespace App\Support\Admin;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\CreditProduct;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Payments\ManualRevenueReclassificationService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerRegisterPage
{
    use InteractsWithCustomerAdmin;
    use SecureAdminComponent;

    #[\Livewire\Attributes\Locked]
    public array $adminIntentIds = [];

    public string $creditSyncReason = '';

    public string $addonClassification = 'no_revenue';

    public string $addonPaymentId = '';

    public string $storageClassification = 'no_revenue';

    public string $storagePaymentId = '';

    public function startNewCorrection(): void
    {
        $admin = AdminAccess::authorize('admin.read');
        if (! \Illuminate\Support\Facades\Gate::forUser($admin)->allows('admin.reconcile')) {
            AdminAccess::authorize('admin.finance');
        }
        $this->newCorrectionIdentities();
    }

    protected function newCorrectionIdentities(): void
    {
        foreach (['plan', 'addon', 'storage', 'credits', 'invalidate', 'reference', 'reconcile', 'non_revenue'] as $action) {
            $this->adminIntentIds[$action] = (string) \Illuminate\Support\Str::uuid();
        }
    }

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
        $this->newCorrectionIdentities();
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
        \App\Support\Admin\AdminAccess::authorize('admin.finance');

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
        $syncResult = app(\App\Services\Admin\AdminFinancialCorrections::class)->plan(
            $this->adminIntentIds['plan'], $customer->id, $plan->id, $billingCycle, $adminNote
        );
        $this->dispatch('alert', type: 'success', message: $this->creditSyncStatusMessage(
            $syncResult, __('Manual plan grant applied successfully without creating a revenue/provider record.')
        ));
    }

    public function applyStoragePlanAdjustment(): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.finance');
        $customer = $this->resolveFocusedCustomer();
        if (! $customer) {
            return;
        }
        $this->validate([
            'storagePlanAdjustmentId' => 'required|integer', 'storagePlanBillingCycle' => 'required|in:monthly,yearly',
            'storagePlanAdjustmentNote' => 'required|string|min:10|max:500',
            'storageClassification' => 'required|in:no_revenue,verified_paid', 'storagePaymentId' => 'nullable|integer',
        ]);
        app(\App\Services\Admin\AdminFinancialCorrections::class)->storage(
            $this->adminIntentIds['storage'], $customer->id, (int) $this->storagePlanAdjustmentId,
            $this->storagePlanBillingCycle, trim($this->storagePlanAdjustmentNote), $this->storageClassification,
            $this->storagePaymentId !== '' ? (int) $this->storagePaymentId : null
        );
        $this->dispatch('alert', type: 'success', message: __('Storage plan updated successfully for this customer.'));
    }

    public function applyAddonAdjustment(): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.finance');
        $customer = $this->resolveFocusedCustomer();
        if (! $customer) {
            return;
        }
        $this->validate([
            'addonProductAdjustmentId' => 'required|integer', 'addonAdjustmentNote' => 'required|string|min:10|max:500',
            'addonClassification' => 'required|in:no_revenue,verified_paid', 'addonPaymentId' => 'nullable|integer',
        ]);
        app(\App\Services\Admin\AdminFinancialCorrections::class)->addon(
            $this->adminIntentIds['addon'], $customer->id, (int) $this->addonProductAdjustmentId,
            trim($this->addonAdjustmentNote), $this->addonClassification,
            $this->addonPaymentId !== '' ? (int) $this->addonPaymentId : null
        );
        $this->dispatch('alert', type: 'success', message: __('Addon credits added successfully for this customer.'));
    }

    public function syncCustomerCreditsToPlan(int $customerId): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.finance');
        $this->validate(['creditSyncReason' => 'required|string|min:10|max:500']);
        $result = app(\App\Services\Admin\AdminFinancialCorrections::class)->credits(
            $this->adminIntentIds['credits'], $customerId, trim($this->creditSyncReason)
        );
        $this->dispatch('alert', type: 'success', message: $this->creditSyncStatusMessage($result, __('Synced successfully.')));
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
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');

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
            if ($mode === 'manual_correction_already_applied' && ! $this->paidReconciliationStatusOnlyConfirmation) {
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

        $this->paidReconciliationPaymentId = (string) $reconciled->id;
        $this->paidReconciliationFibSubscriptionId = (string) ($reconciled->fib_subscription_id ?? '');

        $message = $mode === 'manual_correction_already_applied'
            ? __('Paid FIB subscription reconnected without a duplicate credit refill.')
            : __('Paid FIB subscription applied successfully with one fulfillment pass.');

        $this->dispatch('alert', type: 'success', message: $message);
    }

    public function repairPaidSubscription(int $paymentId): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');

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
            $this->dispatch('alert', type: 'warning', message: AdminData::redact($repaired->reviewMessage() ?? __('The subscription now requires manual review.')));

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
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
        $customer = $this->resolveFocusedCustomer();
        if (! $customer) {
            return;
        }
        $this->validate(['reviewPaymentId' => 'required|integer', 'reviewResolutionReason' => 'required|string|min:10|max:500',
            'reviewCorrectFibSubscriptionId' => 'nullable|string|max:190', 'reviewCorrectFibPaymentId' => 'nullable|string|max:190',
            'reviewReconnectMode' => 'required|in:manual_correction_already_applied,apply_fulfillment_once']);
        $payment = Payment::query()->where('customer_id', $customer->id)->findOrFail((int) $this->reviewPaymentId);
        if ($payment->isProviderSubscriptionObject()
            && trim($this->reviewCorrectFibPaymentId) !== (string) ($payment->fib_payment_id ?? '')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['providerReference' => __('admin_p0.provider_mismatch')]);
        }
        $candidate = $payment->isProviderSubscriptionObject() ? $this->reviewCorrectFibSubscriptionId : $this->reviewCorrectFibPaymentId;
        app(\App\Services\Admin\AdminPaymentReconciliation::class)->handle(
            $this->adminIntentIds['reference'], $customer->id, $payment->id, trim($candidate), $this->reviewReconnectMode, trim($this->reviewResolutionReason), true
        );
        unset($this->selectedReviewPayment, $this->selectedCustomer);
        $this->dispatch('alert', type: 'success', message: __('The correct FIB reference was attached safely and the payment was updated without duplicate fulfillment.'));
    }

    public function markReviewPaymentInvalid(): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
        $customer = $this->resolveFocusedCustomer();
        if (! $customer) {
            return;
        }
        $this->validate(['reviewPaymentId' => 'required|integer', 'reviewResolutionReason' => 'required|string|min:10|max:500']);
        app(\App\Domain\Payments\Actions\InvalidateAdminReviewPayment::class)->handle(
            $this->adminIntentIds['invalidate'], $customer->id, (int) $this->reviewPaymentId, trim($this->reviewResolutionReason)
        );
        unset($this->selectedReviewPayment, $this->selectedCustomer);
        $this->dispatch('alert', type: 'success', message: __('The review payment was marked invalid/expired and removed from actionable review processing.'));
    }

    public function markReviewPaymentNonRevenue(): void
    {
        \App\Support\Admin\AdminAccess::authorize('admin.reconcile');
        $customer = $this->resolveFocusedCustomer();
        if (! $customer) {
            return;
        }
        $this->validate(['reviewPaymentId' => 'required|integer', 'reviewResolutionReason' => 'required|string|min:10|max:500']);
        $paymentId = (int) $this->reviewPaymentId;
        $reason = trim($this->reviewResolutionReason);
        $id = $this->adminIntentIds['non_revenue'];
        app(\App\Services\Admin\AdminOperationRunner::class)->run($id, 'admin.reconcile', 'payment.non_revenue', $customer->id,
            ['payment_id' => $paymentId], $reason, function () use ($customer, $paymentId, $reason, $id) {
                $payment = Payment::query()->lockForUpdate()->where('customer_id', $customer->id)->findOrFail($paymentId);
                $before = $payment->status?->value;
                app(ManualRevenueReclassificationService::class)->execute($payment, $customer->id, 'manual_grant', $reason, false);
                $payment->refresh();
                $payment->forceFill(['review_required_at' => null])->save();
                app(PaymentEventRecorder::class)->record($payment, [
                    'event_type' => 'admin_payment_marked_non_revenue', 'source' => 'admin_customer_register_review',
                    'event_key' => 'admin-non-revenue:'.$id, 'before_status' => $before, 'after_status' => $payment->status?->value,
                    'meta' => ['admin_id' => auth('admin')->id(), 'operation_id' => $id, 'reason' => $reason],
                ]);

                return ['payment_id' => $payment->id];
            });
        unset($this->selectedReviewPayment, $this->selectedCustomer);
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

        return $payment;
    }

    protected function reconcilePaidSubscriptionWithFulfillment(Payment $payment, string $fibSubscriptionId, string $reason): ?Payment
    {
        return app(\App\Services\Admin\AdminPaymentReconciliation::class)->handle(
            $this->adminIntentIds['reconcile'], (int) $payment->customer_id, (int) $payment->id,
            $fibSubscriptionId, 'apply_fulfillment_once', $reason
        );
    }

    protected function reconcilePaidSubscriptionWithoutRefill(Payment $payment, string $fibSubscriptionId, string $reason): ?Payment
    {
        return app(\App\Services\Admin\AdminPaymentReconciliation::class)->handle(
            $this->adminIntentIds['reconcile'], (int) $payment->customer_id, (int) $payment->id,
            $fibSubscriptionId, 'manual_correction_already_applied', $reason
        );
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
            'callback_payload' => $this->encodeJsonTextarea(\App\Support\Admin\AdminData::diagnostics($payment->callback_payload)),
            'status_response' => $this->encodeJsonTextarea(\App\Support\Admin\AdminData::diagnostics($payment->status_response)),
            'created_at' => $payment->created_at?->format('M d, Y H:i') ?? __('n/a'),
            'paid_at' => $payment->paid_at?->format('M d, Y H:i') ?? __('n/a'),
            'fulfilled_at' => $payment->fulfilled_at?->format('M d, Y H:i') ?? __('n/a'),
            'reason' => \App\Support\Admin\AdminData::redact($payment->reviewMessage() ?? __('n/a')),
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

        return (string) json_encode(\App\Support\Admin\AdminData::redact($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
