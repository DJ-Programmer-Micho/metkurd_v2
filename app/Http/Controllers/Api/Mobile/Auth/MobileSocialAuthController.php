<?php

namespace App\Http\Controllers\Api\Mobile\Auth;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Resources\Mobile\MobileCurrentUserResource;
use App\Services\Auth\CustomerSocialAuthService;
use App\Services\Mobile\MobileApiTokenService;
use App\Services\Mobile\MobileAppCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MobileSocialAuthController extends MobileApiController
{
    public function __construct(
        protected CustomerSocialAuthService $socialAuth,
        protected MobileApiTokenService $tokens,
        protected MobileAppCatalog $catalog,
    ) {
    }

    public function login(Request $request, string $provider): JsonResponse
    {
        $validated = $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_slug' => ['nullable', 'string', 'in:tts,ctts,asr,stem,ocr,tran'],
        ]);

        try {
            $providerUser = $this->socialAuth->fetchProviderUserFromToken($provider, (string) $validated['access_token']);
            $customer = $this->socialAuth->authenticateProviderUser($providerUser, $provider, allowCreate: false);
        } catch (\Throwable $e) {
            Log::warning('Mobile social login failed.', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('Social sign-in failed. Make sure the account already exists on the website.'),
            ], 422);
        }

        if ((int) ($customer->status ?? 0) !== 1) {
            return response()->json([
                'message' => __('This account is inactive. Please use the website for support.'),
            ], 423);
        }

        if (! $customer->hasCompletedVerification()) {
            return response()->json([
                'message' => __('Complete your account verification on the website before using the mobile apps.'),
            ], 403);
        }

        $appSlug = $validated['app_slug'] ?? null;

        if ($appSlug && ! $this->catalog->customerCanAccess($customer, (string) $appSlug)) {
            return response()->json([
                'message' => __('Your current subscription does not allow this mobile app.'),
            ], 403);
        }

        $issued = $this->tokens->issue($customer, $validated['device_name'] ?? null, $appSlug);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $issued['plain_text_token'],
            'expires_at' => $issued['expires_at'],
            'abilities' => $issued['abilities'],
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ]);
    }
}
