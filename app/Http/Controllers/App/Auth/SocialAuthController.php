<?php

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Analytics\ConversionTrackingService;
use App\Services\Auth\CustomerSocialAuthService;
use App\Support\TelegramRegistrationNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function __construct(
        protected CustomerSocialAuthService $socialAuth,
        protected ConversionTrackingService $conversionTracking,
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
        $customerExistedBeforeCallback = $this->socialAuth->customerExistsForProviderUser($providerUser, $provider);

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

            try {
                $this->conversionTracking->queueSignupConversion($customer, $provider);
            } catch (\Throwable $e) {
                Log::warning('Social signup conversion session flag could not be queued.', [
                    'customer_id' => (int) $customer->id,
                    'provider' => $provider,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($nextVerificationRoute = $customer->nextVerificationRouteName()) {
            return redirect()->route($nextVerificationRoute);
        }

        return redirect()->route('app.home', ['locale' => app()->getLocale()])
            ->with('status', 'Welcome back!');
    }
}
