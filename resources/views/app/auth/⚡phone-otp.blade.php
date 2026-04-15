<?php

use App\Models\CustomerProfile;
use App\Support\RegistrationPhoneCountryManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    /** 0 = choose provider, 1 = enter code, 2 = edit phone */
    public int $flag = 0;

    public string $phone = '';
    public string $phone_country = '';
    public string $phone_dial_code = '';
    public string $channel = '';

    public array $allowedPhoneCountries = [];
    public array $preferredPhoneCountries = [];

    public string $digit1 = '';
    public string $digit2 = '';
    public string $digit3 = '';
    public string $digit4 = '';
    public string $digit5 = '';
    public string $digit6 = '';

    protected int $otpTtlSeconds = 300;
    protected int $maxAttempts = 5;
    protected int $lockSeconds = 600;
    protected int $cooldownSeconds = 60;

    public int $expiresRemaining = 0;
    public int $cooldownRemaining = 0;
    public int $attemptsLeft = 5;
    public int $lockRemaining = 0;

    public function mount()
    {
        $user = Auth::guard('app')->user();

        if (! $user || ! $user->email_verify) {
            $this->dispatch('alert', type: 'warning', message: __('Please verify your email first.'));

            return redirect()->to(route('app.email.otp'));
        }

        $this->allowedPhoneCountries = RegistrationPhoneCountryManager::enabledCountryCodes();
        if ($this->allowedPhoneCountries === []) {
            $this->allowedPhoneCountries = RegistrationPhoneCountryManager::defaultEnabledCountryCodes();
        }

        $this->preferredPhoneCountries = array_slice($this->allowedPhoneCountries, 0, min(3, count($this->allowedPhoneCountries)));

        $this->phone = (string) (
            optional($user->profile)->phone_number
            ?? CustomerProfile::where('customer_id', $user->id)->value('phone_number')
            ?? ''
        );

        $profileCountry = RegistrationPhoneCountryManager::normalizeIso2(optional($user->profile)->country);
        $this->phone_country = $profileCountry !== ''
            ? $profileCountry
            : ($this->preferredPhoneCountries[0] ?? $this->allowedPhoneCountries[0] ?? 'iq');

        $this->syncState();
    }

    public function tick(): void
    {
        $this->syncState();
    }

    private function redirectIfEmailNotVerified()
    {
        $user = Auth::guard('app')->user();

        if ($user && $user->email_verify) {
            return null;
        }

        $this->dispatch('alert', type: 'warning', message: __('Please verify your email first.'));

        return redirect()->to(route('app.email.otp'));
    }

    public function sendCode(string $channel)
    {
        if ($redirect = $this->redirectIfEmailNotVerified()) {
            return $redirect;
        }

        $user = Auth::guard('app')->user();

        if ($user->phone_verify) {
            $this->dispatch('alert', type: 'info', message: __('Phone already verified.'));
            return redirect()->to(route('app.home',['locale' => app()->getLocale()]));
        }

        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: __('Too many attempts. Locked for :time.', ['time' => $this->fmt($this->lockRemaining)]));
            return;
        }

        if ($this->cooldownRemaining > 0) {
            $this->dispatch('alert', type: 'warning', message: __('Please wait :time before resending.', ['time' => $this->fmt($this->cooldownRemaining)]));
            return;
        }

        $this->validatePhoneOnly();
        $this->channel = $channel;

        $otp = (string) random_int(100000, 999999);
        $user->phone_otp_number = $otp;
        $user->save();

        Cache::put($this->expiresKey(), now()->addSeconds($this->otpTtlSeconds)->timestamp, $this->otpTtlSeconds + 60);
        Cache::put($this->attemptsKey(), 0, $this->otpTtlSeconds + $this->lockSeconds + 600);
        Cache::put($this->cooldownKey(), now()->addSeconds($this->cooldownSeconds)->timestamp, $this->cooldownSeconds + 60);
        Cache::forget($this->lockKey());

        $recipient = ltrim($this->phone, '+');

        try {
            $this->sendStandingTechOtp($channel, $recipient, $otp);

            $this->flag = 1;
            $this->resetDigits();
            $this->syncState();

            $this->dispatch('alert', type: 'success', message: __('Code sent. Please check your phone.'));
        } catch (\Throwable $e) {
            $this->dispatch('alert', type: 'error', message: __('Failed to send code. Please try another provider.'));
        }
    }

    public function editPhone()
    {
        $this->flag = 2;
        $this->resetErrorBag();
        $this->syncState();
    }

    public function goBack()
    {
        $this->flag = 0;
        $this->resetErrorBag();
        $this->resetDigits();
        $this->syncState();
    }

    public function savePhone()
    {
        if ($redirect = $this->redirectIfEmailNotVerified()) {
            return $redirect;
        }

        $this->validatePhoneOnly();
        $this->phone_country = $this->normalizePhoneCountry($this->phone_country);
        $this->phone_dial_code = $this->normalizeDialCode($this->phone_dial_code);

        $user = Auth::guard('app')->user();

        $this->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^\+\d{10,15}$/',
                'max:30',
                Rule::unique('customer_profiles', 'phone_number')
                    ->ignore(CustomerProfile::where('customer_id', $user->id)->value('id')),
            ],
            'phone_country' => ['required', 'string', 'size:2'],
            'phone_dial_code' => ['required', 'string', 'max:4', 'regex:/^\d{1,4}$/'],
        ], [
            'phone_country.required' => __('Please choose your phone country.'),
            'phone_country.size' => __('Please choose a valid phone country.'),
            'phone_dial_code.required' => __('Please choose your phone country code.'),
            'phone_dial_code.regex' => __('Please choose a valid phone country code.'),
        ]);

        if (! RegistrationPhoneCountryManager::isCountryAllowed($this->phone_country)) {
            $this->addError('phone', __('Please select a valid phone country.'));

            return;
        }

        if (! RegistrationPhoneCountryManager::matchesDialCode($this->phone, $this->phone_dial_code)) {
            $this->addError('phone', __('Phone country code and number do not match.'));

            return;
        }

        CustomerProfile::updateOrCreate(
            ['customer_id' => $user->id],
            [
                'phone_number' => $this->phone,
                'country' => strtoupper($this->phone_country),
            ]
        );

        $user->phone_verify = false;
        $user->phone_verified_at = null;
        $user->phone_otp_number = null;
        $user->save();

        $this->clearOtpState();
        $this->resetDigits();
        $this->channel = '';

        $this->phone = (string) CustomerProfile::where('customer_id', $user->id)->value('phone_number');
        $this->phone_country = RegistrationPhoneCountryManager::normalizeIso2(
            (string) CustomerProfile::where('customer_id', $user->id)->value('country')
        ) ?: $this->phone_country;

        $this->dispatch('alert', type: 'success', message: __('Phone updated. Choose a provider to receive a code.'));
        $this->flag = 0;
        $this->syncState();
    }

    public function confirm()
    {
        if ($redirect = $this->redirectIfEmailNotVerified()) {
            return $redirect;
        }

        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: __('Too many attempts. Locked for :time.', ['time' => $this->fmt($this->lockRemaining)]));
            return;
        }

        if ($this->isExpired) {
            $this->dispatch('alert', type: 'warning', message: __('Code expired. Please resend a new code.'));
            return;
        }

        $this->validate([
            'digit1' => ['required', 'digits:1'],
            'digit2' => ['required', 'digits:1'],
            'digit3' => ['required', 'digits:1'],
            'digit4' => ['required', 'digits:1'],
            'digit5' => ['required', 'digits:1'],
            'digit6' => ['required', 'digits:1'],
        ]);

        $code = $this->digit1 . $this->digit2 . $this->digit3 . $this->digit4 . $this->digit5 . $this->digit6;
        $user = Auth::guard('app')->user();

        if (hash_equals((string) ($user->phone_otp_number ?? ''), $code)) {
            $user->phone_verify = true;
            $user->phone_verified_at = now();
            $user->phone_otp_number = null;
            $user->save();

            $this->clearOtpState();

            $this->dispatch('alert', type: 'success', message: __('Phone verified successfully! Redirecting...'));

            if ($nextVerificationRoute = $user->nextVerificationRouteName()) {
                return redirect()->to(route($nextVerificationRoute));
            }

            return redirect()->to(route('app.home',['locale' => app()->getLocale()]));
        }

        $attempts = (int) Cache::get($this->attemptsKey(), 0) + 1;
        Cache::put($this->attemptsKey(), $attempts, $this->otpTtlSeconds + $this->lockSeconds + 600);

        if ($attempts >= $this->maxAttempts) {
            Cache::put($this->lockKey(), now()->addSeconds($this->lockSeconds)->timestamp, $this->lockSeconds + 60);
            $this->syncState();
            $this->dispatch('alert', type: 'error', message: __('Too many wrong attempts. Locked for :time.', ['time' => $this->fmt($this->lockRemaining)]));
            return;
        }

        $this->syncState();
        $this->dispatch('alert', type: 'error', message: __('Invalid code. Please try again.'));
    }

    public function resend()
    {
        if ($redirect = $this->redirectIfEmailNotVerified()) {
            return $redirect;
        }

        $this->syncState();

        if ($this->isLocked) {
            $this->dispatch('alert', type: 'error', message: __('Locked for :time.', ['time' => $this->fmt($this->lockRemaining)]));
            return;
        }

        if ($this->cooldownRemaining > 0) {
            $this->dispatch('alert', type: 'warning', message: __('Please wait :time before requesting a new code.', ['time' => $this->fmt($this->cooldownRemaining)]));
            return;
        }

        if (! $this->channel) {
            $this->dispatch('alert', type: 'info', message: __('Please choose a provider first.'));
            $this->flag = 0;
            return;
        }

        $this->resetDigits();
        $this->sendCode($this->channel);
    }

    private function sendStandingTechOtp(string $type, string $recipient, string $otp): array
    {
        $base = config('services.standingtech.base', env('STANDINGTECH_BASE_URL'));
        $token = config('services.standingtech.token', env('STANDINGTECH_TOKEN'));
        $sender = config('services.standingtech.sender', env('STANDINGTECH_SENDER_ID'));

        if (! $base || ! $token || ! $sender) {
            throw new \RuntimeException(__('StandingTech config is missing (base/token/sender).'));
        }

        $payload = [
            'recipient' => $recipient,
            'sender_id' => $sender,
            'type' => $type,
            'message' => (string) $otp,
            'lang' => 'en',
        ];

        if ($type !== 'sms') {
            $payload['fallback'] = 'sms';
        }

        $res = Http::baseUrl($base)
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->retry(2, 200)
            ->withToken($token)
            ->post('/api/v4/sms/send', $payload);

        $res->throw();
        return $res->json();
    }

    public function fmt(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d', $m, $s);
    }

    public function getIsExpiredProperty(): bool
    {
        return $this->flag === 1 && $this->expiresRemaining <= 0;
    }

    public function getIsLockedProperty(): bool
    {
        return $this->lockRemaining > 0;
    }

    public function getInputsDisabledProperty(): bool
    {
        return $this->isLocked || $this->isExpired;
    }

    private function syncState(): void
    {
        $uid = Auth::guard('app')->id();
        if (! $uid) return;

        $now = now()->timestamp;

        $expTs = (int) Cache::get($this->expiresKey(), 0);
        $cdTs = (int) Cache::get($this->cooldownKey(), 0);
        $lockTs = (int) Cache::get($this->lockKey(), 0);
        $attempts = (int) Cache::get($this->attemptsKey(), 0);

        $this->expiresRemaining = $expTs > 0 ? max(0, $expTs - $now) : 0;
        $this->cooldownRemaining = $cdTs > 0 ? max(0, $cdTs - $now) : 0;
        $this->lockRemaining = $lockTs > 0 ? max(0, $lockTs - $now) : 0;
        $this->attemptsLeft = max(0, $this->maxAttempts - $attempts);
    }

    private function clearOtpState(): void
    {
        Cache::forget($this->expiresKey());
        Cache::forget($this->attemptsKey());
        Cache::forget($this->lockKey());
        Cache::forget($this->cooldownKey());
        $this->syncState();
    }

    private function resetDigits(): void
    {
        $this->digit1 = $this->digit2 = $this->digit3 = $this->digit4 = $this->digit5 = $this->digit6 = '';
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits ? ('+' . $digits) : '';
    }

    private function normalizePhoneCountry(?string $country): string
    {
        return RegistrationPhoneCountryManager::normalizeIso2($country);
    }

    private function normalizeDialCode(?string $dialCode): string
    {
        return RegistrationPhoneCountryManager::normalizeDialCode($dialCode);
    }

    private function validatePhoneOnly(): void
    {
        $this->phone = $this->normalizePhone($this->phone);

        $this->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^\+\d{10,15}$/',
                'max:30',
            ],
        ], [
            'phone.required' => __('Phone number is required.'),
            'phone.regex' => __('Phone number must be in international format (+XXXXXXXXXXX).'),
        ]);
    }

    private function expiresKey(): string
    {
        return 'phone_otp_expires_' . Auth::guard('app')->id();
    }

    private function attemptsKey(): string
    {
        return 'phone_otp_attempts_' . Auth::guard('app')->id();
    }

    private function lockKey(): string
    {
        return 'phone_otp_lock_' . Auth::guard('app')->id();
    }

    private function cooldownKey(): string
    {
        return 'phone_otp_cooldown_' . Auth::guard('app')->id();
    }
};

?>

<x-slot:title>{{ __('Verify Phone') }} | {{ __('MET KURD') }}</x-slot:title>

@include('app.auth.partials.intl-tel-input-shared')

<div class="row"
    @if ($flag === 1)
        wire:poll.visible.1s="tick"
    @elseif ($flag === 0 && ($cooldownRemaining > 0 || $this->isLocked))
        wire:poll.visible.5s="tick"
    @endif>
    <div class="col-lg-12">
        <div class="card m-0" style="box-shadow: -12px -6px 45px 10px rgb(204 0 34 / 0.15);">
            <div class="row justify-content-center g-0">
                <div class="col-lg-6">
                    <div class="p-lg-5 p-4 auth-one-bg h-100">
                        <div class="bg-overlay"></div>
                        <div class="position-relative h-100 d-flex flex-column">
                            <div class="mb-4">
                                <a wire:navigate href="/" class="d-block">
                                    <img src="{{ app('logo_1024_tran') }}" alt="" height="25">
                                    {{ __('MET KURD') }}
                                </a>
                            </div>
                            <div class="mt-auto">
                                <div class="mb-3">
                                    <i class="ri-double-quotes-l display-4 text-success"></i>
                                </div>

                                <div id="qoutescarouselIndicators" class="carousel slide" data-bs-ride="carousel">
                                    <div class="carousel-indicators">
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="0" class="active" aria-current="true"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="1"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="2"></button>
                                    </div>
                                    <div class="carousel-inner text-center text-white pb-5">
                                        <div class="carousel-item active">
                                            <p class="fs-15 fst-italic">" {{ __('Verify your phone to protect your account.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('Strong security is part of the METKURD experience.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('One more step and you\'re ready.') }} "</p>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-lg-5 p-4">
                        @if ($flag === 0)
                            <div wire:key="phone-otp-state-provider">
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/nnzfcuqw.json"
                                            trigger="loop"
                                            delay="1500"
                                            state="in-assembly"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3 mb-4">
                                <h4><b>{{ $phone ?: '--' }}</b></h4>
                                <h5>{{ __('Is this your phone number?') }}</h5>
                                <small>{{ __('Please choose one of the providers') }}</small>

                                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                                    <span class="badge bg-warning text-dark">{{ __('Attempts left: :count', ['count' => $attemptsLeft]) }}</span>

                                    @if ($cooldownRemaining > 0)
                                        <span class="badge bg-secondary">{{ __('Cooldown: :time', ['time' => $this->fmt($cooldownRemaining)]) }}</span>
                                    @endif

                                    @if ($this->isLocked)
                                        <span class="badge bg-danger">{{ __('Locked: :time', ['time' => $this->fmt($lockRemaining)]) }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-4">
                                    <button class="btn btn-outline-success w-100"
                                            type="button"
                                            wire:click="sendCode('whatsapp')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('whatsapp-logo') }}" width="30" alt="{{ __('WhatsApp') }}"> {{ __('WhatsApp') }}
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-4">
                                    <button class="btn btn-outline-info w-100"
                                            type="button"
                                            wire:click="sendCode('telegram')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('telegram-logo') }}" width="30" alt="{{ __('Telegram') }}"> {{ __('Telegram') }}
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-4">
                                    <button class="btn btn-outline-secondary w-100"
                                            type="button"
                                            wire:click="sendCode('sms')"
                                            wire:loading.attr="disabled"
                                            wire:target="sendCode"
                                            @disabled($this->isLocked || $cooldownRemaining > 0)>
                                        <span wire:loading.remove wire:target="sendCode">
                                            <img src="{{ app('sms-logo') }}" width="30" alt="{{ __('SMS') }}"> {{ __('SMS') }}
                                        </span>
                                        <span wire:loading wire:target="sendCode"
                                              class="d-none align-items-center gap-1"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span>
                                        </span>
                                    </button>
                                </div>

                                <div class="col-12 mt-3">
                                    <button class="btn btn-danger w-100" type="button"
                                            wire:click="editPhone"
                                            wire:loading.attr="disabled"
                                            wire:target="editPhone">
                                        <span wire:loading.remove wire:target="editPhone">{{ __('No, let me update it') }}</span>
                                        <span wire:loading wire:target="editPhone"
                                              class="d-none align-items-center gap-2"
                                              wire:loading.class.remove="d-none"
                                              wire:loading.class="d-inline-flex">
                                            <span class="spinner-border spinner-border-sm"></span> {{ __('Opening...') }}
                                        </span>
                                    </button>
                                </div>
                            </div>
                            </div>

                        @elseif ($flag === 2)
                            <div wire:key="phone-otp-state-edit">
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/fikcyfpp.json"
                                            trigger="loop"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3 mb-3">
                                <h5>{{ __('Edit your phone number') }}</h5>
                            </div>

<form wire:submit.prevent="savePhone" id="phone-edit-form">
    <div class="mb-3">
        <label for="phone" class="form-label">{{ __('Phone') }} <span class="text-danger">*</span></label>

        <input type="hidden" id="phone_hidden" wire:model.defer="phone">
        <input type="hidden" id="phone_country_hidden" wire:model.defer="phone_country">
        <input type="hidden" id="phone_dial_code_hidden" wire:model.defer="phone_dial_code">

        <div wire:ignore>
            <input type="tel"
                class="form-control"
                id="phone"
                placeholder="{{ __('phone number') }}"
                inputmode="tel"
                autocomplete="tel"
                dir="ltr"
                required>
        </div>

        @error('phone') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @error('phone_country') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        @error('phone_dial_code') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
        <div id="phone_client_error" class="text-danger small mt-1" style="display:none;"></div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"
                wire:loading.attr="disabled"
                wire:target="savePhone">
            <span wire:loading.remove wire:target="savePhone">{{ __('Save & Choose Provider') }}</span>
            <span wire:loading wire:target="savePhone"
                  class="d-none align-items-center gap-2"
                  wire:loading.class.remove="d-none"
                  wire:loading.class="d-inline-flex">
                <span class="spinner-border spinner-border-sm"></span> {{ __('Saving...') }}
            </span>
        </button>

        <button class="btn btn-secondary" type="button"
                wire:click="goBack"
                wire:loading.attr="disabled"
                wire:target="goBack">
            <span wire:loading.remove wire:target="goBack">{{ __('Cancel') }}</span>
            <span wire:loading wire:target="goBack"
                  class="d-none align-items-center gap-2"
                  wire:loading.class.remove="d-none"
                  wire:loading.class="d-inline-flex">
                <span class="spinner-border spinner-border-sm"></span> {{ __('Closing...') }}
            </span>
        </button>
    </div>
</form>
                            </div>

                        @else
                            <div wire:key="phone-otp-state-code">
                            <div class="mb-4">
                                <div class="avatar-lg mx-auto">
                                    <div class="avatar-title bg-light text-primary display-5 rounded-circle">
                                        <lord-icon
                                            src="https://cdn.lordicon.com/dhzbkemf.json"
                                            trigger="loop"
                                            colors="primary:#cc0022,secondary:#ffffff"
                                            style="width:64px;height:64px">
                                        </lord-icon>
                                    </div>
                                </div>
                            </div>

                            <div class="text-muted text-center mx-lg-3">
                                <h4>{{ __('Please enter the 6-digit code sent to') }} <b>{{ $phone }}</b></h4>

                                <div class="mt-3 d-flex gap-2 justify-content-center flex-wrap">
                                    <span class="badge bg-info">{{ __('Expires in: :time', ['time' => $this->fmt($expiresRemaining)]) }}</span>
                                    <span class="badge bg-warning text-dark">{{ __('Attempts left: :count', ['count' => $attemptsLeft]) }}</span>

                                    @if ($cooldownRemaining > 0)
                                        <span class="badge bg-secondary">{{ __('Cooldown: :time', ['time' => $this->fmt($cooldownRemaining)]) }}</span>
                                    @endif

                                    @if ($this->isExpired)
                                        <span class="badge bg-danger">{{ __('Expired') }}</span>
                                    @endif

                                    @if ($this->isLocked)
                                        <span class="badge bg-danger">{{ __('Locked: :time', ['time' => $this->fmt($lockRemaining)]) }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4">
                                <form autocomplete="off" onsubmit="return false;">
                                    <div class="row">
                                        @foreach (['digit1','digit2','digit3','digit4','digit5','digit6'] as $i => $model)
                                            <div class="col-2">
                                                <div class="mb-3">
                                                    <label for="digit{{ $i+1 }}-input" class="visually-hidden">{{ __('Digit :number', ['number' => $i + 1]) }}</label>
                                                    <input type="text"
                                                           class="form-control form-control-lg bg-light border-light text-center"
                                                           wire:model.defer="{{ $model }}"
                                                           onkeyup="moveToNext({{ $i+1 }}, event)"
                                                           maxlength="1"
                                                           id="digit{{ $i+1 }}-input"
                                                           @disabled($this->inputsDisabled)>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-3">
                                        <button type="button" class="btn btn-success w-100"
                                                wire:click="confirm"
                                                wire:loading.attr="disabled"
                                                wire:target="confirm"
                                                @disabled($this->inputsDisabled)>
                                            <span wire:loading.remove wire:target="confirm">{{ __('Confirm') }}</span>
                                            <span wire:loading wire:target="confirm"
                                                  class="d-none align-items-center gap-2"
                                                  wire:loading.class.remove="d-none"
                                                  wire:loading.class="d-inline-flex">
                                                <span class="spinner-border spinner-border-sm"></span> {{ __('Verifying...') }}
                                            </span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <div class="mt-3 text-center">
                                <p class="mb-0">{{ __('Didn\'t receive a code?') }}
                                    <a href="#"
                                       wire:click.prevent="resend"
                                       wire:loading.attr="disabled"
                                       wire:target="resend"
                                       class="fw-semibold text-primary text-decoration-underline {{ ($cooldownRemaining > 0 || $this->isLocked) ? 'pe-none opacity-50' : '' }}">
                                        {{ $cooldownRemaining > 0 ? __('Resend in :time', ['time' => $this->fmt($cooldownRemaining)]) : __('Resend') }}
                                    </a>
                                </p>
                            </div>
                            </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
window.moveToNext = function (index, e) {
    const id = 'digit' + index + '-input';
    const input = document.getElementById(id);
    if (!input || input.disabled) return;

    const key = e.key || '';
    input.value = input.value.replace(/\D/g, '').slice(0, 1);

    if (/^\d$/.test(input.value)) {
        const next = document.getElementById('digit' + (index + 1) + '-input');
        if (next && !next.disabled) next.focus();
    } else if (key === 'Backspace') {
        const prev = document.getElementById('digit' + (index - 1) + '-input');
        if (prev && !prev.disabled) prev.focus();
    }
};
</script>

@php
    $phoneOtpConfig = [
        'invalidPhoneMessage' => __('Please enter a valid phone number.'),
        'assetErrorMessage' => __('Phone input failed to load. Please refresh and try again.'),
        'allowedCountries' => $allowedPhoneCountries ?? [],
        'preferredCountries' => $preferredPhoneCountries ?? [],
    ];
@endphp
<script>
window.phoneConfig = @json($phoneOtpConfig);
</script>

<script>
(() => {
    const config = window.phoneConfig || {};

    const invalidPhoneMessage = typeof config.invalidPhoneMessage === 'string' && config.invalidPhoneMessage.trim() !== ''
        ? config.invalidPhoneMessage
        : 'Please enter a valid phone number.';
    const assetErrorMessage = typeof config.assetErrorMessage === 'string' && config.assetErrorMessage.trim() !== ''
        ? config.assetErrorMessage
        : 'Phone input failed to load. Please refresh and try again.';
    const allowedCountries = Array.isArray(config.allowedCountries) ? config.allowedCountries : [];
    const preferredCountries = Array.isArray(config.preferredCountries) ? config.preferredCountries : [];

    let initQueued = false;

    async function initPhoneInput() {
        if (!window.MetIntlTelInput) {
            return;
        }

        const initialCountry = document.getElementById('phone_country_hidden')?.value
            || preferredCountries[0]
            || allowedCountries[0]
            || 'iq';

        await window.MetIntlTelInput.init({
            key: 'phone-otp-edit-number',
            inputSelector: '#phone',
            hiddenPhoneSelector: '#phone_hidden',
            hiddenCountrySelector: '#phone_country_hidden',
            hiddenDialCodeSelector: '#phone_dial_code_hidden',
            formSelector: '#phone-edit-form',
            errorSelector: '#phone_client_error',
            invalidMessage: invalidPhoneMessage,
            assetErrorMessage,
            initialCountry,
            onlyCountries: allowedCountries,
            preferredCountries,
        });
    }

    function queueInit() {
        if (initQueued) {
            return;
        }

        initQueued = true;
        requestAnimationFrame(() => {
            initQueued = false;
            initPhoneInput();
        });
    }

    document.addEventListener('DOMContentLoaded', queueInit);
    document.addEventListener('met:intl-tel-input-ready', queueInit);
    document.addEventListener('livewire:navigated', queueInit);
    document.addEventListener('livewire:initialized', queueInit);

    if (window.Livewire && typeof window.Livewire.hook === 'function' && !window.__phoneOtpMorphHookBound) {
        window.__phoneOtpMorphHookBound = true;
        window.Livewire.hook('morphed', () => {
            queueInit();
        });
    }

    queueInit();
})();
</script>
@endpush
