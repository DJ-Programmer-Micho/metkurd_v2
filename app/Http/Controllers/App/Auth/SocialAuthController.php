<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Auth\CustomerSocialAuthService;
use App\Support\TelegramRegistrationNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function __construct(
        protected CustomerSocialAuthService $socialAuth,
    ) {
    }

    public function googleRedirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function googleCallback()
    {
        return $this->handleProviderCallback('google');
    }

    public function githubRedirect()
    {
        return Socialite::driver('github')
            ->scopes(['read:user', 'user:email'])
            ->redirect();
    }

    public function githubCallback()
    {
        return $this->handleProviderCallback('github');
    }

    protected function handleProviderCallback(string $provider)
    {
        try {
            $providerUser = $this->socialAuth->fetchProviderUserFromCallback($provider);
        } catch (\Throwable $e) {
            Log::warning('Social provider callback failed.', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('app.signin')
                ->withErrors(['login' => __('Authentication failed. Please try again.')]);
        }

        return $this->loginOrCreate($providerUser, $provider);
    }

    protected function loginOrCreate($providerUser, string $provider)
    {
        $customerExistedBeforeCallback = $this->customerExistsForProvider($providerUser, $provider);

        try {
            $customer = $this->socialAuth->authenticateProviderUser($providerUser, $provider, allowCreate: true);
        } catch (\Throwable $e) {
            Log::warning('Social login account provisioning failed.', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('app.signin')
                ->withErrors(['login' => __('We could not complete social sign-in. Please try again.')]);
        }

        if (! $customer instanceof Customer) {
            return redirect()->route('app.signin')->withErrors(['login' => 'Login failed.']);
        }

        Auth::guard('app')->login($customer, true);
        request()->session()->regenerate();

        if (! $customerExistedBeforeCallback) {
            TelegramRegistrationNotifier::sendUnverifiedIfNeeded($customer, $provider);
        }

        if ($nextVerificationRoute = $customer->nextVerificationRouteName()) {
            return redirect()->route($nextVerificationRoute);
        }

        return redirect()->route('app.home', ['locale' => app()->getLocale()])
            ->with('status', 'Welcome back!');
    }

    protected function customerExistsForProvider($providerUser, string $provider): bool
    {
        $email = strtolower(trim((string) $providerUser->getEmail()));
        $providerId = trim((string) $providerUser->getId());

        if ($email === '' && $providerId === '') {
            return false;
        }

        return Customer::query()
            ->where(function ($query) use ($provider, $providerId, $email): void {
                if ($providerId !== '') {
                    if ($provider === 'google') {
                        $query->orWhere('g_id', $providerId);
                    } elseif ($provider === 'github') {
                        $query->orWhere('h_id', $providerId);
                    }
                }

                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->exists();
    }
}
