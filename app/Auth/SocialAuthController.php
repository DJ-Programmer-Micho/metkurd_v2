<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

// ...use statements unchanged...

class SocialAuthController extends Controller
{
    // GOOGLE
    public function googleRedirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function googleCallback()
    {
        // Prefer stateful once your session/state is fixed:
        // $providerUser = Socialite::driver('google')->user();
        $providerUser = Socialite::driver('google')->stateless()->user(); // TEMP
        return $this->loginOrCreate($providerUser, 'google');
    }

    // GITHUB
    public function githubRedirect()
    {
        return Socialite::driver('github')
            ->scopes(['read:user', 'user:email'])
            ->redirect();
    }

    public function githubCallback()
    {
        // $providerUser = Socialite::driver('github')->stateless()->user(); // if needed
        $providerUser = Socialite::driver('github')->user();
        return $this->loginOrCreate($providerUser, 'github');
    }

    // SHARED
    protected function loginOrCreate($providerUser, string $provider)
    {
        $email      = $providerUser->getEmail();
        $name       = $providerUser->getName() ?: $providerUser->getNickname();
        $avatarUrl  = $providerUser->getAvatar();
        $providerId = (string) $providerUser->getId();

        if (!$email) {
            return redirect()->route('app.signin')
                ->withErrors(['login' => 'Your '.$provider.' account has no email. Please add an email or use another method.']);
        }

        $normalizedAvatar = $this->normalizeProviderAvatarUrl($avatarUrl);

        $customer = DB::transaction(function () use ($email, $name, $provider, $providerId, $normalizedAvatar) {

            [$first, $last] = $this->splitName($name);

            // Find by email first (preferred)
            $customer = Customer::where('email', $email)->lockForUpdate()->first();

            // If not found by email, try to find by provider id (optional but good)
            if (!$customer) {
                $customer = Customer::when($provider === 'google', fn($q) => $q->where('g_id', $providerId))
                                    ->when($provider === 'github', fn($q) => $q->where('h_id', $providerId))
                                    ->lockForUpdate()
                                    ->first();
            }

            if ($customer) {
                // prevent provider id collision with another account
                $collision = Customer::when($provider === 'google', fn($q)=>$q->where('g_id',$providerId))
                                    ->when($provider === 'github', fn($q)=>$q->where('h_id',$providerId))
                                    ->where('id','!=',$customer->id)
                                    ->exists();

                if ($collision) {
                    // throw to break transaction cleanly
                    throw new \RuntimeException('This '.$provider.' account is already linked to another user.');
                }

                // attach provider id if missing
                if ($provider === 'google' && empty($customer->g_id)) $customer->g_id = $providerId;
                if ($provider === 'github' && empty($customer->h_id)) $customer->h_id = $providerId;

                // mark verified (since provider email is verified)
                $customer->email_verify = true;

                $customer->save();

                // ✅ IMPORTANT: ensure profile exists even for old users
                $profile = CustomerProfile::firstOrCreate(
                    ['customer_id' => $customer->id],
                    [
                        'first_name' => $first,
                        'last_name'  => $last,
                    ]
                );

                // fill avatar if empty
                if ($normalizedAvatar && empty($profile->avatar)) {
                    $profile->avatar = $normalizedAvatar;
                    $profile->save();
                }

                return $customer->fresh('profile');
            }

            // Create new user
            $customer = Customer::create([
                'username'     => $this->uniqueUsernameFromName($name ?: Str::before($email,'@')),
                'email'        => $email,
                'password'     => Str::random(32), // password cast "hashed" will hash it
                'status'       => 1,
                'email_verify' => true,
                'phone_verify' => false,
                'uid'          => Str::ulid(),
                'g_id'         => $provider === 'google' ? $providerId : null,
                'h_id'         => $provider === 'github' ? $providerId : null,
            ]);

            $profile = CustomerProfile::create([
                'customer_id' => $customer->id,
                'first_name'  => $first,
                'last_name'   => $last,
                'avatar'      => $normalizedAvatar,
            ]);

            return $customer->fresh('profile');
        });

        // if collision happened
        if (!$customer instanceof Customer) {
            return redirect()->route('app.signin')->withErrors(['login' => 'Login failed.']);
        }

        Auth::guard('app')->login($customer, true);

        return redirect()->route('app.home', ['locale' => 'en'])
            ->with('status', 'Welcome back!');
    }


    protected function splitName(?string $name): array
    {
        $name = trim((string)$name);
        if ($name === '') return ['User', ''];
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    protected function uniqueUsernameFromName(string $base): string
    {
        $base = Str::slug($base, '_');
        if ($base === '') $base = 'user';
        $username = $base;
        $i = 1;
        while (Customer::where('username', $username)->exists()) {
            $username = $base . '_' . $i++;
        }
        return $username;
    }

    // AVATAR URL normalizer (no file storage)
    private function normalizeProviderAvatarUrl(?string $url): ?string
    {
        if (!$url) return null;

        if (str_contains($url, 'googleusercontent.com')) {
            return preg_replace('/=s\d+-c$/', '=s256-c', $url) ?: $url;
        }
        if (str_contains($url, 'githubusercontent.com')) {
            return $url . (str_contains($url, '?') ? '&' : '?') . 's=256';
        }
        return $url;
    }
}
