<?php

namespace App\Support\Admin;

use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\MlJob;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait ManagesCustomerRankingPage
{
    use InteractsWithCustomerAdmin;
    use SecureAdminComponent;

    #[Url(as: 'q', keep: true)]
    public string $search = '';

    #[Url(as: 'plan', keep: true)]
    public string $planFilter = 'all';

    #[Url(as: 'period', keep: true)]
    public string $periodFilter = '30';

    public int $rankingLimit = 20;

    public function boundedRankingLimit(): int
    {
        return in_array($this->rankingLimit, [20, 40, 60, 80, 100], true) ? $this->rankingLimit : 20;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->planFilter = 'all';
        $this->periodFilter = '30';
    }

    protected function rankingBaseQuery(): Builder
    {
        $query = $this->customersOverviewQuery($this->windowStartFromFilter($this->periodFilter));

        $this->applyCustomerSearch($query, $this->search);
        $this->applyPlanFilter($query, $this->planFilter);

        return $query;
    }

    #[Computed]
    public function topStats(): array
    {
        $windowStart = $this->windowStartFromFilter($this->periodFilter);
        $scope = Customer::query();
        $this->applyCustomerSearch($scope, $this->search);
        $this->applyPlanFilter($scope, $this->planFilter);

        $jobs = MlJob::query()
            ->where('status', '!=', 'deleted')
            ->whereHas('customer', function (Builder $customerQuery) {
                $this->applyCustomerSearch($customerQuery, $this->search);
                $this->applyPlanFilter($customerQuery, $this->planFilter);
            });

        $orders = CreditOrder::query()->revenueIncluded()->currentBillingPeriod()
            ->where('status', 'paid')
            ->whereHas('customer', function (Builder $customerQuery) {
                $this->applyCustomerSearch($customerQuery, $this->search);
                $this->applyPlanFilter($customerQuery, $this->planFilter);
            });

        if ($windowStart) {
            $jobs->where('created_at', '>=', $windowStart);
            $orders->where('created_at', '>=', $windowStart);
        }

        $servicePlanOrders = $this->scopeServicePlanOrders(clone $orders);
        $storageOrders = $this->scopeStorageOrders(clone $orders);
        $creditProductOrders = $this->scopeCreditProductOrders(clone $orders);
        $consumptionLeader = $this->consumptionRanking->first();
        $purchaseLeader = $this->purchaseRanking->first();

        return [
            'customers' => (int) $scope->count(),
            'credits_consumed' => (int) ((clone $jobs)->sum('credits_charged') ?? 0),
            'credits_purchased' => (int) ((clone $orders)->sum('credits_amount') ?? 0),
            'revenue' => (float) ((clone $orders)->sum('amount_usd') ?? 0),
            'service_plan_revenue' => (float) ((clone $servicePlanOrders)->sum('amount_usd') ?? 0),
            'storage_revenue' => (float) ((clone $storageOrders)->sum('amount_usd') ?? 0),
            'credit_product_revenue' => (float) ((clone $creditProductOrders)->sum('amount_usd') ?? 0),
            'top_consumer' => $consumptionLeader ? $this->customerDisplayName($consumptionLeader) : __('No activity'),
            'top_buyer' => $purchaseLeader ? $this->customerDisplayName($purchaseLeader) : __('No purchases'),
        ];
    }

    #[Computed]
    public function consumptionRanking()
    {
        return $this->rankingBaseQuery()
            ->orderByDesc('consumed_credits')
            ->orderByDesc('jobs_count')
            ->orderBy('customers.username')
            ->limit($this->boundedRankingLimit())
            ->get();
    }

    #[Computed]
    public function purchaseRanking()
    {
        return $this->rankingBaseQuery()
            ->orderByDesc('paid_order_credits')
            ->orderByDesc('paid_order_amount')
            ->orderBy('customers.username')
            ->limit($this->boundedRankingLimit())
            ->get();
    }
}
