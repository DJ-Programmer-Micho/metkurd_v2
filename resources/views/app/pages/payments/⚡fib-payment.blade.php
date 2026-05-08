<?php

use App\Domain\Payments\Actions\CancelFibPayment;
use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Actions\CreateAddonPayment;
use App\Domain\Payments\Actions\CreatePlanSubscriptionPayment;
use App\Domain\Payments\Actions\CreateStorageSubscriptionPayment;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PurchaseType;
use App\Domain\Payments\Models\Payment;
use App\Models\CreditProduct;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Services\Analytics\ConversionTrackingService;
use App\Services\Billing\BillingCurrencyService;
use App\Services\Coupons\CouponContext;
use App\Services\Coupons\CouponService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public Payment $payment;
    public string $couponCode = '';
    public array $couponPreview = [];
    public string $couponMessage = '';
    public string $couponMessageType = 'info';
    public bool $showCouponInput = false;

    public function mount(Payment $payment): void
    {
        abort_unless((int) auth('app')->id() === (int) $payment->customer_id, 403);

        $this->payment = $payment->fresh(['customer.profile']) ?? $payment;
        $this->refreshCouponAvailability();
    }

    public function pollStatus(): void
    {
        $payment = $this->payment->fresh(['customer.profile']) ?? $this->payment;

        if ($payment->isTerminal()) {
            $this->payment = $payment;

            return;
        }

        if ($payment->last_status_checked_at?->greaterThan(now()->subSeconds(4))) {
            $this->payment = $payment;

            return;
        }

        try {
            $payment = app(ConfirmFibPayment::class)->handle($payment, 'livewire_poll');
        } catch (\Throwable) {
            $payment = $payment->fresh(['customer.profile']) ?? $payment;
        }

        $this->payment = $payment->fresh(['customer.profile']) ?? $payment;
        $this->refreshCouponAvailability();
    }

    public function clearCoupon(): void
    {
        $this->couponCode = '';
        $this->couponPreview = [];
        $this->couponMessage = '';
        $this->couponMessageType = 'info';
    }

    public function applyCoupon()
    {
        if (! $this->showCouponInput) {
            $this->couponMessage = __('Coupons are not available for this checkout and payment method.');
            $this->couponMessageType = 'warning';

            return;
        }

        $couponCode = strtoupper(trim($this->couponCode));

        if ($couponCode === '') {
            $this->couponMessage = __('Enter a coupon code first.');
            $this->couponMessageType = 'warning';
            $this->couponPreview = [];

            return;
        }

        $context = $this->checkoutCouponContext();

        if (! $context instanceof CouponContext) {
            $this->couponMessage = __('This checkout cannot be repriced with a coupon.');
            $this->couponMessageType = 'danger';

            return;
        }

        $latestPayment = $this->payment->fresh(['customer.profile']) ?? $this->payment;

        if (! $this->canEditCouponForPayment($latestPayment)) {
            $this->payment = $latestPayment;
            $this->refreshCouponAvailability();
            $this->couponMessage = __('This checkout can no longer be updated with a coupon.');
            $this->couponMessageType = 'warning';

            return;
        }

        try {
            $preview = app(CouponService::class)->preview($couponCode, $context);
            $this->couponCode = (string) ($preview['code'] ?? $couponCode);
            $this->couponPreview = $this->decorateCouponPreview($preview, $context->customer);

            try {
                app(CancelFibPayment::class)->handle($latestPayment, 'coupon_reprice');
            } catch (\Throwable) {
                // Keep going. Some provider states are non-cancelable and already handled safely.
            }

            $newPayment = $this->replaceCheckoutWithCoupon($context, $this->couponCode);

            session()->flash('payment_status_message', __('Coupon :code applied. A new checkout was created with the updated total.', [
                'code' => $this->couponCode,
            ]));

            return $this->redirectRoute('payments.fib.show', [
                'locale' => app()->getLocale(),
                'payment' => $newPayment,
            ], navigate: true);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->couponPreview = [];
            $this->couponMessage = collect($exception->errors())->flatten()->first() ?: __('This coupon could not be applied.');
            $this->couponMessageType = 'danger';
        } catch (\Throwable) {
            $this->couponMessage = __('Failed to apply coupon right now. Please try again.');
            $this->couponMessageType = 'danger';
        }
    }

    protected function refreshCouponAvailability(): void
    {
        $payment = $this->payment->fresh(['customer.profile']) ?? $this->payment;
        $this->payment = $payment;

        if (! $this->canEditCouponForPayment($payment)) {
            $this->showCouponInput = false;
            $this->couponCode = '';
            $this->couponPreview = [];
            $this->couponMessage = '';
            $this->couponMessageType = 'info';

            return;
        }

        $context = $this->checkoutCouponContext();

        if (! $context instanceof CouponContext) {
            $this->showCouponInput = false;
            $this->couponCode = '';
            $this->couponPreview = [];
            $this->couponMessage = '';
            $this->couponMessageType = 'info';

            return;
        }

        $this->showCouponInput = app(CouponService::class)->hasEligibleCouponSupport($context);

        if (! $this->showCouponInput) {
            $this->couponCode = '';
            $this->couponPreview = [];
            $this->couponMessage = '';
            $this->couponMessageType = 'info';
        }
    }

    protected function canEditCouponForPayment(Payment $payment): bool
    {
        if ((string) ($payment->coupon_code ?? '') !== '') {
            return false;
        }

        return in_array($payment->status->value, ['pending', 'awaiting_customer_action'], true);
    }

    protected function checkoutCouponContext(): ?CouponContext
    {
        $payment = $this->payment->fresh(['customer.profile']) ?? $this->payment;
        $customer = auth('app')->user()?->fresh(['profile']);

        if (! $customer) {
            return null;
        }

        $purchaseType = $payment->purchase_type ?? null;

        if (! $purchaseType instanceof PurchaseType) {
            return null;
        }

        $snapshot = $payment->snapshot();
        $purchasableType = (string) ($payment->purchasable_type ?? '');
        $purchasableId = (int) ($payment->purchasable_id ?? 0);
        $itemCode = strtoupper(trim((string) data_get($snapshot, 'code', '')));

        if ($purchasableType === '') {
            $purchasableType = match ($purchaseType) {
                PurchaseType::PLAN_SUBSCRIPTION => ServicePlan::class,
                PurchaseType::STORAGE_SUBSCRIPTION => StoragePlan::class,
                PurchaseType::ADDON_CREDITS => CreditProduct::class,
            };
        }

        if ($purchasableId <= 0) {
            return null;
        }

        if ($itemCode === '') {
            $itemCode = match ($purchaseType) {
                PurchaseType::PLAN_SUBSCRIPTION => strtoupper((string) (ServicePlan::query()->find($purchasableId)?->code ?? '')),
                PurchaseType::STORAGE_SUBSCRIPTION => strtoupper((string) (StoragePlan::query()->find($purchasableId)?->code ?? '')),
                PurchaseType::ADDON_CREDITS => strtoupper((string) (CreditProduct::query()->find($purchasableId)?->code ?? '')),
            };
        }

        if ($itemCode === '') {
            return null;
        }

        $originalAmountIqd = (int) data_get(
            $snapshot,
            'original_amount_iqd',
            (int) round((float) ($payment->original_amount_iqd ?? 0))
        );

        if ($originalAmountIqd <= 0) {
            $originalAmountIqd = (int) data_get(
                $snapshot,
                'amount_iqd',
                (int) round((float) ($payment->discounted_amount_iqd ?? $payment->amount ?? 0))
            );
        }

        $resolvedPaymentMode = PaymentMode::fromValue($payment->payment_mode ?? null, PaymentMode::ONE_TIME);
        $isRecurring = $resolvedPaymentMode->isRecurring();
        $billingCycle = strtolower((string) data_get($snapshot, 'billing_cycle', ''));
        $billingCycle = $billingCycle !== '' ? $billingCycle : null;

        if ($purchaseType === PurchaseType::ADDON_CREDITS) {
            $billingCycle = null;
        }
        $provider = strtolower(trim((string) ($payment->provider?->value ?? $payment->provider ?? 'fib')));

        return new CouponContext(
            customer: $customer,
            purchaseType: $purchaseType,
            provider: $provider,
            purchasableType: $purchasableType,
            purchasableId: $purchasableId,
            itemCode: $itemCode,
            originalAmountIqd: $originalAmountIqd,
            billingCycle: $billingCycle,
            isRecurring: $isRecurring,
        );
    }

    protected function replaceCheckoutWithCoupon(CouponContext $context, string $couponCode): Payment
    {
        $payment = $this->payment->fresh() ?? $this->payment;
        $methodCode = strtolower(trim((string) data_get($payment->meta, 'payment_method_code', '')));

        if ($methodCode === '') {
            $methodCode = strtolower(trim((string) (
                $payment->provider?->value
                ?? $payment->provider
                ?? 'fib'
            )));
        }

        return match ($context->purchaseType) {
            PurchaseType::PLAN_SUBSCRIPTION => app(CreatePlanSubscriptionPayment::class)->handle(
                $context->customer,
                $context->purchasableId,
                $context->billingCycle ?? 'monthly',
                $couponCode,
                $methodCode,
            ),
            PurchaseType::STORAGE_SUBSCRIPTION => app(CreateStorageSubscriptionPayment::class)->handle(
                $context->customer,
                $context->purchasableId,
                $context->billingCycle ?? 'monthly',
                $couponCode,
                $methodCode,
            ),
            PurchaseType::ADDON_CREDITS => app(CreateAddonPayment::class)->handle(
                $context->customer,
                $context->purchasableId,
                $couponCode,
            ),
        };
    }

    protected function decorateCouponPreview(array $preview, \App\Models\Customer $customer): array
    {
        $billing = app(BillingCurrencyService::class);
        $preview['original_display'] = $billing->priceDataForBaseAmountIqd((int) ($preview['original_amount_iqd'] ?? 0), $customer);
        $preview['discount_display'] = $billing->priceDataForBaseAmountIqd((int) ($preview['discount_amount_iqd'] ?? 0), $customer);
        $preview['final_display'] = $billing->priceDataForBaseAmountIqd((int) ($preview['final_amount_iqd'] ?? 0), $customer);

        return $preview;
    }

    public function render()
    {
        $payment = $this->payment->fresh(['customer.profile']) ?? $this->payment;
        $snapshot = $payment->snapshot();
        $purchaseConversionPayload = app(ConversionTrackingService::class)->preparePurchaseConversionPayload($payment);
        $links = $payment->appLinks();
        $shouldPoll = in_array($payment->status->value, ['pending', 'awaiting_customer_action'], true);
        $status = $payment->status->value;
        $statusClass = match ($status) {
            'paid' => 'success',
            'failed', 'canceled', 'expired' => 'danger',
            default => 'warning',
        };
        $purchaseLabel = match ($payment->purchase_type->value) {
            'plan_subscription' => __('Plan Subscription'),
            'storage_subscription' => __('Storage Subscription'),
            default => __('Add-on Credits'),
        };
        $backRoute = match ($payment->purchase_type->value) {
            'plan_subscription' => 'subscription-plan',
            'storage_subscription' => 'storage-plan',
            default => 'addon-credits',
        };

        return view('app.pages.payments.⚡fib-payment', [
            'payment' => $payment,
            'snapshot' => $snapshot,
            'purchaseConversionPayload' => $purchaseConversionPayload,
            'links' => $links,
            'shouldPoll' => $shouldPoll,
            'statusEndpoint' => route('payments.fib.status', [
                'locale' => app()->getLocale(),
                'payment' => $payment,
            ]),
            'pollIntervalMs' => 5000,
            'pollMaxDurationMs' => 300000,
            'statusClass' => $statusClass,
            'purchaseLabel' => $purchaseLabel,
            'backRoute' => $backRoute,
        ]);
    }
};
?>

<x-slot:title>{{ $payment->isProviderSubscriptionObject() ? __('FIB Subscription Checkout') : __('FIB Payment') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $billing = app(\App\Services\Billing\BillingCurrencyService::class);
    $fibSubscriptions = app(\App\Domain\Payments\Fib\FibSubscriptionService::class);
    $chargeDisplay = (array) data_get($snapshot, 'display', []);
    $baseDisplay = (array) data_get($snapshot, 'base_display', []);
    $couponSummary = data_get($snapshot, 'coupon');
    $feeQuote = (array) data_get($snapshot, 'fee_quote', data_get($payment->meta, 'fee_quote', []));
    $baghdadTimezone = 'Asia/Baghdad';
    $baghdadTimezoneLabel = __('Baghdad Time (GMT+3)');
    $validUntilLabel = $payment->valid_until?->timezone($baghdadTimezone)->format('Y-m-d H:i');
    $displayPrimary = (string) data_get($chargeDisplay, 'iqd_label', $billing->formatAmount((int) round((float) $payment->amount), 'IQD'));
    $displayEstimate = (string) data_get($chargeDisplay, 'display_label', '');
    $displayCanonical = (string) data_get(
        $baseDisplay,
        'iqd_label',
        data_get($snapshot, 'amount_iqd') !== null
            ? $billing->formatAmount((int) data_get($snapshot, 'amount_iqd'), 'IQD')
            : ''
    );
    $providerFeeLabel = (int) data_get($feeQuote, 'provider_fee_amount_iqd', 0) > 0
        ? $billing->formatAmount((int) data_get($feeQuote, 'provider_fee_amount_iqd', 0), 'IQD')
        : '';
    $surchargeLabel = (int) data_get($feeQuote, 'surcharge_amount_iqd', 0) > 0
        ? $billing->formatAmount((int) data_get($feeQuote, 'surcharge_amount_iqd', 0), 'IQD')
        : '';
    $hasCoupon = is_array($couponSummary) && (string) data_get($couponSummary, 'code', '') !== '';
    $appliedCouponCode = $hasCoupon ? (string) data_get($couponSummary, 'code') : '';
    $appliedCouponOriginalLabel = $hasCoupon
        ? (string) data_get(
            $couponSummary,
            'original_display.iqd_label',
            $billing->formatAmount((int) data_get($couponSummary, 'original_amount_iqd', (int) round((float) ($payment->original_amount_iqd ?? 0))), 'IQD')
        )
        : '';
    $appliedCouponDiscountLabel = $hasCoupon
        ? (string) data_get(
            $couponSummary,
            'discount_display.iqd_label',
            $billing->formatAmount((int) data_get($couponSummary, 'discount_amount_iqd', (int) round((float) ($payment->discount_amount_iqd ?? 0))), 'IQD')
        )
        : '';
    $appliedCouponFinalLabel = $hasCoupon
        ? (string) data_get(
            $couponSummary,
            'final_display.iqd_label',
            $billing->formatAmount((int) data_get($couponSummary, 'final_amount_iqd', (int) round((float) ($payment->discounted_amount_iqd ?? 0))), 'IQD')
        )
        : '';
    $statusText = match ($payment->status->value) {
        'paid' => __('Success'),
        'failed' => __('Declined'),
        'canceled' => __('Canceled'),
        'expired' => __('Expired'),
        default => __('Awaiting Payment'),
    };
    $statusAlertClass = match ($payment->status->value) {
        'paid' => 'success',
        'failed' => 'danger',
        'canceled', 'expired' => 'warning',
        default => 'info',
    };
    $statusAlertMessage = session('payment_status_message');
    if ($statusAlertMessage === null && $payment->isPaid()) {
        $statusAlertMessage = $payment->isProviderSubscriptionObject()
            ? __('Congrats! Your subscription was confirmed successfully. We sent the confirmation by email.')
            : __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.');
    }
    $homeUrl = route('app.home', ['locale' => app()->getLocale()]);
    $shouldAutoRedirectHome = $payment->isPaid() && $payment->fulfilled_at !== null;
    $isSubscriptionCheckout = $payment->isProviderSubscriptionObject();
    $providerObjectLabel = $isSubscriptionCheckout ? __('Subscription') : __('Payment');
    $providerObjectLabelLower = $isSubscriptionCheckout ? __('subscription checkout') : __('payment');
    $providerReferenceLabel = $isSubscriptionCheckout ? __('Subscription ID') : __('Payment ID');
    $providerReferenceValue = $payment->providerReference();
    $activeUntilLabel = $payment->active_until?->timezone($baghdadTimezone)->format('Y-m-d H:i');
    $knownProviderStatus = $fibSubscriptions->normalizeProviderStatus($payment->providerStatusLabel());
    $cancelResult = (string) data_get($payment->cancel_response, 'result', '');
    $showCancel = $payment->status->value === 'awaiting_customer_action'
        && ! in_array($cancelResult, ['already_scheduled', 'already_canceled', 'non_cancelable'], true)
        && (! $isSubscriptionCheckout || $knownProviderStatus === null || $fibSubscriptions->isCancelableProviderStatus($knownProviderStatus));
    $showRefresh = in_array($payment->status->value, ['awaiting_customer_action', 'pending'], true);
    $resolvedPaymentMode = \App\Domain\Payments\Enums\PaymentMode::fromValue($payment->payment_mode ?? null, \App\Domain\Payments\Enums\PaymentMode::ONE_TIME);
    $paymentModeDescription = $payment->purchase_type->value === 'addon_credits'
        ? __('One-time purchase')
        : __($resolvedPaymentMode->description((string) data_get($snapshot, 'billing_cycle', '')));
@endphp

@if (is_array($purchaseConversionPayload))
    @push('scripts')
        <script>
            (() => {
                const payload = @json($purchaseConversionPayload);
                if (!payload || typeof payload !== 'object') {
                    return;
                }

                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push(payload);
            })();
        </script>
    @endpush
@endif

<script src="https://cdn.lordicon.com/lordicon.js"></script>
<div class="row justify-content-center mt-4"
     data-payment-status-polling="{{ $shouldPoll ? 'active' : 'stopped' }}"
     data-payment-status-endpoint="{{ $statusEndpoint }}"
     data-payment-status-interval="{{ $pollIntervalMs }}"
     data-payment-status-max-ms="{{ $pollMaxDurationMs }}"
     data-payment-current-status="{{ $payment->status->value }}">
    <div class="col-xl-10">
        @if ($statusAlertMessage)
            <div class="alert alert-{{ $statusAlertClass }}">{{ $statusAlertMessage }}</div>
        @endif

        @if ($shouldPoll)
            <div class="alert alert-info d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2" aria-live="polite">
                <div>
                    <div class="fw-semibold">{{ __('Waiting for FIB confirmation') }}</div>
                    <div class="small mt-1">{{ __('This page checks your :object status automatically every 5 seconds while it remains pending.', ['object' => $providerObjectLabelLower]) }}</div>
                </div>
                <div class="small text-muted" data-status-polling-indicator="idle">
<lord-icon
    src="https://cdn.lordicon.com/euaablbm.json"
    trigger="loop"
    state="loop-cycle"
    colors="primary:#b4b4b4,secondary:#6c16c7"
    style="width:24px;height:24px">
</lord-icon>
                    {{ __('Automatic check is active.') }}
                </div>
                <div class="small text-muted d-none" data-status-polling-indicator="checking">
<lord-icon 
    src="https://cdn.lordicon.com/euaablbm.json"
    trigger="loop"
    delay="2000"
    colors="primary:#b4b4b4,secondary:#6c16c7"
    style="width:24px;height:24px">
</lord-icon>
                    {{ __('Checking now...') }}
                </div>
                <div class="small text-muted mt-2 w-100" data-status-runtime-message>
                    {{ __('Waiting for provider confirmation...') }}
                </div>
            </div>
        @endif

        @if ($shouldAutoRedirectHome)
                <div class="alert alert-success"
                     x-data
                     x-init="setTimeout(() => { window.location = @js($homeUrl); }, 2200)">
                <div class="fw-semibold">{{ $isSubscriptionCheckout ? __('Subscription completed successfully.') : __('Payment completed successfully.') }}</div>
                <div class="small mt-1">{{ __('Redirecting you to your app home...') }}</div>
            </div>
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div>
                        <div class="text-muted text-uppercase small mb-2">{{ $purchaseLabel }}</div>
                        <h3 class="mb-1">{{ (string) ($snapshot['name'] ?? __('Payment')) }}</h3>
                        <div class="text-muted small">
                            {{ __('Reference: :reference', ['reference' => $payment->local_reference]) }}
                        </div>
                    </div>
                    <div class="text-lg-end">
                        <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }} px-3 py-2">{{ $statusText }}</span>
                        <div class="mt-3 fw-semibold fs-5">{{ $displayPrimary }}</div>
                        @if ($displayEstimate !== '' && $displayEstimate !== $displayPrimary)
                            <div class="text-muted small">{{ __('Estimated local price: :amount', ['amount' => $displayEstimate]) }}</div>
                        @endif
                        @if ($displayCanonical !== '' && $displayCanonical !== $displayPrimary)
                            <div class="text-muted small">{{ __('Net product amount: :amount', ['amount' => $displayCanonical]) }}</div>
                        @endif
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="border rounded-4 p-3 p-lg-4 h-100">
                            <div class="fw-semibold mb-2">{{ __('Payment Details') }}</div>
                            @if ($showCouponInput && ! $hasCoupon)
                                <div class="border rounded-3 p-3 mb-3 bg-light-subtle">
                                    <div class="fw-semibold mb-2">{{ __('Coupon Code') }}</div>
                                    <div class="input-group">
                                        <input type="text"
                                               class="form-control"
                                               wire:model.defer="couponCode"
                                               maxlength="80"
                                               placeholder="{{ __('Enter coupon code') }}">
                                        <button type="button"
                                                class="btn btn-outline-primary"
                                                wire:click="applyCoupon"
                                                wire:loading.attr="disabled"
                                                wire:target="applyCoupon">
                                            <span wire:loading.remove wire:target="applyCoupon">{{ __('Apply Coupon') }}</span>
                                            <span wire:loading wire:target="applyCoupon">{{ __('Applying...') }}</span>
                                        </button>
                                        @if ($couponCode !== '' || $couponPreview !== [])
                                            <button type="button"
                                                    class="btn btn-outline-secondary"
                                                    wire:click="clearCoupon"
                                                    wire:loading.attr="disabled"
                                                    wire:target="clearCoupon,applyCoupon">
                                                {{ __('Clear') }}
                                            </button>
                                        @endif
                                    </div>
                                    <div class="small text-muted mt-2">
                                        {{ __('Coupons are validated server-side. Applying a valid coupon creates a new checkout with the updated total.') }}
                                    </div>

                                    @if ($couponMessage !== '')
                                        <div class="alert alert-{{ $couponMessageType }} mt-2 mb-0 py-2">
                                            {{ $couponMessage }}
                                        </div>
                                    @endif

                                    @if ($couponPreview !== [])
                                        <div class="mt-2 small">
                                            <div class="d-flex justify-content-between gap-3 mt-1">
                                                <span class="text-muted">{{ __('Original Amount') }}</span>
                                                <span>{{ data_get($couponPreview, 'original_display.iqd_label', data_get($couponPreview, 'original_amount_iqd')) }}</span>
                                            </div>
                                            <div class="d-flex justify-content-between gap-3 mt-1">
                                                <span class="text-muted">{{ __('Discount') }}</span>
                                                <span class="text-success">-{{ data_get($couponPreview, 'discount_display.iqd_label', data_get($couponPreview, 'discount_amount_iqd')) }}</span>
                                            </div>
                                            <div class="d-flex justify-content-between gap-3 mt-1 pt-2 border-top">
                                                <span class="fw-semibold">{{ __('Final Amount') }}</span>
                                                <span class="fw-semibold">{{ data_get($couponPreview, 'final_display.iqd_label', data_get($couponPreview, 'final_amount_iqd')) }}</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <dl class="row mb-0 small">
                                <dt class="col-sm-5 text-muted">{{ __('Provider') }}</dt>
                                <dd class="col-sm-7">FIB</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Provider Object') }}</dt>
                                <dd class="col-sm-7">{{ $providerObjectLabel }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Payment Mode') }}</dt>
                                <dd class="col-sm-7">{{ __($resolvedPaymentMode->label()) }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Renewal') }}</dt>
                                <dd class="col-sm-7">{{ $paymentModeDescription }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Amount To Pay') }}</dt>
                                <dd class="col-sm-7">{{ $displayPrimary }}</dd>

                                @if ($hasCoupon)
                                    <dt class="col-sm-5 text-muted">{{ __('Coupon') }}</dt>
                                    <dd class="col-sm-7">{{ $appliedCouponCode }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Original Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $appliedCouponOriginalLabel }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Discount') }}</dt>
                                    <dd class="col-sm-7 text-success">-{{ $appliedCouponDiscountLabel }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Discounted Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $appliedCouponFinalLabel }}</dd>
                                @endif

                                @if ($displayCanonical !== '' && $displayCanonical !== $displayPrimary)
                                    <dt class="col-sm-5 text-muted">{{ __('Net Product Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $displayCanonical }}</dd>
                                @endif

                                @if ($providerFeeLabel !== '')
                                    <dt class="col-sm-5 text-muted">{{ __('Provider Fee') }}</dt>
                                    <dd class="col-sm-7">{{ $providerFeeLabel }}</dd>
                                @endif

                                <dt class="col-sm-5 text-muted">{{ __('Readable Code') }}</dt>
                                <dd class="col-sm-7">{{ $payment->readable_code ?: __('Pending') }}</dd>

                                @if (! $isSubscriptionCheckout)
                                    <dt class="col-sm-5 text-muted">{{ $providerReferenceLabel }}</dt>
                                    <dd class="col-sm-7">{{ $providerReferenceValue ?: __('Pending') }}</dd>
                                @endif

                                <dt class="col-sm-5 text-muted">{{ __('Valid Until') }}</dt>
                                <dd class="col-sm-7">
                                    @if ($validUntilLabel)
                                        <span>{{ $validUntilLabel }}</span>
                                        <span class="d-block text-muted">{{ $baghdadTimezoneLabel }}</span>
                                    @else
                                        {{ __('Not provided') }}
                                    @endif
                                </dd>

                                @if ($isSubscriptionCheckout && $activeUntilLabel)
                                    <dt class="col-sm-5 text-muted">{{ __('Active Until') }}</dt>
                                    <dd class="col-sm-7">
                                        <span>{{ $activeUntilLabel }}</span>
                                        <span class="d-block text-muted">{{ $baghdadTimezoneLabel }}</span>
                                    </dd>
                                @endif
                            </dl>

                            @if ($payment->purchase_type->value === 'plan_subscription')
                                <div class="mt-3 small text-muted">
                                    {{ $isSubscriptionCheckout
                                        ? __('Your plan access updates automatically after the server confirms the subscription status.')
                                        : __('Your plan access updates automatically after the server confirms the payment status.') }}
                                </div>
                            @elseif ($payment->purchase_type->value === 'storage_subscription')
                                <div class="mt-3 small text-muted">
                                    {{ $isSubscriptionCheckout
                                        ? __('Your storage entitlement updates automatically after the server confirms the recurring subscription state.')
                                        : __('Your storage entitlement updates automatically after the server confirms the payment status.') }}
                                </div>
                            @else
                                <div class="mt-3 small text-muted">
                                    {{ __('Add-on credits are a one-time purchase and will only be fulfilled after the payment is confirmed.') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="border rounded-4 p-3 p-lg-4 h-100">
                            <div class="fw-semibold mb-2">{{ $isSubscriptionCheckout ? __('Complete Subscription In FIB') : __('Complete Payment In FIB') }}</div>

                            @if (!empty($payment->qr_code) && $payment->status->value === 'awaiting_customer_action')
                                <div class="text-center mb-3">
                                    <img src="{{ $payment->qr_code }}"
                                         alt="{{ __('FIB payment QR code') }}"
                                         class="img-fluid rounded border bg-white p-2"
                                         style="max-width: 260px;">
                                </div>
                            @endif

                            @if ($payment->readable_code)
                                <div class="alert alert-warning text-center">
                                    <div class="small text-muted mb-1">{{ __('Manual Code Entry') }}</div>
                                    <div class="fw-semibold fs-5">{{ $payment->readable_code }}</div>
                                </div>
                            @endif

                            @if ($links !== [])
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach ($links as $label => $url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                                            {{ __(ucfirst((string) $label) . ' App') }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            <div class="small text-muted mt-3">
                                {{ __('You can either scan the QR code or enter the readable code manually inside the FIB app. While the checkout is still pending, this page checks the server-side status automatically every few seconds. Keep the refresh button only as a fallback if something looks stuck.') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column flex-md-row gap-2 justify-content-between mt-4">
                    <a href="{{ route($backRoute, ['locale' => app()->getLocale()]) }}" class="btn btn-light">
                        {{ __('Back') }}
                    </a>

                    <div class="d-flex flex-wrap gap-2">
                        @if ($showCancel)
                            <form method="POST" action="{{ route('payments.fib.cancel', ['locale' => app()->getLocale(), 'payment' => $payment]) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger">{{ $isSubscriptionCheckout ? __('Cancel Subscription Checkout') : __('Cancel Payment') }}</button>
                            </form>
                        @endif

                        @if ($showRefresh)
                            <form method="POST" action="{{ route('payments.fib.refresh', ['locale' => app()->getLocale(), 'payment' => $payment]) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-secondary">{{ __('Refresh Status') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
(() => {
    const stopExistingFibPolling = () => {
        if (typeof window.__fibPaymentPollingStop === 'function') {
            window.__fibPaymentPollingStop();
            window.__fibPaymentPollingStop = null;
        }
    };

    const initFibPaymentPolling = () => {
        stopExistingFibPolling();

        const wrapper = document.querySelector('[data-payment-status-endpoint]');
        if (!wrapper) {
            return;
        }

        const mode = (wrapper.getAttribute('data-payment-status-polling') || 'stopped').toLowerCase();
        if (mode !== 'active') {
            return;
        }

        const endpoint = wrapper.getAttribute('data-payment-status-endpoint') || '';
        if (!endpoint) {
            return;
        }

        const intervalMs = Math.max(3000, Number(wrapper.getAttribute('data-payment-status-interval') || 5000));
        const maxPollingMs = Math.max(intervalMs, Number(wrapper.getAttribute('data-payment-status-max-ms') || 300000));
        const idleIndicator = wrapper.querySelector('[data-status-polling-indicator="idle"]');
        const checkingIndicator = wrapper.querySelector('[data-status-polling-indicator="checking"]');
        const runtimeMessage = wrapper.querySelector('[data-status-runtime-message]');
        const startedAt = Date.now();
        let consecutiveFailures = 0;
        let timerId = null;
        let inFlight = false;
        const labels = {
            waiting: @js(__('Waiting for provider confirmation...')),
            waitingWithStatus: @js(__('Waiting for provider confirmation. Provider status: :status')),
            timeout: @js(__('Still waiting for confirmation. You can keep this page open and use manual refresh as a fallback.')),
            rateLimited: @js(__('Too many status checks were sent. Please wait a moment and try again.')),
            serverError: @js(__('Status check is temporarily unavailable. Retrying automatically...')),
            networkError: @js(__('Network issue while checking status. Retrying automatically...')),
        };

        const setRuntimeMessage = (message, tone = 'muted') => {
            if (!runtimeMessage || !message) {
                return;
            }

            runtimeMessage.textContent = String(message);
            runtimeMessage.classList.remove('text-muted', 'text-warning', 'text-danger', 'text-success');
            runtimeMessage.classList.add(`text-${tone}`);
        };

        const setChecking = (isChecking) => {
            if (!idleIndicator || !checkingIndicator) {
                return;
            }

            idleIndicator.classList.toggle('d-none', isChecking);
            checkingIndicator.classList.toggle('d-none', !isChecking);
        };

        const stop = () => {
            if (timerId !== null) {
                clearInterval(timerId);
                timerId = null;
            }

            inFlight = false;
            setChecking(false);
        };

        const refreshUi = (redirectUrl = '') => {
            stop();

            if (redirectUrl) {
                window.location.assign(redirectUrl);
                return;
            }

            window.location.reload();
        };

        const poll = async () => {
            if (inFlight) {
                return;
            }

            if (Date.now() - startedAt >= maxPollingMs) {
                stop();
                setRuntimeMessage(labels.timeout, 'warning');

                return;
            }

            inFlight = true;
            setChecking(true);

            try {
                const response = await fetch(endpoint, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    consecutiveFailures += 1;

                    if (response.status === 429) {
                        setRuntimeMessage(labels.rateLimited, 'warning');
                    } else if (response.status >= 500) {
                        setRuntimeMessage(labels.serverError, 'warning');
                    } else {
                        setRuntimeMessage(labels.networkError, 'warning');
                    }

                    return;
                }

                let payload = null;

                try {
                    payload = await response.json();
                } catch (_) {
                    consecutiveFailures += 1;
                    setRuntimeMessage(labels.serverError, 'warning');

                    return;
                }

                consecutiveFailures = 0;
                const latestStatus = String(payload.status || '').toLowerCase();
                const currentStatus = String(wrapper.getAttribute('data-payment-current-status') || '').toLowerCase();
                const isTerminal = Boolean(payload.is_terminal);
                const providerStatus = String(payload.provider_status || '').trim();

                if (latestStatus !== '' && latestStatus !== currentStatus) {
                    refreshUi('');
                    return;
                }

                if (isTerminal) {
                    refreshUi('');

                    return;
                }

                if (providerStatus !== '') {
                    setRuntimeMessage(labels.waitingWithStatus.replace(':status', providerStatus), 'muted');
                } else if (payload && payload.message) {
                    setRuntimeMessage(String(payload.message), 'muted');
                } else {
                    setRuntimeMessage(labels.waiting, 'muted');
                }
            } catch (_) {
                // Keep automatic polling alive; manual refresh remains available as fallback.
                consecutiveFailures += 1;
                setRuntimeMessage(labels.networkError, 'warning');
            } finally {
                inFlight = false;
                setChecking(false);
            }
        };

        setRuntimeMessage(labels.waiting, 'muted');
        window.__fibPaymentPollingStop = stop;
        timerId = window.setInterval(poll, intervalMs);
        poll();
    };

    document.addEventListener('DOMContentLoaded', initFibPaymentPolling);
    document.addEventListener('livewire:navigated', initFibPaymentPolling);
})();
</script>
@endpush
