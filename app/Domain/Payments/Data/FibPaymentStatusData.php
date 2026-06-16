<?php

namespace App\Domain\Payments\Data;

use Illuminate\Support\Carbon;

final class FibPaymentStatusData
{
    /**
     * @param  array<string, mixed>  $amount
     * @param  array{name:string,iban:string}|null  $paidBy
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $status,
        public readonly array $amount,
        public readonly ?Carbon $validUntil,
        public readonly ?Carbon $paidAt,
        public readonly ?string $decliningReason,
        public readonly ?Carbon $declinedAt,
        public readonly ?array $paidBy,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $paidBy = data_get($payload, 'paidBy');

        return new self(
            paymentId: trim((string) data_get($payload, 'paymentId', '')),
            status: strtoupper(trim((string) data_get($payload, 'status', 'UNPAID'))),
            amount: [
                'amount' => (int) data_get($payload, 'amount.amount', 0),
                'currency' => strtoupper(trim((string) data_get($payload, 'amount.currency', 'IQD'))),
            ],
            validUntil: self::nullableCarbon(data_get($payload, 'validUntil')),
            paidAt: self::nullableCarbon(data_get($payload, 'paidAt')),
            decliningReason: self::nullableString(data_get($payload, 'decliningReason')),
            declinedAt: self::nullableCarbon(data_get($payload, 'declinedAt')),
            paidBy: is_array($paidBy) ? [
                'name' => trim((string) data_get($paidBy, 'name', '')),
                'iban' => trim((string) data_get($paidBy, 'iban', '')),
            ] : null,
            raw: $payload,
        );
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
