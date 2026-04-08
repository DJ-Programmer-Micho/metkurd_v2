{{-- resources/views/app/pages/addon-credits/⚡addon-credits.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

use App\Models\CreditProduct;
use App\Models\PaymentIntent;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\CheckoutAuthorizationService;
use App\Services\Payments\PaymentIntentLifecycleService;
use App\Services\Payments\PaymentIntentService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $products = [];
    public array $paymentMethods = [];
    public array $purchaseState = [
        'allowed' => true,
        'reason' => null,
        'plan_code' => 'free',
        'plan_name' => 'Free',
    ];
    public ?int $selectedProductId = null;
    public string $selectedPaymentMethodCode = '';
    public string $displayCurrencyCode = 'IQD';
    public ?int $activeCheckoutIntentId = null;
    public array $checkoutAction = [];

    public bool $showConfirm = false;
    public bool $processing = false;

    public string $message = '';
    public string $messageType = 'success';

    #[On('customerPlanUpdated')]
    #[On('header:refresh')]
    public function refreshPageData(): void
    {
        $this->loadData();
    }

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        $customer = auth('app')->user()?->loadMissing('profile');
        $currency = app(BillingCurrencyService::class);
        $this->purchaseState = app(CheckoutAuthorizationService::class)->addonPurchaseState($customer);
        $displayContext = $currency->resolveDisplayContext($customer);
        $this->displayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');

        $this->products = CreditProduct::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) use ($currency, $customer) {
                $priceIqd = $p->priceIqdAmount();

                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'credits_amount' => (int) ($p->credits_amount ?? 0),
                    'price_iqd' => $priceIqd,
                    'price_display' => $currency->priceDataForBaseAmountIqd($priceIqd, $customer),
                ];
            })
            ->values()
            ->all();
        $this->paymentMethods = app(CheckoutAuthorizationService::class)->paymentMethodOptions('credit_product');

        if ($this->selectedPaymentMethodCode === '' || ! collect($this->paymentMethods)->contains('code', $this->selectedPaymentMethodCode)) {
            $this->selectedPaymentMethodCode = (string) (data_get($this->paymentMethods, '0.code') ?? '');
        }
    }

    public function openConfirm(int $productId): void
    {
        if ($this->processing) {
            return;
        }

        if (! (bool) ($this->purchaseState['allowed'] ?? false)) {
            $this->messageType = 'danger';
            $this->message = (string) ($this->purchaseState['reason'] ?? __('Add-on credits are not available for your current plan.'));
            return;
        }

        if ($this->paymentMethods === []) {
            $this->messageType = 'danger';
            $this->message = __('No checkout-ready payment method is available right now.');
            return;
        }

        $exists = collect($this->products)->contains(fn ($p) => (int) $p['id'] === $productId);
        if (!$exists) {
            $this->messageType = 'danger';
            $this->message = __('Selected add-on product was not found.');
            return;
        }

        $this->selectedProductId = $productId;
        $this->message = '';
        $this->messageType = 'success';
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        if ($this->processing) {
            return;
        }

        $this->showConfirm = false;
        $this->selectedProductId = null;
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
    }

    public function confirmPurchase(): void
    {
        if ($this->processing) {
            return;
        }

        $customer = auth('app')->user();

        if (!$customer) {
            $this->messageType = 'danger';
            $this->message = __('Customer not found.');
            return;
        }

        if (!$this->selectedProductId) {
            $this->messageType = 'danger';
            $this->message = __('Please choose an add-on product first.');
            return;
        }

        $this->processing = true;
        $this->message = '';
        $this->messageType = 'success';

        try {
            $intent = app(PaymentIntentService::class)->startCheckout($customer, 'credit_product', (int) $this->selectedProductId, [
                'ui' => 'addon-credits-page',
                'payment_method' => $this->selectedPaymentMethodCode,
                'redirect_url' => url()->current(),
            ]);

            if ($intent->requiresCustomerAction()) {
                $this->activeCheckoutIntentId = (int) $intent->id;
                $this->checkoutAction = $intent->checkoutAction();
                $this->messageType = 'info';
                $this->message = __('Payment created. Complete it in FIB, then return here and check the payment status.');

                return;
            }

            $product = CreditProduct::query()->findOrFail($this->selectedProductId);
            $this->completeSuccessfulAddonCheckout($customer, $intent, $product);
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = __('Failed: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    public function refreshCheckoutStatus(): void
    {
        if (! $this->activeCheckoutIntentId) {
            return;
        }

        $this->processing = true;

        try {
            $intent = app(PaymentIntentService::class)->syncCheckout($this->activeCheckoutIntentId, [
                'ui' => 'addon-credits-page-status',
            ]);

            if ((string) $intent->status === 'paid') {
                $product = CreditProduct::query()->findOrFail((int) $intent->purpose_id);
                $this->completeSuccessfulAddonCheckout(auth('app')->user(), $intent, $product);

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->messageType = match ((string) $intent->status) {
                'failed', 'canceled', 'expired' => 'danger',
                default => 'info',
            };
            $this->message = match ((string) $intent->status) {
                'failed' => __('Payment was declined by FIB.'),
                'canceled' => __('Payment was canceled before completion.'),
                'expired' => __('This FIB payment expired. Please start a new checkout.'),
                default => __('Payment is still waiting to be completed.'),
            };
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = __('Failed to refresh payment status: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    public function cancelPendingCheckout(): void
    {
        if (! $this->activeCheckoutIntentId) {
            return;
        }

        $this->processing = true;

        try {
            $intent = app(PaymentIntentLifecycleService::class)->cancel($this->activeCheckoutIntentId, [
                'reason' => 'Customer canceled pending checkout from addon modal.',
                'source' => 'addon_credits_modal',
            ]);

            if ((string) $intent->status === 'canceled') {
                $this->showConfirm = false;
                $this->selectedProductId = null;
                $this->activeCheckoutIntentId = null;
                $this->checkoutAction = [];
                $this->messageType = 'info';
                $this->message = __('Payment was canceled before completion.');

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->messageType = 'warning';
            $this->message = __('Payment could not be canceled automatically. Please refresh the status or wait for expiry.');
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = __('Failed to cancel payment: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    protected function completeSuccessfulAddonCheckout($customer, PaymentIntent $intent, CreditProduct $product): void
    {
        $order = $intent->creditOrders->sortByDesc('id')->first();

        $this->showConfirm = false;
        $this->selectedProductId = null;
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
        $this->messageType = 'success';
        $this->message = __('Add-on credits purchased successfully and added to your add-on balance.');

        $freshCustomer = $customer->fresh(['profile']);
        $orderDisplayLabel = $order?->hasLocalizedDisplayAmount()
            ? app(BillingCurrencyService::class)->formatAmount(
                (float) $order->display_amount_rounded,
                (string) $order->display_currency_code
            )
            : null;

        TelegramPaymentNotifier::send(
            $freshCustomer,
            'Add-on Credits',
            (string) $product->name,
            [
                'Plan Code' => strtoupper((string) $product->code),
                'Credits' => number_format((int) $product->credits_amount),
                'Amount (IQD)' => app(BillingCurrencyService::class)->formatAmount(
                    (int) $product->priceIqdAmount(),
                    'IQD'
                ),
                ...($orderDisplayLabel !== null ? ['Estimated Local Price' => $orderDisplayLabel] : []),
                'Order Type' => (string) ($order?->order_type ?? 'addon'),
                'Provider' => strtoupper((string) $intent->provider),
                'Reference' => (string) ($order?->provider_ref ?? $intent->merchant_transaction_id ?? $intent->uuid),
            ],
            'Add-on credits page'
        );

        CustomerEmailNotifier::sendAddonThankYou(
            $freshCustomer,
            [
                'product_name' => (string) $product->name,
                'credits_amount' => (int) $product->credits_amount,
                'amount_label' => app(BillingCurrencyService::class)->formatAmount(
                    (int) $product->priceIqdAmount(),
                    'IQD'
                ),
                'added_on' => $order?->created_at?->format('F d, Y') ?? now()->format('F d, Y'),
                'status_label' => 'Completed',
            ],
            'Add-on credits page'
        );

        $this->dispatch('header:refresh');
        $this->dispatch('customerPlanUpdated');
        $this->dispatch('customerStorageUpdated');
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh();

        $customer->loadMissing([
            'wallet',
            'servicePlan',
            'activeServiceSubscription.servicePlan',
        ]);

        $wallet = $customer->wallet()->first();

        $subscriptionBalance = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addonBalance = (int) ($wallet?->addon_balance_credits ?? 0);
        $combinedBalance = $subscriptionBalance + $addonBalance;

        $currentPlan =
            $customer->servicePlan
            ?: $customer->activeServiceSubscription?->servicePlan;

        $planCode = (string) ($currentPlan?->code ?? 'free');
        $planName = (string) ($currentPlan?->name ?? __('Free'));

        return view('app.pages.addon-credits.⚡addon-credits', [
            'combinedBalance' => $combinedBalance,
            'subscriptionBalance' => $subscriptionBalance,
            'addonBalance' => $addonBalance,
            'planCode' => $planCode,
            'planName' => $planName,
        ]);
    }
};
?>

<x-slot:title>{{ __('Add-on Credits') }} | {{ __('MET KURD') }}</x-slot:title>

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">{{ __('Add-on Credits') }}</h4>
                <p class="text-muted mb-2 fs-15">
                    {{ __('Current plan: :code - :name', ['code' => strtoupper($planCode), 'name' => $planName]) }}
                </p>

                <div class="d-flex justify-content-center flex-wrap gap-3 small">
                    <div>
                        <span class="text-muted">{{ __('Combined Balance:') }}</span>
                        <b>{{ number_format($combinedBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">{{ __('Subscription Credits:') }}</span>
                        <b>{{ number_format($subscriptionBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">{{ __('Add-on Credits:') }}</span>
                        <b>{{ number_format($addonBalance) }}</b>
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0 text-start">
                    <div><b>{{ __('How it works:') }}</b></div>
                    <div>{{ __('Add-on credits are one-time top-ups.') }}</div>
                    <div>{{ __('They are kept separately from your monthly subscription credits.') }}</div>
                    <div>{{ __('When spending credits, subscription credits are used first, then add-on credits.') }}</div>
                    <div>{{ __('Changing your service plan does not remove your add-on credits.') }}</div>
                </div>

                @if(!($purchaseState['allowed'] ?? false))
                    <div class="alert alert-warning mt-3 mb-0 text-start">
                        <div><b>{{ __('Upgrade required') }}</b></div>
                        <div>{{ $purchaseState['reason'] ?? __('Add-on credits require an active paid plan.') }}</div>
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-{{ $messageType }} mt-3 mb-0">{{ $message }}</div>
                @endif

                @if($displayCurrencyCode !== 'IQD')
                    <div class="text-muted small mt-3">
                        {{ __('Local display currency: :currency', ['currency' => $displayCurrencyCode]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        @forelse($products as $p)
            @php
                $showLocalPrice = (bool) data_get($p, 'price_display.has_localized_estimate', false);
            @endphp
            <div class="col-lg-4 col-md-6">
                <div class="card pricing-box">
                    <div class="card-body p-4 m-2">
                        <div class="d-flex align-items-start">
                            <div class="flex-grow-1">
                                <h5 class="mb-1">{{ $p['name'] }}</h5>
                                <p class="text-muted mb-1">{{ strtoupper($p['code']) }}</p>
                                <span class="badge bg-soft-primary text-primary">{{ __('Add-on Pack') }}</span>
                            </div>

                            <div class="ms-auto text-end">
                                <div class="fw-semibold">{{ __(':count credits', ['count' => number_format($p['credits_amount'])]) }}</div>
                                <div class="text-muted fs-12">{{ data_get($p, 'price_display.iqd_label') }}</div>
                                @if($showLocalPrice)
                                    <div class="text-muted fs-12 mt-1">{{ data_get($p, 'price_display.estimated_label') }}</div>
                                @endif
                            </div>
                        </div>

                        <hr class="my-4 text-muted">

                        <ul class="list-unstyled text-muted vstack gap-2 mb-0">
                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    {{ __('One-time credit top-up') }}
                                </div>
                            </li>

                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    {{ __('Preserved when switching plans') }}
                                </div>
                            </li>

                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    {{ __('Used after subscription credits') }}
                                </div>
                            </li>
                        </ul>

                        <div class="mt-4">
                            <button class="btn btn-primary w-100"
                                    wire:click="openConfirm({{ $p['id'] }})"
                                    @disabled(!($purchaseState['allowed'] ?? false))
                                    wire:loading.attr="disabled"
                                    wire:target="openConfirm({{ $p['id'] }})">
                                {{ ($purchaseState['allowed'] ?? false) ? __('Buy Add-on') : __('Paid Plan Required') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-lg-8">
                <div class="alert alert-warning mb-0">
                    {{ __('No active add-on credit products found.') }}
                </div>
            </div>
        @endforelse
    </div>

    @if($showConfirm)
        @php
            $selected = collect($products)->firstWhere('id', $selectedProductId);
            $selectedDisplay = is_array($selected) ? ($selected['price_display'] ?? null) : null;
        @endphp

        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Confirm Add-on Purchase') }}</h5>
                        <button type="button"
                                class="btn-close"
                                wire:click="closeConfirm"
                                @disabled($processing)></button>
                    </div>

                    <div class="modal-body">
                        @if ($checkoutAction === [] && $paymentMethods !== [])
                            <div class="mb-3">
                                <label class="form-label fw-semibold">{{ __('Payment Method') }}</label>
                                <div class="d-flex flex-column gap-2">
                                    @foreach ($paymentMethods as $method)
                                        <label class="border rounded-3 p-3 d-flex align-items-start gap-2">
                                            <input class="form-check-input mt-1" type="radio" wire:model.live="selectedPaymentMethodCode" value="{{ $method['code'] }}">
                                            <div class="flex-grow-1">
                                                <div class="fw-semibold">{{ $method['name'] }}</div>
                                                @if (!empty($method['description']))
                                                    <div class="text-muted small">{{ $method['description'] }}</div>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @elseif($checkoutAction === [])
                            <div class="alert alert-warning mb-3">
                                {{ __('No checkout-ready payment method is available right now.') }}
                            </div>
                        @endif

                        @if($selected)
                            <p class="mb-2">
                                {{ __('Product:') }}
                                <b>{{ $selected['name'] }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Credits:') }}
                                <b>{{ number_format($selected['credits_amount']) }}</b>
                            </p>
                            <p class="mb-2">
                                {{ __('Price:') }}
                                <b>{{ data_get($selectedDisplay, 'iqd_label') }}</b>
                            </p>
                            @if((bool) data_get($selectedDisplay, 'has_localized_estimate', false))
                                <p class="mb-2">
                                    {{ __('Estimated local display:') }}
                                    <b>{{ data_get($selectedDisplay, 'display_label') }}</b>
                                </p>
                            @endif
                        @endif

                        <div class="small text-muted">
                            {{ __('These credits will be added to your add-on credit bucket, not your monthly subscription bucket.') }}
                        </div>

                        @if($checkoutAction !== [])
                            @php
                                $checkoutLinks = (array) data_get($checkoutAction, 'links', []);
                                $validUntilLabel = data_get($checkoutAction, 'valid_until')
                                    ? \Illuminate\Support\Carbon::parse((string) data_get($checkoutAction, 'valid_until'))->timezone(config('app.timezone'))->format('Y-m-d H:i')
                                    : null;
                            @endphp

                            <div class="alert alert-warning mt-3 mb-0">
                                <div class="fw-semibold mb-2">{{ __('Complete the payment in First Iraqi Bank') }}</div>

                                @if(!empty($checkoutAction['readable_code']))
                                    <div class="mb-2">
                                        {{ __('Readable code:') }}
                                        <b>{{ $checkoutAction['readable_code'] }}</b>
                                    </div>
                                @endif

                                @if($validUntilLabel)
                                    <div class="mb-2">
                                        {{ __('Valid until:') }}
                                        <b>{{ $validUntilLabel }}</b>
                                    </div>
                                @endif

                                @if(!empty($checkoutAction['qr_code']))
                                    <div class="text-center py-2">
                                        <img src="{{ $checkoutAction['qr_code'] }}"
                                             alt="{{ __('FIB payment QR code') }}"
                                             class="img-fluid rounded border bg-white p-2"
                                             style="max-width: 220px;">
                                    </div>
                                @endif

                                @if($checkoutLinks !== [])
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        @foreach($checkoutLinks as $linkLabel => $linkUrl)
                                            @if(is_string($linkUrl) && $linkUrl !== '')
                                                <a href="{{ $linkUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary">
                                                    {{ __(ucfirst((string) $linkLabel) . ' App') }}
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                <div class="small mt-3">
                                    {{ __('After you pay in FIB, click "Check Status" here to sync the payment if the callback has not arrived yet.') }}
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-light"
                                wire:click="closeConfirm"
                                @disabled($processing)">
                            {{ $checkoutAction !== [] ? __('Close') : __('Cancel') }}
                        </button>

                        @if($checkoutAction !== [])
                            <button class="btn btn-outline-danger"
                                    wire:click="cancelPendingCheckout"
                                    wire:loading.attr="disabled"
                                    wire:target="cancelPendingCheckout,refreshCheckoutStatus"
                                    @disabled($processing)">
                                <span wire:loading.remove wire:target="cancelPendingCheckout">
                                    {{ __('Cancel Payment') }}
                                </span>
                                <span wire:loading wire:target="cancelPendingCheckout">
                                    {{ __('Canceling...') }}
                                </span>
                            </button>
                            <button class="btn btn-primary"
                                    wire:click="refreshCheckoutStatus"
                                    wire:loading.attr="disabled"
                                    wire:target="cancelPendingCheckout,refreshCheckoutStatus"
                                    @disabled($processing)">
                                <span wire:loading.remove wire:target="refreshCheckoutStatus">
                                    {{ __('Check Status') }}
                                </span>
                                <span wire:loading wire:target="refreshCheckoutStatus">
                                    {{ __('Checking...') }}
                                </span>
                            </button>
                        @else
                            <button class="btn btn-primary"
                                    wire:click="confirmPurchase"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmPurchase"
                                    @disabled($processing)">
                                <span wire:loading.remove wire:target="confirmPurchase">
                                    {{ __('Continue') }}
                                </span>
                                <span wire:loading wire:target="confirmPurchase">
                                    {{ __('Processing...') }}
                                </span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
