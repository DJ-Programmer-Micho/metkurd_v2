{{-- resources/views/app/pages/addon-credits/⚡addon-credits.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Models\CreditProduct;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Payments\CheckoutAuthorizationService;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public array $products = [];
    public array $purchaseState = [
        'allowed' => true,
        'reason' => null,
        'plan_code' => 'free',
        'plan_name' => 'Free',
    ];
    public ?int $selectedProductId = null;
    public string $displayCurrencyCode = 'IQD';
    public string $upgradeUrl = '';

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
        $customer = auth('app')->user();
        $customer = $customer?->fresh(['profile']);
        $currency = app(BillingCurrencyService::class);
        $this->purchaseState = app(CheckoutAuthorizationService::class)->addonPurchaseState($customer);
        $displayContext = $currency->resolveDisplayContext($customer);
        $this->displayCurrencyCode = (string) ($displayContext['currency_code'] ?? 'IQD');
        $this->upgradeUrl = route('subscription-plan', ['locale' => app()->getLocale()]);

        if (! (bool) ($this->purchaseState['allowed'] ?? false)) {
            $this->products = [];
            $this->showConfirm = false;
            $this->selectedProductId = null;

            return;
        }

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

        if (! (bool) ($this->purchaseState['allowed'] ?? false)) {
            $this->messageType = 'danger';
            $this->message = (string) ($this->purchaseState['reason'] ?? __('Add-on credits are not available for your current plan.'));
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

    public function confirmPurchase()
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
            $payment = app(CreateAddonPayment::class)->handle(
                $customer,
                (int) $this->selectedProductId,
            );

            $this->showConfirm = false;

            return $this->redirectRoute('payments.fib.show', [
                'locale' => app()->getLocale(),
                'payment' => $payment,
            ], navigate: true);
        } catch (\Illuminate\Auth\Access\AuthorizationException|\Illuminate\Validation\ValidationException $exception) {
            $this->messageType = 'danger';
            $this->message = $exception instanceof \Illuminate\Validation\ValidationException
                ? (collect($exception->errors())->flatten()->first() ?: __('Could not start the payment.'))
                : $exception->getMessage();
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = __('Failed to start the payment: :message', ['message' => $e->getMessage()]);
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

                @if(!($purchaseState['allowed'] ?? false))
                    <div class="alert alert-warning mt-3 mb-0 text-start">
                        <div><b>{{ __('Upgrade required') }}</b></div>
                        <div>{{ $purchaseState['reason'] ?? __('Add-on credits require an active paid plan.') }}</div>
                        <a href="{{ $upgradeUrl }}" class="btn btn-primary btn-sm mt-3">
                            {{ __('View Subscription Plans') }}
                        </a>
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
        @if(!($purchaseState['allowed'] ?? false))
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 text-center">
                        <h5 class="mb-2">{{ __('Add-on credits require an active paid subscription') }}</h5>
                        <p class="text-muted mb-0">
                            {{ __('Upgrade your main subscription first, then you can buy add-on credits as one-time purchases.') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

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

                        <div class="alert alert-warning mt-3 mb-0">
                            <div class="fw-semibold mb-2">{{ __('Next step: complete payment in First Iraqi Bank') }}</div>
                            <div>{{ __('We will open a dedicated FIB payment page with QR scan, manual code entry, refresh, and cancel controls.') }}</div>
                            <div class="small mt-2">
                                {{ __('Add-on credits stay separated in the one-time purchase flow and are only fulfilled after the FIB payment is confirmed.') }}
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-light"
                                wire:click="closeConfirm"
                                @disabled($processing)>
                            {{ __('Cancel') }}
                        </button>

                        <button class="btn btn-primary"
                                wire:click="confirmPurchase"
                                wire:loading.attr="disabled"
                                wire:target="confirmPurchase"
                                @disabled($processing)>
                            <span wire:loading.remove wire:target="confirmPurchase">
                                {{ __('Open FIB Payment') }}
                            </span>
                            <span wire:loading wire:target="confirmPurchase">
                                {{ __('Preparing...') }}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
