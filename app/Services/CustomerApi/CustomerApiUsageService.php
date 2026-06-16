<?php

namespace App\Services\CustomerApi;

use App\Models\ApiJob;
use App\Models\ApiUsageLog;
use App\Models\Customer;

class CustomerApiUsageService
{
    public function summary(Customer $customer): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $logs = ApiUsageLog::query()->where('customer_id', (int) $customer->id);
        $jobs = ApiJob::query()->where('customer_id', (int) $customer->id);

        return [
            'period' => [
                'from' => $monthStart->toIso8601String(),
                'to' => $monthEnd->toIso8601String(),
            ],
            'requests' => [
                'this_month' => (clone $logs)->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
                'today' => (clone $logs)->whereDate('created_at', today())->count(),
            ],
            'jobs' => [
                'queued' => (clone $jobs)->where('status', 'queued')->count(),
                'processing' => (clone $jobs)->where('status', 'processing')->count(),
                'completed' => (clone $jobs)->where('status', 'completed')->count(),
                'failed' => (clone $jobs)->where('status', 'failed')->count(),
            ],
            'credits' => [
                'charged_this_month' => (int) ((clone $jobs)->whereBetween('created_at', [$monthStart, $monthEnd])->sum('final_credits') ?? 0),
                'reserved_active' => (int) ((clone $jobs)->whereIn('status', ['queued', 'processing'])->sum('reserved_credits') ?? 0),
            ],
        ];
    }
}
