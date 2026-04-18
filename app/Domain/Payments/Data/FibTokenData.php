<?php

namespace App\Domain\Payments\Data;

final class FibTokenData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly string $tokenType,
        public readonly ?string $scope,
        public readonly array $raw,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            accessToken: trim((string) data_get($payload, 'access_token', '')),
            expiresIn: (int) data_get($payload, 'expires_in', 0),
            tokenType: trim((string) data_get($payload, 'token_type', 'Bearer')),
            scope: filled(data_get($payload, 'scope')) ? trim((string) data_get($payload, 'scope')) : null,
            raw: $payload,
        );
    }
}
