<?php

namespace App\Domain\Payments\Data;

use Illuminate\Support\Carbon;

final class FibSubscriptionStatusData
{
    /**
     * @param  array<string, mixed>  $amount
     * @param  array<string, string>  $providerLinks
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly ?string $readableCode,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly array $amount,
        public readonly ?string $interval,
        public readonly ?string $trialPeriod,
        public readonly string $status,
        public readonly ?Carbon $validUntil,
        public readonly ?Carbon $activeUntil,
        public readonly ?Carbon $lastPaymentAt,
        public readonly array $providerLinks,
        public readonly array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $monetaryValue = data_get($payload, 'monetaryValue');
        if (! is_array($monetaryValue)) {
            $monetaryValue = data_get($payload, 'amount');
        }

        $links = array_filter([
            'app' => self::nullableString(data_get($payload, 'appLink')),
            'personal' => self::nullableString(data_get($payload, 'personalAppLink')),
            'business' => self::nullableString(data_get($payload, 'businessAppLink')),
            'corporate' => self::nullableString(data_get($payload, 'corporateAppLink')),
        ]);

        return new self(
            subscriptionId: trim((string) (data_get($payload, 'subscriptionId') ?? data_get($payload, 'id', ''))),
            readableCode: self::nullableString(data_get($payload, 'readableCode')),
            title: self::nullableString(data_get($payload, 'title')),
            description: self::nullableString(data_get($payload, 'description')),
            amount: [
                'amount' => (int) data_get($monetaryValue, 'amount', 0),
                'currency' => strtoupper(trim((string) data_get($monetaryValue, 'currency', 'IQD'))),
            ],
            interval: self::nullableString(data_get($payload, 'interval')),
            trialPeriod: self::nullableString(data_get($payload, 'trialPeriod')),
            status: strtoupper(trim((string) data_get($payload, 'status', 'PENDING'))),
            validUntil: self::nullableCarbon(data_get($payload, 'validUntil')),
            activeUntil: self::nullableCarbon(data_get($payload, 'activeUntil')),
            lastPaymentAt: self::firstCarbonFromCandidates([
                data_get($payload, 'lastPaymentAt'),
                data_get($payload, 'lastPaidAt'),
                data_get($payload, 'lastSuccessfulPaymentAt'),
                data_get($payload, 'latestPaidAt'),
                data_get($payload, 'payment.lastPaymentAt'),
                data_get($payload, 'payment.lastPaidAt'),
                data_get($payload, 'latestPayment.lastPaymentAt'),
                data_get($payload, 'latestPayment.lastPaidAt'),
                data_get($payload, 'subscription.lastPaymentAt'),
                data_get($payload, 'subscription.lastPaidAt'),
            ]),
            providerLinks: $links,
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

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected static function firstCarbonFromCandidates(array $candidates): ?Carbon
    {
        foreach ($candidates as $candidate) {
            $date = self::nullableCarbon($candidate);

            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return null;
    }
}
