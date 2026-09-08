<?php

use App\Support\Admin\ManagesCustomerRegisterPage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesCustomerRegisterPage;
    use WithPagination;

    protected $paginationTheme = 'bootstrap';
};
?>

<x-slot:title>{{ __('Customers Register') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="container-fluid">
    <div wire:loading.delay class="small text-muted mb-2" role="status" aria-live="polite">{{ __('admin_p2.loading') }}</div>
    <x-admin-customer-context :customer-id="(int) $customerFilter" :name="$this->selectedCustomer?->username" />
    <div class="mb-3">
        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="startNewCorrection" @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance') && ! \App\Support\Admin\AdminUiAccess::can('admin.reconcile')) disabled @endif>{{ __('admin_p0.new_correction') }}</button>
        <label class="form-label ms-2" for="admin-credit-reason">{{ __('admin_p0.sync_reason') }}</label>
        <input id="admin-credit-reason" class="form-control" wire:model="creditSyncReason" maxlength="500" dir="auto">
        @error('creditSyncReason') <div class="text-danger">{{ $message }}</div> @enderror
        @foreach (['operation', 'providerReference', 'classification', 'paymentId', 'billingCycle', 'delete'] as $errorKey)
            @error($errorKey) <div class="text-danger">{{ $message }}</div> @enderror
        @endforeach
    </div>
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <div>
                    <h4 class="mb-sm-0">{{ __('Customer Billing Register') }}</h4>
                    <p class="text-muted mb-0">{{ __('Track customer onboarding and apply manual billing corrections when a provider payment succeeded but local plan state did not update.') }}</p>
                </div>
                <div class="page-title-right d-flex align-items-center gap-2">
                    <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale()]) }}" class="btn btn-soft-secondary">{{ __('Back to List') }}</a>
                    @if ($this->selectedCustomer)
                        <button type="button" class="btn btn-soft-info" wire:click="clearFocusedCustomer">{{ __('Clear Focus') }}</button>
                    @endif
                    <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">{{ __('Clear Filters') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Customers') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['customers']) }}</h2>
                    <p class="text-muted mb-0">{{ __('All registered customer accounts.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Joined In 30 Days') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['new_30d']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Recent registrations for onboarding review.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Profile Coverage') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['profiles']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Customers with linked profile data.') }}</p>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card card-animate h-100">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-1">{{ __('Countries') }}</p>
                    <h2 class="mb-1">{{ number_format($this->topStats['countries']) }}</h2>
                    <p class="text-muted mb-0">{{ __('Distinct profile countries represented in the registry.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header border-0">
            <div class="row g-3 align-items-end">
                <div class="col-xl-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-register-1">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search customer, plan, location, or profile...') }}" id="admin-field-adm-customers-register-1">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-register-2">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter" id="admin-field-adm-customers-register-2">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="suspended">{{ __('Suspended') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-register-3">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter" id="admin-field-adm-customers-register-3">
                        <option value="all">{{ __('All plans') }}</option>
                        <option value="none">{{ __('No active plan') }}</option>
                        @foreach ($this->customerPlanOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-register-4">{{ __('Country') }}</label>
                    <select class="form-select" wire:model.live="countryFilter" id="admin-field-adm-customers-register-4">
                        <option value="all">{{ __('All countries') }}</option>
                        @foreach ($this->customerCountryOptions as $country)
                            <option value="{{ $country }}">{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12" for="admin-field-adm-customers-register-5">{{ __('Joined') }}</label>
                    <select class="form-select" wire:model.live="joinedFilter" id="admin-field-adm-customers-register-5">
                        <option value="all">{{ __('All time') }}</option>
                        <option value="7">{{ __('Last 7 days') }}</option>
                        <option value="30">{{ __('Last 30 days') }}</option>
                        <option value="90">{{ __('Last 90 days') }}</option>
                        <option value="365">{{ __('Last 12 months') }}</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    @if ($this->selectedCustomer)
        @php
            $focusedCustomer = $this->selectedCustomer;
            $focusedWallet = $focusedCustomer->wallet;
            $focusedApiWallet = $focusedCustomer->apiWallet;
            $focusedPlan = $focusedCustomer->servicePlan;
            $focusedStoragePlan = $focusedCustomer->activeStorageSubscription?->storagePlan ?? $focusedCustomer->currentStoragePlan();
        @endphp
        <div class="row mb-3">
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('Focused Customer') }}</h5>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-1">{{ $this->customerDisplayName($focusedCustomer) }}</h5>
                        <p class="text-muted mb-3">{{ '@' . $focusedCustomer->username }} • {{ $focusedCustomer->email }}</p>
                        <div class="mb-2"><span class="fw-semibold">{{ __('UID:') }}</span> {{ $focusedCustomer->uid ?? __('n/a') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Joined:') }}</span> {{ $focusedCustomer->created_at?->format('M d, Y H:i') ?? __('n/a') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Location:') }}</span> {{ $this->customerLocation($focusedCustomer) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Phone:') }}</span> {{ data_get($focusedCustomer, 'profile.phone_number', __('Not set')) }}</div>
                        <div><span class="badge {{ $this->customerStatusBadgeClasses($focusedCustomer->status) }}">{{ $this->customerStatusLabel($focusedCustomer->status) }}</span></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('Account Snapshot') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2"><span class="fw-semibold">{{ __('Service Plan:') }}</span> {{ $focusedPlan?->name ?? __('No active plan') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Storage Plan:') }}</span> {{ $focusedStoragePlan?->name ?? __('No active storage plan') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('App Wallet Balance:') }}</span> {{ __(':credits credits', ['credits' => $this->formatCredits($focusedWallet?->balance_credits)]) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('App Subscription Bucket:') }}</span> {{ $this->formatCredits($focusedWallet?->subscription_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('App Addon Bucket:') }}</span> {{ $this->formatCredits($focusedWallet?->addon_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('API Wallet Balance:') }}</span> {{ __(':credits credits', ['credits' => $this->formatCredits($focusedApiWallet?->balance_credits)]) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('API Subscription Bucket:') }}</span> {{ $this->formatCredits($focusedApiWallet?->subscription_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('API Addon Bucket:') }}</span> {{ $this->formatCredits($focusedApiWallet?->addon_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Storage Used:') }}</span> {{ $this->formatBytes(data_get($focusedCustomer, 'usage.storage_used_bytes')) }}</div>
                        <div><span class="fw-semibold">{{ __('Paid Orders:') }}</span> {{ number_format((int) ($focusedCustomer->paid_orders_count ?? 0)) }}</div>
                        <div class="mt-3">
                            <button
                                type="button"
                                class="btn btn-soft-primary w-100"
                                data-admin-target="{{ $focusedCustomer->username }}" data-admin-method="syncCustomerCreditsToPlan" data-admin-args="{{ json_encode([$focusedCustomer->id]) }}" data-admin-impact="{{ __('This will sync the customer\'s subscription credits with their current plan. It will add missing plan credits only when the current subscription balance is lower than the plan allowance. It will not subtract existing credits or remove add-on credits.') }}"
                             @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance')) disabled @endif>
                                {{ __('Sync Credits To Plan') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('Verification and Activity') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2"><span class="fw-semibold">{{ __('Email verification:') }}</span> {{ $focusedCustomer->email_verify ? __('Verified') : __('Pending') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Phone verification:') }}</span> {{ $focusedCustomer->phone_verify ? __('Verified') : __('Pending') }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Jobs:') }}</span> {{ number_format((int) ($focusedCustomer->jobs_count ?? 0)) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Credits consumed:') }}</span> {{ $this->formatCredits($focusedCustomer->consumed_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Subscription records:') }}</span> {{ number_format((int) ($focusedCustomer->service_subscriptions_count ?? 0)) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Storage records:') }}</span> {{ number_format((int) ($focusedCustomer->storage_subscriptions_count ?? 0)) }}</div>
                        <div class="small text-muted">{{ __('Plans :plans | Storage :storage | Addons :addons', ['plans' => $this->formatMoney($focusedCustomer->service_plan_amount_spent), 'storage' => $this->formatMoney($focusedCustomer->storage_amount_spent), 'addons' => $this->formatMoney($focusedCustomer->credit_product_amount_spent)]) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning-subtle border-0">
                <h5 class="card-title mb-1">{{ __('Manual Billing Actions') }}</h5>
                <p class="text-muted mb-0">{{ __('Use Manual Grant for internal/non-revenue access and Paid Customer Reconciliation only for real FIB revenue that must be connected safely.') }}</p>
            </div>
            <div class="card-body">
                @php
                    $paidPreview = $this->paidReconciliationPreview;
                    $paidConfirmMessage = $paidReconciliationMode === 'manual_correction_already_applied'
                        ? __('This will mark/connect the real FIB payment locally without adding credits. Use this only if the customer was already manually corrected.')
                        : __('This will apply the real FIB payment and run fulfillment once. This may change the customer plan and refill app/API credits.');
                @endphp
                <div class="row g-3">
                    <div class="col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-2">{{ __('Manual Plan Grant — No Revenue') }}</h6>
                            <div class="alert alert-warning small mb-3">
                                <div>{{ __('This action does not create revenue.') }}</div>
                                <div>{{ __('This action does not create a FIB payment.') }}</div>
                                <div>{{ __('This action does not connect to a provider subscription.') }}</div>
                                <div>{{ __('Use this only for internal/company/testing/partner access.') }}</div>
                            </div>
                            <div class="fw-semibold mb-3">{{ __('Manual Grant — No Revenue / No Provider Subscription') }}</div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-6">{{ __('Customer') }}</label>
                                <input type="text" class="form-control" value="{{ $this->customerIdentityLabel($focusedCustomer) }}" readonly id="admin-field-adm-customers-register-6">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-7">{{ __('Service Plan') }}</label>
                                <select class="form-select" wire:model="servicePlanAdjustmentId" data-admin-review id="admin-field-adm-customers-register-7">
                                    <option value="">{{ __('Select plan') }}</option>
                                    @foreach ($this->registerServicePlanOptions as $plan)
                                        <option value="{{ $plan->id }}">
                                            {{ $plan->name }} ({{ $this->formatCredits($plan->monthly_credits) }} {{ __('credits') }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('servicePlanAdjustmentId')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-8">{{ __('Reason') }}</label>
                                <select class="form-select" wire:model="servicePlanGrantReason" id="admin-field-adm-customers-register-8">
                                    <option value="">{{ __('Select reason') }}</option>
                                    <option value="internal_team_account">{{ __('Internal team account') }}</option>
                                    <option value="company_account">{{ __('Company account') }}</option>
                                    <option value="testing_account">{{ __('Testing account') }}</option>
                                    <option value="partner_access">{{ __('Partner access') }}</option>
                                    <option value="founder_admin_access">{{ __('Founder/admin access') }}</option>
                                    <option value="other">{{ __('Other') }}</option>
                                </select>
                                @error('servicePlanGrantReason')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            @if ($servicePlanGrantReason === 'other')
                                <div class="mb-3">
                                    <label class="form-label" for="admin-field-adm-customers-register-9">{{ __('Other reason explanation') }}</label>
                                    <textarea class="form-control" rows="3" wire:model.defer="servicePlanGrantReasonOther" placeholder="{{ __('Required explanation for Other...') }}" id="admin-field-adm-customers-register-9" dir="auto"></textarea>
                                    @error('servicePlanGrantReasonOther')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endif
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-10">{{ __('Credit Sync Policy') }}</label>
                                <select class="form-select" wire:model="servicePlanCreditSyncPolicy" id="admin-field-adm-customers-register-10">
                                    <option value="safe_top_up_only">{{ __('Safe top-up only — never subtract') }}</option>
                                </select>
                                @error('servicePlanCreditSyncPolicy')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-11">{{ __('Billing Cycle') }}</label>
                                <select class="form-select" wire:model="servicePlanBillingCycle" data-admin-review id="admin-field-adm-customers-register-11">
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                    <option value="yearly">{{ __('Yearly') }}</option>
                                </select>
                                @error('servicePlanBillingCycle')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-12">{{ __('Additional note (optional)') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="servicePlanAdjustmentNote" placeholder="{{ __('Optional internal context for audit history...') }}" id="admin-field-adm-customers-register-12" dir="auto"></textarea>
                                @error('servicePlanAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="small text-muted mb-3">{{ __('You are about to grant this customer a plan without revenue and without a provider subscription. No FIB payment will be created. No fib_subscription_id will be attached.') }}</div>
                            <button
                                type="button"
                                class="btn btn-primary w-100"
                                data-admin-target="{{ $focusedCustomer?->username }}" data-admin-method="applyServicePlanAdjustment" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('You are about to grant this customer a plan without revenue and without a provider subscription. No FIB payment will be created. No fib_subscription_id will be attached. Continue?') }}"
                             @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance')) disabled @endif>
                                {{ __('Apply Manual Grant') }}
                            </button>
                        </div>
                    </div>
                    <div class="col-xl-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-2">{{ __('Paid Customer Reconciliation — Real FIB Payment') }}</h6>
                            <div class="alert alert-warning small mb-3">
                                <div>{{ __('Use this only when the customer actually paid through FIB.') }}</div>
                                <div>{{ __('This keeps or connects a real revenue/payment record.') }}</div>
                                <div>{{ __('This can mark a provider-paid subscription as locally applied.') }}</div>
                                <div>{{ __('Be careful not to duplicate credits.') }}</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-13">{{ __('Customer') }}</label>
                                <input type="text" class="form-control" value="{{ $this->customerIdentityLabel($focusedCustomer) }}" readonly id="admin-field-adm-customers-register-13">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-14">{{ __('Local Payment ID') }}</label>
                                <input type="number" min="1" class="form-control" wire:model.defer="paidReconciliationPaymentId" placeholder="{{ __('Existing payment id') }}" id="admin-field-adm-customers-register-14">
                                @error('paidReconciliationPaymentId')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            @if (is_array($paidPreview) && ! data_get($paidPreview, 'missing'))
                                <div class="border rounded p-3 bg-light-subtle mb-3">
                                    <div class="fw-semibold mb-2">{{ __('Plan Preview') }}</div>
                                    <div class="row g-2 small">
                                        <div class="col-md-6"><span class="text-muted">{{ __('Payment ID') }}:</span> {{ data_get($paidPreview, 'payment_id') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Customer') }}:</span> {{ data_get($paidPreview, 'customer_display') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Plan from payment') }}:</span> {{ data_get($paidPreview, 'plan_from_payment') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Current customer plan') }}:</span> {{ data_get($paidPreview, 'current_customer_plan') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Payment status') }}:</span> {{ data_get($paidPreview, 'payment_status') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Internal status') }}:</span> {{ data_get($paidPreview, 'internal_status') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('Provider subscription status') }}:</span> {{ data_get($paidPreview, 'provider_subscription_status') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('fib_subscription_id') }}:</span> {{ data_get($paidPreview, 'fib_subscription_id') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('paid_at') }}:</span> {{ data_get($paidPreview, 'paid_at') }}</div>
                                        <div class="col-md-6"><span class="text-muted">{{ __('fulfilled_at') }}:</span> {{ data_get($paidPreview, 'fulfilled_at') }}</div>
                                    </div>
                                    @foreach ((array) data_get($paidPreview, 'warnings', []) as $warning)
                                        <div class="alert alert-secondary py-2 px-3 mt-2 mb-0 small">{{ $warning }}</div>
                                    @endforeach
                                </div>
                            @elseif (is_array($paidPreview) && data_get($paidPreview, 'missing'))
                                <div class="alert alert-danger small mb-3">{{ __('The selected payment could not be found for preview.') }}</div>
                            @endif
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-15">{{ __('FIB Subscription ID') }}</label>
                                <input type="text" class="form-control" wire:model.defer="paidReconciliationFibSubscriptionId" placeholder="{{ __('Real fib_subscription_id') }}" id="admin-field-adm-customers-register-15" dir="ltr">
                                @error('paidReconciliationFibSubscriptionId')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-16">{{ __('Reconciliation Mode') }}</label>
                                <select class="form-select" wire:model="paidReconciliationMode" data-admin-review id="admin-field-adm-customers-register-16">
                                    <option value="manual_correction_already_applied">{{ __('Manual correction already applied — no credit refill') }}</option>
                                    <option value="apply_fulfillment_once">{{ __('Apply fulfillment and credits once') }}</option>
                                </select>
                                @error('paidReconciliationMode')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            @if (is_array($paidPreview) && data_get($paidPreview, 'fulfilled'))
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="paid-reconciliation-status-only" wire:model="paidReconciliationStatusOnlyConfirmation">
                                    <label class="form-check-label small" for="paid-reconciliation-status-only">
                                        {{ __('I confirm this is status-only reconciliation for an already fulfilled payment.') }}
                                    </label>
                                </div>
                                @error('paidReconciliationStatusOnlyConfirmation')
                                    <div class="text-danger small mt-n2 mb-3">{{ $message }}</div>
                                @enderror
                            @endif
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-17">{{ __('Reason') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="paidReconciliationReason" placeholder="{{ __('Required reason for this paid reconciliation...') }}" id="admin-field-adm-customers-register-17" dir="auto"></textarea>
                                @error('paidReconciliationReason')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="small text-muted mb-3">{{ $paidConfirmMessage }}</div>
                            <button
                                type="button"
                                class="btn btn-warning w-100"
                                data-admin-target="{{ $focusedCustomer?->username }}" data-admin-method="applyPaidSubscriptionReconciliation" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ $paidConfirmMessage }}"
                             @if(! \App\Support\Admin\AdminUiAccess::can('admin.reconcile')) disabled @endif>
                                {{ __('Reconcile Paid FIB Subscription') }}
                            </button>
                        </div>
                    </div>
                    <div class="col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Storage Subscription (Recurring)') }}</h6>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-18">{{ __('Storage Plan') }}</label>
                                <select class="form-select" wire:model="storagePlanAdjustmentId" data-admin-review id="admin-field-adm-customers-register-18">
                                    <option value="">{{ __('Select storage plan') }}</option>
                                    @foreach ($this->registerStoragePlanOptions as $plan)
                                        <option value="{{ $plan->id }}">
                                            {{ $plan->name }} ({{ number_format((int) $plan->quota_mb) }} MB)
                                        </option>
                                    @endforeach
                                </select>
                                @error('storagePlanAdjustmentId')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-19">{{ __('Billing Cycle') }}</label>
                                <select class="form-select" wire:model="storagePlanBillingCycle" data-admin-review id="admin-field-adm-customers-register-19">
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                    <option value="yearly">{{ __('Yearly') }}</option>
                                </select>
                                @error('storagePlanBillingCycle')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-20">{{ __('admin_p0.classification') }}</label>
                                <select class="form-select" wire:model="storageClassification" id="admin-field-adm-customers-register-20">
                                    <option value="no_revenue">{{ __('admin_p0.no_revenue') }}</option>
                                    <option value="verified_paid">{{ __('admin_p0.verified_paid') }}</option>
                                </select>
                                <label class="form-label" for="admin-field-adm-customers-register-21">{{ __('admin_p0.payment_evidence') }}</label>
                                <input type="number" min="1" class="form-control" wire:model="storagePaymentId" id="admin-field-adm-customers-register-21">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-22">{{ __('Admin Note') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="storagePlanAdjustmentNote" placeholder="{{ __('Why this correction is being applied...') }}" id="admin-field-adm-customers-register-22" dir="auto"></textarea>
                                @error('storagePlanAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-primary w-100" data-admin-target="{{ $focusedCustomer?->username }}" data-admin-method="applyStoragePlanAdjustment" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.financial') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance')) disabled @endif>{{ __('Apply Storage Correction') }}</button>
                        </div>
                    </div>
                    <div class="col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Addon Credits (One-Time)') }}</h6>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-23">{{ __('Addon Package') }}</label>
                                <select class="form-select" wire:model="addonProductAdjustmentId" data-admin-review id="admin-field-adm-customers-register-23">
                                    <option value="">{{ __('Select addon pack') }}</option>
                                    @foreach ($this->registerAddonOptions as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->name }} ({{ $this->formatCredits($product->credits_amount) }} {{ __('credits') }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('addonProductAdjustmentId')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-24">{{ __('admin_p0.classification') }}</label>
                                <select class="form-select" wire:model="addonClassification" id="admin-field-adm-customers-register-24">
                                    <option value="no_revenue">{{ __('admin_p0.no_revenue') }}</option>
                                    <option value="verified_paid">{{ __('admin_p0.verified_paid') }}</option>
                                </select>
                                <label class="form-label" for="admin-field-adm-customers-register-25">{{ __('admin_p0.payment_evidence') }}</label>
                                <input type="number" min="1" class="form-control" wire:model="addonPaymentId" id="admin-field-adm-customers-register-25">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="admin-field-adm-customers-register-26">{{ __('Admin Note') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="addonAdjustmentNote" placeholder="{{ __('Why this correction is being applied...') }}" id="admin-field-adm-customers-register-26" dir="auto"></textarea>
                                @error('addonAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-primary w-100" data-admin-target="{{ $focusedCustomer?->username }}" data-admin-method="applyAddonAdjustment" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('admin_p3.financial') }}" @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance')) disabled @endif>{{ __('Apply Addon Correction') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-xl-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('Recent Subscription History') }}</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Plan') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Cycle') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($focusedCustomer->serviceSubscriptions as $subscription)
                                        <tr>
                                            <td><span class="badge bg-info-subtle text-info">{{ __('Service') }}</span></td>
                                            <td>{{ $subscription->servicePlan?->name ?? __('Unknown plan') }}</td>
                                            <td><span class="badge bg-light text-body">{{ $subscription->status }}</span></td>
                                            <td>{{ $subscription->cycle_started_on?->format('M d, Y') ?? __('n/a') }} - {{ $subscription->cycle_ends_on?->format('M d, Y') ?? __('n/a') }}</td>
                                        </tr>
                                    @empty
                                    @endforelse

                                    @forelse ($focusedCustomer->storageSubscriptions as $storageSubscription)
                                        <tr>
                                            <td><span class="badge bg-warning-subtle text-warning">{{ __('Storage') }}</span></td>
                                            <td>{{ $storageSubscription->storagePlan?->name ?? __('Unknown storage plan') }}</td>
                                            <td><span class="badge bg-light text-body">{{ $storageSubscription->status }}</span></td>
                                            <td>{{ $storageSubscription->cycle_started_on?->format('M d, Y') ?? __('n/a') }} - {{ $storageSubscription->cycle_ends_on?->format('M d, Y') ?? __('n/a') }}</td>
                                        </tr>
                                    @empty
                                    @endforelse

                                    @if ($focusedCustomer->serviceSubscriptions->isEmpty() && $focusedCustomer->storageSubscriptions->isEmpty())
                                        <tr>
                                            <td colspan="4" class="text-center py-3 text-muted">{{ __('No subscription history recorded.') }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6 mb-3">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('FIB Payment Ledger') }}</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Intended Item') }}</th>
                                        <th>{{ __('Provider State') }}</th>
                                        <th>{{ __('Application') }}</th>
                                        <th>{{ __('Current Customer Plan') }}</th>
                                        <th>{{ __('Dates') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($focusedCustomer->payments as $payment)
                                        @php
                                            $snapshot = $payment->snapshot();
                                            $intendedName = (string) data_get($snapshot, 'name', __('n/a'));
                                            $intendedCode = (string) data_get($snapshot, 'code', '');
                                            $providerReference = $payment->fib_subscription_id ?: $payment->fib_payment_id ?: __('n/a');
                                            $currentCustomerPlan = match ($payment->purchase_type?->value ?? $payment->purchase_type) {
                                                'storage_subscription' => $focusedCustomer->activeStorageSubscription?->storagePlan?->name,
                                                default => $focusedCustomer->activeServiceSubscription?->servicePlan?->name,
                                            };
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="small fw-semibold">{{ $providerReference }}</div>
                                                <div class="text-muted small">{{ $payment->uuid }}</div>
                                            </td>
                                            <td>
                                                <div class="small fw-semibold">{{ $intendedName }}</div>
                                                <div class="text-muted small">
                                                    {{ $intendedCode !== '' ? strtoupper($intendedCode) . ' · ' : '' }}
                                                    {{ \Illuminate\Support\Str::headline((string) ($payment->purchase_type?->value ?? $payment->purchase_type ?? __('n/a'))) }}
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <span class="badge bg-light text-body">{{ strtoupper((string) ($payment->providerStatusLabel() ?? __('n/a'))) }}</span>
                                                </div>
                                                <div class="text-muted small mt-1">{{ \Illuminate\Support\Str::headline((string) ($payment->payment_mode?->value ?? $payment->payment_mode ?? __('n/a'))) }}</div>
                                            </td>
                                            <td>
                                                <div>
                                                    <span class="badge {{ $payment->requiresReview() ? 'bg-warning-subtle text-warning' : ($payment->isApplied() ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info') }}">
                                                        {{ \Illuminate\Support\Str::headline($payment->applicationStatusLabel()) }}
                                                    </span>
                                                </div>
                                                <div class="text-muted small mt-1">{{ \Illuminate\Support\Str::headline((string) ($payment->status?->value ?? $payment->status ?? __('n/a'))) }}</div>
                                                @if ($payment->reviewMessage())
                                                    <div class="text-warning small mt-1">{{ \App\Support\Admin\AdminData::redact($payment->reviewMessage()) }}</div>
                                                @endif
                                                @if ($payment->requiresOpenReview())
                                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-soft-warning"
                                                            wire:click="openReviewPayment({{ $payment->id }})"
                                                        >
                                                            {{ __('Review') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-soft-info"
                                                            wire:click="openReviewPayment({{ $payment->id }})"
                                                        >
                                                            {{ __('Attach Correct FIB Reference') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-soft-secondary"
                                                            wire:click="openReviewPayment({{ $payment->id }})"
                                                        >
                                                            {{ __('Mark Invalid / Expired') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-soft-dark"
                                                            wire:click="openReviewPayment({{ $payment->id }})"
                                                        >
                                                            {{ __('Mark As Non-Revenue Internal Record') }}
                                                        </button>
                                                    </div>
                                                @endif
                                                @if ($payment->isProviderPaidButLocallyUnappliedSubscription())
                                                    <div class="text-danger small mt-1 fw-semibold">{{ __('Provider Paid / Local Not Applied') }}</div>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-soft-info mt-2"
                                                        wire:click="prefillPaidSubscriptionReconciliation({{ $payment->id }})"
                                                    >
                                                        {{ __('Open Paid Reconciliation') }}
                                                    </button>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="small fw-semibold">{{ $currentCustomerPlan ?: __('No active plan') }}</div>
                                                <div class="text-muted small">{{ __('Callback') }}: {{ $payment->last_callback_received_at?->diffForHumans() ?? __('n/a') }}</div>
                                                <div class="text-muted small">{{ __('Status check') }}: {{ $payment->last_status_checked_at?->diffForHumans() ?? __('n/a') }}</div>
                                            </td>
                                            <td>
                                                <div class="small">{{ __('Created') }}: {{ $payment->created_at?->format('M d, Y H:i') ?? __('n/a') }}</div>
                                                <div class="text-muted small">{{ __('Paid') }}: {{ $payment->paid_at?->format('M d, Y H:i') ?? __('n/a') }}</div>
                                                <div class="text-muted small">{{ __('Applied') }}: {{ $payment->fulfilled_at?->format('M d, Y H:i') ?? __('n/a') }}</div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-3 text-muted">{{ __('No recent payments for this customer.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @php($reviewSelection = $this->selectedReviewPayment)
        @if ($reviewSelection)
            <div class="card mb-3 border-warning">
                <div class="card-header bg-warning-subtle border-0">
                    <h5 class="card-title mb-1">{{ __('Review Payment') }}</h5>
                    <p class="text-muted mb-0">{{ __('Use this guided review workflow to reconnect the correct FIB reference, close invalid rows safely, or reclassify internal/manual records without deleting history.') }}</p>
                </div>
                <div class="card-body">
                    @if (data_get($reviewSelection, 'missing'))
                        <div class="alert alert-danger mb-0">{{ __('The selected review payment could not be loaded. Refresh the page and try again.') }}</div>
                    @else
                        <div class="row g-3 mb-3">
                            <div class="col-xl-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Payment Details') }}</h6>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Payment ID:') }}</span> {{ $reviewSelection['payment_id'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Customer:') }}</span> {{ $reviewSelection['customer'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Plan / Intended Item:') }}</span> {{ $reviewSelection['intended_item'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Local Reference:') }}</span> {{ $reviewSelection['local_reference'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Stored FIB Payment ID:') }}</span> {{ $reviewSelection['fib_payment_id'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Stored FIB Subscription ID:') }}</span> {{ $reviewSelection['fib_subscription_id'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Provider Status:') }}</span> {{ strtoupper((string) $reviewSelection['provider_status']) }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Created:') }}</span> {{ $reviewSelection['created_at'] }}</div>
                                    <div class="mb-2"><span class="fw-semibold">{{ __('Paid:') }}</span> {{ $reviewSelection['paid_at'] }}</div>
                                    <div><span class="fw-semibold">{{ __('Fulfilled:') }}</span> {{ $reviewSelection['fulfilled_at'] }}</div>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Review Guidance') }}</h6>
                                    <div class="alert {{ $reviewSelection['requires_open_review'] ? 'alert-warning' : 'alert-secondary' }} mb-3">
                                        <div class="fw-semibold mb-1">
                                            {{ $reviewSelection['requires_open_review'] ? __('Requires Review') : __('Review Closed') }}
                                        </div>
                                        <div class="small mb-0">{{ $reviewSelection['reason'] }}</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="admin-field-adm-customers-register-27">{{ __('Operator Reason') }}</label>
                                        <textarea class="form-control" rows="4" wire:model.defer="reviewResolutionReason" placeholder="{{ __('Explain what you verified, which FIB reference you matched, or why the record should be closed/reclassified.') }}" id="admin-field-adm-customers-register-27" dir="auto"></textarea>
                                        @error('reviewResolutionReason')
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="small text-muted">
                                        {{ __('Safety: this review flow never refunds, never deletes audit history, and only fulfills when you explicitly choose the one-time fulfillment mode.') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-xl-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Attach Correct FIB Reference') }}</h6>
                                    <div class="mb-3">
                                        <label class="form-label" for="admin-field-adm-customers-register-28">{{ __('Correct FIB Subscription ID') }}</label>
                                        <input type="text" class="form-control" wire:model.defer="reviewCorrectFibSubscriptionId" placeholder="{{ __('For recurring subscription rows') }}" id="admin-field-adm-customers-register-28" dir="ltr">
                                        @error('reviewCorrectFibSubscriptionId')
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="admin-field-adm-customers-register-29">{{ __('Correct FIB Payment ID') }}</label>
                                        <input type="text" class="form-control" wire:model.defer="reviewCorrectFibPaymentId" placeholder="{{ __('For one-time payment rows or additional provider proof') }}" id="admin-field-adm-customers-register-29" dir="ltr">
                                        @error('reviewCorrectFibPaymentId')
                                            <div class="text-danger small mt-1">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="admin-field-adm-customers-register-30">{{ __('Reconnect Mode') }}</label>
                                        <select class="form-select" wire:model="reviewReconnectMode" data-admin-review id="admin-field-adm-customers-register-30">
                                            <option value="manual_correction_already_applied">{{ __('Reconnect only, no credit refill') }}</option>
                                            <option value="apply_fulfillment_once">{{ __('Reconnect and fulfill once') }}</option>
                                        </select>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-warning"
                                        data-admin-target="{{ $reviewSelection['customer'].' · '.__('Payment ID:').' '.$reviewSelection['payment_id'] }}" data-admin-method="attachCorrectReviewProviderReference" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('This will attach the corrected FIB reference and continue using the selected reconnect mode. It will not refund, it will not delete audit history, and it will only fulfill if you selected the one-time fulfillment mode. Continue?') }}"
                                     @if(! \App\Support\Admin\AdminUiAccess::can('admin.reconcile')) disabled @endif>
                                        {{ __('Attach Correct FIB Reference') }}
                                    </button>
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div class="border rounded p-3 h-100">
                                    <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Review Resolution Actions') }}</h6>
                                    <div class="mb-3">
                                        <button
                                            type="button"
                                            class="btn btn-soft-secondary me-2 mb-2"
                                            data-admin-target="{{ $reviewSelection['customer'].' · '.__('Payment ID:').' '.$reviewSelection['payment_id'] }}" data-admin-method="markReviewPaymentInvalid" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('This will mark the payment as invalid/expired, remove it from actionable review processing, and keep full audit history. It will not fulfill or refill credits. Continue?') }}"
                                         @if(! \App\Support\Admin\AdminUiAccess::can('admin.reconcile')) disabled @endif>
                                            {{ __('Mark Invalid / Expired') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-soft-dark mb-2"
                                            data-admin-target="{{ $reviewSelection['customer'].' · '.__('Payment ID:').' '.$reviewSelection['payment_id'] }}" data-admin-method="markReviewPaymentNonRevenue" data-admin-args="{{ json_encode([]) }}" data-admin-impact="{{ __('This will exclude this record from revenue totals and mark it as an internal/manual grant. It will not call FIB. It will not refund. It will not delete audit history. Continue?') }}"
                                         @if(! \App\Support\Admin\AdminUiAccess::can('admin.reconcile')) disabled @endif>
                                            {{ __('Reclassify as No-Revenue Manual Grant') }}
                                        </button>
                                    </div>
                                    <div class="small text-muted mb-3">
                                        {{ __('Use Invalid / Expired when the stored FIB reference is truly wrong or no paid transaction exists. Use No-Revenue Manual Grant only for internal/manual/company/testing access that should never count as revenue.') }}
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label" for="admin-field-adm-customers-register-31">{{ __('Callback Payload') }}</label>
                                            <textarea class="form-control font-monospace" rows="6" readonly id="admin-field-adm-customers-register-31" dir="ltr">{{ $reviewSelection['callback_payload'] }}</textarea>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="admin-field-adm-customers-register-32">{{ __('Status Response') }}</label>
                                            <textarea class="form-control font-monospace" rows="6" readonly id="admin-field-adm-customers-register-32" dir="ltr">{{ $reviewSelection['status_response'] }}</textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="row mb-3">
            <div class="col-xl-12">
                <div class="card h-100">
                    <div class="card-header border-0">
                        <h5 class="card-title mb-0">{{ __('Recent Jobs and Paid Orders') }}</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-6 mb-3">
                                <h6 class="text-uppercase text-muted fs-12">{{ __('Latest Jobs') }}</h6>
                                @forelse ($focusedCustomer->mlJobs as $job)
                                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                        <div>
                                            <div class="fw-semibold">{{ $job->tool?->name ?? $job->toolAction?->tool?->name ?? __('Unknown tool') }}</div>
                                            <div class="text-muted small">{{ $job->toolAction?->full_code ?? $job->job_kind }}</div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold">{{ $this->formatCredits($job->credits_charged) }} {{ __('credits') }}</div>
                                            <div class="text-muted small">{{ $job->created_at?->diffForHumans() ?? __('n/a') }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-muted">{{ __('No job history yet.') }}</div>
                                @endforelse
                            </div>
                            <div class="col-lg-6 mb-3">
                                <h6 class="text-uppercase text-muted fs-12">{{ __('Latest Paid Orders') }}</h6>
                                @forelse ($focusedCustomer->creditOrders as $order)
                                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                                        <div>
                                            <div class="fw-semibold">{{ $this->paymentSourceLabel($order->source_type, $order->order_type) }}</div>
                                            <div class="text-muted small">{{ $order->servicePlan?->name ?? $order->creditProduct?->name ?? ($order->source_type ?? __('n/a')) }}</div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold">{{ $this->formatMoney($order->amount_usd) }}</div>
                                            <div class="text-muted small">{{ $order->created_at?->format('M d, Y') ?? __('n/a') }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-muted">{{ __('No paid orders yet.') }}</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">{{ __('Register Table') }}</h5>
            <p class="text-muted mb-0">{{ __('Sort recent joins, profile coverage, location, and plan state for onboarding and support workflows.') }}</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Customer') }}</th>
                            <th>{{ __('Joined') }}</th>
                            <th>{{ __('Location') }}</th>
                            <th>{{ __('Plan') }}</th>
                            <th>{{ __('Profile') }}</th>
                            <th>{{ __('Verification') }}</th>
                            <th class="text-end">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->registrationCustomers as $customer)
                            <tr wire:key="register-row-{{ $customer->id }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerDisplayName($customer) }}</span>
                                        <span class="text-muted small">{{ '@' . $customer->username }}</span>
                                        <span class="text-muted small">{{ $customer->email }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $customer->created_at?->format('M d, Y') ?? __('n/a') }}</span>
                                        <span class="text-muted small">{{ $customer->created_at?->diffForHumans() ?? '' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $this->customerLocation($customer) }}</span>
                                        <span class="text-muted small">{{ data_get($customer, 'profile.address', __('No address provided')) }}</span>
                                    </div>
                                </td>
                                <td><span class="badge {{ $this->planBadgeClasses($customer->servicePlan?->code) }}">{{ $customer->servicePlan?->name ?? __('No active plan') }}</span></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ data_get($customer, 'profile.job_title', __('No job title')) }}</span>
                                        <span class="text-muted small">{{ data_get($customer, 'profile.brand_name', __('No brand')) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->email_verify) }}">{{ __('Email') }}</span>
                                        <span class="badge {{ $this->verificationBadgeClasses((bool) $customer->phone_verify) }}">{{ __('Phone') }}</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-soft-info" wire:click="focusCustomer({{ $customer->id }})">{{ __('Focus') }}</button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-soft-primary"
                                            data-admin-method="syncCustomerCreditsToPlan" data-admin-args="{{ json_encode([$customer->id]) }}" data-admin-impact="{{ __('This will sync the customer\'s subscription credits with their current plan. It will add missing plan credits only when the current subscription balance is lower than the plan allowance. It will not subtract existing credits or remove add-on credits.') }}"
                                         @if(! \App\Support\Admin\AdminUiAccess::can('admin.finance')) disabled @endif>
                                            {{ __('Sync Credits To Plan') }}
                                        </button>
                                        <a wire:navigate class="btn btn-sm btn-soft-info" href="{{ route('admin.customers.detail', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}">{{ __('admin_p2.operations') }}</a>
                                        <a wire:navigate href="{{ route('admin.customers.usage', ['locale' => app()->getLocale(), 'customer' => $customer->id]) }}" class="btn btn-sm btn-soft-secondary">{{ __('Usage') }}</a>
                                        <a wire:navigate href="{{ route('admin.customers.list', ['locale' => app()->getLocale(), 'q' => $customer->username]) }}" class="btn btn-sm btn-soft-primary">{{ __('Locate') }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">{{ __('No customer registrations matched the current filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-dark">
            {{ $this->registrationCustomers->onEachSide(1)->links() }}
        </div>
    </div>

</div>
