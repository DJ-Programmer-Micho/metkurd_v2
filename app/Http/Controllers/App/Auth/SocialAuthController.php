<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function googleRedirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function googleCallback()
    {
        $providerUser = Socialite::driver('google')->stateless()->user();

        return $this->loginOrCreate($providerUser, 'google');
    }

    public function githubRedirect()
    {
        return Socialite::driver('github')
            ->scopes(['read:user', 'user:email'])
            ->redirect();
    }

    public function githubCallback()
    {
        $providerUser = Socialite::driver('github')->user();

        return $this->loginOrCreate($providerUser, 'github');
    }

    protected function loginOrCreate($providerUser, string $provider)
    {
        $email = $providerUser->getEmail();
        $name = $providerUser->getName() ?: $providerUser->getNickname();
        $avatarUrl = $providerUser->getAvatar();
        $providerId = (string) $providerUser->getId();

        if (! $email) {
            return redirect()->route('app.signin')
                ->withErrors(['login' => 'Your '.$provider.' account has no email. Please add an email or use another method.']);
        }

        $normalizedAvatar = $this->normalizeProviderAvatarUrl($avatarUrl);

        $customer = DB::transaction(function () use ($email, $name, $provider, $providerId, $normalizedAvatar) {
            [$first, $last] = $this->splitName($name);

            $customerByProvider = Customer::query()
                ->when($provider === 'google', fn ($query) => $query->where('g_id', $providerId))
                ->when($provider === 'github', fn ($query) => $query->where('h_id', $providerId))
                ->lockForUpdate()
                ->first();

            $customerByEmail = Customer::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($customerByProvider && $customerByEmail && $customerByProvider->id !== $customerByEmail->id) {
                throw new \RuntimeException('This '.$provider.' account is already linked to another user.');
            }

            $customer = $customerByProvider
                ?? Customer::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'username' => $this->uniqueUsernameFromName($name ?: Str::before($email, '@')),
                        'password' => Str::random(32),
                        'status' => 1,
                        'email_verify' => true,
                        'email_verified_at' => now(),
                        'phone_verify' => false,
                        'uid' => (string) Str::ulid(),
                        'g_id' => $provider === 'google' ? $providerId : null,
                        'h_id' => $provider === 'github' ? $providerId : null,
                    ]
                );

            $customerNeedsSave = false;

            if ($provider === 'google' && empty($customer->g_id)) {
                $customer->g_id = $providerId;
                $customerNeedsSave = true;
            }

            if ($provider === 'github' && empty($customer->h_id)) {
                $customer->h_id = $providerId;
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

            $profile = CustomerProfile::firstOrCreate(
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

            return $customer->fresh('profile');
        });

        if (! $customer instanceof Customer) {
            return redirect()->route('app.signin')->withErrors(['login' => 'Login failed.']);
        }

        Auth::guard('app')->login($customer, true);

        if (! $customer->phone_verify) {
            return redirect()->route('app.phone.otp');
        }

        return redirect()->route('app.home')
            ->with('status', 'Welcome back!');
    }

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

        while (Customer::where('username', $username)->exists()) {
            $username = $base . '_' . $i++;
        }

        return $username;
    }

    private function normalizeProviderAvatarUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_contains($url, 'googleusercontent.com')) {
            return preg_replace('/=s\d+-c$/', '=s256-c', $url) ?: $url;
        }

        if (str_contains($url, 'githubusercontent.com')) {
            return $url . (str_contains($url, '?') ? '&' : '?') . 's=256';
        }

        return $url;
    }
}
