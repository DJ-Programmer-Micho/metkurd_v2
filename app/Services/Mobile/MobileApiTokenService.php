<?php

namespace App\Services\Mobile;

use App\Models\Customer;

class MobileApiTokenService
{
    /**
     * @return array{plain_text_token: string, abilities: array<int, string>, expires_at: string|null}
     */
    public function issue(Customer $customer, ?string $deviceName = null, ?string $appSlug = null): array
    {
        $abilities = app(MobileAppCatalog::class)->abilitiesFor($appSlug);
        $nameParts = array_filter([
            'mobile',
            $appSlug ?: 'all-apps',
            trim((string) $deviceName) ?: 'device',
        ]);

        $expiresAt = now()->addDays(max(1, (int) config('mobile_api.token_expiration_days', 90)));
        $token = $customer->createToken(implode(':', $nameParts), $abilities, $expiresAt);

        return [
            'plain_text_token' => $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => optional($token->accessToken->expires_at)->toIso8601String(),
        ];
    }

    public function revokeCurrent(Customer $customer): void
    {
        $customer->currentAccessToken()?->delete();
    }

    public function revokeAll(Customer $customer): void
    {
        $customer->tokens()->delete();
    }
}
