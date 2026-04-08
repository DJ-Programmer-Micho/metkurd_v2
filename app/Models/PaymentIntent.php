<?php

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentIntent extends Model
{
    protected $fillable = [
        'uuid',
        'customer_id',
        'customer_payment_method_id',
        'provider',
        'payment_method',
        'purpose_type',
        'purpose_id',
        'purpose_code',
        'purpose_name',
        'billing_interval',
        'is_recurring',
        'recurring_strategy',
        'base_currency_code',
        'base_amount_iqd',
        'gross_amount_iqd',
        'surcharge_amount_iqd',
        'provider_fee_amount_iqd',
        'net_amount_iqd',
        'fee_currency_code',
        'display_currency_code',
        'display_exchange_rate',
        'display_amount_raw',
        'display_amount_rounded',
        'display_rounding_step',
        'display_rounding_mode',
        'display_country_code',
        'status',
        'status_reason',
        'idempotency_key',
        'merchant_transaction_id',
        'provider_payment_id',
        'provider_transaction_id',
        'provider_purchase_id',
        'provider_customer_ref',
        'provider_schedule_ref',
        'card_origin',
        'expires_at',
        'authorized_at',
        'paid_at',
        'failed_at',
        'canceled_at',
        'expired_at',
        'refunded_at',
        'fulfilled_at',
        'last_status_synced_at',
        'request_payload',
        'response_payload',
        'meta',
    ];

    protected $casts = [
        'is_recurring' => 'boolean',
        'base_amount_iqd' => 'decimal:0',
        'gross_amount_iqd' => 'decimal:0',
        'surcharge_amount_iqd' => 'decimal:0',
        'provider_fee_amount_iqd' => 'decimal:0',
        'net_amount_iqd' => 'decimal:0',
        'display_exchange_rate' => 'decimal:8',
        'display_amount_raw' => 'decimal:8',
        'display_amount_rounded' => 'decimal:4',
        'display_rounding_step' => 'decimal:4',
        'expires_at' => 'datetime',
        'authorized_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'expired_at' => 'datetime',
        'refunded_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'last_status_synced_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'meta' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function customerPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentMethod::class, 'customer_payment_method_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class, 'payment_intent_id');
    }

    public function creditOrders(): HasMany
    {
        return $this->hasMany(CreditOrder::class, 'payment_intent_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function checkoutAction(): array
    {
        $action = data_get($this->meta ?? [], 'checkout_action');

        return is_array($action) ? $action : [];
    }

    public function requiresCustomerAction(): bool
    {
        return $this->checkoutAction() !== []
            && in_array((string) $this->status, [
                PaymentIntentStatus::PENDING->value,
                PaymentIntentStatus::REQUIRES_ACTION->value,
                PaymentIntentStatus::PROCESSING->value,
            ], true);
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [
            PaymentIntentStatus::PAID->value,
            PaymentIntentStatus::FAILED->value,
            PaymentIntentStatus::CANCELED->value,
            PaymentIntentStatus::EXPIRED->value,
            PaymentIntentStatus::REFUNDED->value,
        ], true);
    }
}
