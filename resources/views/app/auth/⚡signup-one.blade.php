{{-- resources/views/app/auth/⚡signup-one.blade.php --}}
<?php
use App\Rules\ValidTurnstile;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Support\RegistrationPhoneCountryManager;
use App\Support\TelegramRegistrationNotifier;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $first_name = '';
    public string $last_name = '';
    public string $username = '';
    public string $job_title = '';
    public string $phone_number = '';
    public string $phone_country = '';
    public string $phone_dial_code = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $cfTurnstileResponse = '';
    public bool $accept_terms = false;

    public array $allowedPhoneCountries = [];
    public array $preferredPhoneCountries = [];

    public function mount(): void
    {
        $this->allowedPhoneCountries = RegistrationPhoneCountryManager::enabledCountryCodes();

        if ($this->allowedPhoneCountries === []) {
            $this->allowedPhoneCountries = RegistrationPhoneCountryManager::defaultEnabledCountryCodes();
        }

        $this->preferredPhoneCountries = array_slice($this->allowedPhoneCountries, 0, min(3, count($this->allowedPhoneCountries)));
        $this->phone_country = $this->preferredPhoneCountries[0] ?? $this->allowedPhoneCountries[0] ?? 'iq';
    }

    public function signUp()
    {
        $this->resetErrorBag('form');
        $this->ensureNotRateLimited();
        $this->phone_number = $this->normalizePhone($this->phone_number);
        $this->phone_country = $this->normalizePhoneCountry($this->phone_country);
        $this->phone_dial_code = $this->normalizeDialCode($this->phone_dial_code);

        try {
            $this->validate([
                'first_name' => ['required', 'string', 'max:60'],
                'last_name' => ['required', 'string', 'max:60'],
                'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:customers,username'],
                'job_title' => ['nullable', 'string', 'max:60'],
                'phone_number' => [
                    'required',
                    'string',
                    'max:30',
                    'regex:/^\+\d{10,15}$/',
                    Rule::unique('customer_profiles', 'phone_number'),
                ],
                'phone_country' => ['required', 'string', 'size:2'],
                'phone_dial_code' => ['required', 'string', 'max:4', 'regex:/^\d{1,4}$/'],
                'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
                'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
                'accept_terms' => ['accepted'],
                'cfTurnstileResponse' => ['bail', 'required', 'string', new ValidTurnstile()],
            ], [
                'cfTurnstileResponse.required' => __('Please complete the human verification challenge.'),
                'accept_terms.accepted' => __('You must agree to the Terms & Conditions and Privacy Policy.'),
                'phone_country.required' => __('Please choose your phone country.'),
                'phone_country.size' => __('Please choose a valid phone country.'),
                'phone_dial_code.required' => __('Please choose your phone country code.'),
                'phone_dial_code.regex' => __('Please choose a valid phone country code.'),
            ]);

            if (! RegistrationPhoneCountryManager::isCountryAllowed($this->phone_country)) {
                throw ValidationException::withMessages([
                    'phone_number' => __('Please select a valid phone country.'),
                ]);
            }

            if (! RegistrationPhoneCountryManager::matchesDialCode($this->phone_number, $this->phone_dial_code)) {
                throw ValidationException::withMessages([
                    'phone_number' => __('Phone country code and number do not match.'),
                ]);
            }
        } catch (ValidationException $e) {
            $this->resetTurnstileChallenge();

            throw $e;
        }

        try {
            $customer = DB::transaction(function () {
                $customer = Customer::create([
                    'username'     => trim($this->username),
                    'email'        => strtolower(trim($this->email)),
                    'password'     => Hash::make($this->password),
                    'status'       => 1,
                    'email_verify' => false,
                    'phone_verify' => false,
                ]);

                CustomerProfile::updateOrCreate(
                    ['customer_id' => $customer->id],
                    [
                        'first_name'   => trim($this->first_name),
                        'last_name'    => trim($this->last_name),
                        'job_title'    => $this->job_title !== '' ? trim($this->job_title) : null,
                        'phone_number' => $this->phone_number,
                        'country'      => strtoupper($this->phone_country),
                    ]
                );

                return $customer->fresh(['profile', 'wallet', 'usage']);
            });
        } catch (\Throwable $e) {
            Log::error('Signup failed while provisioning customer defaults.', [
                'email' => strtolower(trim($this->email)),
                'username' => trim($this->username),
                'error' => $e->getMessage(),
            ]);

            $this->addError('form', __('We could not create your account right now. Please try again later.'));
            $this->resetTurnstileChallenge();

            return null;
        }

        TelegramRegistrationNotifier::sendUnverifiedIfNeeded($customer, 'normal_form');

        Auth::guard('app')->login($customer);
        request()->session()->regenerate();
        $this->resetTurnstileChallenge();

        $this->dispatch('alert', type: 'success', message: __('Account created! Please verify your email.'));

        return redirect()->to(route('app.email.otp'));
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

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

    public function updatedCfTurnstileResponse(): void
    {
        $this->resetValidation('cfTurnstileResponse');
        $this->resetErrorBag('form');
    }

    protected function ensureNotRateLimited(): void
    {
        $key = $this->rateLimitKey();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);
            $this->resetTurnstileChallenge();

            throw ValidationException::withMessages([
                'form' => __('Too many account creation attempts were made from your network. Please wait :seconds seconds and try again.', [
                    'seconds' => $seconds,
                ]),
            ]);
        }

        RateLimiter::hit($key, 600);
    }

    protected function rateLimitKey(): string
    {
        return 'app_signup:' . sha1((string) request()->ip());
    }

    protected function resetTurnstileChallenge(): void
    {
        $this->cfTurnstileResponse = '';
        $this->dispatch('turnstile-reset');
    }
};
?>

<x-slot:title>{{ __('Sign Up') }} | {{ __('MET KURD') }}</x-slot:title>

@include('app.auth.partials.intl-tel-input-shared')

@push('styles')
<style>
    #password-contain { display:block !important; visibility:visible !important; }
    #password-contain p.valid { color:#16a34a; }
    #password-contain p.invalid { color:#dc2626; }
</style>
@endpush

<div class="row">
    <div class="col-lg-12">
        <div class="card overflow-hidden m-0" style="box-shadow: -12px -6px 45px 10px rgb(204 0 34 / 0.15);">
            <div class="row justify-content-center g-0">

                <div class="col-lg-6">
                    <div class="p-lg-5 p-4 auth-one-bg h-100">
                        <div class="bg-overlay"></div>
                        <div class="position-relative h-100 d-flex flex-column">
                            <div class="mb-4">
                                <a wire:navigate href="/" class="d-block">
                                    <img src="{{ app('logo_1024_tran_black') }}" alt="" height="25">
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
                                            <p class="fs-15 fst-italic">" {{ __('Great! Clean code, clean design, easy for customization. Thanks very much!') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('The theme is really great with an amazing customer support.') }}"</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('Great! Clean code, clean design, easy for customization. Thanks very much!') }} "</p>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="p-lg-5 p-4">
                        <div>
                            <h5 class="text-primary">{{ __('Register Account') }}</h5>
                            <p class="text-muted">{{ __('Get your Free') }} <b class="text-danger">{{ __('MET KURD') }}</b> {{ __('account now.') }}</p>
                        </div>

                        <div class="mt-4">
                            <form wire:submit.prevent="signUp">
                                @error('form')
                                    <div class="alert alert-danger">{{ $message }}</div>
                                @enderror

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('First name *') }}</label>
                                        <input type="text" class="form-control @error('first_name') is-invalid @enderror" wire:model.defer="first_name">
                                        @error('first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('Last name *') }}</label>
                                        <input type="text" class="form-control @error('last_name') is-invalid @enderror" wire:model.defer="last_name">
                                        @error('last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('Username *') }}</label>
                                        <input type="text" class="form-control @error('username') is-invalid @enderror" wire:model.defer="username">
                                        @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">{{ __('Job title') }}</label>
                                        <select class="form-select @error('job_title') is-invalid @enderror" wire:model.defer="job_title">
                                            <option value="">{{ __('Select...') }}</option>
                                            <option value="Student">{{ __('Student') }}</option>
                                            <option value="Teacher">{{ __('Teacher') }}</option>
                                            <option value="Developer">{{ __('Developer') }}</option>
                                        </select>
                                        @error('job_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('Phone *') }}</label>

                                    <input type="hidden" id="phone_number_hidden" wire:model.defer="phone_number">
                                    <input type="hidden" id="phone_country_hidden" wire:model.defer="phone_country">
                                    <input type="hidden" id="phone_dial_code_hidden" wire:model.defer="phone_dial_code">

                                    <div wire:ignore>
                                        <input
                                            id="phone_number"
                                            type="tel"
                                            class="form-control @error('phone_number') is-invalid @enderror"
                                            placeholder="{{ __('Phone Number') }}"
                                            dir="ltr"
                                            autocomplete="tel"
                                            inputmode="tel"
                                        >
                                    </div>

                                    @error('phone_number') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    @error('phone_country') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    @error('phone_dial_code') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    <div id="phone_number_client_error" class="invalid-feedback d-block" style="display:none;"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('Email *') }}</label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                           wire:model.defer="email" placeholder="{{ __('youremail@example.com') }}">
                                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('Password *') }}</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                            id="password" wire:model.defer="password" autocomplete="password">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="{{ __('Toggle password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">{{ __('Confirm Password *') }}</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control"
                                            id="password_confirmation" wire:model.defer="password_confirmation" autocomplete="password_confirmation">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="{{ __('Toggle confirm password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                </div>

                                <div id="password-contain" class="p-3 bg-light mb-3 rounded">
                                    <h5 class="fs-13">{{ __('Password must contain:') }}</h5>
                                    <p id="pass-lower"   class="invalid fs-12 mb-2">{{ __('At least one lowercase letter') }}</p>
                                    <p id="pass-upper"   class="invalid fs-12 mb-2">{{ __('At least one uppercase letter') }}</p>
                                    <p id="pass-number"  class="invalid fs-12 mb-2">{{ __('At least one number') }}</p>
                                    <p id="pass-special" class="invalid fs-12 mb-2">{{ __('At least one special character') }}</p>
                                    <p id="pass-length"  class="invalid fs-12 mb-2">{{ __('At least 8 characters') }}</p>
                                    <p id="pass-match"   class="invalid fs-12 mb-0">{{ __('Passwords match') }}</p>
                                </div>

                                <div class="mb-3">
                                    <x-turnstile-widget model="cfTurnstileResponse" theme="dark" />
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input @error('accept_terms') is-invalid @enderror"
                                               type="checkbox"
                                               id="accept_terms"
                                               wire:model="accept_terms">
                                        <label class="form-check-label" for="accept_terms">
                                            {!! __('I agree to the <a href=\":terms\" target=\"_blank\" rel=\"noopener noreferrer\">Terms &amp; Conditions</a> and <a href=\":privacy\" target=\"_blank\" rel=\"noopener noreferrer\">Privacy Policy</a>.', ['terms' => route('law.terms'), 'privacy' => route('law.privacy')]) !!}
                                        </label>
                                    </div>
                                    @error('accept_terms') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="mt-4">
                                    <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove>{{ __('Sign Up') }}</span>
                                        <span wire:loading>
                                            <span class="spinner-border spinner-border-sm me-2"></span>
                                            {{ __('Creating...') }}
                                        </span>
                                    </button>
                                </div>

                                <div class="mt-4 text-center">
                                    <div class="signin-other-title">
                                        <h5 class="fs-13 mb-4 title">{{ __('Sign Up with') }}</h5>
                                    </div>

                                    <div>
                                        <a href="{{ route('social.google.redirect') }}" class="btn btn-primary btn-icon waves-effect waves-light" aria-label="{{ __('Sign up with Google') }}">
                                            <i class="ri-google-fill fs-16"></i>
                                        </a>
                                        <a href="{{ route('social.github.redirect') }}" class="btn btn-dark btn-icon waves-effect waves-light" aria-label="{{ __('Sign up with GitHub') }}">
                                            <i class="ri-github-fill fs-16"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="mt-5 text-center">
                            <p class="mb-0">{{ __('Already have an account?') }}
                                <a wire:navigate href="{{ route('app.signin') }}" class="fw-semibold text-primary text-decoration-underline"> {{ __('Sign in') }}</a>
                            </p>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@push('scripts')
@php
    $signupPhoneConfig = [
        'invalidPhoneMessage' => __('Please enter a valid phone number.'),
        'assetErrorMessage' => __('Phone input failed to load. Please refresh and try again.'),
        'allowedCountries' => $allowedPhoneCountries ?? [],
        'preferredCountries' => $preferredPhoneCountries ?? [],
    ];
@endphp
<script>
window.phoneConfig = @json($signupPhoneConfig);
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

    function setState(el, ok) {
        if (!el) return;
        el.classList.toggle('valid', ok);
        el.classList.toggle('invalid', !ok);
    }

    function applyPasswordRules(value) {
        const v = value || '';
        setState(document.getElementById('pass-length'),  v.length >= 8);
        setState(document.getElementById('pass-lower'),   /[a-z]/.test(v));
        setState(document.getElementById('pass-upper'),   /[A-Z]/.test(v));
        setState(document.getElementById('pass-number'),  /\d/.test(v));
        setState(document.getElementById('pass-special'), /[^A-Za-z0-9]/.test(v));
        checkMatch();
    }

    function checkMatch() {
        const pwd = document.getElementById('password');
        const confirm = document.getElementById('password_confirmation');
        const el = document.getElementById('pass-match');

        if (!pwd || !confirm || !el) return;

        const bothFilled = pwd.value.length > 0 && confirm.value.length > 0;
        setState(el, bothFilled && pwd.value === confirm.value);
    }

    async function initPhoneInput() {
        if (!window.MetIntlTelInput) {
            return;
        }

        const initialCountry = document.getElementById('phone_country_hidden')?.value
            || preferredCountries[0]
            || allowedCountries[0]
            || 'iq';

        await window.MetIntlTelInput.init({
            key: 'signup-phone-number',
            inputSelector: '#phone_number',
            hiddenPhoneSelector: '#phone_number_hidden',
            hiddenCountrySelector: '#phone_country_hidden',
            hiddenDialCodeSelector: '#phone_dial_code_hidden',
            formSelector: 'form[wire\\:submit\\.prevent="signUp"]',
            errorSelector: '#phone_number_client_error',
            invalidMessage: invalidPhoneMessage,
            assetErrorMessage,
            initialCountry,
            onlyCountries: allowedCountries,
            preferredCountries,
        });
    }

    if (!window.__signupToggleBound) {
        window.__signupToggleBound = true;

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.password-addon');
            if (!btn) return;

            const input = btn.closest('.input-group')?.querySelector('input');
            const icon = btn.querySelector('i');
            if (!input) return;

            const toText = input.type === 'password';
            input.type = toText ? 'text' : 'password';

            if (icon) {
                icon.classList.toggle('ri-eye-fill', !toText);
                icon.classList.toggle('ri-eye-off-fill', toText);
            }
        });
    }

    if (!window.__signupStrengthBound) {
        window.__signupStrengthBound = true;

        document.addEventListener('input', (e) => {
            if (e.target?.id === 'password') applyPasswordRules(e.target.value);
            if (e.target?.id === 'password_confirmation') checkMatch();
        }, true);

        document.addEventListener('livewire:updated', () => {
            const pwd = document.getElementById('password');
            if (pwd) applyPasswordRules(pwd.value);
        });
    }

    function init() {
        const pwd = document.getElementById('password');
        if (pwd) applyPasswordRules(pwd.value);

        initPhoneInput();
    }

    function queueInit() {
        if (initQueued) {
            return;
        }

        initQueued = true;
        requestAnimationFrame(() => {
            initQueued = false;
            init();
        });
    }

    document.addEventListener('DOMContentLoaded', queueInit);
    document.addEventListener('met:intl-tel-input-ready', queueInit);
    document.addEventListener('livewire:navigated', queueInit);
    document.addEventListener('livewire:initialized', queueInit);

    if (window.Livewire && typeof window.Livewire.hook === 'function' && !window.__signupPhoneMorphHookBound) {
        window.__signupPhoneMorphHookBound = true;
        window.Livewire.hook('morphed', () => {
            queueInit();
        });
    }

    queueInit();
})();
</script>
@endpush
