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
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Search') }}</label>
                    <div class="search-box">
                        <input type="text" class="form-control" wire:model.live.debounce.350ms="search" placeholder="{{ __('Search customer, plan, location, or profile...') }}">
                        <i class="ri-search-line search-icon"></i>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Status') }}</label>
                    <select class="form-select" wire:model.live="statusFilter">
                        <option value="all">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="suspended">{{ __('Suspended') }}</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Plan') }}</label>
                    <select class="form-select" wire:model.live="planFilter">
                        <option value="all">{{ __('All plans') }}</option>
                        <option value="none">{{ __('No active plan') }}</option>
                        @foreach ($this->customerPlanOptions as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Country') }}</label>
                    <select class="form-select" wire:model.live="countryFilter">
                        <option value="all">{{ __('All countries') }}</option>
                        @foreach ($this->customerCountryOptions as $country)
                            <option value="{{ $country }}">{{ $country }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-muted text-uppercase fs-12">{{ __('Joined') }}</label>
                    <select class="form-select" wire:model.live="joinedFilter">
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
                        <div class="mb-2"><span class="fw-semibold">{{ __('Wallet Balance:') }}</span> {{ __(':credits credits', ['credits' => $this->formatCredits($focusedWallet?->balance_credits)]) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Subscription Bucket:') }}</span> {{ $this->formatCredits($focusedWallet?->subscription_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Addon Bucket:') }}</span> {{ $this->formatCredits($focusedWallet?->addon_balance_credits) }}</div>
                        <div class="mb-2"><span class="fw-semibold">{{ __('Storage Used:') }}</span> {{ $this->formatBytes(data_get($focusedCustomer, 'usage.storage_used_bytes')) }}</div>
                        <div><span class="fw-semibold">{{ __('Paid Orders:') }}</span> {{ number_format((int) ($focusedCustomer->paid_orders_count ?? 0)) }}</div>
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
                <h5 class="card-title mb-1">{{ __('Manual Billing Correction') }}</h5>
                <p class="text-muted mb-0">{{ __('Use these actions only when a payment is confirmed externally and local fulfillment did not apply correctly.') }}</p>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Plan Subscription (Recurring)') }}</h6>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Service Plan') }}</label>
                                <select class="form-select" wire:model="servicePlanAdjustmentId">
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
                                <label class="form-label">{{ __('Billing Cycle') }}</label>
                                <select class="form-select" wire:model="servicePlanBillingCycle">
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                    <option value="yearly">{{ __('Yearly') }}</option>
                                </select>
                                @error('servicePlanBillingCycle')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Provider Reference (Optional)') }}</label>
                                <input type="text" class="form-control" wire:model.defer="servicePlanProviderRef" placeholder="{{ __('FIB subscription/payment reference') }}">
                                @error('servicePlanProviderRef')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Admin Note') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="servicePlanAdjustmentNote" placeholder="{{ __('Why this correction is being applied...') }}"></textarea>
                                @error('servicePlanAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-primary w-100" wire:click="applyServicePlanAdjustment">{{ __('Apply Plan Correction') }}</button>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Storage Subscription (Recurring)') }}</h6>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Storage Plan') }}</label>
                                <select class="form-select" wire:model="storagePlanAdjustmentId">
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
                                <label class="form-label">{{ __('Billing Cycle') }}</label>
                                <select class="form-select" wire:model="storagePlanBillingCycle">
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                    <option value="yearly">{{ __('Yearly') }}</option>
                                </select>
                                @error('storagePlanBillingCycle')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Provider Reference (Optional)') }}</label>
                                <input type="text" class="form-control" wire:model.defer="storagePlanProviderRef" placeholder="{{ __('FIB subscription/payment reference') }}">
                                @error('storagePlanProviderRef')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Admin Note') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="storagePlanAdjustmentNote" placeholder="{{ __('Why this correction is being applied...') }}"></textarea>
                                @error('storagePlanAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-primary w-100" wire:click="applyStoragePlanAdjustment">{{ __('Apply Storage Correction') }}</button>
                        </div>
                    </div>
                    <div class="col-xl-4">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-uppercase text-muted fs-12 mb-3">{{ __('Addon Credits (One-Time)') }}</h6>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Addon Package') }}</label>
                                <select class="form-select" wire:model="addonProductAdjustmentId">
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
                                <label class="form-label">{{ __('Provider Reference (Optional)') }}</label>
                                <input type="text" class="form-control" wire:model.defer="addonProviderRef" placeholder="{{ __('FIB payment reference') }}">
                                @error('addonProviderRef')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label">{{ __('Admin Note') }}</label>
                                <textarea class="form-control" rows="3" wire:model.defer="addonAdjustmentNote" placeholder="{{ __('Why this correction is being applied...') }}"></textarea>
                                @error('addonAdjustmentNote')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <button type="button" class="btn btn-primary w-100" wire:click="applyAddonAdjustment">{{ __('Apply Addon Correction') }}</button>
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
                        <h5 class="card-title mb-0">{{ __('Recent Payments') }}</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('UUID') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Fulfilled') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($focusedCustomer->payments as $payment)
                                        <tr>
                                            <td>
                                                <div class="small fw-semibold">{{ $payment->uuid }}</div>
                                                <div class="text-muted small">{{ $payment->fib_subscription_id ?: $payment->fib_payment_id ?: __('n/a') }}</div>
                                            </td>
                                            <td>
                                                <div class="small">{{ $payment->purchase_type?->value ?? $payment->purchase_type ?? __('n/a') }}</div>
                                                <div class="text-muted small">{{ $payment->payment_mode?->value ?? $payment->payment_mode ?? __('n/a') }}</div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-body">{{ $payment->status?->value ?? $payment->status ?? __('n/a') }}</span>
                                            </td>
                                            <td>
                                                @if ($payment->fulfilled_at)
                                                    <span class="badge bg-success-subtle text-success">{{ __('Yes') }}</span>
                                                @else
                                                    <span class="badge bg-danger-subtle text-danger">{{ __('No') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-3 text-muted">{{ __('No recent payments for this customer.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
