<?php

namespace App\Http\Controllers\Api\Mobile\Auth;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Resources\Mobile\MobileCurrentUserResource;
use App\Models\Customer;
use App\Services\Mobile\MobileApiTokenService;
use App\Services\Mobile\MobileAppCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends MobileApiController
{
    public function __construct(
        protected MobileApiTokenService $tokens,
        protected MobileAppCatalog $catalog,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_slug' => ['nullable', 'string', 'in:tts,ctts,asr,stem,ocr,tran'],
        ]);

        $customer = Customer::query()
            ->with(['profile', 'usage', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan'])
            ->where('email', strtolower(trim((string) $validated['email'])))
            ->first();

        if (! $customer || ! Hash::check((string) $validated['password'], (string) $customer->password)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if ($response = $this->ensureCustomerCanUseMobile($customer, $validated['app_slug'] ?? null)) {
            return $response;
        }

        $issued = $this->tokens->issue($customer, $validated['device_name'] ?? null, $validated['app_slug'] ?? null);

        return response()->json([
            'token_type' => 'Bearer',
            'token' => $issued['plain_text_token'],
            'expires_at' => $issued['expires_at'],
            'abilities' => $issued['abilities'],
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $this->tokens->revokeCurrent($customer);

        return response()->json([
            'message' => __('Logged out successfully.'),
        ]);
    }

    public function me(Request $request): MobileCurrentUserResource
    {
        return new MobileCurrentUserResource($this->customer($request));
    }

    public function apps(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($response = $this->ensureCustomerCanUseMobile($customer)) {
            return $response;
        }

        return response()->json([
            'data' => $this->catalog->appsForCustomer($customer),
        ]);
    }

    protected function ensureCustomerCanUseMobile(Customer $customer, ?string $appSlug = null): ?JsonResponse
    {
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

        if ($appSlug && ! $this->catalog->customerCanAccess($customer, $appSlug)) {
            return response()->json([
                'message' => __('Your current subscription does not allow this mobile app.'),
            ], 403);
        }

        return null;
    }
}
