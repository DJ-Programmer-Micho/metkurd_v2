<?php

namespace App\Livewire\Account;

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentCheckoutState;
use App\Models\CreditProduct;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

abstract class PaymentPage extends Component
{
    #[Locked]
    public int $paymentId;

    #[Locked]
    public int $polls = 0;

    public function mount(Payment $payment): void
    {
        abort_unless((int) auth('app')->id() === (int) $payment->customer_id, 403);
        $this->paymentId = $payment->id;
    }

    protected function owned(): Payment
    {
        abort_unless(auth('app')->check(), 403);

        return Payment::where('customer_id', auth('app')->id())->findOrFail($this->paymentId);
    }

    #[Computed]
    public function checkout(): array
    {
        $payment = $this->owned();
        $state = app(PaymentCheckoutState::class)->state($payment);
        $canAbandon = app(\App\Domain\Payments\Support\AbandonedCheckoutEligibility::class)->customerEligible($payment);
        $deadline = app(PaymentCheckoutState::class)->deadline($payment);
        $kind = match ($payment->purchasable_type) {
            ServicePlan::class => 'subscription-plans', StoragePlan::class => 'storage-plans', CreditProduct::class => 'addon-credits', default => null,
        };
        $snapshot = $payment->snapshot();
        $cycle = data_get($snapshot, 'billing_cycle', data_get($payment->meta, 'billing_cycle'));
        $mode = $payment->resolvedPaymentMode()->value;
        $links = [];
        $qr = null;
        if ($state === 'awaiting') {
            foreach ($payment->appLinks() as $name => $url) {
                if (in_array($name, ['app', 'personal', 'business', 'corporate'], true) && $this->safeProviderUrl($url)) {
                    $links[$name] = $url;
                }
            }
            $rawQr = (string) \App\Domain\Payments\Support\CheckoutQrCache::read($payment);
            if (preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$~', $rawQr) || $this->safeProviderUrl($rawQr)) {
                $qr = $rawQr;
            }
        }

        return ['state' => $state, 'name' => (string) ($snapshot['name'] ?? __('payment_v2.purchase')),
            'canAbandon' => $canAbandon,
            'stateLabel' => $canAbandon ? 'attention' : ($state === 'canceled' && data_get($payment->meta, 'customer_checkout_resolution.action') === 'abandon_unpaid_checkout' ? 'checkout_canceled' : $state),
            'cycle' => in_array($cycle, ['monthly', 'yearly', 'lifetime', 'hourly'], true) ? $cycle : null,
            'mode' => $mode, 'amount' => number_format((float) $payment->amount), 'currency' => $payment->currency,
            'created' => $payment->created_at?->format('Y-m-d H:i'), 'expires' => app(PaymentCheckoutState::class)->deadline($payment)?->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
            'method' => strtoupper((string) $payment->provider->value), 'links' => $links, 'qr' => $qr,
            'code' => $state === 'awaiting' ? trim((string) $payment->readable_code) : '',
            'expiresAt' => $state === 'awaiting' ? $deadline?->getTimestamp() : null,
            'serverNow' => now()->getTimestamp(),
            'operatorReviewOnly' => $this->operatorReviewOnly($payment),
            'app' => $state === 'completed' ? data_get($snapshot, 'app_credits_monthly', data_get($snapshot, 'monthly_credits')) : null,
            'api' => $state === 'completed' ? data_get($snapshot, 'api_credits_monthly') : null,
            'credits' => $state === 'completed' ? data_get($snapshot, 'credits_amount') : null,
            'quota' => $state === 'completed' ? data_get($snapshot, 'quota_mb') : null,
            'poll' => $state === 'awaiting' && $this->polls < 60,
            'returnUrl' => route($kind ? 'app.v2.'.$kind : 'app.v2.billing', ['locale' => app()->getLocale()])];
    }

    protected function safeProviderUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && ($host === 'fib.iq' || str_ends_with($host, '.fib.iq'));
    }

    public function pollStatus(): void
    {
        $payment = $this->owned();
        if ($this->polls >= 60 || ! app(PaymentCheckoutState::class)->canPoll($payment)) {
            unset($this->checkout);

            return;
        }
        $this->polls++;
        $this->sync($payment);
    }

    public function refreshStatus(): void
    {
        $payment = $this->owned();
        $this->resetErrorBag('status');
        if ($this->operatorReviewOnly($payment)) {
            unset($this->checkout);

            return;
        }
        if (in_array(app(PaymentCheckoutState::class)->state($payment), ['awaiting', 'confirming', 'review'], true)) {
            $this->sync($payment);
        }
        unset($this->checkout);
    }

    public function abandonCheckout(): void
    {
        $this->owned();
        $this->resetErrorBag('status');
        try {
            app(\App\Domain\Payments\Actions\AbandonCustomerCheckout::class)->handle($this->paymentId);
            $this->dispatch('v2-checkout-changed');
        } finally {
            unset($this->checkout);
        }
    }

    protected function operatorReviewOnly(Payment $payment): bool
    {
        return app(PaymentCheckoutState::class)->state($payment) === 'review'
            && ((bool) data_get($payment->meta, 'latest_sync_failure_pause_reconciliation', false)
                || app(\App\Domain\Payments\Support\AbandonedCheckoutEligibility::class)->customerEligible($payment));
    }

    protected function sync(Payment $payment): void
    {
        // Cross-tab throttle, with no customer-supplied financial state.
        $lock = Cache::lock('checkout-status:'.$payment->id, 60);
        if (! $lock->get()) {
            return;
        }
        try {
            if (! Cache::add('checkout-status-throttle:'.$payment->id, true, 10)) {
                return;
            }
            app(ConfirmFibPayment::class)->handle($payment, 'v2_checkout_status');
        } catch (\Throwable) {
            $this->addError('status', __('payment_v2.refresh_failed'));
        } finally {
            $lock->release();
            unset($this->checkout);
        }
    }
}
