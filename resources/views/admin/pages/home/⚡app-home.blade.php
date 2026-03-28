<?php

use App\Support\Admin\ManagesAdminHomePage;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('admin::layouts.app')]
class extends Component
{
    use ManagesAdminHomePage;
};
?>

<x-slot:title>{{ __('Analysis Dashboard') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $stats = $this->overviewStats;
    $activity = $this->recentActivity;
    $charts = $this->chartPayload;
    $revenueSources = $stats['revenue_sources'] ?? [];
@endphp

<div class="container-fluid analysis-dashboard" data-admin-home-dashboard>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 overflow-hidden analysis-hero mb-4">
                <div class="card-body p-4 p-lg-5 position-relative">
                    <div class="row align-items-end g-4">
                        <div class="col-xl-8">
                            <span class="badge text-bg-light text-uppercase fw-semibold mb-3">{{ __('Admin Analysis') }}</span>
                            <h2 class="text-white mb-2">{{ __('Live operating snapshot for plans, customers, purchase flow, and tool consumption.') }}</h2>
                            <p class="text-white-50 mb-0 analysis-hero-copy">
                                {{ __('This dashboard uses grouped database aggregates and short-lived caching to keep the admin overview fast as orders, subscriptions, and `MlJob` history grow.') }}
                            </p>
                        </div>
                        <div class="col-xl-4">
                            <div class="analysis-panel p-3 rounded-4">
                                <label class="form-label text-uppercase fs-12 text-white-50 mb-2">{{ __('Analysis Window') }}</label>
                                <select class="form-select bg-white border-0" wire:model.live="periodFilter">
                                    <option value="7">{{ __('Last 7 days') }}</option>
                                    <option value="30">{{ __('Last 30 days') }}</option>
                                    <option value="90">{{ __('Last 90 days') }}</option>
                                    <option value="365">{{ __('Last 12 months') }}</option>
                                    <option value="all">{{ __('All time') }}</option>
                                </select>
                                <div class="small text-white-50 mt-2">
                                    {{ __('Showing :period for revenue, purchases, and usage-heavy sections. Snapshot cache: 5 minutes.', ['period' => $this->periodLabel($periodFilter)]) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="analysis-orb analysis-orb-one"></div>
                    <div class="analysis-orb analysis-orb-two"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Customers') }}</p>
                    <h3 class="mb-1">{{ number_format($stats['customers_total']) }}</h3>
                    <div class="text-muted small">{{ __(':count registered in :period', ['count' => number_format($stats['period_new_customers']), 'period' => $this->periodLabel($periodFilter)]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Paid Plans') }}</p>
                    <h3 class="mb-1">{{ number_format($stats['paid_subscribers']) }}</h3>
                    <div class="text-muted small">{{ __(':value of active customers', ['value' => $this->formatPercent($stats['paid_subscriber_share'])]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Revenue') }}</p>
                    <h3 class="mb-1">{{ $this->formatMoney($stats['revenue_total']) }}</h3>
                    <div class="text-muted small">{{ __(':amount in :period', ['amount' => $this->formatMoney($stats['revenue_period']), 'period' => $this->periodLabel($periodFilter)]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Credits Sold') }}</p>
                    <h3 class="mb-1">{{ $this->formatCredits($stats['credits_sold_total']) }}</h3>
                    <div class="text-muted small">{{ __(':credits sold in this window', ['credits' => $this->formatCredits($stats['credits_sold_period'])]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Consumption') }}</p>
                    <h3 class="mb-1">{{ $this->formatCredits($stats['consumed_total']) }}</h3>
                    <div class="text-muted small">{{ __(':credits consumed in this window', ['credits' => $this->formatCredits($stats['consumed_period'])]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xxl-2 col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Jobs') }}</p>
                    <h3 class="mb-1">{{ number_format($stats['jobs_total']) }}</h3>
                    <div class="text-muted small">{{ __(':rate success rate, :count live', ['rate' => $this->formatPercent($stats['success_rate']), 'count' => number_format($stats['active_jobs'])]) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Service Plans Revenue') }}</p>
                    <h3 class="mb-1">{{ $this->formatMoney(data_get($revenueSources, 'service_plan.revenue_total', 0)) }}</h3>
                    <div class="text-muted small">{{ __(':orders orders and :amount in :period', ['orders' => number_format((int) data_get($revenueSources, 'service_plan.orders_period', 0)), 'amount' => $this->formatMoney(data_get($revenueSources, 'service_plan.revenue_period', 0)), 'period' => $this->periodLabel($periodFilter)]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Storage Plans Revenue') }}</p>
                    <h3 class="mb-1">{{ $this->formatMoney(data_get($revenueSources, 'storage_plan.revenue_total', 0)) }}</h3>
                    <div class="text-muted small">{{ __(':orders orders and :amount in :period', ['orders' => number_format((int) data_get($revenueSources, 'storage_plan.orders_period', 0)), 'amount' => $this->formatMoney(data_get($revenueSources, 'storage_plan.revenue_period', 0)), 'period' => $this->periodLabel($periodFilter)]) }}</div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6">
            <div class="card h-100 analysis-stat-card">
                <div class="card-body">
                    <p class="text-uppercase fw-medium text-muted mb-2">{{ __('Credit Products Revenue') }}</p>
                    <h3 class="mb-1">{{ $this->formatMoney(data_get($revenueSources, 'credit_product.revenue_total', 0)) }}</h3>
                    <div class="text-muted small">{{ __(':orders orders and :amount in :period', ['orders' => number_format((int) data_get($revenueSources, 'credit_product.orders_period', 0)), 'amount' => $this->formatMoney(data_get($revenueSources, 'credit_product.revenue_period', 0)), 'period' => $this->periodLabel($periodFilter)]) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xxl-8">
            <div class="card h-100 analysis-chart-card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Operational Trend') }}</h5>
                    <p class="text-muted mb-0">{{ __('A compact 14-day view of job flow, registrations, and paid revenue.') }}</p>
                </div>
                <div class="card-body">
                    <div class="analysis-chart-wrap analysis-chart-wrap-lg">
                        <canvas id="adminHomeActivityChart" wire:ignore></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xxl-4">
            <div class="card h-100 analysis-chart-card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Revenue by Payment Source') }}</h5>
                    <p class="text-muted mb-0">{{ __('How paid revenue is split across service plans, storage plans, and credit products.') }}</p>
                </div>
                <div class="card-body">
                    <div class="analysis-chart-wrap analysis-chart-wrap-md">
                        <canvas id="adminHomePurchaseMixChart" wire:ignore></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-5">
            <div class="card h-100 analysis-chart-card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Plan Distribution') }}</h5>
                    <p class="text-muted mb-0">{{ __('Active subscriber spread across service plans right now.') }}</p>
                </div>
                <div class="card-body">
                    <div class="analysis-chart-wrap">
                        <canvas id="adminHomePlanMixChart" wire:ignore></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card h-100 analysis-chart-card">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Top Tools by Credits') }}</h5>
                    <p class="text-muted mb-0">{{ __('Usage leaders for the selected window, with job totals in the tooltip.') }}</p>
                </div>
                <div class="card-body">
                    <div class="analysis-chart-wrap">
                        <canvas id="adminHomeToolsChart" wire:ignore></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Plan Health') }}</h5>
                    <p class="text-muted mb-0">{{ __('Current subscriber distribution with revenue and credits sold during :period.', ['period' => $this->periodLabel($periodFilter)]) }}</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th>{{ __('Plan') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Active Subscribers') }}</th>
                                    <th>{{ __('Monthly Credits') }}</th>
                                    <th>{{ __('Price') }}</th>
                                    <th>{{ __('Paid Orders') }}</th>
                                    <th>{{ __('Revenue') }}</th>
                                    <th>{{ __('Credits Sold') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->planMix as $plan)
                                    <tr wire:key="analysis-plan-{{ $plan->id }}">
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $plan->name }}</span>
                                                <span class="text-muted small">{{ $plan->code }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge {{ $plan->is_free ? 'bg-light text-body' : 'bg-info-subtle text-info' }}">
                                                {{ $plan->is_free ? __('Free') : __('Paid') }}
                                            </span>
                                        </td>
                                        <td>{{ number_format((int) ($plan->active_subscribers ?? 0)) }}</td>
                                        <td>{{ $this->formatCredits($plan->monthly_credits) }}</td>
                                        <td>{{ $this->formatMoney($plan->price_usd_monthly) }}</td>
                                        <td>{{ number_format((int) ($plan->paid_orders ?? 0)) }}</td>
                                        <td>{{ $this->formatMoney($plan->revenue) }}</td>
                                        <td>{{ $this->formatCredits($plan->credits_sold) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">{{ __('No plan analytics are available yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Purchase Mix') }}</h5>
                    <p class="text-muted mb-0">{{ __('Breakdown of paid orders by payment source in the selected window.') }}</p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        @forelse ($this->purchaseMix as $row)
                            <div class="analysis-metric-row">
                                <div class="d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <h6 class="mb-1">{{ $row->label ?? $row->category }}</h6>
                                        <div class="text-muted small">{{ __(':orders orders from :customers customers', ['orders' => number_format((int) ($row->orders ?? 0)), 'customers' => number_format((int) ($row->customers ?? 0))]) }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ $this->formatMoney($row->revenue) }}</div>
                                        <div class="text-muted small">{{ __(':credits credits', ['credits' => $this->formatCredits($row->credits)]) }}</div>
                                    </div>
                                </div>
                                <div class="progress mt-3" style="height: 7px;">
                                    <div class="progress-bar bg-info" role="progressbar" style="width: {{ $this->trendWidth($row->revenue, max(1, (float) $stats['revenue_period'])) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted">{{ __('No paid purchase activity matched the current window.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Tool Consumption') }}</h5>
                    <p class="text-muted mb-0">{{ __('Top tools by credits and job volume in :period.', ['period' => strtolower($this->periodLabel($periodFilter))]) }}</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted">
                                <tr class="text-uppercase">
                                    <th>{{ __('Tool') }}</th>
                                    <th>{{ __('Customers') }}</th>
                                    <th>{{ __('Jobs') }}</th>
                                    <th>{{ __('Done / Failed') }}</th>
                                    <th>{{ __('Credits') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->topTools as $tool)
                                    <tr wire:key="analysis-tool-{{ md5($tool->tool_code) }}">
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-semibold">{{ $tool->tool_name }}</span>
                                                <span class="text-muted small">{{ $tool->tool_code }}</span>
                                            </div>
                                        </td>
                                        <td>{{ number_format((int) ($tool->customers ?? 0)) }}</td>
                                        <td>{{ number_format((int) ($tool->jobs ?? 0)) }}</td>
                                        <td>{{ __(':done / :failed', ['done' => number_format((int) ($tool->completed_jobs ?? 0)), 'failed' => number_format((int) ($tool->failed_jobs ?? 0))]) }}</td>
                                        <td>{{ $this->formatCredits($tool->credits) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">{{ __('No tool usage matched the current window.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header border-0">
                    <h5 class="card-title mb-1">{{ __('Geographic Footprint') }}</h5>
                    <p class="text-muted mb-0">{{ __('Top customer countries with revenue in the current analysis window.') }}</p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-3">
                        @forelse ($this->topCountries as $country)
                            <div class="analysis-metric-row">
                                <div class="d-flex align-items-start justify-content-between gap-3">
                                    <div>
                                        <h6 class="mb-1">{{ $country->country }}</h6>
                                        <div class="text-muted small">{{ __(':active active of :customers customers', ['active' => number_format((int) ($country->active_customers ?? 0)), 'customers' => number_format((int) ($country->customers ?? 0))]) }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-semibold">{{ $this->formatMoney($country->revenue) }}</div>
                                        <div class="text-muted small">{{ __('period revenue') }}</div>
                                    </div>
                                </div>
                                <div class="progress mt-3" style="height: 7px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: {{ $this->trendWidth($country->customers, max(1, (int) $stats['customers_total'])) }}%"></div>
                                </div>
                            </div>
                        @empty
                            <div class="text-muted">{{ __('No profile geography data is available yet.') }}</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header border-0">
            <h5 class="card-title mb-1">{{ __('Recent 14-Day Activity') }}</h5>
            <p class="text-muted mb-0">{{ __('Compact daily timeline for registrations, paid revenue, jobs, and credit consumption.') }}</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted">
                        <tr class="text-uppercase">
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('New Customers') }}</th>
                            <th>{{ __('Paid Revenue') }}</th>
                            <th>{{ __('Jobs') }}</th>
                            <th style="min-width: 220px;">{{ __('Credits') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity['rows'] as $row)
                            <tr wire:key="analysis-day-{{ $row['day'] }}">
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $row['label'] }}</span>
                                        <span class="text-muted small">{{ $row['day'] }}</span>
                                    </div>
                                </td>
                                <td>{{ number_format($row['customers']) }}</td>
                                <td>{{ $this->formatMoney($row['revenue']) }}</td>
                                <td>{{ number_format($row['jobs']) }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="fw-semibold">{{ $this->formatCredits($row['credits']) }}</span>
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar bg-warning" role="progressbar" style="width: {{ $this->trendWidth($row['credits'], $activity['max_credits']) }}%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script type="application/json" id="admin-home-chart-data">@json($charts)</script>

    <style>
        .analysis-dashboard .analysis-hero{
            background:
                radial-gradient(circle at top right, rgba(255, 196, 112, 0.28), transparent 30%),
                linear-gradient(135deg, #0f172a 0%, #15304c 55%, #0b3a3e 100%);
        }

        .analysis-dashboard .analysis-hero-copy{
            max-width: 60rem;
        }

        .analysis-dashboard .analysis-panel{
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(8px);
        }

        .analysis-dashboard .analysis-orb{
            position: absolute;
            border-radius: 999px;
            filter: blur(4px);
            opacity: 0.5;
            pointer-events: none;
        }

        .analysis-dashboard .analysis-orb-one{
            width: 140px;
            height: 140px;
            right: -30px;
            top: -30px;
            background: rgba(45, 212, 191, 0.18);
        }

        .analysis-dashboard .analysis-orb-two{
            width: 110px;
            height: 110px;
            right: 22%;
            bottom: -25px;
            background: rgba(251, 191, 36, 0.18);
        }

        .analysis-dashboard .analysis-stat-card{
            border: 1px solid rgba(15, 23, 42, 0.04);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .analysis-dashboard .analysis-chart-card{
            border: 1px solid rgba(15, 23, 42, 0.04);
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
        }

        .analysis-dashboard .analysis-chart-wrap{
            position: relative;
            min-height: 300px;
        }

        .analysis-dashboard .analysis-chart-wrap-lg{
            min-height: 340px;
        }

        .analysis-dashboard .analysis-chart-wrap-md{
            min-height: 340px;
        }

        .analysis-dashboard .analysis-metric-row{
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 1rem;
            padding: 1rem;
            background: linear-gradient(180deg, #313233, #333435);
            color: #fff
        }
    </style>

    @push('scripts')
        @once
            <script data-navigate-once src="{{ asset('app/libs/chart.js/chart.umd.js') }}"></script>
            <script>
                (() => {
                    const state = window.__ADMIN_HOME_CHARTS__ ??= {
                        booted: false,
                        commitHooked: false,
                        instances: {},
                    };

                    const rootSelector = '[data-admin-home-dashboard]';
                    const numberFormatter = new Intl.NumberFormat('en-US');
                    const moneyFormatter = new Intl.NumberFormat('en-US', {
                        style: 'currency',
                        currency: 'USD',
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });

                    const colorSet = {
                        teal: '#0f766e',
                        tealSoft: 'rgba(15, 118, 110, 0.18)',
                        sky: '#0284c7',
                        skySoft: 'rgba(2, 132, 199, 0.14)',
                        amber: '#d97706',
                        amberSoft: 'rgba(217, 119, 6, 0.16)',
                        indigo: '#4f46e5',
                        indigoSoft: 'rgba(79, 70, 229, 0.16)',
                        rose: '#e11d48',
                        slate: '#334155',
                    };
                    const paletteSeeds = [
                        '15, 118, 110',
                        '2, 132, 199',
                        '217, 119, 6',
                        '79, 70, 229',
                        '225, 29, 72',
                        '100, 116, 139',
                    ];

                    const seriesColors = (count, alpha = 0.82) => Array.from({ length: count }, (_, index) => {
                        const seed = paletteSeeds[index % paletteSeeds.length];

                        return `rgba(${seed}, ${alpha})`;
                    });

                    const defaultFontFamily = () => getComputedStyle(document.body).fontFamily || 'system-ui';

                    const destroyCharts = () => {
                        Object.values(state.instances).forEach((instance) => {
                            try {
                                instance?.destroy();
                            } catch (_) {
                            }
                        });

                        state.instances = {};
                    };

                    const pageIsActive = () => Boolean(document.querySelector(rootSelector));

                    const chartData = () => {
                        const element = document.getElementById('admin-home-chart-data');

                        if (!element) {
                            return null;
                        }

                        try {
                            return JSON.parse(element.textContent || '{}');
                        } catch (_) {
                            return null;
                        }
                    };

                    const sharedPlugins = {
                        legend: {
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: {
                                    family: defaultFontFamily(),
                                },
                            },
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.94)',
                            titleFont: {
                                family: defaultFontFamily(),
                                weight: '600',
                            },
                            bodyFont: {
                                family: defaultFontFamily(),
                            },
                            padding: 12,
                            cornerRadius: 12,
                        },
                    };

                    const sharedScales = {
                        x: {
                            grid: {
                                display: false,
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    family: defaultFontFamily(),
                                },
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.18)',
                            },
                            ticks: {
                                color: '#64748b',
                                font: {
                                    family: defaultFontFamily(),
                                },
                            },
                        },
                    };

                    const renderCharts = () => {
                        if (!pageIsActive() || typeof Chart === 'undefined') {
                            return;
                        }

                        const payload = chartData();

                        if (!payload) {
                            return;
                        }

                        destroyCharts();

                        const activityCanvas = document.getElementById('adminHomeActivityChart');

                        if (activityCanvas) {
                            state.instances.activity = new Chart(activityCanvas, {
                                type: 'bar',
                                data: {
                                    labels: payload.activity.labels,
                                    datasets: [
                                        {
                                            type: 'bar',
                                            label: 'Jobs',
                                            data: payload.activity.jobs,
                                            backgroundColor: colorSet.tealSoft,
                                            borderColor: colorSet.teal,
                                            borderRadius: 10,
                                            borderSkipped: false,
                                            yAxisID: 'counts',
                                        },
                                        {
                                            type: 'line',
                                            label: 'New Customers',
                                            data: payload.activity.customers,
                                            borderColor: colorSet.indigo,
                                            backgroundColor: colorSet.indigoSoft,
                                            pointBackgroundColor: colorSet.indigo,
                                            pointRadius: 3,
                                            pointHoverRadius: 5,
                                            borderWidth: 2,
                                            tension: 0.35,
                                            fill: false,
                                            yAxisID: 'counts',
                                        },
                                        {
                                            type: 'line',
                                            label: 'Revenue',
                                            data: payload.activity.revenue,
                                            borderColor: colorSet.amber,
                                            backgroundColor: colorSet.amberSoft,
                                            pointBackgroundColor: colorSet.amber,
                                            pointRadius: 3,
                                            pointHoverRadius: 5,
                                            borderWidth: 2,
                                            tension: 0.35,
                                            fill: true,
                                            yAxisID: 'money',
                                        },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: {
                                        mode: 'index',
                                        intersect: false,
                                    },
                                    plugins: sharedPlugins,
                                    scales: {
                                        x: sharedScales.x,
                                        counts: {
                                            ...sharedScales.y,
                                            title: {
                                                display: true,
                                                text: 'Jobs / Customers',
                                                color: '#64748b',
                                                font: {
                                                    family: defaultFontFamily(),
                                                    weight: '600',
                                                },
                                            },
                                        },
                                        money: {
                                            beginAtZero: true,
                                            position: 'right',
                                            grid: {
                                                drawOnChartArea: false,
                                            },
                                            ticks: {
                                                color: '#64748b',
                                                callback: (value) => moneyFormatter.format(value),
                                                font: {
                                                    family: defaultFontFamily(),
                                                },
                                            },
                                        },
                                    },
                                },
                            });
                        }

                        const purchaseCanvas = document.getElementById('adminHomePurchaseMixChart');

                        if (purchaseCanvas) {
                            state.instances.purchaseMix = new Chart(purchaseCanvas, {
                                type: 'doughnut',
                                data: {
                                    labels: payload.purchase_mix.labels,
                                    datasets: [
                                        {
                                            data: payload.purchase_mix.revenue,
                                            backgroundColor: seriesColors(payload.purchase_mix.labels.length, 0.88),
                                            borderWidth: 0,
                                            hoverOffset: 8,
                                        },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    cutout: '68%',
                                    plugins: {
                                        ...sharedPlugins,
                                        tooltip: {
                                            ...sharedPlugins.tooltip,
                                            callbacks: {
                                                label: (context) => {
                                                    const index = context.dataIndex;
                                                    const orders = payload.purchase_mix.orders[index] ?? 0;
                                                    const credits = payload.purchase_mix.credits[index] ?? 0;

                                                    return [
                                                        `${context.label}: ${moneyFormatter.format(context.parsed || 0)}`,
                                                        `${numberFormatter.format(orders)} orders`,
                                                        `${numberFormatter.format(credits)} credits`,
                                                    ];
                                                },
                                            },
                                        },
                                    },
                                },
                            });
                        }

                        const toolsCanvas = document.getElementById('adminHomeToolsChart');

                        if (toolsCanvas) {
                            state.instances.tools = new Chart(toolsCanvas, {
                                type: 'bar',
                                data: {
                                    labels: payload.top_tools.labels,
                                    datasets: [
                                        {
                                            label: 'Credits',
                                            data: payload.top_tools.credits,
                                            backgroundColor: 'rgba(2, 132, 199, 0.78)',
                                            borderRadius: 12,
                                            borderSkipped: false,
                                        },
                                    ],
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        ...sharedPlugins,
                                        tooltip: {
                                            ...sharedPlugins.tooltip,
                                            callbacks: {
                                                title: (items) => {
                                                    const item = items[0];
                                                    const code = payload.top_tools.codes[item.dataIndex] ?? '';

                                                    return code ? `${item.label} (${code})` : item.label;
                                                },
                                                label: (context) => {
                                                    const jobs = payload.top_tools.jobs[context.dataIndex] ?? 0;

                                                    return [
                                                        `Credits: ${numberFormatter.format(context.parsed.x || 0)}`,
                                                        `Jobs: ${numberFormatter.format(jobs)}`,
                                                    ];
                                                },
                                            },
                                        },
                                    },
                                    scales: {
                                        x: {
                                            ...sharedScales.y,
                                            ticks: {
                                                color: '#64748b',
                                                callback: (value) => numberFormatter.format(value),
                                                font: {
                                                    family: defaultFontFamily(),
                                                },
                                            },
                                        },
                                        y: {
                                            ...sharedScales.x,
                                            ticks: {
                                                color: '#475569',
                                                font: {
                                                    family: defaultFontFamily(),
                                                    weight: '600',
                                                },
                                            },
                                        },
                                    },
                                },
                            });
                        }

                        const planCanvas = document.getElementById('adminHomePlanMixChart');

                        if (planCanvas) {
                            state.instances.planMix = new Chart(planCanvas, {
                                type: 'bar',
                                data: {
                                    labels: payload.plan_mix.labels,
                                    datasets: [
                                        {
                                            label: 'Active Subscribers',
                                            data: payload.plan_mix.subscribers,
                                            backgroundColor: seriesColors(payload.plan_mix.labels.length, 0.82),
                                            borderRadius: 12,
                                            borderSkipped: false,
                                        },
                                    ],
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        ...sharedPlugins,
                                        tooltip: {
                                            ...sharedPlugins.tooltip,
                                            callbacks: {
                                                title: (items) => {
                                                    const item = items[0];
                                                    const code = payload.plan_mix.codes[item.dataIndex] ?? '';

                                                    return code ? `${item.label} (${code})` : item.label;
                                                },
                                                label: (context) => {
                                                    const revenue = payload.plan_mix.revenue[context.dataIndex] ?? 0;

                                                    return [
                                                        `Subscribers: ${numberFormatter.format(context.parsed.y || 0)}`,
                                                        `Revenue: ${moneyFormatter.format(revenue)}`,
                                                    ];
                                                },
                                            },
                                        },
                                    },
                                    scales: sharedScales,
                                },
                            });
                        }
                    };

                    const bootCharts = () => {
                        if (!pageIsActive()) {
                            return;
                        }

                        if (!state.commitHooked && window.Livewire && typeof Livewire.hook === 'function') {
                            state.commitHooked = true;

                            Livewire.hook('commit', ({ succeed }) => {
                                succeed(() => {
                                    requestAnimationFrame(renderCharts);
                                });
                            });
                        }

                        state.booted = true;
                        requestAnimationFrame(renderCharts);
                    };

                    document.addEventListener('livewire:initialized', bootCharts);
                    document.addEventListener('livewire:navigated', bootCharts);
                    document.addEventListener('livewire:navigating', destroyCharts);

                    if (document.readyState !== 'loading') {
                        bootCharts();
                    } else {
                        document.addEventListener('DOMContentLoaded', bootCharts, { once: true });
                    }
                })();
            </script>
        @endonce
    @endpush
</div>
