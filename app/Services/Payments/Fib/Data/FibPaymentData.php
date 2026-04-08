<?php

namespace App\Services\Payments\Fib\Data;

use Illuminate\Support\Carbon;

class FibPaymentData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $status,
        public readonly ?string $readableCode,
        public readonly ?string $qrCode,
        public readonly ?string $personalAppLink,
        public readonly ?string $businessAppLink,
        public readonly ?string $corporateAppLink,
        public readonly ?Carbon $validUntil,
        public readonly ?Carbon $paidAt,
        public readonly ?Carbon $declinedAt,
        public readonly ?Carbon $cancelledAt,
        public readonly ?Carbon $refundedAt,
        public readonly ?string $decliningReason,
        public readonly array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            paymentId: trim((string) data_get($payload, 'paymentId', '')),
            status: strtoupper(trim((string) data_get($payload, 'status', 'UNPAID'))),
            readableCode: self::nullableString(data_get($payload, 'readableCode')),
            qrCode: self::nullableString(data_get($payload, 'qrCode')),
            personalAppLink: self::nullableString(data_get($payload, 'personalAppLink')),
            businessAppLink: self::nullableString(data_get($payload, 'businessAppLink')),
            corporateAppLink: self::nullableString(data_get($payload, 'corporateAppLink')),
            validUntil: self::nullableCarbon(data_get($payload, 'validUntil')),
            paidAt: self::nullableCarbon(data_get($payload, 'paidAt')),
            declinedAt: self::nullableCarbon(data_get($payload, 'declinedAt')),
            cancelledAt: self::nullableCarbon(data_get($payload, 'cancelledAt') ?? data_get($payload, 'canceledAt')),
            refundedAt: self::nullableCarbon(data_get($payload, 'refundedAt')),
            decliningReason: self::nullableString(data_get($payload, 'decliningReason')),
            raw: $payload,
        );
    }

    /**
     * @return array<string, string>
     */
    public function appLinks(): array
    {
        return array_filter([
            'personal' => $this->personalAppLink,
            'business' => $this->businessAppLink,
            'corporate' => $this->corporateAppLink,
        ], fn ($value) => is_string($value) && trim($value) !== '');
    }

    protected static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected static function nullableCarbon(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
