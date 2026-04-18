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

<x-slot:title>{{ __('FIB Payment') }} | {{ __('MET KURD') }}</x-slot:title>

@php
    $billing = app(\App\Services\Billing\BillingCurrencyService::class);
    $chargeDisplay = (array) data_get($snapshot, 'display', []);
    $baseDisplay = (array) data_get($snapshot, 'base_display', []);
    $feeQuote = (array) data_get($snapshot, 'fee_quote', data_get($payment->meta, 'fee_quote', []));
    $validUntilLabel = $payment->valid_until?->timezone(config('app.timezone'))->format('Y-m-d H:i');
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
        $statusAlertMessage = __('Congrats! Your payment was confirmed successfully. We sent the confirmation by email.');
    }
    $homeUrl = route('app.home', ['locale' => app()->getLocale()]);
    $shouldAutoRedirectHome = $payment->isPaid() && $payment->fulfilled_at !== null;
    $showCancel = $payment->status->value === 'awaiting_customer_action';
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
                    <div class="small mt-1">{{ __('This page checks your payment status automatically every 5 seconds while it remains pending.') }}</div>
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
                <div class="fw-semibold">{{ __('Payment completed successfully.') }}</div>
                <div class="small mt-1">{{ __('Redirecting you to your app home...') }}</div>
            </div>
        @endif

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                    <div>
                        <div class="text-muted text-uppercase small mb-2">{{ $purchaseLabel }}</div>
                        <h3 class="mb-2">{{ (string) ($snapshot['name'] ?? __('Payment')) }}</h3>
                        <div class="text-muted">
                            {{ __('Reference: :reference', ['reference' => $payment->local_reference]) }}
                        </div>
                    </div>
                    <div class="text-lg-end">
                        <span class="badge bg-{{ $statusClass }}-subtle text-{{ $statusClass }} px-3 py-2">{{ $statusText }}</span>
                        <div class="mt-3 fw-semibold">{{ $displayPrimary }}</div>
                        @if ($displayEstimate !== '' && $displayEstimate !== $displayPrimary)
                            <div class="text-muted small">{{ __('Estimated local price: :amount', ['amount' => $displayEstimate]) }}</div>
                        @endif
                        @if ($displayCanonical !== '' && $displayCanonical !== $displayPrimary)
                            <div class="text-muted small">{{ __('Net product amount: :amount', ['amount' => $displayCanonical]) }}</div>
                        @endif
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <div class="fw-semibold mb-3">{{ __('Payment Details') }}</div>
                            <dl class="row mb-0">
                                <dt class="col-sm-5 text-muted">{{ __('Provider') }}</dt>
                                <dd class="col-sm-7">FIB</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Payment Mode') }}</dt>
                                <dd class="col-sm-7">{{ $payment->payment_mode->value === 'recurring' ? __('Recurring') : __('One-Time') }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Amount To Pay') }}</dt>
                                <dd class="col-sm-7">{{ $displayPrimary }}</dd>

                                @if ($displayCanonical !== '' && $displayCanonical !== $displayPrimary)
                                    <dt class="col-sm-5 text-muted">{{ __('Net Product Amount') }}</dt>
                                    <dd class="col-sm-7">{{ $displayCanonical }}</dd>
                                @endif

                                @if ($providerFeeLabel !== '')
                                    <dt class="col-sm-5 text-muted">{{ __('Provider Fee') }}</dt>
                                    <dd class="col-sm-7">{{ $providerFeeLabel }}</dd>
                                @endif

                                @if ($surchargeLabel !== '')
                                    <dt class="col-sm-5 text-muted">{{ __('Customer Surcharge') }}</dt>
                                    <dd class="col-sm-7">{{ $surchargeLabel }}</dd>
                                @endif

                                <dt class="col-sm-5 text-muted">{{ __('Readable Code') }}</dt>
                                <dd class="col-sm-7">{{ $payment->readable_code ?: __('Pending') }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Valid Until') }}</dt>
                                <dd class="col-sm-7">{{ $validUntilLabel ?: __('Not provided') }}</dd>

                                <dt class="col-sm-5 text-muted">{{ __('Status Reason') }}</dt>
                                <dd class="col-sm-7">{{ $payment->status_reason ?: __('Waiting for FIB confirmation') }}</dd>
                            </dl>

                            @if ($payment->purchase_type->value === 'plan_subscription')
                                <div class="mt-4 small text-muted">
                                    {{ __('Recurring plan payments are currently handled as app-level subscriptions with manual renewal, because the published FIB docs do not expose a provider-managed recurring billing API.') }}
                                </div>
                            @elseif ($payment->purchase_type->value === 'storage_subscription')
                                <div class="mt-4 small text-muted">
                                    {{ __('Storage subscriptions follow the same recurring application mode boundary with manual renewal behavior on top of the documented FIB payment endpoints.') }}
                                </div>
                            @else
                                <div class="mt-4 small text-muted">
                                    {{ __('Add-on credits are a one-time purchase and will only be fulfilled after the payment is confirmed.') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <div class="fw-semibold mb-3">{{ __('Complete Payment In FIB') }}</div>

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
                                {{ __('You can either scan the QR code or enter the readable code manually inside the FIB app. While the payment is still pending, this page checks the status automatically every few seconds. Keep the refresh button only as a fallback if something looks stuck.') }}
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
                                <button type="submit" class="btn btn-outline-danger">{{ __('Cancel Payment') }}</button>
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
