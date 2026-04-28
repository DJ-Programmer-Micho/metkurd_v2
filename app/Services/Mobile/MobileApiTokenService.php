<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use Carbon\CarbonInterface;

class MobileApiTokenService
{
    public const ONBOARDING_ABILITY = 'mobile:onboarding';

    /**
     * @return array{plain_text_token: string, abilities: array<int, string>, expires_at: string|null}
     */
    public function issue(Customer $customer, ?string $deviceName = null, ?string $appSlug = null): array
    {
        $abilities = app(MobileAppCatalog::class)->abilitiesFor($appSlug);
        $expiresAt = now()->addDays(max(1, (int) config('mobile_api.token_expiration_days', 90)));

        return $this->issueWithAbilities(
            customer: $customer,
            abilities: $abilities,
            tokenScope: $appSlug ?: 'all-apps',
            deviceName: $deviceName,
            expiresAt: $expiresAt
        );
    }

    /**
     * @return array{plain_text_token: string, abilities: array<int, string>, expires_at: string|null}
     */
    public function issueOnboarding(Customer $customer, ?string $deviceName = null): array
    {
        $expiresAt = now()->addMinutes(max(5, (int) config('mobile_api.onboarding_token_expiration_minutes', 120)));

        // Keep one active onboarding token per customer/device scope.
        $customer->tokens()
            ->where('name', 'like', 'mobile:onboarding:%')
            ->delete();

        return $this->issueWithAbilities(
            customer: $customer,
            abilities: [self::ONBOARDING_ABILITY],
            tokenScope: 'onboarding',
            deviceName: $deviceName,
            expiresAt: $expiresAt
        );
    }

    public function tokenCanAccessFullMobile(?array $abilities): bool
    {
        $abilities = array_values((array) $abilities);

        return in_array('mobile', $abilities, true)
            || in_array('mobile:*', $abilities, true)
            || collect($abilities)->contains(function (string $ability): bool {
                $ability = strtolower(trim($ability));

                return str_starts_with($ability, 'mobile:')
                    && $ability !== self::ONBOARDING_ABILITY;
            });
    }

    /**
     * @param array<int, string> $abilities
     * @return array{plain_text_token: string, abilities: array<int, string>, expires_at: string|null}
     */
    protected function issueWithAbilities(
        Customer $customer,
        array $abilities,
        string $tokenScope,
        ?string $deviceName = null,
        ?CarbonInterface $expiresAt = null
    ): array {
        $nameParts = array_filter([
            'mobile',
            trim((string) $tokenScope) !== '' ? trim((string) $tokenScope) : 'all-apps',
            trim((string) $deviceName) ?: 'device',
        ]);

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
