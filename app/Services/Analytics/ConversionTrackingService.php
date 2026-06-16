<?php

namespace App\Services\Analytics;

use App\Domain\Payments\Models\Payment;
use App\Models\AdConversionEvent;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class ConversionTrackingService
{
    public const SIGNUP_EVENT = 'metkurd_signup';

    public const PURCHASE_EVENT = 'metkurd_purchase';

    public const SIGNUP_SESSION_KEY = 'metkurd_signup_conversion';

    public function queueSignupConversion(Customer $customer, string $signupMethod): void
    {
        $method = $this->normalizeSignupMethod($signupMethod);
        $payload = [
            'event' => self::SIGNUP_EVENT,
            'signup_method' => $method,
            'customer_id' => (int) $customer->id,
            'customer_uid' => filled((string) ($customer->uid ?? '')) ? (string) $customer->uid : null,
            'locale' => $this->normalizeLocale(app()->getLocale()),
        ];

        session()->put(self::SIGNUP_SESSION_KEY, $payload);

        $this->debug('Signup conversion session flag queued.', [
            'customer_id' => (int) $customer->id,
            'signup_method' => $method,
        ]);
    }

    public function consumeSignupConversionPayload(): ?array
    {
        $payload = session()->pull(self::SIGNUP_SESSION_KEY);

        if (! is_array($payload)) {
            return null;
        }

        if ((string) ($payload['event'] ?? '') !== self::SIGNUP_EVENT) {
            return null;
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);

        if ($customerId <= 0) {
            return null;
        }

        $normalizedPayload = [
            'event' => self::SIGNUP_EVENT,
            'signup_method' => $this->normalizeSignupMethod((string) ($payload['signup_method'] ?? '')),
            'customer_id' => $customerId,
            'customer_uid' => filled((string) ($payload['customer_uid'] ?? '')) ? (string) $payload['customer_uid'] : null,
            'locale' => $this->normalizeLocale((string) ($payload['locale'] ?? app()->getLocale())),
        ];

        $recorded = $this->storeConversionEvent(
            eventName: self::SIGNUP_EVENT,
            customerId: $customerId,
            paymentId: null,
            transactionId: null,
            dedupeKey: sprintf('%s:customer:%d', self::SIGNUP_EVENT, $customerId),
            payload: $normalizedPayload,
        );

        if (! $recorded) {
            $this->debug('Signup conversion skipped because it was already tracked.', [
                'customer_id' => $customerId,
            ]);

            return null;
        }

        return $normalizedPayload;
    }

    public function preparePurchaseConversionPayload(Payment $payment): ?array
    {
        $status = (string) ($payment->status?->value ?? $payment->status ?? '');

        if ($status !== 'paid') {
            return null;
        }

        if ($payment->fulfilled_at === null) {
            $this->debug('Purchase conversion skipped because fulfillment is pending.', [
                'payment_id' => (int) $payment->id,
            ]);

            return null;
        }

        $paymentId = (int) ($payment->id ?? 0);

        if ($paymentId <= 0) {
            return null;
        }

        $payload = $this->buildPurchasePayload($payment);
        $recorded = $this->storeConversionEvent(
            eventName: self::PURCHASE_EVENT,
            customerId: (int) ($payment->customer_id ?? 0) ?: null,
            paymentId: $paymentId,
            transactionId: (string) ($payment->uuid ?? ''),
            dedupeKey: sprintf('%s:payment:%d', self::PURCHASE_EVENT, $paymentId),
            payload: $payload,
        );

        if (! $recorded) {
            $this->debug('Purchase conversion skipped because it was already tracked.', [
                'payment_id' => $paymentId,
            ]);

            return null;
        }

        $this->debug('Purchase conversion is eligible and prepared.', [
            'payment_id' => $paymentId,
            'purchase_type' => (string) ($payload['purchase_type'] ?? ''),
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPurchasePayload(Payment $payment): array
    {
        $snapshot = $payment->snapshot();
        $purchaseType = (string) ($payment->purchase_type?->value ?? $payment->purchase_type ?? '');
        $paymentMode = (string) ($payment->payment_mode?->value ?? $payment->payment_mode ?? '');
        $itemName = trim((string) data_get($snapshot, 'name', ''));
        $itemCode = trim((string) data_get($snapshot, 'code', ''));
        $billingCycle = trim((string) data_get($snapshot, 'billing_cycle', ''));

        $payload = [
            'event' => self::PURCHASE_EVENT,
            'transaction_id' => (string) ($payment->uuid ?? ''),
            'payment_id' => (int) $payment->id,
            'purchase_type' => $purchaseType,
            'payment_mode' => $paymentMode,
            'value' => (float) ($payment->amount ?? 0),
            'currency' => strtoupper((string) ($payment->currency ?? 'IQD')),
            'customer_id' => (int) ($payment->customer_id ?? 0),
            'provider' => 'fib',
            'provider_ref' => (string) ($payment->fib_payment_id ?: $payment->fib_subscription_id ?: ''),
            'locale' => $this->normalizeLocale((string) data_get($payment->meta, 'locale', app()->getLocale())),
            'item_name' => $itemName !== '' ? $itemName : null,
            'item_code' => $itemCode !== '' ? $itemCode : null,
            'billing_cycle' => $billingCycle !== '' ? $billingCycle : null,
        ];

        if ($purchaseType === 'plan_subscription') {
            $payload['plan_name'] = $itemName !== '' ? $itemName : null;
        } elseif ($purchaseType === 'storage_subscription') {
            $payload['storage_name'] = $itemName !== '' ? $itemName : null;
        } elseif ($purchaseType === 'addon_credits') {
            $payload['addon_name'] = $itemName !== '' ? $itemName : null;
            $payload['credits_amount'] = (int) data_get($snapshot, 'credits_amount', 0);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function storeConversionEvent(
        string $eventName,
        ?int $customerId,
        ?int $paymentId,
        ?string $transactionId,
        string $dedupeKey,
        array $payload,
    ): bool {
        try {
            $event = AdConversionEvent::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'event_name' => $eventName,
                    'customer_id' => $customerId,
                    'payment_id' => $paymentId,
                    'transaction_id' => filled((string) $transactionId) ? (string) $transactionId : null,
                    'payload' => $payload,
                    'fired_at' => now(),
                ]
            );

            return (bool) $event->wasRecentlyCreated;
        } catch (\Throwable $exception) {
            Log::warning('Ad conversion event persistence failed.', [
                'event_name' => $eventName,
                'customer_id' => $customerId,
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    protected function normalizeSignupMethod(string $method): string
    {
        $method = strtolower(trim($method));

        return in_array($method, ['email_form', 'google', 'github'], true)
            ? $method
            : 'email_form';
    }

    protected function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        if ($locale === '') {
            $locale = strtolower(trim((string) app()->getLocale()));
        }

        return $locale !== '' ? $locale : 'en';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function debug(string $message, array $context = []): void
    {
        if (! $this->shouldDebug()) {
            return;
        }

        Log::info($message, $context);
    }

    protected function shouldDebug(): bool
    {
        return (bool) config('services.gtm.debug', false)
            && app()->environment(['local', 'staging']);
    }
}
