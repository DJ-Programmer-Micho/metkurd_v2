<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Models\Payment;
use App\Services\Billing\BillingCurrencyService;
use App\Models\CreditOrder;
use App\Models\Customer;
use App\Models\CreditWallet;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Support\CustomerFacingToolName;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new
#[Layout('app::layouts.app')]
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
            'all'       => __('All Tools'),
            'tts'       => CustomerFacingToolName::translated('tts'),
            'xomni'     => CustomerFacingToolName::translated('xomni'),
            'ftts'      => CustomerFacingToolName::translated('ftts'),
            'clone_tts' => CustomerFacingToolName::translated('clone_tts'),
            'clone_xomni' => CustomerFacingToolName::translated('clone_xomni'),
            'stem'      => CustomerFacingToolName::translated('stem'),
            'wasr'      => CustomerFacingToolName::translated('asr'),
            'qasr'      => CustomerFacingToolName::translated('qasr'),
            'caption'   => CustomerFacingToolName::translated('caption'),
            'tran'      => CustomerFacingToolName::translated('tran'),
            'ocr'       => CustomerFacingToolName::translated('ocr'),
        ];

        if (!$this->dateFrom || !$this->dateTo) {
            $this->applyPresetDates();
        }

        if (!in_array($this->groupBy, ['day', 'week', 'month'], true)) {
            $this->groupBy = 'day';
        }

        if ($this->toolFilter !== 'all') {
            $this->toolFilter = $this->normalizeToolFilter($this->toolFilter);
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

        $orders = CreditOrder::query()
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
                    'reference' => $payment->providerReference() ?: ('CHECKOUT-' . $payment->id),
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

        $orders = CreditOrder::query()
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
                    'reference' => $order->provider_ref ?: ('ORDER-' . $order->id),
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
                    'reference' => $row->reference_code ?: ('LEDGER-' . $row->id),
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

        return $baseLabel . ' (' . $displayLabel . ')';
    }

    public function formatCredits(int|float|null $value): string
    {
        return number_format((float) $value) . ' ' . __('cr');
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
