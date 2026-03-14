<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new
#[Layout('app::layouts.app')]
#[Title('Billing | METKURD')]
class extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'period', keep: true)]
    public string $periodPreset = 'monthly'; // daily|weekly|monthly|custom

    #[Url(as: 'group', keep: true)]
    public string $groupBy = 'day'; // day|week|month

    #[Url(as: 'tool', keep: true)]
    public string $toolFilter = 'all';

    #[Url(as: 'status', keep: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'search', keep: true)]
    public string $search = '';

    #[Url(as: 'from', keep: true)]
    public ?string $dateFrom = null;

    #[Url(as: 'to', keep: true)]
    public ?string $dateTo = null;

    public int $jobsPerPage = 10;
    public int $billingPerPage = 10;

    public array $toolOptions = [
        'all'       => 'All Tools',
        'tts'       => 'TTS',
        'clone_tts' => 'Clone TTS',
        'stem'      => 'STEM',
        'wasr'      => 'WASR',
        'ocr'       => 'OCR',
    ];

    public function mount(): void
    {
        if (!$this->dateFrom || !$this->dateTo) {
            $this->applyPresetDates();
        }

        if (!in_array($this->groupBy, ['day', 'week', 'month'], true)) {
            $this->groupBy = 'day';
        }

        if (!array_key_exists($this->toolFilter, $this->toolOptions)) {
            $this->toolFilter = 'all';
        }
    }

    public function updatedPeriodPreset(): void
    {
        $this->applyPresetDates();

        if ($this->periodPreset === 'daily') {
            $this->groupBy = 'day';
        } elseif ($this->periodPreset === 'weekly') {
            $this->groupBy = 'day';
        } elseif ($this->periodPreset === 'monthly') {
            $this->groupBy = 'day';
        }

        $this->resetPagedTables();
    }

    public function updatedToolFilter(): void
    {
        $this->resetPagedTables();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPagedTables();
    }

    public function updatingSearch(): void
    {
        $this->resetPagedTables();
    }

    public function updatedDateFrom(): void
    {
        $this->normalizeDates();
        $this->periodPreset = 'custom';
        $this->resetPagedTables();
    }

    public function updatedDateTo(): void
    {
        $this->normalizeDates();
        $this->periodPreset = 'custom';
        $this->resetPagedTables();
    }

    public function updatedGroupBy(): void
    {
        if (!in_array($this->groupBy, ['day', 'week', 'month'], true)) {
            $this->groupBy = 'day';
        }

        $this->resetPagedTables();
    }

    public function resetFilters(): void
    {
        $this->periodPreset = 'monthly';
        $this->groupBy = 'day';
        $this->toolFilter = 'all';
        $this->statusFilter = 'all';
        $this->search = '';
        $this->applyPresetDates();
        $this->resetPagedTables();
    }

    protected function resetPagedTables(): void
    {
        $this->resetPage('jobsPage');
        $this->resetPage('billingPage');
    }

    protected function applyPresetDates(): void
    {
        $now = now();

        match ($this->periodPreset) {
            'daily' => [
                $this->dateFrom = $now->copy()->startOfDay()->toDateString(),
                $this->dateTo = $now->copy()->endOfDay()->toDateString(),
            ],
            'weekly' => [
                $this->dateFrom = $now->copy()->startOfWeek()->toDateString(),
                $this->dateTo = $now->copy()->endOfWeek()->toDateString(),
            ],
            default => [
                $this->dateFrom = $now->copy()->startOfMonth()->toDateString(),
                $this->dateTo = $now->copy()->endOfMonth()->toDateString(),
            ],
        };
    }

    protected function normalizeDates(): void
    {
        try {
            $from = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : now()->startOfMonth();
            $to = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : now()->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            $this->dateFrom = $from->toDateString();
            $this->dateTo = $to->toDateString();
        } catch (\Throwable) {
            $this->applyPresetDates();
        }
    }

    protected function rangeStart(): Carbon
    {
        return Carbon::parse($this->dateFrom ?: now()->startOfMonth()->toDateString())->startOfDay();
    }

    protected function rangeEnd(): Carbon
    {
        return Carbon::parse($this->dateTo ?: now()->toDateString())->endOfDay();
    }

    protected function customerId(): int
    {
        return (int) auth('app')->id();
    }

    protected function jobsBaseQuery()
    {
        $query = MlJob::query()
            ->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->whereNotIn('status', ['deleted']);

        if ($this->toolFilter !== 'all') {
            $query->where('job_kind', $this->toolFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('provider_job_id', 'like', "%{$search}%")
                    ->orWhere('job_kind', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    #[Computed]
    public function wallet(): ?CreditWallet
    {
        return CreditWallet::query()
            ->where('customer_id', $this->customerId())
            ->first();
    }

    #[Computed]
    public function usage(): ?CustomerUsage
    {
        return CustomerUsage::query()
            ->where('customer_id', $this->customerId())
            ->first();
    }

    #[Computed]
    public function topStats(): array
    {
        $wallet = $this->wallet();
        $usage = $this->usage();

        $jobs = (clone $this->jobsBaseQuery())->get();

        $creditsSpent = (int) $jobs->sum(fn ($job) => (int) ($job->credits_charged ?? 0));
        $storageIn = (int) $jobs->sum(fn ($job) => (int) ($job->storage_in_bytes ?? 0));
        $storageOut = (int) $jobs->sum(fn ($job) => (int) ($job->storage_out_bytes ?? 0));

        $orders = CreditOrder::query()
            ->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()]);

        $subscriptionPayments = (clone $orders)
            ->where(function ($q) {
                $q->where('order_type', 'subscription')
                  ->orWhere('source_type', 'service_plan');
            })
            ->where('status', 'paid')
            ->sum('amount_usd');

        $storagePayments = (clone $orders)
            ->where('source_type', 'storage_plan')
            ->where('status', 'paid')
            ->sum('amount_usd');

        $addonPayments = (clone $orders)
            ->where(function ($q) {
                $q->whereIn('order_type', ['addon', 'addon_purchase', 'credit'])
                  ->orWhereIn('source_type', ['credit_product', 'addon']);
            })
            ->where('status', 'paid')
            ->sum('amount_usd');

        return [
            'current_balance' => (int) ($wallet?->balance_credits ?? 0),
            'subscription_balance' => (int) ($wallet?->subscription_balance_credits ?? 0),
            'addon_balance' => (int) ($wallet?->addon_balance_credits ?? 0),
            'period_credits_spent' => $creditsSpent,
            'period_storage_in' => $storageIn,
            'period_storage_out' => $storageOut,
            'current_storage_used' => (int) ($usage?->storage_used_bytes ?? 0),
            'subscription_payments' => (float) $subscriptionPayments,
            'storage_payments' => (float) $storagePayments,
            'addon_payments' => (float) $addonPayments,
            'jobs_count' => $jobs->count(),
            'jobs_success' => $jobs->where('status', 'done')->count(),
            'jobs_failed' => $jobs->where('status', 'failed')->count(),
        ];
    }

    #[Computed]
    public function monthlyGrantCredits(): int
    {
        return (int) DB::table('credit_monthly_grants')
            ->where('customer_id', $this->customerId())
            ->whereBetween('granted_at', [$this->rangeStart(), $this->rangeEnd()])
            ->sum('granted_credits');
    }

    #[Computed]
    public function toolBreakdown(): Collection
    {
        $jobs = (clone $this->jobsBaseQuery())->get();

        return $jobs
            ->groupBy(fn ($job) => (string) ($job->job_kind ?: 'unknown'))
            ->map(function (Collection $rows, string $tool) {
                return [
                    'tool' => $tool,
                    'label' => $this->toolOptions[$tool] ?? Str::headline(str_replace('_', ' ', $tool)),
                    'jobs' => $rows->count(),
                    'success' => $rows->where('status', 'done')->count(),
                    'failed' => $rows->where('status', 'failed')->count(),
                    'credits' => (int) $rows->sum(fn ($job) => (int) ($job->credits_charged ?? 0)),
                    'storage_in' => (int) $rows->sum(fn ($job) => (int) ($job->storage_in_bytes ?? 0)),
                    'storage_out' => (int) $rows->sum(fn ($job) => (int) ($job->storage_out_bytes ?? 0)),
                    'provider_cost' => (float) $rows->sum(fn ($job) => (float) ($job->provider_cost_usd ?? 0)),
                ];
            })
            ->sortByDesc('credits')
            ->values();
    }

    #[Computed]
    public function timelineBreakdown(): Collection
    {
        $jobs = (clone $this->jobsBaseQuery())
            ->orderBy('created_at')
            ->get();

        return $jobs
            ->groupBy(function ($job) {
                $at = $job->created_at instanceof Carbon ? $job->created_at : Carbon::parse($job->created_at);

                return match ($this->groupBy) {
                    'week' => $at->copy()->startOfWeek()->format('Y-m-d'),
                    'month' => $at->format('Y-m'),
                    default => $at->format('Y-m-d'),
                };
            })
            ->map(function (Collection $rows, string $period) {
                return [
                    'period' => $period,
                    'jobs' => $rows->count(),
                    'success' => $rows->where('status', 'done')->count(),
                    'failed' => $rows->where('status', 'failed')->count(),
                    'credits' => (int) $rows->sum(fn ($job) => (int) ($job->credits_charged ?? 0)),
                    'storage_in' => (int) $rows->sum(fn ($job) => (int) ($job->storage_in_bytes ?? 0)),
                    'storage_out' => (int) $rows->sum(fn ($job) => (int) ($job->storage_out_bytes ?? 0)),
                ];
            })
            ->values();
    }

    #[Computed]
    public function jobsPaginator()
    {
        return (clone $this->jobsBaseQuery())
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $this->jobsPerPage,
                columns: ['*'],
                pageName: 'jobsPage',
                page: $this->getPage('jobsPage')
            );
    }

    #[Computed]
    public function billingActivityPaginator(): LengthAwarePaginator
    {
        $customerId = $this->customerId();
        $from = $this->rangeStart();
        $to = $this->rangeEnd();

        $orders = CreditOrder::query()
            ->where('customer_id', $customerId)
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->map(function (CreditOrder $order) {
                $category = match (true) {
                    $order->source_type === 'service_plan' || $order->order_type === 'subscription' => 'Subscription Payment',
                    $order->source_type === 'storage_plan' => 'Storage Payment',
                    in_array($order->source_type, ['credit_product', 'addon'], true) || in_array($order->order_type, ['addon', 'addon_purchase', 'credit'], true) => 'Addon Payment',
                    default => 'Payment',
                };

                return [
                    'row_type' => 'payment',
                    'timestamp' => $order->created_at,
                    'category' => $category,
                    'reference' => $order->provider_ref ?: ('ORDER-' . $order->id),
                    'tool' => null,
                    'description' => $order->meta['purpose'] ?? $order->source_type ?? $order->order_type,
                    'credits_delta' => (int) ($order->credits_amount ?? 0),
                    'amount_usd' => (float) ($order->amount_usd ?? 0),
                    'status' => (string) ($order->status ?? 'paid'),
                    'bucket' => null,
                ];
            });

        $ledgers = DB::table('credit_ledgers')
            ->where('customer_id', $customerId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row) {
                $meta = json_decode($row->meta ?? '[]', true) ?: [];

                return [
                    'row_type' => 'credit',
                    'timestamp' => Carbon::parse($row->created_at),
                    'category' => Str::headline(str_replace('_', ' ', (string) $row->type)),
                    'reference' => $row->reference_code ?: ('LEDGER-' . $row->id),
                    'tool' => $meta['tool'] ?? $meta['tool_code'] ?? $meta['job_kind'] ?? null,
                    'description' => $meta['purpose'] ?? $meta['plan_code'] ?? $meta['bucket_spent'] ?? $row->type,
                    'credits_delta' => (int) ($row->credits_delta ?? 0),
                    'amount_usd' => null,
                    'status' => $row->credits_delta >= 0 ? 'credit' : 'debit',
                    'bucket' => $row->bucket ?? null,
                ];
            });

        $items = $orders
            ->concat($ledgers)
            ->sortByDesc(fn ($row) => $row['timestamp'])
            ->values();

        $page = $this->getPage('billingPage');
        $perPage = $this->billingPerPage;

        return new LengthAwarePaginator(
            items: $items->forPage($page, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'pageName' => 'billingPage',
            ]
        );
    }

    public function money(float|int|null $value): string
    {
        return '$' . number_format((float) $value, 2);
    }

    public function formatCredits(int|float|null $value): string
    {
        return number_format((float) $value) . ' cr';
    }

    public function formatBytes(int|float|null $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }

    public function statusBadgeClass(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'done', 'paid', 'success', 'credit' => 'success',
            'failed', 'debit' => 'danger',
            'queued', 'running', 'saving', 'processing' => 'warning',
            default => 'secondary',
        };
    }

    public function toolBadgeClass(?string $tool): string
    {
        return match ((string) $tool) {
            'tts' => 'primary',
            'clone_tts' => 'info',
            'stem' => 'success',
            'wasr' => 'warning',
            'ocr' => 'danger',
            default => 'secondary',
        };
    }

    public function render()
    {
        return view('app.pages.billing.⚡app-billing');
    }
};
?>

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
    </style>



            <div class="row mb-3">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <div>
                            <h4 class="mb-sm-0">Billing & Usage</h4>
                            <div class="text-muted mt-1">Credits, subscriptions, storage, add-ons, and tool consumption in one place.</div>
                        </div>

                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="javascript:void(0);">Account</a></li>
                                <li class="breadcrumb-item active">Billing</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card billing-hero-card mb-4">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">Period</label>
                            <select class="form-select" wire:model.live="periodPreset">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">Group By</label>
                            <select class="form-select" wire:model.live="groupBy">
                                <option value="day">Daily</option>
                                <option value="week">Weekly</option>
                                <option value="month">Monthly</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">Tool</label>
                            <select class="form-select" wire:model.live="toolFilter">
                                @foreach($toolOptions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">Job Status</label>
                            <select class="form-select" wire:model.live="statusFilter">
                                <option value="all">All</option>
                                <option value="done">Done</option>
                                <option value="failed">Failed</option>
                                <option value="queued">Queued</option>
                                <option value="running">Running</option>
                                <option value="saving">Saving</option>
                            </select>
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">From</label>
                            <input type="date" class="form-control" wire:model.live="dateFrom">
                        </div>

                        <div class="col-xl-2 col-md-4">
                            <label class="form-label">To</label>
                            <input type="date" class="form-control" wire:model.live="dateTo">
                        </div>

                        <div class="col-xl-8">
                            <label class="form-label">Search</label>
                            <div class="position-relative">
                                <input
                                    type="text"
                                    class="form-control ps-5"
                                    placeholder="Search job ID, provider job ID, tool, or status..."
                                    wire:model.live.debounce.350ms="search"
                                >
                                <i class="ri-search-line position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                            </div>
                        </div>

                        <div class="col-xl-4">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="d-flex gap-2 justify-content-xl-end filter-chip-group">
                                <button type="button" class="btn btn-soft-secondary" wire:click="resetFilters">
                                    <i class="ri-refresh-line align-bottom me-1"></i> Reset
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
                                    <span class="badge bg-primary-subtle text-primary badge-border">Current</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatCredits($stats['current_balance']) }}</h3>
                            <h6 class="text-muted mb-2">Available Credits</h6>
                            <div class="mini-stat">
                                Subscription: <b>{{ $this->formatCredits($stats['subscription_balance']) }}</b><br>
                                Add-on: <b>{{ $this->formatCredits($stats['addon_balance']) }}</b>
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
                                    <span class="badge bg-warning-subtle text-warning badge-border">{{ ucfirst($periodPreset) }}</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatCredits($stats['period_credits_spent']) }}</h3>
                            <h6 class="text-muted mb-2">Credits Charged</h6>
                            <div class="mini-stat">
                                Jobs: <b>{{ number_format($stats['jobs_count']) }}</b><br>
                                Monthly grants in range: <b>{{ $this->formatCredits($this->monthlyGrantCredits()) }}</b>
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
                                    <span class="badge bg-info-subtle text-info badge-border">Storage</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->formatBytes($stats['current_storage_used']) }}</h3>
                            <h6 class="text-muted mb-2">Current Storage Used</h6>
                            <div class="mini-stat">
                                Input in range: <b>{{ $this->formatBytes($stats['period_storage_in']) }}</b><br>
                                Output in range: <b>{{ $this->formatBytes($stats['period_storage_out']) }}</b>
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
                                    <span class="badge bg-success-subtle text-success badge-border">Payments</span>
                                </div>
                            </div>
                            <h3 class="mb-2">{{ $this->money($stats['subscription_payments'] + $stats['storage_payments'] + $stats['addon_payments']) }}</h3>
                            <h6 class="text-muted mb-2">Paid in Selected Range</h6>
                            <div class="mini-stat">
                                Subscription: <b>{{ $this->money($stats['subscription_payments']) }}</b><br>
                                Storage: <b>{{ $this->money($stats['storage_payments']) }}</b><br>
                                Add-ons: <b>{{ $this->money($stats['addon_payments']) }}</b>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-1">
                <div class="col-xl-6">
                    <div class="card billing-table-card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Consumption Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive table-card">
                                <table class="table align-middle table-nowrap mb-0">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Period</th>
                                            <th>Jobs</th>
                                            <th>Success</th>
                                            <th>Failed</th>
                                            <th>Credits</th>
                                            <th>Input</th>
                                            <th>Output</th>
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
                                                <td colspan="7" class="text-center text-muted py-4">No usage found for this range.</td>
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
                            <h5 class="card-title mb-0">Tool Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive table-card">
                                <table class="table align-middle table-nowrap mb-0">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Tool</th>
                                            <th>Jobs</th>
                                            <th>Credits</th>
                                            <th>Input</th>
                                            <th>Output</th>
                                            <th>Status</th>
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
                                                    <span class="text-success">✔ {{ $row['success'] }}</span>
                                                    <span class="mx-1 text-muted">/</span>
                                                    <span class="text-danger">✖ {{ $row['failed'] }}</span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">No tool activity found.</td>
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
                            <h5 class="card-title mb-0">Tool Usage Transactions</h5>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <span class="text-muted">
                                {{ $this->jobsPaginator()->total() }} job record(s)
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive table-card">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light text-muted">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Tool</th>
                                    <th>Job ID</th>
                                    <th>Status</th>
                                    <th>Credits</th>
                                    <th>Input</th>
                                    <th>Output</th>
                                    <th>Total</th>
                                    <th>Provider Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($this->jobsPaginator() as $job)
                                    <tr wire:key="job-row-{{ $job->id }}">
                                        <td>{{ optional($job->created_at)->format('d M Y, h:i A') }}</td>
                                        <td>
                                            <span class="badge bg-{{ $this->toolBadgeClass($job->job_kind) }}-subtle text-{{ $this->toolBadgeClass($job->job_kind) }}">
                                                {{ $toolOptions[$job->job_kind] ?? Str::headline(str_replace('_', ' ', (string) $job->job_kind)) }}
                                            </span>
                                        </td>
                                        <td class="fw-semibold">{{ $job->id }}</td>
                                        <td>
                                            <span class="badge bg-{{ $this->statusBadgeClass($job->status) }}-subtle text-{{ $this->statusBadgeClass($job->status) }}">
                                                {{ Str::headline((string) $job->status) }}
                                            </span>
                                        </td>
                                        <td>{{ $this->formatCredits((int) ($job->credits_charged ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_in_bytes ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_out_bytes ?? 0)) }}</td>
                                        <td>{{ $this->formatBytes((int) ($job->storage_in_bytes ?? 0) + (int) ($job->storage_out_bytes ?? 0)) }}</td>
                                        <td>{{ $this->money((float) ($job->provider_cost_usd ?? 0)) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-4">No job transactions found.</td>
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
                            <h5 class="card-title mb-0">Billing Activity</h5>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <span class="text-muted">
                                Orders, grants, charges, refunds, and add-on activity
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="table-responsive table-card">
                        <table class="table align-middle table-nowrap mb-0">
                            <thead class="table-light text-muted">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Category</th>
                                    <th>Reference</th>
                                    <th>Tool / Bucket</th>
                                    <th>Details</th>
                                    <th>Credits</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($this->billingActivityPaginator() as $row)
                                    <tr>
                                        <td>{{ optional($row['timestamp'])->format('d M Y, h:i A') }}</td>
                                        <td class="fw-semibold">{{ $row['category'] }}</td>
                                        <td>{{ $row['reference'] }}</td>
                                        <td>
                                            @if($row['tool'])
                                                <span class="badge bg-{{ $this->toolBadgeClass($row['tool']) }}-subtle text-{{ $this->toolBadgeClass($row['tool']) }}">
                                                    {{ $toolOptions[$row['tool']] ?? Str::headline(str_replace('_', ' ', (string) $row['tool'])) }}
                                                </span>
                                            @elseif($row['bucket'])
                                                <span class="badge bg-secondary-subtle text-secondary">
                                                    {{ Str::headline((string) $row['bucket']) }}
                                                </span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>{{ Str::headline(str_replace('_', ' ', (string) $row['description'])) }}</td>
                                        <td>
                                            @if($row['credits_delta'] !== null)
                                                <span class="{{ $row['credits_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                    {{ $row['credits_delta'] > 0 ? '+' : '' }}{{ number_format($row['credits_delta']) }}
                                                </span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if($row['amount_usd'] !== null)
                                                {{ $this->money($row['amount_usd']) }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $this->statusBadgeClass($row['status']) }}-subtle text-{{ $this->statusBadgeClass($row['status']) }}">
                                                {{ Str::headline((string) $row['status']) }}
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No billing activity found.</td>
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