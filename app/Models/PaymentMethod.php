<?php

namespace App\Models;

use App\Enums\PaymentPurposeType;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code',
        'driver',
        'name',
        'description',
        'icon',
        'is_active',
        'is_visible',
        'sort_order',
        'supported_currencies',
        'supported_purchase_types',
        'supports_recurring',
        'supports_refunds',
        'supports_webhooks',
        'supports_redirect',
        'supports_qr',
        'settings',
        'fee_config',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'supported_currencies' => 'array',
        'supported_purchase_types' => 'array',
        'supports_recurring' => 'boolean',
        'supports_refunds' => 'boolean',
        'supports_webhooks' => 'boolean',
        'supports_redirect' => 'boolean',
        'supports_qr' => 'boolean',
        'settings' => 'array',
        'fee_config' => 'array',
        'meta' => 'array',
    ];

    public function supportsPurchaseType(PaymentPurposeType|string $purposeType): bool
    {
        $purposeType = $purposeType instanceof PaymentPurposeType
            ? $purposeType->value
            : strtolower(trim((string) $purposeType));

        $supported = collect($this->supported_purchase_types ?? [])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        return $supported === [] || in_array($purposeType, $supported, true);
    }

    public function supportsCurrency(?string $currencyCode): bool
    {
        $currencyCode = strtoupper(trim((string) $currencyCode));

        if ($currencyCode === '') {
            return true;
        }

        $supported = collect($this->supported_currencies ?? [])
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        return $supported === [] || in_array($currencyCode, $supported, true);
    }
}
