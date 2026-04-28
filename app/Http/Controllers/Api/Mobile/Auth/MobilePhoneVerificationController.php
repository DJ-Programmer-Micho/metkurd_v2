<?php

namespace App\Http\Controllers\Api\Mobile\Auth;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Http\Resources\Mobile\MobileCurrentUserResource;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Auth\CustomerPhoneOtpService;
use App\Services\Mobile\MobileApiTokenService;
use App\Services\Mobile\MobileAppCatalog;
use App\Support\RegistrationPhoneCountryManager;
use App\Support\TelegramRegistrationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MobilePhoneVerificationController extends MobileApiController
{
    public function __construct(
        protected CustomerPhoneOtpService $phoneOtp,
        protected MobileApiTokenService $tokens,
        protected MobileAppCatalog $catalog,
    ) {
    }

    public function savePhone(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($inactive = $this->inactiveResponse($customer)) {
            return $inactive;
        }

        if ($emailGuard = $this->emailNotVerifiedResponse($customer)) {
            return $emailGuard;
        }

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:30'],
            'phone_country' => ['required', 'string', 'size:2'],
            'phone_dial_code' => ['required', 'string', 'max:4', 'regex:/^\d{1,4}$/'],
            'channel' => ['nullable', 'string', Rule::in(['sms', 'telegram', 'whatsapp'])],
        ], [
            'phone_country.required' => __('Please choose your phone country.'),
            'phone_country.size' => __('Please choose a valid phone country.'),
            'phone_dial_code.required' => __('Please choose your phone country code.'),
            'phone_dial_code.regex' => __('Please choose a valid phone country code.'),
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        /** @var array{phone:string, phone_country:string, phone_dial_code:string, channel?:string|null} $validated */
        $validated = $validator->validated();
        $phone = $this->phoneOtp->normalizePhone((string) $validated['phone']);
        $country = RegistrationPhoneCountryManager::normalizeIso2((string) $validated['phone_country']);
        $dialCode = RegistrationPhoneCountryManager::normalizeDialCode((string) $validated['phone_dial_code']);

        $profileId = (int) CustomerProfile::query()
            ->where('customer_id', (int) $customer->id)
            ->value('id');

        $uniqueRule = Rule::unique('customer_profiles', 'phone_number');
        if ($profileId > 0) {
            $uniqueRule = $uniqueRule->ignore($profileId);
        }

        $extraValidator = Validator::make([
            'phone' => $phone,
            'phone_country' => $country,
            'phone_dial_code' => $dialCode,
        ], [
            'phone' => ['required', 'string', 'regex:/^\+\d{10,15}$/', 'max:30', $uniqueRule],
            'phone_country' => ['required', 'string', 'size:2'],
            'phone_dial_code' => ['required', 'string', 'max:4', 'regex:/^\d{1,4}$/'],
        ]);

        if ($extraValidator->fails()) {
            return $this->validationErrorResponse($extraValidator->errors()->toArray());
        }

        if (! RegistrationPhoneCountryManager::isCountryAllowed($country)) {
            return $this->validationErrorResponse([
                'phone' => [__('Please select a valid phone country.')],
            ]);
        }

        if (! RegistrationPhoneCountryManager::matchesDialCode($phone, $dialCode)) {
            return $this->validationErrorResponse([
                'phone' => [__('Phone country code and number do not match.')],
            ]);
        }

        CustomerProfile::query()->updateOrCreate(
            ['customer_id' => (int) $customer->id],
            [
                'phone_number' => $phone,
                'country' => strtoupper($country),
            ]
        );

        $customer->forceFill([
            'phone_verify' => false,
            'phone_verified_at' => null,
            'phone_otp_number' => null,
        ])->save();

        $this->phoneOtp->clearOtpState($customer);
        $customer->refresh()->loadMissing('profile');

        $channel = strtolower(trim((string) ($validated['channel'] ?? '')));
        $sent = false;
        $message = __('Phone saved. Choose a provider to receive your OTP.');

        if ($channel !== '') {
            $state = $this->phoneOtp->state($customer);

            if ((bool) $state['is_locked']) {
                return $this->otpStateResponse(
                    customer: $customer,
                    message: __('Too many attempts. Locked for :time.', ['time' => $this->fmt((int) $state['lock_remaining'])]),
                    status: 429
                );
            }

            if ((int) $state['cooldown_remaining'] > 0) {
                return $this->otpStateResponse(
                    customer: $customer,
                    message: __('Please wait :time before resending.', ['time' => $this->fmt((int) $state['cooldown_remaining'])]),
                    status: 429
                );
            }

            try {
                $this->phoneOtp->issueCode($customer, $channel);
                $sent = true;
                $message = __('Phone saved and OTP sent successfully.');
            } catch (\Throwable $e) {
                Log::warning('Mobile phone OTP send failed after phone save.', [
                    'customer_id' => (int) $customer->id,
                    'channel' => $channel,
                    'message' => $e->getMessage(),
                ]);

                return response()->json([
                    'state' => 'needs_phone_otp',
                    'message' => __('Phone saved, but sending OTP failed. Please try another provider.'),
                    'phone' => $this->phonePayload($customer),
                    'otp' => $this->otpPayload($customer),
                    'user' => new MobileCurrentUserResource($customer->refresh()),
                ], 503);
            }
        }

        return response()->json([
            'state' => 'needs_phone_otp',
            'message' => $message,
            'otp_sent' => $sent,
            'phone' => $this->phonePayload($customer),
            'otp' => $this->otpPayload($customer),
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($inactive = $this->inactiveResponse($customer)) {
            return $inactive;
        }

        if ($emailGuard = $this->emailNotVerifiedResponse($customer)) {
            return $emailGuard;
        }

        if ((bool) $customer->phone_verify) {
            return response()->json([
                'state' => 'phone_verified',
                'message' => __('Phone already verified.'),
                'phone' => $this->phonePayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ]);
        }

        if (! $this->phoneOtp->hasValidPhone($customer)) {
            return response()->json([
                'state' => 'needs_phone_number',
                'message' => __('Please submit your phone number first.'),
                'phone' => $this->phonePayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'channel' => ['required', 'string', Rule::in(['sms', 'telegram', 'whatsapp'])],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $state = $this->phoneOtp->state($customer);

        if ((bool) $state['is_locked']) {
            return $this->otpStateResponse(
                customer: $customer,
                message: __('Too many attempts. Locked for :time.', ['time' => $this->fmt((int) $state['lock_remaining'])]),
                status: 429
            );
        }

        if ((int) $state['cooldown_remaining'] > 0) {
            return $this->otpStateResponse(
                customer: $customer,
                message: __('Please wait :time before resending.', ['time' => $this->fmt((int) $state['cooldown_remaining'])]),
                status: 429
            );
        }

        $channel = strtolower(trim((string) $validator->validated()['channel']));

        try {
            $this->phoneOtp->issueCode($customer, $channel);
        } catch (\Throwable $e) {
            Log::warning('Mobile phone OTP send failed.', [
                'customer_id' => (int) $customer->id,
                'channel' => $channel,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'state' => 'needs_phone_otp',
                'message' => __('Failed to send code. Please try another provider.'),
                'phone' => $this->phonePayload($customer),
                'otp' => $this->otpPayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ], 503);
        }

        return response()->json([
            'state' => 'needs_phone_otp',
            'message' => __('Code sent. Please check your phone.'),
            'phone' => $this->phonePayload($customer),
            'otp' => $this->otpPayload($customer),
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if ($inactive = $this->inactiveResponse($customer)) {
            return $inactive;
        }

        if ($emailGuard = $this->emailNotVerifiedResponse($customer)) {
            return $emailGuard;
        }

        $validator = Validator::make($request->all(), [
            'otp_code' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_slug' => ['nullable', 'string', Rule::in(['tts', 'ctts', 'asr', 'stem', 'ocr', 'tran'])],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        /** @var array{otp_code: string, device_name?: string, app_slug?: string|null} $validated */
        $validated = $validator->validated();
        $appSlug = $validated['app_slug'] ?? null;

        if ((bool) $customer->phone_verify) {
            $tokenAbilities = (array) ($customer->currentAccessToken()?->abilities ?? []);

            if ($this->tokens->tokenCanAccessFullMobile($tokenAbilities)) {
                return response()->json([
                    'state' => 'authenticated',
                    'message' => __('Phone already verified.'),
                    'phone' => $this->phonePayload($customer),
                    'user' => new MobileCurrentUserResource($customer->refresh()),
                ]);
            }

            $this->tokens->revokeCurrent($customer);

            return $this->issueAuthenticatedTokenResponse(
                customer: $customer,
                deviceName: $validated['device_name'] ?? null,
                appSlug: $appSlug,
                state: 'authenticated',
                message: __('Phone already verified.'),
            );
        }

        if (! $this->phoneOtp->hasValidPhone($customer)) {
            return response()->json([
                'state' => 'needs_phone_number',
                'message' => __('Please submit your phone number first.'),
                'phone' => $this->phonePayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ], 422);
        }

        $state = $this->phoneOtp->state($customer);

        if ((bool) $state['is_locked']) {
            return $this->otpStateResponse(
                customer: $customer,
                message: __('Too many attempts. Locked for :time.', ['time' => $this->fmt((int) $state['lock_remaining'])]),
                status: 429
            );
        }

        if ((bool) $state['is_expired']) {
            return response()->json([
                'state' => 'needs_phone_otp',
                'message' => __('Code expired. Please resend a new code.'),
                'phone' => $this->phonePayload($customer),
                'otp' => $this->otpPayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ], 422);
        }

        $otpCode = $this->phoneOtp->normalizeOtpCode((string) $validated['otp_code']);

        if (preg_match('/^\d{6}$/', $otpCode) !== 1) {
            return $this->validationErrorResponse([
                'otp_code' => [__('The code must be exactly 6 digits.')],
            ]);
        }

        if (! hash_equals((string) ($customer->phone_otp_number ?? ''), $otpCode)) {
            $isNowLocked = $this->phoneOtp->markWrongAttempt($customer);

            if ($isNowLocked) {
                return $this->otpStateResponse(
                    customer: $customer,
                    message: __('Too many wrong attempts. Locked for :time.', ['time' => $this->fmt((int) data_get($this->otpPayload($customer), 'lock_remaining', 0))]),
                    status: 429
                );
            }

            return response()->json([
                'state' => 'validation_error',
                'message' => __('Invalid code. Please try again.'),
                'errors' => [
                    'otp_code' => [__('Invalid code. Please try again.')],
                ],
                'phone' => $this->phonePayload($customer),
                'otp' => $this->otpPayload($customer),
                'user' => new MobileCurrentUserResource($customer->refresh()),
            ], 422);
        }

        $this->phoneOtp->markVerified($customer);
        TelegramRegistrationNotifier::sendVerifiedIfNeeded($customer->refresh(), $this->registrationMethodFromCustomer($customer));
        $this->tokens->revokeCurrent($customer);

        return $this->issueAuthenticatedTokenResponse(
            customer: $customer->refresh(),
            deviceName: $validated['device_name'] ?? null,
            appSlug: $appSlug,
            state: 'phone_verified',
            message: __('Phone verified successfully.'),
        );
    }

    protected function issueAuthenticatedTokenResponse(
        Customer $customer,
        ?string $deviceName,
        ?string $appSlug,
        string $state,
        string $message
    ): JsonResponse {
        if ($appSlug && ! $this->catalog->customerCanAccess($customer, $appSlug)) {
            return response()->json([
                'state' => 'validation_error',
                'message' => __('Your current subscription does not allow this mobile app.'),
            ], 403);
        }

        $issued = $this->tokens->issue($customer, $deviceName, $appSlug);

        return response()->json([
            'state' => $state,
            'message' => $message,
            'token_type' => 'Bearer',
            'token' => $issued['plain_text_token'],
            'expires_at' => $issued['expires_at'],
            'abilities' => $issued['abilities'],
            'phone' => $this->phonePayload($customer),
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

    protected function inactiveResponse(Customer $customer): ?JsonResponse
    {
        if ((int) ($customer->status ?? 0) !== 1) {
            return response()->json([
                'state' => 'validation_error',
                'message' => __('This account is inactive. Please use the website for support.'),
            ], 423);
        }

        return null;
    }

    protected function emailNotVerifiedResponse(Customer $customer): ?JsonResponse
    {
        if ((bool) $customer->email_verify) {
            return null;
        }

        return response()->json([
            'state' => 'validation_error',
            'message' => __('Please verify your email first.'),
        ], 403);
    }

    protected function otpStateResponse(Customer $customer, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'state' => 'needs_phone_otp',
            'message' => $message,
            'phone' => $this->phonePayload($customer),
            'otp' => $this->otpPayload($customer),
            'user' => new MobileCurrentUserResource($customer->refresh()),
        ], $status);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    protected function phonePayload(Customer $customer): array
    {
        $customer->loadMissing('profile');
        $phone = $this->phoneOtp->resolvePhone($customer);
        $exists = preg_match('/^\+\d{10,15}$/', $phone) === 1;

        return [
            'exists' => $exists,
            'number' => $exists ? $phone : null,
            'verified' => (bool) $customer->phone_verify,
        ];
    }

    /**
     * @return array<string, bool|int>
     */
    protected function otpPayload(Customer $customer): array
    {
        $state = $this->phoneOtp->state($customer);

        return [
            'expires_remaining' => (int) $state['expires_remaining'],
            'cooldown_remaining' => (int) $state['cooldown_remaining'],
            'attempts_left' => (int) $state['attempts_left'],
            'lock_remaining' => (int) $state['lock_remaining'],
            'is_locked' => (bool) $state['is_locked'],
            'is_expired' => (bool) $state['is_expired'],
        ];
    }

    protected function fmt(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        return sprintf('%02d:%02d', $minutes, $remainder);
    }

    protected function registrationMethodFromCustomer(Customer $customer): string
    {
        if (filled($customer->g_id)) {
            return 'google';
        }

        if (filled($customer->h_id)) {
            return 'github';
        }

        return 'normal_form';
    }
}
