<?php

namespace App\Livewire\Account;

use App\Domain\Payments\Models\Payment;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\CustomerServiceSubscription;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Support\CustomerFacingToolName;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

abstract class V2BillingPage extends BillingPage
{
    public string $chartMetric = 'credits';

    public bool $confirmCancellation = false;

    public function mount(): void
    {
        $tool = $this->toolFilter;
        parent::mount();
        foreach (['vector-v2', 'leo'] as $code) {
            $this->toolOptions[$code] = CustomerFacingToolName::translated($code);
        }
        $this->toolFilter = array_key_exists($tool, $this->toolOptions) ? $tool : $this->toolFilter;
        $this->normalizeDates();
    }

    protected function resetPagedTables(): void
    {
        parent::resetPagedTables();
        $this->resetPage('ledgerPage');
    }

    #[Computed]
    public function walletBalances(): array
    {
        $wallets = CreditWallet::where('customer_id', $this->customerId())->get()->keyBy('wallet_type');

        return collect(['app', 'api'])->mapWithKeys(fn ($type) => [$type => [
            'balance' => (int) ($wallets->get($type)?->balance_credits ?? 0),
            'subscription' => (int) ($wallets->get($type)?->subscription_balance_credits ?? 0),
            'addon' => (int) ($wallets->get($type)?->addon_balance_credits ?? 0),
        ]])->all();
    }

    #[Computed]
    public function complimentary(): bool
    {
        $s = $this->serviceState()['subscription'] ?? null;

        return $s && ! CustomerServiceSubscription::whereKey($s->id)->excludingComplimentary()->exists();
    }

    #[Computed]
    public function paymentHistory()
    {
        $native = Payment::query()->currentBillingPeriod()->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->select(['id', 'created_at'])->selectRaw("'payment' as kind");
        $legacy = CreditOrder::query()->currentBillingPeriod()->revenueIncluded()->where('customer_id', $this->customerId())->whereNull('payment_id')
            ->where(fn ($q) => $q->whereNull('provider')->orWhereNotIn('provider', ['admin_manual', 'admin_manual_grant', 'internal_non_revenue']))
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->select(['id', 'created_at'])->selectRaw("'order' as kind");
        $page = DB::query()->fromSub($native->unionAll($legacy), 'history')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(10, ['*'], 'billingPage', $this->getPage('billingPage'));
        $payments = Payment::with('purchasable')->where('customer_id', $this->customerId())
            ->whereIn('id', $page->getCollection()->where('kind', 'payment')->pluck('id'))->get()->keyBy('id');
        $orders = CreditOrder::with(['servicePlan', 'creditProduct'])->where('customer_id', $this->customerId())
            ->whereIn('id', $page->getCollection()->where('kind', 'order')->pluck('id'))->get()->keyBy('id');
        $page->setCollection($page->getCollection()->map(function ($row) use ($payments, $orders) {
            $p = $row->kind === 'payment' ? $payments[$row->id] : $orders[$row->id];
            $native = $row->kind === 'payment';

            return [
                'key' => $row->kind.'-'.$p->id, 'date' => $p->created_at,
                'product' => $native ? ($p->purchasable?->name ?? __('Payment')) : ($p->servicePlan?->name ?? $p->creditProduct?->name ?? __('Payment')),
                'amount' => $native ? number_format((float) $p->amount, 0).' '.$p->currency : $this->moneyWithDisplay($this->orderBaseAmountIqd($p), $p->display_amount_rounded, $p->display_currency_code),
                'status' => $native && $p->provider?->value === 'fib' ? app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->state($p) : ($native ? $p->applicationStatusLabel() : $p->status),
                'method' => in_array($native ? $p->provider?->value : $p->provider, ['fib', 'areeba'], true) ? strtoupper($native ? $p->provider->value : $p->provider) : __('account_v2.other_method'),
                'flow' => $native ? $this->flowLabel($p->payment_mode?->value, data_get($p->purchase_snapshot, 'billing_cycle')) : __('account_v2.previous_purchase'),
                'url' => $native && $p->provider?->value === 'fib' ? route('app.v2.payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $p]) : null,
            ];
        }));

        return $page;
    }

    #[Computed]
    public function creditActivity()
    {
        return DB::table('credit_ledgers')->where('customer_id', $this->customerId())
            ->whereBetween('created_at', [$this->rangeStart(), $this->rangeEnd()])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(10, ['id', 'created_at', 'wallet_type', 'bucket', 'credits_delta', 'tool_code'], 'ledgerPage', $this->getPage('ledgerPage'));
    }

    #[Computed]
    public function dashboard(): array
    {
        try {
            $jobs = $this->jobsPaginator();
            $channels = DB::table('api_jobs')->where('customer_id', $this->customerId())->whereIn('ml_job_id', $jobs->pluck('id'))->pluck('ml_job_id')->all();

            return ['available' => true, 'state' => $this->serviceState(), 'wallets' => $this->walletBalances,
                'stats' => $this->topStats(), 'timeline' => $this->timelineBreakdown(), 'tools' => $this->toolBreakdown(),
                'jobs' => $jobs, 'apiJobs' => $channels, 'payments' => $this->paymentHistory, 'ledger' => $this->creditActivity,
                'complimentary' => $this->complimentary];
        } catch (\Throwable $e) {
            // Never expose SQL, provider payloads, signed links or exception text to customers.
            return ['available' => false];
        }
    }

    public function jobsPaginator()
    {
        $this->jobsPerPage = max(10, min(50, $this->jobsPerPage));

        return parent::jobsPaginator();
    }

    public function cancelSubscription(): void
    {
        abort_unless(auth('app')->check(), 403);
        try {
            app(ScheduleServicePlanCancellation::class)->handle(auth('app')->user());
            $this->confirmCancellation = false;
            unset($this->dashboard, $this->complimentary);
            $this->dispatch('alert', type: 'success', message: __('subscription_lifecycle.cancel_success'));
        } catch (\Throwable) {
            $this->addError('cancellation', __('account_v2.cancel_error'));
        }
    }

    public function customerStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'done', 'completed', 'applied' => __('account_v2.completed'),
            'paid' => __('account_v2.paid'), 'failed' => __('account_v2.failed'),
            'canceled', 'cancelled' => __('account_v2.canceled'), 'expired' => __('account_v2.expired'),
            'saving' => __('account_v2.saving_result'),
            'running', 'processing' => __('account_v2.processing'),
            'pending', 'queued', 'awaiting', 'confirming', 'awaiting_customer_action', 'paid_pending_application' => __('account_v2.pending'),
            'review', 'requires_review', 'refund_requested' => __('account_v2.review'),
            'refunded' => __('account_v2.refunded'), default => __('account_v2.unknown'),
        };
    }
}
