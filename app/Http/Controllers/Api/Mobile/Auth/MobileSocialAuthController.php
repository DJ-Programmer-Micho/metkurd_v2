<?php

namespace App\Http\Controllers\Api\Mobile\Auth;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Resources\Mobile\MobileCurrentUserResource;
use App\Services\Auth\CustomerPhoneOtpService;
use App\Services\Auth\CustomerSocialAuthService;
use App\Services\Mobile\MobileApiTokenService;
use App\Services\Mobile\MobileAppCatalog;
use App\Support\TelegramRegistrationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MobileSocialAuthController extends MobileApiController
{
    public function __construct(
        protected CustomerSocialAuthService $socialAuth,
        protected CustomerPhoneOtpService $phoneOtp,
        protected MobileApiTokenService $tokens,
        protected MobileAppCatalog $catalog,
    ) {
    }

    public function login(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));

        if (! $this->socialAuth->isSupportedProvider($provider)) {
            return $this->validationErrorResponse([
                'provider' => [__('This social provider is not supported.')],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'access_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_slug' => ['nullable', 'string', Rule::in(['tts', 'ctts', 'asr', 'stem', 'ocr', 'tran'])],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        /** @var array{access_token: string, device_name?: string, app_slug?: string|null} $validated */
        $validated = $validator->validated();

        $customerExistedBefore = false;

        try {
            $providerUser = $this->socialAuth->fetchProviderUserFromToken($provider, (string) $validated['access_token']);
            $customerExistedBefore = $this->socialAuth->customerExistsForProviderUser($providerUser, $provider);
            $customer = $this->socialAuth->authenticateProviderUser($providerUser, $provider, allowCreate: true);
        } catch (\Throwable $e) {
            Log::warning('Mobile social login failed.', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return $this->validationErrorResponse([
                'access_token' => [__('Social sign-in failed. Please confirm your provider token and try again.')],
            ]);
        }

        if ((int) ($customer->status ?? 0) !== 1) {
            return response()->json([
                'state' => 'validation_error',
                'message' => __('This account is inactive. Please use the website for support.'),
            ], 423);
        }

        if (! $customerExistedBefore) {
            TelegramRegistrationNotifier::sendUnverifiedIfNeeded($customer, $provider);
        }

        $appSlug = $validated['app_slug'] ?? null;

        if ($customer->hasCompletedVerification()) {
            if ($appSlug && ! $this->catalog->customerCanAccess($customer, (string) $appSlug)) {
                return response()->json([
                    'state' => 'validation_error',
                    'message' => __('Your current subscription does not allow this mobile app.'),
                ], 403);
            }

            $issued = $this->tokens->issue($customer, $validated['device_name'] ?? null, $appSlug);

            return response()->json([
                'state' => 'authenticated',
                'token_type' => 'Bearer',
                'token' => $issued['plain_text_token'],
                'expires_at' => $issued['expires_at'],
                'abilities' => $issued['abilities'],
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ]);
        }

        $onboardingToken = $this->tokens->issueOnboarding($customer, $validated['device_name'] ?? null);
        $hasPhone = $this->phoneOtp->hasValidPhone($customer);
        $resolvedPhone = $hasPhone ? $this->phoneOtp->resolvePhone($customer) : null;
        $state = $hasPhone ? 'needs_phone_otp' : 'needs_phone_number';
        $otpState = $this->phoneOtp->state($customer);

        return response()->json([
            'state' => $state,
            'token_type' => 'Bearer',
            'token' => $onboardingToken['plain_text_token'],
            'expires_at' => $onboardingToken['expires_at'],
            'abilities' => $onboardingToken['abilities'],
            'phone' => [
                'exists' => $hasPhone,
                'number' => $resolvedPhone,
                'verified' => (bool) $customer->phone_verify,
            ],
            'otp' => [
                'channel_required' => true,
                'expires_remaining' => (int) $otpState['expires_remaining'],
                'cooldown_remaining' => (int) $otpState['cooldown_remaining'],
                'attempts_left' => (int) $otpState['attempts_left'],
                'lock_remaining' => (int) $otpState['lock_remaining'],
            ],
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ]);
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    protected function validationErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'state' => 'validation_error',
            'message' => __('The given data was invalid.'),
            'errors' => $errors,
        ], 422);
    }
}
