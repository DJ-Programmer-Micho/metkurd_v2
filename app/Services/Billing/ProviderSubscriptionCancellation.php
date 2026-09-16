<?php

namespace App\Services\Billing;

use App\Domain\Payments\Fib\FibSubscriptionCancellationService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Durable local intent. Remote renewal state never determines already-paid access. */
class ProviderSubscriptionCancellation
{
    public function customerCancel(Customer $customer, CustomerServiceSubscription|CustomerStorageSubscription $subscription): mixed
    {
        DB::transaction(function () use ($customer, $subscription) {
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($subscription->payment_id)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            $locked = $subscription->newQuery()->whereKey($subscription->id)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('payment', $payment);
            if ((int) $locked->payment_id !== (int) $payment->id || ! $payment->isFulfilled()
                || $locked->source === ServiceAgreementLifecycle::SOURCE
                || $payment->provider?->value !== 'fib' || ! $payment->fib_subscription_id
                || ! app(SubscriptionCyclePolicy::class)->isCurrent($locked) || $locked->status !== 'active'
                || ! app(SubscriptionCyclePolicy::class)->boundary($locked)?->isFuture()) {
                throw ValidationException::withMessages(['plan' => __('admin_p0.state_changed')]);
            }
            $this->request($payment, 'customer_cancel', ['type' => 'customer', 'id' => $customer->id]);
        });

        return $subscription->fresh();
    }

    public function request(Payment $payment, string $reason, ?array $actor = null, array $replacement = []): void
    {
        if (! in_array($reason, ['customer_cancel', 'plan_switch', 'failed_renewal', 'admin_cancel'], true)) {
            throw new \InvalidArgumentException('Unsupported cancellation context.');
        }
        DB::transaction(function () use ($payment, $reason, $actor, $replacement) {
            Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->where('customer_id', $payment->customer_id)->lockForUpdate()->firstOrFail();
            if (! $locked->isCurrentBillingPeriod()) {
                return;
            }
            if (! $locked->isProviderSubscriptionObject() || $locked->provider?->value !== 'fib' || ! $locked->fib_subscription_id) {
                return;
            }
            $meta = (array) $locked->meta;
            if (data_get($meta, 'provider_cancellation.requested_at')) {
                if ($reason === 'plan_switch') {
                    $meta['provider_cancellation']['reason_code'] = 'plan_switch';
                    $meta['provider_cancellation']['replacement_subscription_id'] = $replacement['subscription_id'] ?? null;
                    $meta['provider_cancellation']['replacement_payment_id'] = $replacement['payment_id'] ?? null;
                    $locked->update(['meta' => $meta]);
                    $this->project($locked);
                }

                return;
            }
            $meta['provider_cancellation'] = [
                'provider' => 'fib', 'provider_ref' => $locked->fib_subscription_id,
                'reason_code' => $reason, 'requested_at' => now()->toIso8601String(),
                'requested_by' => $actor ?? ['type' => 'system'], 'state' => 'pending',
                'provider_cancel_pending' => true, 'effective_access_until' => $locked->active_until?->toIso8601String(),
                'last_payment_at' => $locked->last_payment_at?->toIso8601String(),
                'replacement_subscription_id' => $replacement['subscription_id'] ?? null,
                'replacement_payment_id' => $replacement['payment_id'] ?? null,
                'attempts' => 0,
            ];
            $locked->update(['meta' => $meta]);
            $this->project($locked);
            $this->event($locked, 'provider_cancel_intent', $meta['provider_cancellation']);
            DB::afterCommit(function () use ($locked) {
                try {
                    $this->process($locked);
                } catch (\Throwable $e) {
                    // Committed intent remains available to the scheduler after any persistence failure.
                    Log::warning('Committed subscription cancellation awaits retry.', ['payment_id' => $locked->id, 'exception' => get_class($e)]);
                }
            });
        });
    }

    public function process(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->where('customer_id', $payment->customer_id)->lockForUpdate()->firstOrFail();
            if (! $locked->isCurrentBillingPeriod()) {
                return;
            }
            $context = (array) data_get($locked->meta, 'provider_cancellation', []);
            if (! ($context['provider_cancel_pending'] ?? false)
                || (! empty($context['retry_after']) && now()->lt(\Illuminate\Support\Carbon::parse($context['retry_after'])))) {
                return;
            }
            $accepted = ! empty($context['provider_cancel_requested_at']);
            try {
                $result = app(FibSubscriptionCancellationService::class)->cancel($locked, ! $accepted);
            } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException) {
                $result = ['result' => 'provider_error', 'last_payment_at' => null, 'active_until' => null];
            }
            $context['attempts'] = (int) ($context['attempts'] ?? 0) + 1;
            $context['last_attempt_at'] = now()->toIso8601String();
            $context['result'] = $result['result'];
            $context['retry_after'] = now()->addMinutes(5)->toIso8601String();
            if ($result['result'] !== 'provider_error') {
                $locked->provider_status = $result['provider_status'];
                $locked->provider_subscription_status = $result['provider_status'];
            }
            if ($result['result'] === 'cancel_requested') {
                $context['provider_cancel_requested_at'] ??= now()->toIso8601String();
                $context['state'] = 'requested';
            } elseif (in_array($result['result'], ['already_scheduled', 'already_canceled'], true)) {
                $context['state'] = 'confirmed';
                $context['provider_cancel_confirmed_at'] ??= now()->toIso8601String();
                $context['provider_cancel_pending'] = false;
                $context['retry_after'] = null;
                // Only validated authenticated GET, not POST acceptance, confirms remote state.
                $locked->provider_status = $result['provider_status'];
                $locked->provider_subscription_status = $result['provider_status'];
            }
            if ($result['result'] === 'provider_error') {
                $context['state'] = $accepted ? 'requested' : 'pending';
            }
            $meta = (array) $locked->meta;
            $context['observed_last_payment_at'] = $result['last_payment_at']?->toIso8601String();
            $context['observed_active_until'] = $result['active_until']?->toIso8601String();
            $meta['provider_cancellation'] = $context;
            if (data_get($meta, 'supersession.superseded_at')) {
                $meta['supersession']['provider_cancel_result'] = $result['result'];
            }
            $locked->meta = $meta;
            if ($result['last_payment_at'] && (! $locked->last_payment_at || $result['last_payment_at']->gt($locked->last_payment_at))) {
                $locked->internal_status = 'requires_review';
                $locked->review_required_at ??= now();
                $locked->mismatch_reason = 'collection_after_cancellation_intent';
            }
            $locked->save();
            $this->project($locked);
            $this->event($locked, 'provider_cancellation_'.$context['state'], $context);
            if ($result['result'] === 'cancel_requested') {
                $prefix = $locked->purchase_type?->value === 'storage_subscription' ? 'storage' : 'service';
                $this->event($locked, $prefix.'_subscription_cancel_requested', $context);
            }
            if ($result['result'] === 'provider_error') {
                $this->event($locked, 'provider_cancel_failed', $context);
                if ($context['reason_code'] === 'plan_switch' && $context['attempts'] === 1) {
                    DB::afterCommit(fn () => app(\App\Support\TelegramSubscriptionLifecycleNotifier::class)->send(
                        'Failed to cancel superseded FIB service subscription',
                        ['Payment ID' => $locked->id, 'Customer ID' => $locked->customer_id, 'Cancellation' => 'pending retry'],
                    ));
                }
            }
        });
    }

    /** Called only after the shared authenticated GET validation succeeds. */
    public function observe(Payment $payment, string $source): void
    {
        if (! in_array(strtoupper((string) $payment->provider_subscription_status), ['CANCELLED', 'CANCELED'], true)) {
            return;
        }
        DB::transaction(function () use ($payment, $source) {
            Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->where('customer_id', $payment->customer_id)->lockForUpdate()->firstOrFail();
            if (! $locked->isCurrentBillingPeriod()) {
                return;
            }
            if (! in_array(strtoupper((string) $locked->provider_subscription_status), ['CANCELLED', 'CANCELED'], true)) {
                return;
            }
            $meta = (array) $locked->meta;
            $context = (array) ($meta['provider_cancellation'] ?? []);
            $context += ['reason_code' => 'provider_app', 'requested_at' => now()->toIso8601String(),
                'requested_by' => ['type' => 'provider'], 'effective_access_until' => $locked->active_until?->toIso8601String(),
                'last_payment_at' => $locked->last_payment_at?->toIso8601String()];
            $context['state'] = 'confirmed';
            $context['provider_cancel_pending'] = false;
            $context['provider_cancel_confirmed_at'] ??= now()->toIso8601String();
            $context['retry_after'] = null;
            $meta['provider_cancellation'] = $context;
            $locked->update(['meta' => $meta]);
            $this->project($locked);
            $this->event($locked, 'provider_cancellation_confirmed', $context + ['source' => $source]);
        });
    }

    private function project(Payment $payment): void
    {
        $context = (array) data_get($payment->meta, 'provider_cancellation', []);
        foreach ([CustomerServiceSubscription::class, CustomerStorageSubscription::class] as $class) {
            foreach ($class::where('customer_id', $payment->customer_id)->where('payment_id', $payment->id)->lockForUpdate()->get() as $sub) {
                $meta = (array) $sub->meta;
                $wasAutoRenewing = (bool) $sub->auto_renew;
                $meta['provider_cancellation'] = $context;
                $meta['cancel_source'] = match ($context['reason_code']) {
                    'customer_cancel' => 'customer_web',
                    'failed_renewal' => 'renewal_failed',
                    default => $context['reason_code'],
                };
                $meta['provider_cancel_pending'] = $context['provider_cancel_pending'];
                $meta['provider_status'] = $payment->provider_subscription_status;
                $sub->forceFill(['auto_renew' => false, 'canceled_at' => $sub->canceled_at ?? now(), 'meta' => $meta]);
                if ($sub->status === 'active' && ! data_get($meta, 'superseded_at') && $context['effective_access_until']) {
                    $sub->ends_at = $context['effective_access_until'];
                }
                $sub->save();
                if ($wasAutoRenewing && $sub->status === 'active') {
                    $this->event($payment, ($sub instanceof CustomerServiceSubscription ? 'service' : 'storage').'_subscription_cancel_at_period_end', $context);
                }
            }
        }
    }

    private function event(Payment $payment, string $type, array $context): void
    {
        app(PaymentEventRecorder::class)->record($payment, ['event_type' => $type, 'source' => 'subscription_cancellation',
            'event_key' => $type.':'.$payment->id.':'.($context['attempts'] ?? 0),
            'before_status' => $payment->status->value, 'after_status' => $payment->status->value, 'meta' => $context]);
    }
}
