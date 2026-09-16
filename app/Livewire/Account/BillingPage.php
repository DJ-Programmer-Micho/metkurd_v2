<?php

namespace App\Livewire\Account;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\Billing\BillingCurrencyService;
use App\Support\CustomerFacingToolName;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

abstract class BillingPage extends Component
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

    public array $toolOptions = [];

    protected function orderBaseAmountIqd(CreditOrder $order): int
    {
        if ($order->base_amount_iqd !== null) {
            return (int) round((float) $order->base_amount_iqd);
        }

        return app(BillingCurrencyService::class)->legacyUsdAmountToIqd($order->amount_usd);
    }

    public function mount(): void
    {
        $this->toolOptions = [
            'all' => __('All Tools'),
            'tts' => CustomerFacingToolName::translated('tts'),
            'xomni' => CustomerFacingToolName::translated('xomni'),
            'xomni-v2' => CustomerFacingToolName::translated('xomni-v2'),
            'ftts' => CustomerFacingToolName::translated('ftts'),
            'clone_tts' => CustomerFacingToolName::translated('clone_tts'),
            'clone_xomni' => CustomerFacingToolName::translated('clone_xomni'),
            'stem' => CustomerFacingToolName::translated('stem'),
            'wasr' => CustomerFacingToolName::translated('asr'),
            'qasr' => CustomerFacingToolName::translated('qasr'),
            'caption' => CustomerFacingToolName::translated('caption'),
            'tran' => CustomerFacingToolName::translated('tran'),
            'ocr' => CustomerFacingToolName::translated('ocr'),
        ];

        if (! $this->dateFrom || ! $this->dateTo) {
            $this->applyPresetDates();
        }

        if (! in_array($this->groupBy, ['day', 'week', 'month'], true)) {
            $this->groupBy = 'day';
        }

        if ($this->toolFilter !== 'all') {
            $this->toolFilter = $this->normalizeToolFilter($this->toolFilter);
        }

        if (! array_key_exists($this->toolFilter, $this->toolOptions)) {
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
        if (! in_array($this->groupBy, ['day', 'week', 'month'], true)) {
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

    #[Computed]
    public function customer(): ?Customer
    {
        $customerId = $this->customerId();

        if ($customerId <= 0) {
            return null;
        }

        return Customer::query()
            ->with([
                'profile',
                'usage',
                'wallet',
                'activeServiceSubscription.servicePlan',
                'activeStorageSubscription.storagePlan',
            ])
            ->find($customerId);
    }

    #[Computed]
    public function serviceState(): array
    {
        $customer = $this->customer();

        return $customer ? $customer->servicePlanState() : [];
    }

    #[Computed]
    public function storageState(): array
    {
        $customer = $this->customer();

        return $customer ? $customer->storageQuotaState() : [];
    }

    #[Computed]
    public function latestCheckout(): ?Payment
    {
        return Payment::query()
            ->where('customer_id', $this->customerId())
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function openCheckoutCount(): int
    {
        return Payment::query()
            ->where('customer_id', $this->customerId())
            ->whereIn('status', ['pending', 'awaiting_customer_action'])
            ->count();
    }

    protected function jobsBaseQuery()
    {
        $query = MlJob::query()
            ->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->whereNotIn('status', ['deleted']);

        if ($this->toolFilter !== 'all') {
            $filterCodes = CustomerFacingToolName::filterCodes($this->toolFilter);

            if (count($filterCodes) > 1) {
                $query->whereIn('job_kind', $filterCodes);
            } elseif ($filterCodes !== []) {
                $query->where('job_kind', $filterCodes[0]);
            } else {
                $query->where('job_kind', $this->toolFilter);
            }
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
    public function jobsSnapshot(): Collection
    {
        return (clone $this->jobsBaseQuery())
            ->orderBy('created_at')
            ->get([
                'id',
                'provider_job_id',
                'job_kind',
                'status',
                'credits_charged',
                'storage_in_bytes',
                'storage_out_bytes',
                'provider_cost_usd',
                'created_at',
            ]);
    }

    #[Computed]
    public function topStats(): array
    {
        $wallet = $this->wallet();
        $usage = $this->usage();

        $jobs = $this->jobsSnapshot();

        $creditsSpent = (int) $jobs->sum(fn ($job) => (int) ($job->credits_charged ?? 0));
        $storageIn = (int) $jobs->sum(fn ($job) => (int) ($job->storage_in_bytes ?? 0));
        $storageOut = (int) $jobs->sum(fn ($job) => (int) ($job->storage_out_bytes ?? 0));

        $orders = CreditOrder::query()->currentBillingPeriod()->revenueIncluded()
            ->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->where('status', 'paid')
            ->get([
                'id',
                'order_type',
                'source_type',
                'amount_usd',
                'base_amount_iqd',
            ]);

        $subscriptionPayments = $orders
            ->filter(fn (CreditOrder $order) => $order->order_type === 'subscription' || $order->source_type === 'service_plan')
            ->sum(fn (CreditOrder $order) => $this->orderBaseAmountIqd($order));

        $storagePayments = $orders
            ->filter(fn (CreditOrder $order) => $order->source_type === 'storage_plan')
            ->sum(fn (CreditOrder $order) => $this->orderBaseAmountIqd($order));

        $addonPayments = $orders
            ->filter(fn (CreditOrder $order) => in_array($order->order_type, ['addon', 'addon_purchase', 'credit'], true)
                || in_array($order->source_type, ['credit_product', 'addon'], true))
            ->sum(fn (CreditOrder $order) => $this->orderBaseAmountIqd($order));

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
        $jobs = $this->jobsSnapshot();

        return $jobs
            ->groupBy(fn ($job) => (string) ($job->job_kind ?: 'unknown'))
            ->map(function (Collection $rows, string $tool) {
                return [
                    'tool' => $tool,
                    'label' => $this->toolLabel($tool),
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
        $jobs = $this->jobsSnapshot();

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
                columns: [
                    'id',
                    'provider_job_id',
                    'job_kind',
                    'status',
                    'credits_charged',
                    'storage_in_bytes',
                    'storage_out_bytes',
                    'provider_cost_usd',
                    'created_at',
                ],
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

        $checkouts = Payment::query()
            ->where('customer_id', $customerId)
            ->whereBetween('created_at', [$from, $to])
            ->latest('created_at')
            ->get()
            ->map(function (Payment $payment) {
                $snapshot = $payment->snapshot();
                $baseDisplay = (array) data_get($snapshot, 'base_display', []);
                $originalDisplay = (array) data_get($snapshot, 'original_display', []);
                $discountDisplay = (array) data_get($snapshot, 'discount_display', []);
                $billingCycle = (string) data_get($snapshot, 'billing_cycle', '');
                $flowLabel = $this->flowLabel($payment->payment_mode?->value, $billingCycle);
                $status = $payment->applicationStatusLabel();
                $providerStatus = trim((string) ($payment->providerStatusLabel() ?? ''));

                return [
                    'row_type' => 'checkout',
                    'timestamp' => $payment->created_at,
                    'category' => $this->paymentActivityCategory($payment),
                    'reference' => $payment->providerReference() ?: ('CHECKOUT-'.$payment->id),
                    'support_label' => $flowLabel,
                    'support_badge_class' => $this->flowBadgeClass($flowLabel),
                    'description' => (string) (data_get($snapshot, 'name') ?: data_get($snapshot, 'code') ?: $payment->local_reference),
                    'details_hint' => $this->checkoutStatusHint($payment),
                    'lifecycle_details' => $this->checkoutLifecycleDetails($payment, $providerStatus !== '' ? $providerStatus : null),
                    'credits_delta' => null,
                    'base_amount_iqd' => (int) round((float) ($payment->discounted_amount_iqd ?? data_get($snapshot, 'amount_iqd', round((float) $payment->amount)))),
                    'display_amount' => data_get($baseDisplay, 'display_amount_rounded'),
                    'display_currency_code' => (string) data_get($baseDisplay, 'display_currency_code', ''),
                    'original_amount_iqd' => (int) round((float) ($payment->original_amount_iqd ?? data_get($snapshot, 'original_amount_iqd', 0))),
                    'original_display_amount' => data_get($originalDisplay, 'display_amount_rounded'),
                    'original_display_currency_code' => (string) data_get($originalDisplay, 'display_currency_code', ''),
                    'discount_amount_iqd' => (int) round((float) ($payment->discount_amount_iqd ?? data_get($snapshot, 'discount_amount_iqd', 0))),
                    'discount_display_amount' => data_get($discountDisplay, 'display_amount_rounded'),
                    'discount_display_currency_code' => (string) data_get($discountDisplay, 'display_currency_code', ''),
                    'status' => $status,
                    'status_label' => $status,
                    'coupon_code' => (string) ($payment->coupon_code ?? ''),
                    'action_url' => route('payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $payment]),
                ];
            });

        $orders = CreditOrder::query()->currentBillingPeriod()->revenueIncluded()
            ->with(['servicePlan', 'creditProduct', 'payment'])
            ->where('customer_id', $customerId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('payment_id')
            ->get()
            ->map(function (CreditOrder $order) {
                $category = match (true) {
                    $order->source_type === 'service_plan' || $order->order_type === 'subscription' => __('Plan Charge'),
                    $order->source_type === 'storage_plan' => __('Storage Charge'),
                    in_array($order->source_type, ['credit_product', 'addon'], true) || in_array($order->order_type, ['addon', 'addon_purchase', 'credit'], true) => __('Add-on Charge'),
                    default => __('Payment'),
                };
                $billingCycle = (string) data_get($order->meta, 'billing_cycle', '');
                $flowLabel = $order->source_type === 'service_plan' || $order->source_type === 'storage_plan'
                    ? $this->flowLabel('recurring', $billingCycle)
                    : $this->flowLabel('one_time');

                return [
                    'row_type' => 'order',
                    'timestamp' => $order->created_at,
                    'category' => $category,
                    'reference' => $order->provider_ref ?: ('ORDER-'.$order->id),
                    'support_label' => $flowLabel,
                    'support_badge_class' => $this->flowBadgeClass($flowLabel),
                    'description' => (string) (
                        data_get($order->meta, 'storage_plan_name')
                        ?: $order->servicePlan?->name
                        ?: $order->creditProduct?->name
                        ?: data_get($order->meta, 'plan_code')
                        ?: data_get($order->meta, 'storage_plan_code')
                        ?: data_get($order->meta, 'purpose')
                        ?: $order->source_type
                        ?: $order->order_type
                    ),
                    'details_hint' => $this->paidOrderHint($order),
                    'lifecycle_details' => [],
                    'credits_delta' => (int) ($order->credits_amount ?? 0),
                    'base_amount_iqd' => $this->orderBaseAmountIqd($order),
                    'display_amount' => $order->display_amount_rounded !== null ? (float) $order->display_amount_rounded : null,
                    'display_currency_code' => (string) ($order->display_currency_code ?? ''),
                    'original_amount_iqd' => (int) round((float) ($order->original_amount_iqd ?? $this->orderBaseAmountIqd($order))),
                    'original_display_amount' => data_get(data_get($order->meta, 'coupon', []), 'original_display.display_amount_rounded', $order->display_amount_rounded),
                    'original_display_currency_code' => (string) data_get(data_get($order->meta, 'coupon', []), 'original_display.display_currency_code', $order->display_currency_code ?? ''),
                    'discount_amount_iqd' => (int) round((float) ($order->discount_amount_iqd ?? 0)),
                    'discount_display_amount' => data_get(data_get($order->meta, 'coupon', []), 'discount_display.display_amount_rounded'),
                    'discount_display_currency_code' => (string) data_get(data_get($order->meta, 'coupon', []), 'discount_display.display_currency_code', $order->display_currency_code ?? ''),
                    'status' => (string) ($order->status ?? 'paid'),
                    'status_label' => (string) ($order->status ?? 'paid'),
                    'coupon_code' => (string) ($order->coupon_code ?? ''),
                    'action_url' => $order->payment ? route('payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $order->payment]) : null,
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
                    'category' => __(Str::headline(str_replace('_', ' ', (string) $row->type))),
                    'reference' => $row->reference_code ?: ('LEDGER-'.$row->id),
                    'support_label' => $meta['tool'] ?? $meta['tool_code'] ?? $meta['job_kind'] ?? $row->bucket ?? null,
                    'support_badge_class' => $this->toolBadgeClass($meta['tool'] ?? $meta['tool_code'] ?? $meta['job_kind'] ?? null),
                    'description' => $meta['purpose'] ?? $meta['plan_code'] ?? $meta['bucket_spent'] ?? $row->type,
                    'details_hint' => null,
                    'lifecycle_details' => [],
                    'credits_delta' => (int) ($row->credits_delta ?? 0),
                    'base_amount_iqd' => null,
                    'display_amount' => null,
                    'display_currency_code' => null,
                    'original_amount_iqd' => null,
                    'original_display_amount' => null,
                    'original_display_currency_code' => null,
                    'discount_amount_iqd' => 0,
                    'discount_display_amount' => null,
                    'discount_display_currency_code' => null,
                    'status' => $row->credits_delta >= 0 ? __('credit') : __('debit'),
                    'status_label' => $row->credits_delta >= 0 ? __('credit') : __('debit'),
                    'coupon_code' => '',
                    'action_url' => null,
                ];
            });

        $items = $checkouts
            ->concat($orders)
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
        return app(BillingCurrencyService::class)->formatAmount((float) $value, 'IQD');
    }

    public function usdMoney(float|int|null $value): string
    {
        return app(BillingCurrencyService::class)->formatAmount((float) $value, 'USD');
    }

    public function moneyWithDisplay(int|float|null $baseIqdValue, float|int|string|null $displayAmount = null, ?string $displayCurrencyCode = null): string
    {
        $baseLabel = $this->money($baseIqdValue);
        $displayCurrencyCode = strtoupper(trim((string) $displayCurrencyCode));

        if ($displayAmount === null || $displayCurrencyCode === '' || $displayCurrencyCode === 'IQD') {
            return $baseLabel;
        }

        $displayLabel = app(BillingCurrencyService::class)->formatAmount((float) $displayAmount, $displayCurrencyCode);

        return $baseLabel.' ('.$displayLabel.')';
    }

    public function formatCredits(int|float|null $value): string
    {
        return number_format((float) $value).' '.__('cr');
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

        return number_format($bytes, $i === 0 ? 0 : 2).' '.$units[$i];
    }

    public function formatTimestamp(mixed $value): string
    {
        if (! $value) {
            return __('Unknown');
        }

        try {
            $timestamp = $value instanceof Carbon ? $value : Carbon::parse((string) $value);

            return $timestamp->timezone(config('app.timezone'))->format('d M Y, h:i A');
        } catch (\Throwable) {
            return __('Unknown');
        }
    }

    public function checkoutStatusHint(Payment $payment): ?string
    {
        $status = strtolower((string) ($payment->status?->value ?? $payment->status ?? 'pending'));
        $applicationStatus = strtolower($payment->applicationStatusLabel());

        if ($applicationStatus === 'requires_review') {
            return $payment->reviewMessage()
                ?: __('Payment received and queued for manual review before access is changed.');
        }

        if ($applicationStatus === 'payment_received') {
            return __('Payment received. We are applying your access now.');
        }

        if ($applicationStatus === 'applied') {
            return $payment->active_until
                ? __('Applied successfully. Active until :date', ['date' => $this->formatTimestamp($payment->active_until)])
                : __('Payment was applied successfully.');
        }

        return match ($status) {
            'awaiting_customer_action', 'pending' => $payment->valid_until
                ? __('Complete checkout before :date', ['date' => $this->formatTimestamp($payment->valid_until)])
                : __('Waiting for provider confirmation.'),
            'paid' => __('Payment was confirmed successfully.'),
            'canceled' => $payment->active_until
                ? __('Cancellation is scheduled. Access remains until :date', ['date' => $this->formatTimestamp($payment->active_until)])
                : __('This checkout was canceled.'),
            'expired' => $payment->expired_at
                ? __('Expired on :date', ['date' => $this->formatTimestamp($payment->expired_at)])
                : __('This checkout expired before completion.'),
            'failed' => trim((string) ($payment->status_reason ?? '')) !== ''
                ? trim((string) $payment->status_reason)
                : __('Provider confirmation did not complete.'),
            default => null,
        };
    }

    public function checkoutLifecycleDetails(Payment $payment, ?string $providerStatus = null): array
    {
        $meta = (array) ($payment->meta ?? []);
        $lifecycle = (array) data_get($meta, 'subscription_lifecycle', []);
        $cancelSource = trim((string) data_get($lifecycle, 'cancel_source', ''));
        $syncSource = trim((string) data_get($lifecycle, 'sync_source', ''));
        $facts = [];

        if ($providerStatus !== null && trim($providerStatus) !== '') {
            $facts[] = __('Provider status: :status', ['status' => $providerStatus]);
        }

        $facts[] = __('Application status: :status', [
            'status' => Str::headline(str_replace('_', ' ', $payment->applicationStatusLabel())),
        ]);

        $facts[] = __('Local status: :status', [
            'status' => Str::headline((string) ($payment->status?->value ?? $payment->status ?? 'pending')),
        ]);

        if ($payment->reviewMessage()) {
            $facts[] = __('Review reason: :reason', ['reason' => $payment->reviewMessage()]);
        }

        if ($payment->last_payment_at) {
            $facts[] = __('Last payment at: :date', ['date' => $this->formatTimestamp($payment->last_payment_at)]);
        }

        if ($payment->active_until) {
            $facts[] = __('Active until: :date', ['date' => $this->formatTimestamp($payment->active_until)]);
        }

        if ($payment->last_status_checked_at) {
            $facts[] = __('Last sync: :date', ['date' => $this->formatTimestamp($payment->last_status_checked_at)]);
        }

        if ($payment->last_callback_received_at) {
            $facts[] = __('Callback received: :date', ['date' => $this->formatTimestamp($payment->last_callback_received_at)]);
        }

        if ($payment->payment_mode === PaymentMode::RECURRING && $syncSource !== '') {
            $facts[] = __('Sync source: :source', ['source' => Str::headline(str_replace('_', ' ', $syncSource))]);
        }

        if ($payment->payment_mode === PaymentMode::RECURRING && $cancelSource !== '') {
            $facts[] = __('Cancellation source: :source', ['source' => Str::headline(str_replace('_', ' ', $cancelSource))]);
        }

        return $facts;
    }

    public function paidOrderHint(CreditOrder $order): ?string
    {
        $couponCode = trim((string) ($order->coupon_code ?? ''));
        $billingCycle = $this->billingCycleLabel((string) data_get($order->meta, 'billing_cycle', ''));

        if ($couponCode !== '' && $billingCycle !== null) {
            return __('Coupon :code applied on :cycle billing.', [
                'code' => $couponCode,
                'cycle' => strtolower($billingCycle),
            ]);
        }

        if ($couponCode !== '') {
            return __('Coupon :code applied.', ['code' => $couponCode]);
        }

        if ($billingCycle !== null) {
            return __('Charged on the :cycle cycle.', ['cycle' => strtolower($billingCycle)]);
        }

        return null;
    }

    public function statusBadgeClass(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'done', 'paid', 'success', 'credit', 'fulfilled', 'applied' => 'success',
            'payment_received', 'paid_pending_application' => 'info',
            'requires_review' => 'warning',
            'failed', 'debit' => 'danger',
            'queued', 'running', 'saving', 'processing', 'pending', 'awaiting_customer_action', 'awaiting customer action' => 'warning',
            'canceled', 'cancelled', 'expired' => 'secondary',
            default => 'secondary',
        };
    }

    public function toolBadgeClass(?string $tool): string
    {
        return match (CustomerFacingToolName::canonical($tool)) {
            'tts', 'xomni' => 'primary',
            'ftts' => 'info',
            'clone_tts', 'clone_xomni' => 'info',
            'stem' => 'success',
            'asr' => 'warning',
            'qasr' => 'warning',
            'caption' => 'warning',
            'tran' => 'primary',
            'ocr' => 'danger',
            default => 'secondary',
        };
    }

    public function toolLabel(?string $tool): string
    {
        return CustomerFacingToolName::translated($tool);
    }

    public function supportLabel(?string $label, ?string $rowType = null): string
    {
        $label = trim((string) $label);

        if ($label === '') {
            return '';
        }

        if (in_array($rowType, ['checkout', 'order'], true)) {
            return $label;
        }

        return $this->toolLabel($label);
    }

    protected function normalizeToolFilter(string $tool): string
    {
        return match (CustomerFacingToolName::canonical($tool)) {
            'asr' => 'wasr',
            default => CustomerFacingToolName::canonical($tool),
        };
    }

    public function billingCycleLabel(?string $billingCycle): ?string
    {
        return match (strtolower(trim((string) $billingCycle))) {
            'monthly' => __('Monthly'),
            'yearly' => __('Yearly'),
            'hourly' => __('Hourly Test'),
            default => null,
        };
    }

    public function paymentActivityCategory(Payment $payment): string
    {
        return match ($payment->purchase_type?->value) {
            'plan_subscription' => __('Plan Subscription Checkout'),
            'storage_subscription' => __('Storage Subscription Checkout'),
            'addon_credits' => __('Add-on Checkout'),
            default => __('Checkout'),
        };
    }

    public function flowLabel(?string $paymentMode = null, ?string $billingCycle = null): string
    {
        $paymentMode = strtolower(trim((string) $paymentMode));
        $cycleLabel = $this->billingCycleLabel($billingCycle);

        if ($paymentMode === 'recurring') {
            return $cycleLabel ? __('Recurring - :cycle', ['cycle' => $cycleLabel]) : __('Recurring');
        }

        return __('One-time');
    }

    public function flowBadgeClass(?string $label): string
    {
        $label = strtolower(trim((string) $label));

        if (str_contains($label, 'recurring')) {
            return 'primary';
        }

        if (str_contains($label, 'bucket')) {
            return 'secondary';
        }

        return 'info';
    }
}
