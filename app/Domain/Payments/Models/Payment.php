<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\PaymentMode;
use App\Domain\Payments\Enums\PaymentProvider;
use App\Domain\Payments\Enums\PaymentProviderObjectType;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\PurchaseType;
use App\Models\Coupon;
use App\Models\Customer;
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
        'last_status_checked_at',
        'last_callback_received_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'purchase_type' => PurchaseType::class,
        'payment_mode' => PaymentMode::class,
        'provider_object_type' => PaymentProviderObjectType::class,
        'status' => PaymentStatus::class,
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

    public function resolvedPaymentMode(PaymentMode $fallback = PaymentMode::ONE_TIME): PaymentMode
    {
        return PaymentMode::fromValue($this->payment_mode, $fallback);
    }
}
