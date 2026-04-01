{{-- resources/views/app/pages/addon-credits/⚡addon-credits.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

use App\Models\CreditProduct;
use App\Models\CreditOrder;
use App\Services\Billing\BillingCurrencyService;
use App\Support\CustomerEmailNotifier;
use App\Support\TelegramPaymentNotifier;
use App\Services\Billing\CreditService;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $products = [];
    public ?int $selectedProductId = null;
    public string $displayCurrencyCode = 'IQD';

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
    }

    public function openConfirm(int $productId): void
    {
        if ($this->processing) {
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
            $purchase = DB::transaction(function () use ($customer) {
                $product = CreditProduct::query()
                    ->where('is_active', true)
                    ->findOrFail($this->selectedProductId);
                $baseAmountIqd = $product->priceIqdAmount();
                $currencySnapshot = app(BillingCurrencyService::class)->snapshotForBaseAmountIqd(
                    $baseAmountIqd,
                    $customer,
                );

                $order = CreditOrder::create([
                    'customer_id' => (int) $customer->id,
                    'order_type' => 'addon',
                    'source_type' => 'credit_product',
                    'service_plan_id' => null,
                    'credit_product_id' => (int) $product->id,
                    'status' => 'paid',
                    'credits_amount' => (int) $product->credits_amount,
                    'amount_usd' => $currencySnapshot['usd_reference_amount'],
                    'currency' => 'IQD',
                    'base_currency_code' => 'IQD',
                    'base_amount_iqd' => $baseAmountIqd,
                    'display_currency_code' => $currencySnapshot['display_currency_code'],
                    'display_exchange_rate' => $currencySnapshot['display_exchange_rate'],
                    'display_amount_raw' => $currencySnapshot['display_amount_raw'],
                    'display_amount_rounded' => $currencySnapshot['display_amount_rounded'],
                    'display_rounding_step' => $currencySnapshot['display_rounding_step'],
                    'display_rounding_mode' => $currencySnapshot['display_rounding_mode'],
                    'display_country_code' => $currencySnapshot['display_country_code'],
                    'provider' => 'fake',
                    'provider_ref' => 'FAKE-ADDON-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                    'meta' => [
                        'ui' => 'addon-credits-page',
                        'product_code' => (string) $product->code,
                        'product_name' => (string) $product->name,
                        'display_label' => $currencySnapshot['display_label'],
                        'base_label' => $currencySnapshot['base_label'],
                        'iqd_label' => $currencySnapshot['iqd_label'],
                        'usd_reference_label' => $currencySnapshot['usd_reference_label'],
                        'currency_resolution_source' => $currencySnapshot['currency_resolution_source'],
                    ],
                ]);

                app(CreditService::class)->addAddonCredits(
                    customerId: (int) $customer->id,
                    credits: (int) $product->credits_amount,
                    meta: [
                        'related_type' => CreditOrder::class,
                        'related_id' => (string) $order->id,
                        'reference_code' => 'ADDON-' . $order->id,
                        'product_id' => (int) $product->id,
                        'product_code' => (string) $product->code,
                        'product_name' => (string) $product->name,
                        'base_amount_iqd' => (int) $baseAmountIqd,
                        'ui' => 'addon-credits-page',
                    ]
                );

                return [
                    'product' => $product,
                    'order' => $order,
                ];
            }, 3);

            $this->showConfirm = false;
            $this->selectedProductId = null;
            $this->messageType = 'success';
            $this->message = __('Add-on credits purchased successfully and added to your add-on balance.');

            $freshCustomer = $customer->fresh(['profile']);
            $orderDisplayLabel = $purchase['order']->hasLocalizedDisplayAmount()
                ? app(BillingCurrencyService::class)->formatAmount(
                    (float) $purchase['order']->display_amount_rounded,
                    (string) $purchase['order']->display_currency_code
                )
                : null;

            TelegramPaymentNotifier::send(
                $freshCustomer,
                'Add-on Credits',
                (string) $purchase['product']->name,
                [
                    'Plan Code' => strtoupper((string) $purchase['product']->code),
                    'Credits' => number_format((int) $purchase['product']->credits_amount),
                    'Amount (IQD)' => app(BillingCurrencyService::class)->formatAmount(
                        (int) $purchase['product']->priceIqdAmount(),
                        'IQD'
                    ),
                    ...($orderDisplayLabel !== null ? ['Estimated Local Price' => $orderDisplayLabel] : []),
                    'Order Type' => (string) $purchase['order']->order_type,
                    'Provider' => (string) $purchase['order']->provider,
                    'Reference' => (string) $purchase['order']->provider_ref,
                ],
                'Add-on credits page'
            );

            CustomerEmailNotifier::sendAddonThankYou(
                $freshCustomer,
                [
                    'product_name' => (string) $purchase['product']->name,
                    'credits_amount' => (int) $purchase['product']->credits_amount,
                    'amount_label' => app(BillingCurrencyService::class)->formatAmount(
                        (int) $purchase['product']->priceIqdAmount(),
                        'IQD'
                    ),
                    'added_on' => $purchase['order']->created_at?->format('F d, Y') ?? now()->format('F d, Y'),
                    'status_label' => 'Completed',
                ],
                'Add-on credits page'
            );

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = __('Failed: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
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
                                    wire:loading.attr="disabled"
                                    wire:target="openConfirm({{ $p['id'] }})">
                                {{ __('Buy Add-on (Fake Pay)') }}
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
                        <div class="alert alert-warning mb-3">
                            {{ __('This is a fake payment for testing.') }}
                        </div>

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
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-light"
                                wire:click="closeConfirm"
                                @disabled($processing)">
                            {{ __('Cancel') }}
                        </button>

                        <button class="btn btn-primary"
                                wire:click="confirmPurchase"
                                wire:loading.attr="disabled"
                                wire:target="confirmPurchase"
                                @disabled($processing)">
                            <span wire:loading.remove wire:target="confirmPurchase">
                                {{ __('Confirm Purchase') }}
                            </span>
                            <span wire:loading wire:target="confirmPurchase">
                                {{ __('Processing...') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
