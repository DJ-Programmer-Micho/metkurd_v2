<?php

namespace App\Domain\Payments\Data;

use Illuminate\Support\Carbon;

final class FibCreatePaymentResponseData
{
    /**
     * @param  array<string, string>  $providerLinks
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly ?string $readableCode,
        public readonly ?string $qrCode,
        public readonly array $providerLinks,
        public readonly ?Carbon $validUntil,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $links = array_filter([
            'personal' => self::nullableString(data_get($payload, 'personalAppLink')),
            'business' => self::nullableString(data_get($payload, 'businessAppLink')),
            'corporate' => self::nullableString(data_get($payload, 'corporateAppLink')),
        ]);

        return new self(
            paymentId: trim((string) data_get($payload, 'paymentId', '')),
            readableCode: self::nullableString(data_get($payload, 'readableCode')),
            qrCode: self::nullableString(data_get($payload, 'qrCode')),
            providerLinks: $links,
            validUntil: self::nullableCarbon(data_get($payload, 'validUntil')),
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
