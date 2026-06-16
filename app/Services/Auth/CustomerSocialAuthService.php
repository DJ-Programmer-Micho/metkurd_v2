<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;

class CustomerSocialAuthService
{
    /**
     * @return array<string, string>
     */
    protected function providerColumns(): array
    {
        return [
            'google' => 'g_id',
            'github' => 'h_id',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return collect($this->providerColumns())
            ->keys()
            ->map(fn (string $provider): string => strtolower(trim($provider)))
            ->filter(function (string $provider): bool {
                return filled(config('services.'.$provider.'.client_id'))
                    && filled(config('services.'.$provider.'.client_secret'));
            })
            ->values()
            ->all();
    }

    public function isSupportedProvider(string $provider): bool
    {
        $provider = strtolower(trim($provider));

        $configured = $this->supportedProviders();

        if ($configured !== []) {
            return in_array($provider, $configured, true);
        }

        return array_key_exists($provider, $this->providerColumns());
    }

    public function customerExistsForProviderUser(ProviderUser $providerUser, string $provider): bool
    {
        $provider = strtolower(trim($provider));
        $providerColumn = $this->providerColumn($provider);
        $email = strtolower(trim((string) $providerUser->getEmail()));
        $providerId = trim((string) $providerUser->getId());

        if ($email === '' && $providerId === '') {
            return false;
        }

        return Customer::query()
            ->where(function ($query) use ($providerColumn, $providerId, $email): void {
                $applied = false;

                if ($providerColumn !== null && $providerId !== '') {
                    $query->where($providerColumn, $providerId);
                    $applied = true;
                }

                if ($email !== '') {
                    if ($applied) {
                        $query->orWhere('email', $email);
                    } else {
                        $query->where('email', $email);
                    }
                }
            })
            ->exists();
    }

    public function fetchProviderUserFromCallback(string $provider): ProviderUser
    {
        if (! $this->isSupportedProvider($provider)) {
            throw new \RuntimeException("Unsupported social provider [{$provider}].");
        }

        return Socialite::driver($provider)->stateless()->user();
    }

    public function fetchProviderUserFromToken(string $provider, string $accessToken): ProviderUser
    {
        if (! $this->isSupportedProvider($provider)) {
            throw new \RuntimeException("Unsupported social provider [{$provider}].");
        }

        return Socialite::driver($provider)->stateless()->userFromToken($accessToken);
    }

    public function authenticateProviderUser(ProviderUser $providerUser, string $provider, bool $allowCreate = true): Customer
    {
        $provider = strtolower(trim($provider));
        $providerColumn = $this->providerColumn($provider);
        $email = strtolower(trim((string) $providerUser->getEmail()));
        $name = $providerUser->getName() ?: $providerUser->getNickname();
        $avatarUrl = $providerUser->getAvatar();
        $providerId = (string) $providerUser->getId();

        if ($providerColumn === null) {
            throw new \RuntimeException("Unsupported social provider [{$provider}].");
        }

        if ($email === '') {
            throw new \RuntimeException("Your {$provider} account has no email address.");
        }

        if ($providerId === '') {
            throw new \RuntimeException("Your {$provider} account did not return a provider identifier.");
        }

        $normalizedAvatar = $this->normalizeProviderAvatarUrl($avatarUrl);

        return DB::transaction(function () use ($allowCreate, $email, $name, $provider, $providerColumn, $providerId, $normalizedAvatar) {
            [$first, $last] = $this->splitName($name);

            $customerByProvider = Customer::query()
                ->where($providerColumn, $providerId)
                ->lockForUpdate()
                ->first();

            $customerByEmail = Customer::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($customerByProvider && $customerByEmail && $customerByProvider->id !== $customerByEmail->id) {
                throw new \RuntimeException("This {$provider} account is already linked to another user.");
            }

            $customer = $customerByProvider ?? $customerByEmail;

            if (! $customer && ! $allowCreate) {
                throw new \RuntimeException('No existing customer matched this social sign-in.');
            }

            if (! $customer) {
                $customer = Customer::query()->create([
                    'username' => $this->uniqueUsernameFromName($name ?: Str::before($email, '@')),
                    'email' => $email,
                    'password' => Str::random(32),
                    'status' => 1,
                    'email_verify' => true,
                    'email_verified_at' => now(),
                    'phone_verify' => false,
                    'uid' => (string) Str::ulid(),
                    'g_id' => $provider === 'google' ? $providerId : null,
                    'h_id' => $provider === 'github' ? $providerId : null,
                ]);
            }

            $customerNeedsSave = false;

            if (empty($customer->getAttribute($providerColumn))) {
                $customer->setAttribute($providerColumn, $providerId);
                $customerNeedsSave = true;
            }

            if (! $customer->email_verify) {
                $customer->email_verify = true;
                $customerNeedsSave = true;
            }

            if (! $customer->email_verified_at) {
                $customer->email_verified_at = now();
                $customerNeedsSave = true;
            }

            if ($customerNeedsSave) {
                $customer->save();
            }

            $profile = CustomerProfile::query()->firstOrCreate(
                ['customer_id' => $customer->id],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'avatar' => $normalizedAvatar,
                ]
            );

            $profileNeedsSave = false;

            if (blank($profile->first_name) && filled($first)) {
                $profile->first_name = $first;
                $profileNeedsSave = true;
            }

            if (blank($profile->last_name) && filled($last)) {
                $profile->last_name = $last;
                $profileNeedsSave = true;
            }

            if ($normalizedAvatar && empty($profile->avatar)) {
                $profile->avatar = $normalizedAvatar;
                $profileNeedsSave = true;
            }

            if ($profileNeedsSave) {
                $profile->save();
            }

            return $customer->fresh(['profile', 'usage', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['User', ''];
        }

        $parts = preg_split('/\s+/', $name, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    protected function uniqueUsernameFromName(string $base): string
    {
        $base = Str::slug($base, '_');

        if ($base === '') {
            $base = 'user';
        }

        $username = $base;
        $i = 1;

        while (Customer::query()->where('username', $username)->exists()) {
            $username = $base.'_'.$i++;
        }

        return $username;
    }

    protected function normalizeProviderAvatarUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_contains($url, 'googleusercontent.com')) {
            return preg_replace('/=s\d+-c$/', '=s256-c', $url) ?: $url;
        }

        if (str_contains($url, 'githubusercontent.com')) {
            return $url.(str_contains($url, '?') ? '&' : '?').'s=256';
        }

        return $url;
    }

    protected function providerColumn(string $provider): ?string
    {
        $provider = strtolower(trim($provider));

        return $this->providerColumns()[$provider] ?? null;
    }
}
