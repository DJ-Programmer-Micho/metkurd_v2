<?php

namespace App\Services\Payments;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Fib\FibFailureInterpreter;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Support\PaymentEventRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PaymentSyncFailureService
{
    public function __construct(
        protected FibFailureInterpreter $interpreter,
        protected PaymentEventRecorder $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function capture(Payment $payment, \Throwable $exception, string $source): array
    {
        return $this->captureFailure(
            payment: $payment,
            exception: $exception,
            source: $source,
            eventType: 'provider_status_sync_failed',
            metaPrefix: 'latest_sync_failure',
            markRequiresReview: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function captureRenewalFailure(Payment $payment, \Throwable $exception, string $source): array
    {
        return $this->captureFailure(
            payment: $payment,
            exception: $exception,
            source: $source,
            eventType: 'provider_renewal_sync_failed',
            metaPrefix: 'latest_renewal_sync_failure',
            markRequiresReview: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function captureFailure(
        Payment $payment,
        \Throwable $exception,
        string $source,
        string $eventType,
        string $metaPrefix,
        bool $markRequiresReview,
    ): array {
        $payment = $payment->fresh() ?? $payment;
        $now = now();
        $providerReferenceType = $payment->isProviderSubscriptionObject() ? 'subscription' : 'payment';
        $details = $this->interpreter->describe(
            $exception,
            $providerReferenceType,
            $payment->providerReference(),
            $source,
            $payment->isProviderSubscriptionObject() ? 'subscription_status' : 'payment_status',
            $payment->isProviderSubscriptionObject() ? 'subscription' : 'payment',
        );
        $signature = $this->signature($details);
        $captured = [];

        DB::transaction(function () use ($payment, $now, $details, $signature, $eventType, $metaPrefix, $markRequiresReview, &$captured) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $meta = (array) ($locked->meta ?? []);
            $previousSignature = trim((string) data_get($meta, $metaPrefix.'_signature', ''));
            $sameFailure = $previousSignature !== '' && hash_equals($previousSignature, $signature);
            $failureCount = $sameFailure
                ? max(1, (int) data_get($meta, $metaPrefix.'_count', 0)) + 1
                : 1;
            $firstSeenAt = $sameFailure
                ? (string) data_get($meta, $metaPrefix.'_first_seen_at', $now->toIso8601String())
                : $now->toIso8601String();
            $pauseReconciliation = $this->shouldPauseReconciliation($locked, $details, $failureCount);
            $failureSnapshot = array_filter([
                'http_status' => $details['http_status'] ?? null,
                'fib_error_code' => $details['fib_error_code'] ?? null,
                'fib_error_title' => $details['fib_error_title'] ?? null,
                'fib_trace_id' => $details['fib_trace_id'] ?? null,
                'provider_reference' => $details['provider_reference'] ?? null,
                'provider_reference_type' => $details['provider_reference_type'] ?? null,
                'endpoint' => $details['endpoint'] ?? null,
                'profile' => $details['profile'] ?? null,
                'source' => $details['source'] ?? null,
                'safe_message' => $details['safe_message'] ?? null,
                'display_error' => $details['display_error'] ?? null,
                'permanent' => $details['permanent'] ?? null,
                'transient' => $details['transient'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $meta[$metaPrefix] = $failureSnapshot;
            $meta[$metaPrefix.'_signature'] = $signature;
            $meta[$metaPrefix.'_count'] = $failureCount;
            $meta[$metaPrefix.'_first_seen_at'] = $firstSeenAt;
            $meta[$metaPrefix.'_last_seen_at'] = $now->toIso8601String();
            $meta[$metaPrefix.'_http_status'] = $details['http_status'] ?? null;
            $meta[$metaPrefix.'_error_code'] = $details['fib_error_code'] ?? null;
            $meta[$metaPrefix.'_trace_id'] = $details['fib_trace_id'] ?? null;
            $meta[$metaPrefix.'_pause_reconciliation'] = $pauseReconciliation;

            $locked->forceFill([
                'meta' => $meta,
                'last_status_checked_at' => $now,
            ])->save();

            $event = $this->recordFailureEvent($locked, $details, $signature, $failureCount, $firstSeenAt, $now, $eventType);

            if ($markRequiresReview && $this->shouldMarkRequiresReview($locked, $details, $failureCount)) {
                $locked->forceFill([
                    'internal_status' => PaymentInternalStatus::REQUIRES_REVIEW,
                    'review_required_at' => $locked->review_required_at ?? $now,
                    'mismatch_reason' => (string) ($details['safe_message'] ?? 'Manual verification required.'),
                    'status_reason' => (string) ($details['safe_message'] ?? $locked->status_reason),
                    'meta' => $meta,
                ])->save();

                $this->events->record($locked, [
                    'event_type' => 'payment_requires_review',
                    'source' => 'provider_sync_guard',
                    'event_key' => 'payment-provider-review:'.$locked->id.':'.$signature,
                    'before_status' => $locked->status->value,
                    'after_status' => $locked->status->value,
                    'payload' => $event->payload,
                    'meta' => [
                        'reason' => $details['safe_message'] ?? null,
                        'failure_count' => $failureCount,
                        'http_status' => $details['http_status'] ?? null,
                        'fib_error_code' => $details['fib_error_code'] ?? null,
                        'provider_reference_type' => $details['provider_reference_type'] ?? null,
                    ],
                ]);
            }

            $captured = array_merge($details, [
                'failure_count' => $failureCount,
                'first_seen_at' => $firstSeenAt,
                'last_seen_at' => $now->toIso8601String(),
                'pause_reconciliation' => $pauseReconciliation,
            ]);
        }, 3);

        return $captured;
    }

    protected function shouldPauseReconciliation(Payment $payment, array $details, int $failureCount): bool
    {
        if (! ($details['permanent'] ?? false)) {
            return false;
        }

        return $failureCount >= max(1, (int) ($details['review_after_failures'] ?? 0));
    }

    protected function shouldMarkRequiresReview(Payment $payment, array $details, int $failureCount): bool
    {
        if (! ($details['permanent'] ?? false)) {
            return false;
        }

        $threshold = max(1, (int) ($details['review_after_failures'] ?? 0));

        if ($failureCount < $threshold) {
            return false;
        }

        return ! $payment->status->isTerminal();
    }

    protected function recordFailureEvent(
        Payment $payment,
        array $details,
        string $signature,
        int $failureCount,
        string $firstSeenAt,
        CarbonInterface $now,
        string $eventType,
    ): PaymentEvent {
        $event = $this->events->record($payment, [
            'event_type' => $eventType,
            'source' => (string) ($details['source'] ?? 'system'),
            'event_key' => $this->failureEventKey($payment, $details, $signature, $now, $eventType),
            'before_status' => $payment->status->value,
            'after_status' => $payment->status->value,
            'response_code' => $this->responseCode($details['http_status'] ?? null),
            'payload' => $this->safePayload($details),
            'meta' => [
                'failure_count' => $failureCount,
                'first_seen_at' => $firstSeenAt,
                'last_seen_at' => $now->toIso8601String(),
                'signature' => $signature,
                'permanent' => (bool) ($details['permanent'] ?? false),
                'transient' => (bool) ($details['transient'] ?? false),
            ],
        ]);

        $event->forceFill([
            'response_code' => $this->responseCode($details['http_status'] ?? null),
            'payload' => $this->safePayload($details),
            'meta' => [
                'failure_count' => $failureCount,
                'first_seen_at' => $firstSeenAt,
                'last_seen_at' => $now->toIso8601String(),
                'signature' => $signature,
                'permanent' => (bool) ($details['permanent'] ?? false),
                'transient' => (bool) ($details['transient'] ?? false),
            ],
            'processed_at' => $now,
        ])->save();

        return $event->fresh() ?? $event;
    }

    protected function failureEventKey(Payment $payment, array $details, string $signature, CarbonInterface $now, string $eventType): string
    {
        $intervalHours = max(1, (int) ($details['event_interval_hours'] ?? 24));
        $bucket = (int) floor($now->getTimestamp() / ($intervalHours * 3600));

        return sprintf(
            '%s:%d:%s:%s:%d',
            $eventType,
            (int) $payment->id,
            (string) ($details['source'] ?? 'system'),
            sha1($signature),
            $bucket,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function safePayload(array $details): array
    {
        return array_filter([
            'http_status' => $details['http_status'] ?? null,
            'traceId' => $details['fib_trace_id'] ?? null,
            'fib_error_code' => $details['fib_error_code'] ?? null,
            'fib_error_title' => $details['fib_error_title'] ?? null,
            'provider_reference' => $details['provider_reference'] ?? null,
            'provider_reference_type' => $details['provider_reference_type'] ?? null,
            'endpoint' => $details['endpoint'] ?? null,
            'profile' => $details['profile'] ?? null,
            'source' => $details['source'] ?? null,
            'display_error' => $details['display_error'] ?? null,
            'safe_message' => $details['safe_message'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function signature(array $details): string
    {
        return implode('|', [
            (string) ($details['provider_reference_type'] ?? ''),
            (string) ($details['source'] ?? ''),
            (string) ($details['endpoint'] ?? ''),
            (string) ($details['http_status'] ?? ''),
            strtoupper((string) ($details['fib_error_code'] ?? 'UNKNOWN')),
        ]);
    }

    protected function responseCode(mixed $status): ?int
    {
        $status = is_numeric($status) ? (int) $status : 0;

        return $status > 0 && $status <= 65535 ? $status : null;
    }
}
