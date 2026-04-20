<?php

namespace App\Domain\Payments\Data;

use Illuminate\Support\Carbon;

final class FibCreateSubscriptionResponseData
{
    /**
     * @param  array<string, string>  $providerLinks
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly ?string $readableCode,
        public readonly ?string $qrCode,
        public readonly array $providerLinks,
        public readonly ?Carbon $validUntil,
        public readonly array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $links = array_filter([
            'app' => self::nullableString(data_get($payload, 'appLink')),
            'personal' => self::nullableString(data_get($payload, 'personalAppLink')),
            'business' => self::nullableString(data_get($payload, 'businessAppLink')),
            'corporate' => self::nullableString(data_get($payload, 'corporateAppLink')),
        ]);

        return new self(
            subscriptionId: trim((string) (data_get($payload, 'subscriptionId') ?? data_get($payload, 'id', ''))),
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
