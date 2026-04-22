<?php

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Models\Payment;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('app::layouts.app')]
class extends Component
{
    public Payment $payment;

    public function mount(Payment $payment): void
    {
        abort_unless((int) auth('app')->id() === (int) $payment->customer_id, 403);

        $this->payment = $payment->fresh(['customer.profile']) ?? $payment;
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
    }

    public function render()
    {
        $payment = $this->payment->fresh(['customer.profile']) ?? $this->payment;
        $snapshot = $payment->snapshot();
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
            'links' => $links,
            'shouldPoll' => $shouldPoll,
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
    $couponCode = $hasCoupon ? (string) data_get($couponSummary, 'code') : '';
    $couponOriginalLabel = $hasCoupon
        ? (string) data_get(
            $couponSummary,
            'original_display.iqd_label',
            $billing->formatAmount((int) data_get($couponSummary, 'original_amount_iqd', (int) round((float) ($payment->original_amount_iqd ?? 0))), 'IQD')
        )
        : '';
    $couponDiscountLabel = $hasCoupon
        ? (string) data_get(
            $couponSummary,
            'discount_display.iqd_label',
            $billing->formatAmount((int) data_get($couponSummary, 'discount_amount_iqd', (int) round((float) ($payment->discount_amount_iqd ?? 0))), 'IQD')
        )
        : '';
    $couponFinalLabel = $hasCoupon
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
@endphp

<div class="row justify-content-center mt-4"
     data-payment-status-polling="{{ $shouldPoll ? 'active' : 'stopped' }}"
     @if ($shouldPoll) wire:poll.5s="pollStatus" @endif>
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
                <div class="small text-muted" wire:loading.remove wire:target="pollStatus">
                    {{ __('Automatic check is active.') }}
                </div>
                <div class="small text-muted" wire:loading.delay wire:target="pollStatus">
                    {{ __('Checking now...') }}
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
                            <dl class="row mb-0 small">
                                <dt class="col-sm-5 text-muted">{{ __('Provider') }}</dt>
                                <dd class="col-sm-7">FIB</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Provider Object') }}</dt>
                                <dd class="col-sm-7">{{ $providerObjectLabel }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Payment Mode') }}</dt>
                                <dd class="col-sm-7">{{ $payment->payment_mode->value === 'recurring' ? __('Recurring') : __('One-Time') }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Amount To Pay') }}</dt>
                                <dd class="col-sm-7">{{ $displayPrimary }}</dd>

                                @if ($hasCoupon)
                                    <dt class="col-sm-5 text-muted">{{ __('Coupon') }}</dt>
                                    <dd class="col-sm-7">{{ $couponCode }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Original Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $couponOriginalLabel }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Discount') }}</dt>
                                    <dd class="col-sm-7 text-success">-{{ $couponDiscountLabel }}</dd>

                                    <dt class="col-sm-5 text-muted">{{ __('Discounted Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $couponFinalLabel }}</dd>
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
                                    {{ __('Your plan access updates automatically after the server confirms the subscription status.') }}
                                </div>
                            @elseif ($payment->purchase_type->value === 'storage_subscription')
                                <div class="mt-3 small text-muted">
                                    {{ __('Your storage entitlement updates automatically after the server confirms the recurring subscription state.') }}
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
