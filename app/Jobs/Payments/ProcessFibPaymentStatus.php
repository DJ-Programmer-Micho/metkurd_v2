<?php

namespace App\Jobs\Payments;

use App\Domain\Payments\Actions\ConfirmFibPayment;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Support\PaymentEventRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessFibPaymentStatus implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerObjectType,
        public string $providerReference,
        public array $payload,
        public string $source = 'callback',
    ) {}

    public function handle(ConfirmFibPayment $confirm, PaymentEventRecorder $events): void
    {
        $payment = Payment::query()->where('provider', 'fib')->where('provider_object_type', $this->providerObjectType)
            ->where($this->providerObjectType === 'subscription' ? 'fib_subscription_id' : 'fib_payment_id', $this->providerReference)->first();

        try {
            $objectType = PaymentProviderObjectType::from($this->providerObjectType);

            $payment = $objectType->isSubscription()
                ? $confirm->handleByFibSubscriptionId($this->providerReference, $this->source, $this->payload)
                : $confirm->handleByFibPaymentId($this->providerReference, $this->source, $this->payload);

            if (! $payment instanceof Payment) {
                $events->record(null, [
                    'event_type' => 'callback_orphaned',
                    'source' => $this->source,
                    'event_key' => $this->eventKey('orphaned'),
                    'fib_payment_id' => $objectType->isPayment() ? $this->providerReference : null,
                    'fib_subscription_id' => $objectType->isSubscription() ? $this->providerReference : null,
                    'payload' => $this->payload,
                    'meta' => [
                        'provider_object_type' => $objectType->value,
                    ],
                ]);

                return;
            }

            // Successful GET transitions are recorded by SyncFibCheckoutStatus; no callback echo event.
        } catch (\Throwable $exception) {
            Log::error('Queued FIB callback processing failed.', [
                'provider_object_type' => $this->providerObjectType,
                'provider_reference' => $this->providerReference,
                'source' => $this->source,
                'message' => $exception->getMessage(),
            ]);

            if ($payment instanceof Payment) {
                app(\App\Services\Payments\PaymentSyncFailureService::class)->captureCallbackFailure($payment, $exception, $this->source);
            } else {
                $events->record($payment, [
                    'event_type' => 'callback_failed',
                    'source' => $this->source,
                    'event_key' => $this->eventKey('failed'),
                    'fib_payment_id' => $this->providerObjectType === PaymentProviderObjectType::PAYMENT->value ? $this->providerReference : null,
                    'fib_subscription_id' => $this->providerObjectType === PaymentProviderObjectType::SUBSCRIPTION->value ? $this->providerReference : null,
                    'before_status' => $payment?->status?->value,
                    'after_status' => $payment?->status?->value,
                    'payload' => $this->payload,
                    'meta' => [
                        'provider_object_type' => $this->providerObjectType,
                        'message' => $exception->getMessage(),
                    ],
                ]);

            }

            throw $exception;
        }
    }

    protected function eventKey(string $suffix): string
    {
        return sprintf(
            'fib-callback:%s:%s:%s',
            $suffix,
            $this->providerReference,
            sha1(json_encode($this->payload))
        );
    }
}
