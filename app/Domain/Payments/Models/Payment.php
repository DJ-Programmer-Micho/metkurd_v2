<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\PaymentInternalStatus;
use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerServiceSubscription;
use App\Models\CustomerStorageSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $fillable = [
        'uuid',
        'customer_id',
        'coupon_id',
        'coupon_code',
        'provider',
        'purchase_type',
        'payment_mode',
        'provider_object_type',
        'status',
        'internal_status',
        'local_reference',
        'idempotency_key',
        'fib_payment_id',
        'fib_subscription_id',
        'readable_code',
        'qr_code',
        'provider_links',
        'amount',
        'currency',
        'original_amount_iqd',
        'discount_amount_iqd',
        'discounted_amount_iqd',
        'status_reason',
        'mismatch_reason',
        'declining_reason',
        'provider_status',
        'provider_payment_status',
        'provider_subscription_status',
        'provider_interval',
        'provider_trial_period',
        'callback_payload',
        'create_payload',
        'create_response',
        'status_response',
        'cancel_response',
        'purchase_snapshot',
        'meta',
        'purchasable_type',
        'purchasable_id',
        'valid_until',
        'active_until',
        'last_payment_at',
        'paid_at',
        'canceled_at',
        'expired_at',
        'fulfilled_at',
        'review_required_at',
        'failed_at',
        'last_status_checked_at',
        'last_callback_received_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'purchase_type' => PurchaseType::class,
        'payment_mode' => PaymentMode::class,
        'provider_object_type' => PaymentProviderObjectType::class,
        'status' => PaymentStatus::class,
        'internal_status' => PaymentInternalStatus::class,
        'coupon_id' => 'integer',
        'provider_links' => 'array',
        'callback_payload' => 'array',
        'create_payload' => 'array',
        'create_response' => 'array',
        'status_response' => 'array',
        'cancel_response' => 'array',
        'purchase_snapshot' => 'array',
        'meta' => 'array',
        'amount' => 'decimal:0',
        'original_amount_iqd' => 'decimal:0',
        'discount_amount_iqd' => 'decimal:0',
        'discounted_amount_iqd' => 'decimal:0',
        'valid_until' => 'datetime',
        'active_until' => 'datetime',
        'last_payment_at' => 'datetime',
        'paid_at' => 'datetime',
        'canceled_at' => 'datetime',
        'expired_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'review_required_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_status_checked_at' => 'datetime',
        'last_callback_received_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'payment_id');
    }

    public function serviceSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerServiceSubscription::class, 'payment_id');
    }

    public function storageSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerStorageSubscription::class, 'payment_id');
    }

    public function isAwaitingCustomerAction(): bool
    {
        return $this->status === PaymentStatus::AWAITING_CUSTOMER_ACTION;
    }

    public function isProviderPaymentObject(): bool
    {
        return ($this->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->isPayment();
    }

    public function isProviderSubscriptionObject(): bool
    {
        return ($this->provider_object_type ?? PaymentProviderObjectType::PAYMENT)->isSubscription();
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    public function isApplied(): bool
    {
        return $this->fulfilled_at !== null || $this->internal_status === PaymentInternalStatus::APPLIED;
    }

    public function requiresReview(): bool
    {
        return $this->internal_status === PaymentInternalStatus::REQUIRES_REVIEW;
    }

    public function isTerminal(): bool
    {
        return ($this->status ?? PaymentStatus::PENDING)?->isTerminal() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return is_array($this->purchase_snapshot) ? $this->purchase_snapshot : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function feeQuote(): array
    {
        $metaQuote = data_get($this->meta, 'fee_quote', []);

        if (is_array($metaQuote) && $metaQuote !== []) {
            return $metaQuote;
        }

        $snapshotQuote = data_get($this->purchase_snapshot, 'fee_quote', []);

        return is_array($snapshotQuote) ? $snapshotQuote : [];
    }

    /**
     * @return array<string, string>
     */
    public function appLinks(): array
    {
        return collect($this->provider_links ?? [])
            ->filter(fn (mixed $value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->all();
    }

    public function providerReference(): string
    {
        return (string) (
            $this->fib_subscription_id
            ?: $this->fib_payment_id
            ?: $this->local_reference
        );
    }

    public function providerStatusLabel(): ?string
    {
        return $this->isProviderSubscriptionObject()
            ? ($this->provider_subscription_status ?: $this->provider_status)
            : ($this->provider_payment_status ?: $this->provider_status);
    }

    public function providerPaymentStatusLabel(): ?string
    {
        $candidates = [
            $this->provider_payment_status,
            data_get($this->status_response, 'paymentStatus'),
            data_get($this->status_response, 'payment.status'),
            data_get($this->status_response, 'latestPayment.status'),
            data_get($this->status_response, 'latestPayment.paymentStatus'),
            data_get($this->callback_payload, 'paymentStatus'),
            data_get($this->callback_payload, 'payment.status'),
            data_get($this->callback_payload, 'latestPayment.status'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = strtoupper(trim((string) $candidate));

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    public function applicationStatusLabel(): string
    {
        return match ($this->internal_status ?? PaymentInternalStatus::fromPaymentStatus($this->status ?? PaymentStatus::PENDING, $this->isApplied(), $this->requiresReview())) {
            PaymentInternalStatus::APPLIED => 'applied',
            PaymentInternalStatus::PAID_PENDING_APPLICATION => 'payment_received',
            PaymentInternalStatus::REQUIRES_REVIEW => 'requires_review',
            default => (string) (($this->internal_status ?? null)?->value ?? $this->status?->value ?? 'pending'),
        };
    }

    public function reviewMessage(): ?string
    {
        $reason = trim((string) ($this->mismatch_reason ?? ''));

        return $reason !== '' ? $reason : null;
    }

    public function hasProviderPaidSubscriptionEvidence(): bool
    {
        if (! $this->isProviderSubscriptionObject()) {
            return false;
        }

        $providerStatus = strtoupper(trim((string) ($this->providerStatusLabel() ?? '')));

        if (! in_array($providerStatus, ['ACTIVE', 'SUBSCRIBED', 'PAID'], true)) {
            return false;
        }

        if ($this->last_payment_at !== null) {
            return true;
        }

        $paymentStatus = $this->providerPaymentStatusLabel();

        if (in_array($paymentStatus, ['PAID', 'APPROVED', 'CONFIRMED', 'CAPTURED', 'SETTLED', 'SUCCESS'], true)) {
            return true;
        }

        foreach ([
            data_get($this->status_response, 'isPaid'),
            data_get($this->status_response, 'paid'),
            data_get($this->status_response, 'paymentCompleted'),
            data_get($this->status_response, 'isPaymentCompleted'),
            data_get($this->callback_payload, 'isPaid'),
            data_get($this->callback_payload, 'paid'),
            data_get($this->callback_payload, 'paymentCompleted'),
            data_get($this->callback_payload, 'isPaymentCompleted'),
        ] as $flag) {
            if (is_bool($flag) && $flag) {
                return true;
            }
        }

        return false;
    }

    public function isProviderPaidButLocallyUnappliedSubscription(): bool
    {
        if (! $this->isProviderSubscriptionObject() || $this->fulfilled_at !== null || $this->isApplied()) {
            return false;
        }

        return $this->hasProviderPaidSubscriptionEvidence()
            && in_array($this->status, [
                PaymentStatus::PENDING,
                PaymentStatus::AWAITING_CUSTOMER_ACTION,
                PaymentStatus::PAID,
            ], true);
    }

    public function providerRecurringCycleKey(): ?string
    {
        if (! $this->isProviderSubscriptionObject()
            || ! $this->last_payment_at instanceof \DateTimeInterface
            || trim((string) $this->fib_subscription_id) === '') {
            return null;
        }

        $timestamp = \Illuminate\Support\Carbon::instance($this->last_payment_at)->copy()->utc();

        return sprintf(
            'fib:%s:%s',
            trim((string) $this->fib_subscription_id),
            $timestamp->format('Y-m-d\TH:i:s\Z'),
        );
    }

    public function resolvedPaymentMode(PaymentMode $fallback = PaymentMode::ONE_TIME): PaymentMode
    {
        return PaymentMode::fromValue($this->payment_mode, $fallback);
    }

    public function isRevenueExcluded(): bool
    {
        if ((bool) data_get($this->meta, 'revenue_excluded', false)) {
            return true;
        }

        if (data_get($this->meta, 'revenue_record') === false) {
            return true;
        }

        return in_array((string) data_get($this->meta, 'billing_source', ''), [
            'admin_manual_grant',
            'internal_non_revenue',
        ], true);
    }

    public function reviewResolution(): array
    {
        $resolution = data_get($this->meta, 'review_resolution', []);

        return is_array($resolution) ? $resolution : [];
    }

    public function reviewIsClosed(): bool
    {
        return trim((string) data_get($this->reviewResolution(), 'closed_at', '')) !== '';
    }

    public function requiresOpenReview(): bool
    {
        return $this->requiresReview() && ! $this->reviewIsClosed();
    }

    public function scopeRevenueIncluded(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('meta->revenue_excluded')
                ->orWhere('meta->revenue_excluded', false)
                ->orWhere('meta->revenue_excluded', 0)
                ->orWhere('meta->revenue_excluded', '0')
                ->orWhere('meta->revenue_excluded', 'false');
        });
    }
}
