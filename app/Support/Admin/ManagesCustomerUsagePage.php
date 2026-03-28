<?php

namespace App\Support\Admin;

use App\Models\MlJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerUsagePage
{
    use InteractsWithCustomerAdmin;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'customer', keep: true)]
    public string $customerFilter = 'all';

    #[Url(as: 'period', keep: true)]
    public string $periodFilter = '30';

    #[Url(as: 'jobs', keep: true)]
    public string $jobStatusFilter = 'all';

    public int $perPage = 10;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPeriodFilter(): void
    {
        $this->resetPage();
    }

    public function updatedJobStatusFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->customerFilter = 'all';
        $this->periodFilter = '30';
        $this->jobStatusFilter = 'all';
        $this->resetPage();
    }

    protected function filteredJobsQuery(): Builder
    {
        $query = MlJob::query()->where('status', '!=', 'deleted');
        $windowStart = $this->windowStartFromFilter($this->periodFilter);

        if ($windowStart) {
            $query->where('created_at', '>=', $windowStart);
        }

        if ($this->customerFilter !== 'all') {
            $query->where('customer_id', (int) $this->customerFilter);
        }

        if ($this->jobStatusFilter === 'done') {
            $query->where('status', 'done');
        } elseif ($this->jobStatusFilter === 'failed') {
            $query->where('status', 'failed');
        } elseif ($this->jobStatusFilter === 'active') {
            $query->whereIn('status', ['queued', 'running', 'saving']);
        }

        if (trim($this->search) !== '') {
            $query->whereHas('customer', fn (Builder $customerQuery) => $this->applyCustomerSearch($customerQuery, $this->search));
        }

        return $query;
    }

    protected function usageCustomersBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery($this->windowStartFromFilter($this->periodFilter), $this->jobStatusFilter);

        $this->applyCustomerSearch($query, $this->search);

        if ($this->customerFilter !== 'all') {
            $query->whereKey((int) $this->customerFilter);
        }

        return $query
            ->orderByDesc('consumed_credits')
            ->orderByDesc('jobs_count')
            ->orderBy('customers.username');
    }

    #[Computed]
    public function topStats(): array
    {
        $jobs = $this->filteredJobsQuery();
        $topTool = $this->toolBreakdown->first();

        return [
            'jobs' => (int) (clone $jobs)->count(),
            'credits' => (int) ((clone $jobs)->sum('credits_charged') ?? 0),
            'customers' => (int) (clone $jobs)->distinct('customer_id')->count('customer_id'),
            'top_tool' => $topTool?->tool_name ?? __('No usage yet'),
        ];
    }

    #[Computed]
    public function customerUsageRows()
    {
        return $this->usageCustomersBaseQuery()->paginate($this->perPage);
    }

    #[Computed]
    public function selectedCustomer()
    {
        if ($this->customerFilter === 'all') {
            return null;
        }

        return $this->customersOverviewQuery($this->windowStartFromFilter($this->periodFilter), $this->jobStatusFilter)
            ->find((int) $this->customerFilter);
    }

    #[Computed]
    public function toolBreakdown()
    {
        $toolExpr = "COALESCE(tools.name, action_tools.name, ml_jobs.job_kind, 'Unknown Tool')";
        $toolCodeExpr = "COALESCE(tools.code, action_tools.code, tool_actions.tool_code, ml_jobs.job_kind, 'unknown')";
        $actionExpr = "COALESCE(tool_actions.name, 'Default Action')";
        $actionCodeExpr = "COALESCE(tool_actions.full_code, CONCAT(COALESCE(tools.code, action_tools.code, tool_actions.tool_code, ml_jobs.job_kind, 'unknown'), '.default'))";

        $query = DB::table('ml_jobs')
            ->leftJoin('tool_actions', 'tool_actions.id', '=', 'ml_jobs.tool_action_id')
            ->leftJoin('tools', 'tools.id', '=', 'ml_jobs.tool_id')
            ->leftJoin('tools as action_tools', 'action_tools.code', '=', 'tool_actions.tool_code')
            ->leftJoin('customers', 'customers.id', '=', 'ml_jobs.customer_id')
            ->leftJoin('customer_profiles', 'customer_profiles.customer_id', '=', 'customers.id')
            ->where('ml_jobs.status', '!=', 'deleted');

        $windowStart = $this->windowStartFromFilter($this->periodFilter);

        if ($windowStart) {
            $query->where('ml_jobs.created_at', '>=', $windowStart);
        }

        if ($this->customerFilter !== 'all') {
            $query->where('ml_jobs.customer_id', (int) $this->customerFilter);
        }

        if ($this->jobStatusFilter === 'done') {
            $query->where('ml_jobs.status', 'done');
        } elseif ($this->jobStatusFilter === 'failed') {
            $query->where('ml_jobs.status', 'failed');
        } elseif ($this->jobStatusFilter === 'active') {
            $query->whereIn('ml_jobs.status', ['queued', 'running', 'saving']);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('customers.username', 'like', "%{$search}%")
                    ->orWhere('customers.email', 'like', "%{$search}%")
                    ->orWhere('customer_profiles.first_name', 'like', "%{$search}%")
                    ->orWhere('customer_profiles.last_name', 'like', "%{$search}%")
                    ->orWhere('customer_profiles.brand_name', 'like', "%{$search}%");
            });
        }

        return $query
            ->selectRaw($toolExpr . ' as tool_name')
            ->selectRaw($toolCodeExpr . ' as tool_code')
            ->selectRaw($actionExpr . ' as action_name')
            ->selectRaw($actionCodeExpr . ' as action_code')
            ->selectRaw('COUNT(*) as jobs')
            ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'done' THEN 1 ELSE 0 END) as done_jobs")
            ->selectRaw("SUM(CASE WHEN ml_jobs.status = 'failed' THEN 1 ELSE 0 END) as failed_jobs")
            ->selectRaw('SUM(COALESCE(ml_jobs.credits_charged, 0)) as consumed_credits')
            ->groupBy(DB::raw($toolExpr))
            ->groupBy(DB::raw($toolCodeExpr))
            ->groupBy(DB::raw($actionExpr))
            ->groupBy(DB::raw($actionCodeExpr))
            ->orderByDesc('jobs')
            ->orderByDesc('consumed_credits')
            ->limit(20)
            ->get();
    }

    public function focusCustomer(int $customerId): void
    {
        $this->customerFilter = (string) $customerId;
    }

    public function clearFocusedCustomer(): void
    {
        $this->customerFilter = 'all';
    }
}
