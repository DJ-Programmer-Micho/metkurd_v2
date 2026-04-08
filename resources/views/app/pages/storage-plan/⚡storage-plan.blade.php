{{-- resources/views/app/pages/storage-plan/⚡storage-plan.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\PaymentIntent;
use App\Models\StoragePlan;
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
    public array $plans = [];
    public array $paymentMethods = [];
    public ?int $currentPlanId = null;
    public ?int $selectedPlanId = null;
    public string $selectedPaymentMethodCode = '';
    public string $displayCurrencyCode = 'IQD';
    public ?int $activeCheckoutIntentId = null;
    public array $checkoutAction = [];

    public bool $showConfirm = false;
    public bool $processing = false;
    public string $message = '';

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

        $this->plans = StoragePlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) use ($currency, $customer) {
                $priceIqd = $p->priceIqdAmount();

                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'quota_mb' => (int) ($p->quota_mb ?? 0),
                    'price_iqd' => $priceIqd,
                    'price_display' => $currency->priceDataForBaseAmountIqd($priceIqd, $customer),
                ];
            })
            ->values()
            ->all();

        $this->currentPlanId = $customer->storagePlan()->first()?->id;
        $this->paymentMethods = app(CheckoutAuthorizationService::class)->paymentMethodOptions('storage_plan');

        if ($this->selectedPaymentMethodCode === '' || ! collect($this->paymentMethods)->contains('code', $this->selectedPaymentMethodCode)) {
            $this->selectedPaymentMethodCode = (string) (data_get($this->paymentMethods, '0.code') ?? '');
        }
    }

    public function openConfirm(int $planId): void
    {
        if ($this->paymentMethods === []) {
            $this->message = __('No checkout-ready payment method is available right now.');
            return;
        }

        $this->selectedPlanId = $planId;
        $this->message = '';
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        if ($this->processing) {
            return;
        }

        $this->showConfirm = false;
        $this->selectedPlanId = null;
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
    }

    public function confirmChange(): void
    {
        $customer = auth('app')->user();
        $selectedPlan = collect($this->plans)->firstWhere('id', $this->selectedPlanId);

        if (!$this->selectedPlanId) {
            return;
        }

        if (! $selectedPlan) {
            $this->message = 'Selected storage plan was not found.';
            return;
        }

        if ((int) $this->selectedPlanId === (int) $this->currentPlanId) {
            $this->message = 'This is already your current storage plan.';
            return;
        }

        $this->processing = true;
        $this->message = '';
        $selectedPriceDisplay = app(BillingCurrencyService::class)->priceDataForBaseAmountIqd((int) $selectedPlan['price_iqd'], $customer);

        try {
            $intent = app(PaymentIntentService::class)->startCheckout($customer, 'storage_plan', (int) $this->selectedPlanId, [
                'ui' => 'storage-plan-page',
                'payment_method' => $this->selectedPaymentMethodCode,
                'redirect_url' => url()->current(),
            ]);

            if ($intent->requiresCustomerAction()) {
                $this->activeCheckoutIntentId = (int) $intent->id;
                $this->checkoutAction = $intent->checkoutAction();
                $this->message = __('Payment created. Complete it in FIB, then return here and check the payment status.');

                return;
            }

            $this->completeSuccessfulStorageCheckout($customer, $intent, $selectedPlan, $selectedPriceDisplay);
        } catch (\Throwable $e) {
            $this->message = 'Failed: ' . $e->getMessage();
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
                'ui' => 'storage-plan-page-status',
            ]);

            if ((string) $intent->status === 'paid') {
                $selectedPlan = collect($this->plans)->firstWhere('id', (int) $intent->purpose_id);

                if (! is_array($selectedPlan)) {
                    $plan = StoragePlan::query()->findOrFail((int) $intent->purpose_id);
                    $selectedPlan = [
                        'id' => (int) $plan->id,
                        'code' => (string) $plan->code,
                        'name' => (string) $plan->name,
                        'quota_mb' => (int) ($plan->quota_mb ?? 0),
                        'price_iqd' => $plan->priceIqdAmount(),
                    ];
                }

                $selectedPriceDisplay = app(BillingCurrencyService::class)->priceDataForBaseAmountIqd(
                    (int) ($selectedPlan['price_iqd'] ?? 0),
                    auth('app')->user()
                );

                $this->completeSuccessfulStorageCheckout(auth('app')->user(), $intent, $selectedPlan, $selectedPriceDisplay);

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->message = match ((string) $intent->status) {
                'failed' => __('Payment was declined by FIB.'),
                'canceled' => __('Payment was canceled before completion.'),
                'expired' => __('This FIB payment expired. Please start a new checkout.'),
                default => __('Payment is still waiting to be completed.'),
            };
        } catch (\Throwable $e) {
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
                'reason' => 'Customer canceled pending checkout from storage modal.',
                'source' => 'storage_plan_modal',
            ]);

            if ((string) $intent->status === 'canceled') {
                $this->showConfirm = false;
                $this->selectedPlanId = null;
                $this->activeCheckoutIntentId = null;
                $this->checkoutAction = [];
                $this->message = __('Payment was canceled before completion.');

                return;
            }

            $this->checkoutAction = $intent->checkoutAction();
            $this->message = __('Payment could not be canceled automatically. Please refresh the status or wait for expiry.');
        } catch (\Throwable $e) {
            $this->message = __('Failed to cancel payment: :message', ['message' => $e->getMessage()]);
        } finally {
            $this->processing = false;
        }
    }

    protected function completeSuccessfulStorageCheckout($customer, PaymentIntent $intent, array $selectedPlan, array $selectedPriceDisplay): void
    {
        $this->loadData();

        $this->showConfirm = false;
        $this->selectedPlanId = null;
        $this->activeCheckoutIntentId = null;
        $this->checkoutAction = [];
        $this->message = 'Storage plan updated successfully.';

        $freshCustomer = $customer->fresh(['profile']);

        TelegramPaymentNotifier::send(
            $freshCustomer,
            'Storage Plan',
            (string) $selectedPlan['name'],
            [
                'Plan Code' => strtoupper((string) $selectedPlan['code']),
                'Storage Quota (MB)' => number_format((int) $selectedPlan['quota_mb']),
                'Amount (IQD)' => $selectedPriceDisplay['iqd_label'],
                ...($selectedPriceDisplay['has_localized_estimate']
                    ? ['Estimated Local Price' => $selectedPriceDisplay['display_label']]
                    : []),
                'Provider' => strtoupper((string) $intent->provider),
                'Reference' => (string) ($intent->merchant_transaction_id ?? $intent->provider_payment_id ?? $intent->uuid),
            ],
            'Storage plan page'
        );

        CustomerEmailNotifier::sendStorageThankYou(
            $freshCustomer,
            [
                'plan_name' => (string) $selectedPlan['name'],
                'quota_mb' => (int) $selectedPlan['quota_mb'],
                'amount_label' => (string) $selectedPriceDisplay['iqd_label'],
                'activated_on' => now()->format('F d, Y'),
            ],
            'Storage plan page'
        );

        $this->dispatch('header:refresh');
        $this->dispatch('customerStorageUpdated');
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh();

        $usedBytes = (int) $customer->storageUsedBytes();
        $quotaMb = (int) $customer->storageQuotaMb(512);
        $quotaBytes = $quotaMb * 1024 * 1024;

        $usedMb = (int) floor($usedBytes / 1024 / 1024);
        $storagePct = $quotaMb > 0
            ? min(100, (int) round(($usedMb / $quotaMb) * 100))
            : 0;

        return view('app.pages.storage-plan.⚡storage-plan', [
            'usedBytes' => $usedBytes,
            'quotaBytes' => $quotaBytes,
            'quotaMb' => $quotaMb,
            'usedMb' => $usedMb,
            'storagePct' => $storagePct,
            'overQuota' => $usedBytes > $quotaBytes,
        ]);
    }
};
?>

<x-slot:title>{{ __('Storage Plan') }} | {{ __('MET KURD') }}</x-slot:title>

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">{{ __('Storage Plans') }}</h4>

                <p class="text-muted mb-1 fs-15">
                    {{ __('Used:') }} <b>{{ number_format($usedMb) }} MB</b> /
                    {{ __('Quota:') }} <b>{{ number_format($quotaMb) }} MB</b>
                </p>

                <div class="mx-auto mt-3" style="max-width: 420px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted">{{ __('Storage usage') }}</small>
                        <small class="fw-semibold">{{ $storagePct }}%</small>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-info" role="progressbar"
                             style="width: {{ $storagePct }}%;"
                             aria-valuenow="{{ $storagePct }}" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>

                @if($overQuota)
                    <div class="alert alert-danger mt-3 mb-0">
                        {{ __('You are currently over quota. Uploads should be blocked until you upgrade or delete files.') }}
                    </div>
                @endif

                @if($message)
                    <div class="alert alert-info mt-3 mb-0">{{ $message }}</div>
                @endif

                @if($displayCurrencyCode !== 'IQD')
                    <div class="text-muted small mt-3">
                        {{ __('Local display currency: :currency', ['currency' => $displayCurrencyCode]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        @foreach($plans as $p)
            @php
                $isCurrent = (int) $p['id'] === (int) $currentPlanId;
                $showLocalPrice = (bool) data_get($p, 'price_display.has_localized_estimate', false);
            @endphp

            <div class="col-xxl-3 col-lg-6">
                <div class="card pricing-box {{ $isCurrent ? 'border border-success shadow-sm' : '' }}">
                    <div class="card-body bg-light m-2 p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="flex-grow-1">
                                <h5 class="mb-0">{{ $p['name'] }}</h5>
                                <div class="text-muted fs-12">{{ strtoupper($p['code']) }}</div>
                            </div>
                            <div class="ms-auto text-end">
                                <div class="fw-semibold">{{ number_format($p['quota_mb']) }} MB</div>
                                <div class="text-muted fs-12">{{ __('quota') }}</div>
                                <div class="fw-semibold mt-2">{{ data_get($p, 'price_display.iqd_label') }}</div>
                                <div class="text-muted fs-12">{{ __('per change') }}</div>
                                @if($showLocalPrice)
                                    <div class="text-muted fs-12 mt-1">{{ data_get($p, 'price_display.estimated_label') }}</div>
                                @endif
                            </div>
                        </div>

                        <p class="text-muted mb-3">
                            {{ __('Storage quota for uploads and generated outputs.') }}
                        </p>

                        <div class="mt-3 pt-2">
                            @if($isCurrent)
                                <button class="btn btn-success w-100" disabled>
                                    {{ __('Your Current Plan') }}
                                </button>
                            @else
                                    <button class="btn btn-info w-100"
                                            wire:click="openConfirm({{ $p['id'] }})"
                                            wire:loading.attr="disabled">
                                        {{ __('Change Plan') }}
                                    </button>
                                @endif
                            </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($showConfirm)
        @php
            $selected = collect($plans)->firstWhere('id', $selectedPlanId);
            $selectedDisplay = is_array($selected) ? ($selected['price_display'] ?? null) : null;
        @endphp

        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Confirm Storage Change') }}</h5>
                        <button type="button" class="btn-close" wire:click="closeConfirm" @disabled($processing)></button>
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
                                {{ __('You are switching to:') }}
                                <b>{{ $selected['name'] }}</b>
                                ({{ strtoupper($selected['code']) }})
                            </p>
                            <p class="mb-2">
                                {{ __('New quota:') }}
                                <b>{{ number_format($selected['quota_mb']) }} MB</b>
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
                            {{ __('If you downgrade below your used storage, the plan can still be activated, but your account will remain marked as over quota until you delete files or upgrade again.') }}
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
                        <button class="btn btn-light" wire:click="closeConfirm" @disabled($processing)">
                            {{ $checkoutAction !== [] ? __('Close') : __('Cancel') }}
                        </button>

                        @if($checkoutAction !== [])
                            <button class="btn btn-outline-danger" wire:click="cancelPendingCheckout" @disabled($processing)>
                                @if($processing)
                                    {{ __('Canceling...') }}
                                @else
                                    {{ __('Cancel Payment') }}
                                @endif
                            </button>
                            <button class="btn btn-primary" wire:click="refreshCheckoutStatus" @disabled($processing)>
                                @if($processing)
                                    {{ __('Checking...') }}
                                @else
                                    {{ __('Check Status') }}
                                @endif
                            </button>
                        @else
                            <button class="btn btn-primary" wire:click="confirmChange" @disabled($processing)>
                                @if($processing)
                                    {{ __('Processing...') }}
                                @else
                                    {{ __('Continue') }}
                                @endif
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
