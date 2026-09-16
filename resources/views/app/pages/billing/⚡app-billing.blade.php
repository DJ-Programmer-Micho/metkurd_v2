<?php

use Illuminate\Support\Str;
use App\Domain\Payments\Enums\PaymentMode;
use Livewire\Attributes\Layout;

new #[Layout('app::layouts.app')] class extends \App\Livewire\Account\BillingPage
{
    public function render()
    {
        return view('app.pages.billing.⚡app-billing');
    }
};
?>

<x-slot:title>{{ __('Billing') }} | {{ __('MET KURD') }}</x-slot:title>

<div>
    <style>
        .billing-hero-card {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
        }

        .billing-metric-card {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            transition: .2s ease;
        }

        .billing-metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.08);
        }

        .billing-table-card {
            border-radius: 1rem;
            overflow: hidden;
        }

        .filter-chip-group .btn {
            border-radius: 999px;
        }

        .mini-stat {
            font-size: .825rem;
            color: var(--vz-secondary-color, var(--bs-secondary-color));
        }

        .billing-state-card {
            border: 1px solid var(--vz-border-color, var(--bs-border-color));
            border-radius: 1rem;
            height: 100%;
        }

        .billing-amount-breakdown {
            font-size: .75rem;
            line-height: 1.55;
        }

        .billing-divider {
            width: 100%;
            height: 1px;
            background: var(--vz-border-color, var(--bs-border-color));
            opacity: .7;
        }
    </style>



            <div class="row mb-3">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <div>
                            <h4 class="mb-sm-0">{{ __('Billing & Usage') }}</h4>
                            <div class="text-muted mt-1">{{ __('Credits, subscriptions, storage, add-ons, and tool consumption in one place.') }}</div>
                        </div>

                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">{{ __('Account') }}</a></li>
                                <li class="breadcrumb-item active">{{ __('Billing') }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card billing-hero-card mb-4">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('Period') }}</label>
                            <select class="form-select" wire:model.change="periodPreset">
                                <option value="daily">{{ __('Daily') }}</option>
                                <option value="weekly">{{ __('Weekly') }}</option>
                                <option value="monthly">{{ __('Monthly') }}</option>
                                <option value="custom">{{ __('Custom') }}</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('Group By') }}</label>
                            <select class="form-select" wire:model.change="groupBy">
                                <option value="day">{{ __('Daily') }}</option>
                                <option value="week">{{ __('Weekly') }}</option>
                                <option value="month">{{ __('Monthly') }}</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('Tool') }}</label>
                            <select class="form-select" wire:model.change="toolFilter">
                                @foreach($toolOptions as $key => $label)
                                    <option value="{{ $key }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('Job Status') }}</label>
                            <select class="form-select" wire:model.change="statusFilter">
                                <option value="all">{{ __('All') }}</option>
                                <option value="done">{{ __('Done') }}</option>
                                <option value="failed">{{ __('Failed') }}</option>
                                <option value="queued">{{ __('Queued') }}</option>
                                <option value="running">{{ __('Running') }}</option>
                                <option value="saving">{{ __('Saving') }}</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" wire:model.change="dateFrom">
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" wire:model.change="dateTo">
                        </div>

                        <div class="col-xl-8">
                            <label class="form-label">{{ __('Search') }}</label>
                            <div class="position-relative">
                                <input
                                    type="text"
                                    class="form-control ps-5"
                                    placeholder="{{ __('Search job ID, provider job ID, tool, or status...') }}"
                                    wire:model.live.debounce.500ms="search"
                                >
                                <i class="ri-search-line position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="d-flex gap-2 justify-content-xl-end filter-chip-group">
                                <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">
                                    <i class="ri-refresh-line align-bottom me-1"></i> {{ __('Reset') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @php $stats = $this->topStats(); @endphp

            <div class="row">
                <div class="col-xxl-3 col-md-6">
                    <div class="card billing-metric-card card-animate">
                        <div class="card-body">
                            <div class="d-flex mb-3">
                                <div class="flex-grow-1">
                                    <lord-icon src="https://cdn.lordicon.com/fhtaantg.json" trigger="loop" colors="primary:#405189,secondary:#0ab39c" style="width:55px;height:55px"></lord-icon>
                                </div>
                                <div class="flex-shrink-0">
                                    <span class="badge bg-primary-subtle text-primary badge-border">{{ __('Current') }}</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatCredits($stats['current_balance']) }}</h3>
                            <h6 class="text-muted mb-2">{{ __('Available Credits') }}</h6>
                            <div class="mini-stat">
                                {{ __('Subscription:') }} <b>{{ $this->formatCredits($stats['subscription_balance']) }}</b><br>
                                {{ __('Add-on:') }} <b>{{ $this->formatCredits($stats['addon_balance']) }}</b>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xxl-3 col-md-6">
                    <div class="card billing-metric-card card-animate">
                        <div class="card-body">
                            <div class="d-flex mb-3">
                                <div class="flex-grow-1">
                                    <lord-icon src="https://cdn.lordicon.com/qhviklyi.json" trigger="loop" colors="primary:#405189,secondary:#0ab39c" style="width:55px;height:55px"></lord-icon>
                                </div>
                                <div class="flex-shrink-0">
                                    <span class="badge bg-warning-subtle text-warning badge-border">{{ __(ucfirst($periodPreset)) }}</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatCredits($stats['period_credits_spent']) }}</h3>
                            <h6 class="text-muted mb-2">{{ __('Credits Charged') }}</h6>
                            <div class="mini-stat">
                                {{ __('Jobs:') }} <b>{{ number_format($stats['jobs_count']) }}</b><br>
                                {{ __('Monthly grants in range:') }} <b>{{ $this->formatCredits($this->monthlyGrantCredits()) }}</b>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xxl-3 col-md-6">
                    <div class="card billing-metric-card card-animate">
                        <div class="card-body">
                            <div class="d-flex mb-3">
                                <div class="flex-grow-1">
                                    <lord-icon src="https://cdn.lordicon.com/yeallgsa.json" trigger="loop" colors="primary:#405189,secondary:#0ab39c" style="width:55px;height:55px"></lord-icon>
                                </div>
                                <div class="flex-shrink-0">
                                    <span class="badge bg-info-subtle text-info badge-border">{{ __('Storage') }}</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatBytes($stats['current_storage_used']) }}</h3>
                            <h6 class="text-muted mb-2">{{ __('Current Storage Used') }}</h6>
                            <div class="mini-stat">
                                {{ __('Input in range:') }} <b>{{ $this->formatBytes($stats['period_storage_in']) }}</b><br>
                                {{ __('Output in range:') }} <b>{{ $this->formatBytes($stats['period_storage_out']) }}</b>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xxl-3 col-md-6">
                    <div class="card billing-metric-card card-animate overflow-hidden">
                        <div class="card-body">
                            <div class="d-flex mb-3">
                                <div class="flex-grow-1">
                                    <lord-icon src="https://cdn.lordicon.com/vaeagfzc.json" trigger="loop" colors="primary:#405189,secondary:#0ab39c" style="width:55px;height:55px"></lord-icon>
                                </div>
                                <div class="flex-shrink-0">
                                    <span class="badge bg-success-subtle text-success badge-border">{{ __('Payments') }}</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->money($stats['subscription_payments'] + $stats['storage_payments'] + $stats['addon_payments']) }}</h3>
                            <h6 class="text-muted mb-2">{{ __('Paid in Selected Range') }}</h6>
                            <div class="mini-stat">
                                {{ __('Subscription:') }} <b>{{ $this->money($stats['subscription_payments']) }}</b><br>
                                {{ __('Storage:') }} <b>{{ $this->money($stats['storage_payments']) }}</b><br>
                                {{ __('Add-ons:') }} <b>{{ $this->money($stats['addon_payments']) }}</b>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @php
                $serviceState = $this->serviceState();
                $storageState = $this->storageState();
                $latestCheckout = $this->latestCheckout();
                $latestCheckoutSnapshot = $latestCheckout?->snapshot() ?? [];
                $latestCheckoutFlow = $latestCheckout
                    ? $this->flowLabel($latestCheckout->payment_mode?->value, (string) data_get($latestCheckoutSnapshot, 'billing_cycle', ''))
                    : null;
            @endphp

            <div class="row mt-1">
                <div class="col-xl-4">
                    <div class="card billing-state-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <div class="text-muted text-uppercase small fw-semibold">{{ __('Main Subscription') }}</div>
                                    <h5 class="mb-1">{{ data_get($serviceState, 'current_plan.name', __('Free')) }}</h5>
                                </div>
                                @if (data_get($serviceState, 'cancellation_scheduled'))
                                    <span class="badge bg-warning-subtle text-warning">{{ __('Cancel at period end') }}</span>
                                @elseif (data_get($serviceState, 'has_active_paid_main_plan'))
                                    <span class="badge bg-success-subtle text-success">{{ __('Recurring active') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('Free plan') }}</span>
                                @endif
                            </div>

                            <div class="mini-stat">
                                @if (data_get($serviceState, 'period_ends_at'))
                                    {{ data_get($serviceState, 'cancellation_scheduled')
                                        ? __('Access remains until :date', ['date' => $this->formatTimestamp(data_get($serviceState, 'period_ends_at'))])
                                        : __('Current cycle ends on :date', ['date' => $this->formatTimestamp(data_get($serviceState, 'period_ends_at'))]) }}
                                @else
                                    {{ __('No paid service renewal is currently active.') }}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card billing-state-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <div class="text-muted text-uppercase small fw-semibold">{{ __('Storage Subscription') }}</div>
                                    <h5 class="mb-1">{{ data_get($storageState, 'current_plan.name', __('Free Storage')) }}</h5>
                                </div>
                                @if (data_get($storageState, 'cancellation_scheduled'))
                                    <span class="badge bg-warning-subtle text-warning">{{ __('Cancel at period end') }}</span>
                                @elseif (data_get($storageState, 'has_paid_storage_plan'))
                                    <span class="badge bg-info-subtle text-info">{{ __('Recurring active') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('Default storage') }}</span>
                                @endif
                            </div>

                            <div class="mini-stat">
                                @if (data_get($storageState, 'period_ends_at'))
                                    {{ data_get($storageState, 'cancellation_scheduled')
                                        ? __('Storage remains active until :date', ['date' => $this->formatTimestamp(data_get($storageState, 'period_ends_at'))])
                                        : __('Current storage cycle ends on :date', ['date' => $this->formatTimestamp(data_get($storageState, 'period_ends_at'))]) }}
                                @else
                                    {{ __('No paid storage renewal is currently active.') }}
                                @endif
                                <br>
                                {{ __('Used: :used of :total', [
                                    'used' => $this->formatBytes((int) data_get($storageState, 'used_bytes', 0)),
                                    'total' => $this->formatBytes((int) data_get($storageState, 'current_limit_bytes', 512 * 1024 * 1024)),
                                ]) }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card billing-state-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <div class="text-muted text-uppercase small fw-semibold">{{ __('Latest Checkout') }}</div>
                                    <h5 class="mb-1">{{ $latestCheckout ? (data_get($latestCheckoutSnapshot, 'name') ?: data_get($latestCheckoutSnapshot, 'code') ?: __('Checkout')) : __('No recent checkout') }}</h5>
                                </div>
                                <span class="badge bg-primary-subtle text-primary">{{ __('Open: :count', ['count' => $this->openCheckoutCount()]) }}</span>
                            </div>

                            @if ($latestCheckout)
                                <div class="mini-stat">
                                    <div class="mb-1">
                                        <span class="badge bg-{{ $this->flowBadgeClass($latestCheckoutFlow) }}-subtle text-{{ $this->flowBadgeClass($latestCheckoutFlow) }}">
                                            {{ $latestCheckoutFlow }}
                                        </span>
                                        <span class="badge bg-{{ $this->statusBadgeClass($latestCheckout->status?->value ?? $latestCheckout->status) }}-subtle text-{{ $this->statusBadgeClass($latestCheckout->status?->value ?? $latestCheckout->status) }}">
                                            {{ __(Str::headline((string) ($latestCheckout->status?->value ?? $latestCheckout->status))) }}
                                        </span>
                                    </div>

                                    {{ $this->checkoutStatusHint($latestCheckout) ?: __('Review the checkout page for the latest provider status.') }}
                                </div>

                                <div class="billing-divider my-3"></div>

                                <a href="{{ route('payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $latestCheckout]) }}" class="btn btn-soft-primary btn-sm">
                                    {{ __('Open Checkout') }}
                                </a>
                            @else
                                <div class="mini-stat">{{ __('Your recent payment and subscription activity will appear here once you start a checkout.') }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-1">
                <div class="col-xl-6">
                    <div class="card billing-table-card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('Consumption Timeline') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive table-card">
                                <table class="table align-middle table-nowrap mb-0">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>{{ __('Period') }}</th>
                                            <th>{{ __('Jobs') }}</th>
                                            <th>{{ __('Success') }}</th>
                                            <th>{{ __('Failed') }}</th>
                                            <th>{{ __('Credits') }}</th>
                                            <th>{{ __('Input') }}</th>
                                            <th>{{ __('Output') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($this->timelineBreakdown() as $row)
                                            <tr>
                                                <td class="fw-semibold">{{ $row['period'] }}</td>
                                                <td>{{ number_format($row['jobs']) }}</td>
                                                <td><span class="badge bg-success-subtle text-success">{{ $row['success'] }}</span></td>
                                                <td><span class="badge bg-danger-subtle text-danger">{{ $row['failed'] }}</span></td>
                                                <td>{{ $this->formatCredits($row['credits']) }}</td>
                                                <td>{{ $this->formatBytes($row['storage_in']) }}</td>
                                                <td>{{ $this->formatBytes($row['storage_out']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">{{ __('No usage found for this range.') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card billing-table-card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">{{ __('Tool Breakdown') }}</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive table-card">
                                <table class="table align-middle table-nowrap mb-0">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>{{ __('Tool') }}</th>
                                            <th>{{ __('Jobs') }}</th>
                                            <th>{{ __('Credits') }}</th>
                                            <th>{{ __('Input') }}</th>
                                            <th>{{ __('Output') }}</th>
                                            <th>{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($this->toolBreakdown() as $row)
                                            <tr>
                                                <td>
                                                    <span class="badge bg-{{ $this->toolBadgeClass($row['tool']) }}-subtle text-{{ $this->toolBadgeClass($row['tool']) }}">
                                                        {{ $row['label'] }}
                                                    </span>
                                                </td>
                                                <td>{{ number_format($row['jobs']) }}</td>
                                                <td>{{ $this->formatCredits($row['credits']) }}</td>
                                                <td>{{ $this->formatBytes($row['storage_in']) }}</td>
                                                <td>{{ $this->formatBytes($row['storage_out']) }}</td>
                                                <td>
                                                    <span class="badge bg-success-subtle text-success">{{ __('Success: :count', ['count' => $row['success']]) }}</span>
                                                    <span class="badge bg-danger-subtle text-danger">{{ __('Failed: :count', ['count' => $row['failed']]) }}</span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">{{ __('No tool activity found.') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card billing-table-card mt-2">
                <div class="card-header">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="card-title mb-0">{{ __('Tool Usage Transactions') }}</h5>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <span class="text-muted">
                                {{ __(':count job record(s)', ['count' => $this->jobsPaginator()->total()]) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive table-card">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light text-muted">
                                <tr>
                                    <th>{{ __('Timestamp') }}</th>
                                    <th>{{ __('Tool') }}</th>
                                    <th>{{ __('Job ID') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Credits') }}</th>
                                    <th>{{ __('Input') }}</th>
                                    <th>{{ __('Output') }}</th>
                                    <th>{{ __('Total') }}</th>
                                    <th>{{ __('Provider Cost') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($this->jobsPaginator() as $job)
                                    <tr wire:key="job-row-{{ $job->id }}">
                                        <td>{{ $this->formatTimestamp($job->created_at) }}</td>
                                        <td>
                                            <span class="badge bg-{{ $this->toolBadgeClass($job->job_kind) }}-subtle text-{{ $this->toolBadgeClass($job->job_kind) }}">
                                                {{ $this->toolLabel($job->job_kind) }}
                                            </span>
                                        </td>
                                        <td class="fw-semibold">{{ $job->id }}</td>
                                        <td>
                                            <span class="badge bg-{{ $this->statusBadgeClass($job->status) }}-subtle text-{{ $this->statusBadgeClass($job->status) }}">
                                                {{ __(Str::headline((string) $job->status)) }}
                                            </span>
                                        </td>
                                        <td>{{ $this->formatCredits((int) ($job->credits_charged ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_in_bytes ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_out_bytes ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_in_bytes ?? 0) + (int) ($job->storage_out_bytes ?? 0)) }}</td>
                                        <td>{{ $this->usdMoney((float) ($job->provider_cost_usd ?? 0)) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">{{ __('No job transactions found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($this->jobsPaginator()->hasPages())
                        <div class="d-flex justify-content-end mt-3">
                            {{ $this->jobsPaginator()->links(data: ['scrollTo' => false]) }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="card billing-table-card mt-4">
                <div class="card-header">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="card-title mb-0">{{ __('Billing Activity') }}</h5>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <span class="text-muted">
                                {{ __('Checkouts, recurring charges, add-ons, credits, and billing adjustments') }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive table-card">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light text-muted">
                                <tr>
                                    <th>{{ __('Timestamp') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Reference') }}</th>
                                    <th>{{ __('Flow / Source') }}</th>
                                    <th>{{ __('Details') }}</th>
                                    <th>{{ __('Credits') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($this->billingActivityPaginator() as $row)
                                    <tr>
                                        <td>{{ $this->formatTimestamp($row['timestamp']) }}</td>
                                        <td class="fw-semibold">{{ $row['category'] }}</td>
                                        <td>{{ $row['reference'] }}</td>
                                        <td>
                                            @if(!empty($row['support_label']))
                                                <span class="badge bg-{{ $row['support_badge_class'] ?? 'secondary' }}-subtle text-{{ $row['support_badge_class'] ?? 'secondary' }}">
                                                    {{ $this->supportLabel((string) $row['support_label'], (string) ($row['row_type'] ?? '')) }}
                                                </span>
                                            @else
                                                <span class="text-muted">{{ __('Not applicable') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ __(Str::headline(str_replace('_', ' ', (string) $row['description']))) }}</div>

                                            @if(!empty($row['details_hint']))
                                                <div class="text-muted small mt-1">{{ $row['details_hint'] }}</div>
                                            @endif

                                            @if(!empty($row['lifecycle_details']))
                                                <div class="text-muted small mt-1">
                                                    @foreach($row['lifecycle_details'] as $detailLine)
                                                        <div>{{ $detailLine }}</div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            @if(!empty($row['coupon_code']))
                                                <div class="text-primary small mt-1">{{ __('Coupon: :code', ['code' => $row['coupon_code']]) }}</div>
                                            @endif

                                            @if(!empty($row['action_url']))
                                                <div class="mt-2">
                                                    <a href="{{ $row['action_url'] }}" class="btn btn-sm btn-soft-primary">
                                                        {{ __('Open Checkout') }}
                                                    </a>
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($row['credits_delta'] !== null)
                                                <span class="{{ $row['credits_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                    {{ $row['credits_delta'] > 0 ? '+' : '' }}{{ number_format($row['credits_delta']) }}
                                                </span>
                                            @else
                                                <span class="text-muted">{{ __('Not applicable') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($row['base_amount_iqd'] !== null)
                                                <div class="fw-semibold">
                                                    {{ $this->moneyWithDisplay($row['base_amount_iqd'], $row['display_amount'] ?? null, $row['display_currency_code'] ?? null) }}
                                                </div>

                                                @if(($row['discount_amount_iqd'] ?? 0) > 0)
                                                    <div class="billing-amount-breakdown text-muted mt-1">
                                                        <div>{{ __('Original: :amount', ['amount' => $this->moneyWithDisplay($row['original_amount_iqd'] ?? 0, $row['original_display_amount'] ?? null, $row['original_display_currency_code'] ?? null)]) }}</div>
                                                        <div>{{ __('Discount: -:amount', ['amount' => $this->moneyWithDisplay($row['discount_amount_iqd'] ?? 0, $row['discount_display_amount'] ?? null, $row['discount_display_currency_code'] ?? null)]) }}</div>
                                                    </div>
                                                @endif
                                            @else
                                                <span class="text-muted">{{ __('Not applicable') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $this->statusBadgeClass($row['status']) }}-subtle text-{{ $this->statusBadgeClass($row['status']) }}">
                                                {{ __(Str::headline((string) ($row['status_label'] ?? $row['status']))) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">{{ __('No billing activity found.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($this->billingActivityPaginator()->hasPages())
                        <div class="d-flex justify-content-end mt-3">
                            {{ $this->billingActivityPaginator()->links(data: ['scrollTo' => false]) }}
                        </div>
                    @endif
                </div>
            </div>

</div>
