<?php

namespace App\Livewire\Account;

use App\Models\CreditWallet;
use App\Models\CustomerServiceSubscription;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Billing\CustomerBillingStateService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Billing\ScheduleServicePlanCancellation;
use App\Services\Billing\ScheduleStoragePlanCancellation;
use App\Services\Payments\CheckoutAuthorizationService;
use App\Services\Payments\CustomerPurchaseCheckout;
use App\Services\Payments\PaymentFeeCalculator;
use Livewire\Attributes\Computed;
use Livewire\Component;

abstract class PurchasePage extends Component
{
    protected string $kind = 'service';

    public string $cycle = 'monthly';

    public string $mode = 'all';

    public ?int $selectedId = null;

    #[\Livewire\Attributes\Locked]
    public string $selectedMode = '';

    public string $paymentMethod = '';

    public bool $confirmCancellation = false;

    #[Computed]
    public function catalog(): array
    {
        try {
            $customer = auth('app')->user()->fresh(['profile', 'usage']);
            $checkout = app(CustomerPurchaseCheckout::class);
            $billing = app(CustomerBillingStateService::class);
            $state = $this->kind === 'storage' ? $billing->storageQuotaState($customer) : $billing->servicePlanState($customer);
            $sub = $state['subscription'];
            $current = $state['current_plan'];
            $wallets = CreditWallet::where('customer_id', $customer->id)->get()->keyBy('wallet_type');
            $complimentary = $this->kind !== 'storage' && $sub && ! CustomerServiceSubscription::whereKey($sub->id)->excludingComplimentary()->exists();
            $access = $this->kind === 'addon' ? app(CheckoutAuthorizationService::class)->addonPurchaseState($customer) : ['allowed' => true];
            if ($this->kind === 'service' && app(\App\Services\Billing\ServiceAgreementLifecycle::class)->hasReservedTerm($customer)) {
                $access = ['allowed' => false, 'reason' => __('agreement.customer_help')];
            }
            $blocked = $this->kind === 'storage' && $checkout->storageReplacementBlocked($customer);
            $model = $checkout->modelClass($this->kind);
            $records = $model::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
            $intervals = $this->kind === 'addon' ? [] : $records->flatMap(fn ($p) => $p->billingIntervals())->unique()->values()->all();
            $modes = $this->kind === 'addon' ? ['one_time'] : $records->reject(fn ($p) => $this->isFree($p))->map(fn ($p) => $p->checkoutPaymentModeValue())->unique()->values()->all();
            if ($this->kind === 'service' && count($modes) === 1) {
                $this->mode = $modes[0];
            }
            $items = $records->map(function ($item) use ($customer, $current, $checkout, $wallets) {
                $free = $this->kind !== 'addon' && $this->isFree($item);
                $itemMode = $this->kind === 'addon' ? 'one_time' : $item->checkoutPaymentModeValue();
                $intervals = $this->kind === 'addon' ? [] : $item->billingIntervals();
                $cycle = in_array($this->cycle, $intervals, true) ? $this->cycle : ($intervals[0] ?? 'monthly');
                $amount = $this->kind === 'service' ? $item->priceIqdForCycle($cycle === 'yearly' ? 'yearly' : 'monthly') : $item->priceIqdAmount();
                $same = $this->kind !== 'addon' && $item->id === $current->id;
                // Compare service catalog prices, storage capacity; sort_order is merchandising, not a tier contract.
                $comparison = $this->kind === 'storage' ? ($item->quota_mb <=> $current->quota_mb) : ($this->kind === 'service' ? ($item->priceIqdForCycle('monthly') <=> $current->priceIqdForCycle('monthly')) : 0);
                $action = $same ? 'current' : ($this->kind === 'addon' ? 'buy' : ($current->is_free ?? $current->priceIqdAmount() <= 0 ? 'choose' : ($comparison > 0 ? 'upgrade' : ($comparison < 0 ? 'downgrade' : 'switch'))));
                $ui = $this->kind === 'service' ? $item->localizedUiFeatures() : [];
                $methods = $checkout->methods($this->kind, $itemMode);
                $method = $methods->firstWhere('code', $this->paymentMethod) ?? $methods->first();
                $fee = $method ? app(PaymentFeeCalculator::class)->quote($method, $amount) : null;

                return ['id' => $item->id, 'name' => $item->name, 'free' => $free, 'same' => $same, 'action' => $action,
                    'mode' => $itemMode, 'cycle' => $cycle, 'intervals' => $intervals,
                    'supported' => ! ($this->kind === 'storage' && $itemMode === 'one_time' && $cycle !== 'monthly'),
                    'recommended' => (bool) ($ui['recommended'] ?? false),
                    'price' => app(BillingCurrencyService::class)->priceDataForBaseAmountIqd($amount, $customer),
                    'total' => $fee ? app(BillingCurrencyService::class)->priceDataForBaseAmountIqd($fee['gross_amount_iqd'], $customer) : null,
                    'fees' => $fee ? app(BillingCurrencyService::class)->priceDataForBaseAmountIqd($fee['surcharge_amount_iqd'], $customer) : null,
                    'methods' => $methods->map(fn ($m) => ['code' => $m->code, 'name' => $m->name])->all(),
                    'app' => $this->kind === 'service' ? $item->appMonthlyCredits() : (int) ($item->credits_amount ?? 0),
                    'api' => $this->kind === 'service' ? $item->apiMonthlyCredits() : 0,
                    'jobs' => (int) ($item->concurrent_jobs_limit ?? 0), 'quota' => (int) ($item->quota_mb ?? 0),
                    'features' => array_values(array_filter((array) ($ui['features'] ?? []), fn ($feature) => is_string($feature) && ! preg_match('/\p{N}/u', $feature))),
                    'preview' => $this->kind === 'service' ? app(PlanSwitcher::class)->servicePlanBalanceResult($item, (int) ($wallets->get('app')?->addon_balance_credits ?? 0), (int) ($wallets->get('api')?->addon_balance_credits ?? 0)) : null];
            });
            $pending = $checkout->pending($customer, $this->kind);

            return ['available' => true, 'kind' => $this->kind, 'state' => $state, 'current' => $current, 'complimentary' => $complimentary,
                'wallets' => $wallets, 'access' => $access, 'blocked' => $blocked, 'modes' => $modes, 'intervals' => $intervals,
                'items' => $items->filter(fn ($p) => $this->mode === 'all' || $p['free'] || $p['mode'] === $this->mode)->values(),
                'selected' => $items->firstWhere('id', $this->selectedId),
                'pendingState' => $pending ? app(\App\Domain\Payments\Support\PaymentCheckoutState::class)->state($pending) : null,
                'pendingUrl' => $pending ? route('app.v2.payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $pending]) : null];
        } catch (\Throwable) {
            return ['available' => false, 'kind' => $this->kind];
        }
    }

    protected function isFree($item): bool
    {
        return $this->kind === 'service' ? (bool) $item->is_free : $item->priceIqdAmount() <= 0;
    }

    public function updatedCycle(): void
    {
        $this->selectedId = null;
        $this->resetErrorBag();
    }

    public function updatedMode(): void
    {
        $this->selectedId = null;
        $this->resetErrorBag();
    }

    public function select(int $id): void
    {
        $this->resetErrorBag();
        if (! $this->catalog['available']) {
            $this->addError('checkout', __('purchase_v2.unavailable'));

            return;
        }
        $item = $this->catalog['items']->firstWhere('id', $id);
        if (! $item || $item['same'] || $item['free']) {
            return;
        }
        $this->selectedId = $id;
        $this->selectedMode = $item['mode'];
        $this->paymentMethod = $item['methods'][0]['code'] ?? '';
        unset($this->catalog);
    }

    public function purchase()
    {
        abort_unless(auth('app')->check(), 403);
        $this->validate(['selectedId' => 'required|integer|min:1', 'paymentMethod' => 'required|string|max:80']);
        unset($this->catalog);
        $data = $this->catalog;
        if (! $data['available'] || ! $data['selected']) {
            $this->addError('checkout', __('purchase_v2.selection_changed'));

            return;
        }
        try {
            $item = $data['selected'];
            $payment = app(CustomerPurchaseCheckout::class)->start(auth('app')->user(), $this->kind, $item['id'], $item['cycle'], $this->selectedMode, $this->paymentMethod, null);

            return $this->redirectRoute('app.v2.payments.fib.show', ['locale' => app()->getLocale(), 'payment' => $payment], navigate: true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('checkout', collect($e->errors())->flatten()->first() ?: __('purchase_v2.failed'));
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('V2 customer checkout failed.', [
                'customer_id' => (int) auth('app')->id(),
                'kind' => $this->kind,
                'exception_type' => $exception::class,
            ]);
            $this->addError('checkout', __('purchase_v2.failed'));
        }
    }

    public function cancel(): void
    {
        abort_unless(auth('app')->check() && $this->kind !== 'addon', 403);
        if (! $this->confirmCancellation) {
            return;
        }
        try {
            $service = $this->kind === 'storage' ? ScheduleStoragePlanCancellation::class : ScheduleServicePlanCancellation::class;
            app($service)->handle(auth('app')->user());
            $this->confirmCancellation = false;
            unset($this->catalog);
            $this->dispatch('alert', type: 'success', message: __('subscription_lifecycle.cancel_success'));
        } catch (\Throwable) {
            $this->addError('cancellation', __('account_v2.cancel_error'));
        }
    }
}
