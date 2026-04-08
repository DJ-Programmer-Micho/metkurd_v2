{{-- resources/views/app/auth/⚡signup-one.blade.php --}}
<?php
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Notifications\Landing\TelegramNewRegister;
use Stevebauman\Location\Facades\Location;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $first_name = '';
    public string $last_name = '';
    public string $username = '';
    public string $job_title = '';
    public string $phone_number = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function signUp()
    {
        
        $this->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name'  => ['required', 'string', 'max:60'],
            'username'   => ['required', 'string', 'max:50', 'alpha_dash', 'unique:customers,username'],
            'job_title'  => ['nullable', 'string', 'max:60'],

            'phone_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^\+\d{10,15}$/',
                Rule::unique('customer_profiles', 'phone_number'),
            ],

            'email'    => ['required', 'email', 'max:255', 'unique:customers,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

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
                        'phone_number' => $this->normalizePhone($this->phone_number),
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

            $this->dispatch(
                'alert',
                type: 'error',
                message: __('We could not create your account right now. Please try again later.')
            );

            return null;
        }

        $this->sendTelegramRegistrationNotification();

        Auth::guard('app')->login($customer);
        request()->session()->regenerate();

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

    private function sendTelegramRegistrationNotification(): void
    {
        $teleId = trim((string) env('TELEGRAM_GROUP_REG'));

        if ($teleId === '') {
            return;
        }

        $guestIdentifier = request()->ip();
        $deviceIdentifier = (string) request()->userAgent();
        $location = $this->resolveLocation($guestIdentifier);

        try {
            Notification::route('telegram', $teleId)->notify(
                new TelegramNewRegister(
                    trim($this->first_name . ' ' . $this->last_name),
                    trim($this->username),
                    strtolower(trim($this->email)),
                    $this->job_title !== '' ? trim($this->job_title) : '',
                    $this->normalizePhone($this->phone_number),
                    $location,
                    $guestIdentifier,
                    $deviceIdentifier,
                    $teleId
                )
            );
        } catch (\Throwable $e) {
            Log::warning('Signup telegram notification failed.', [
                'error' => $e->getMessage(),
                'email' => $this->email,
                'username' => $this->username,
                'ip' => $guestIdentifier,
            ]);
        }
    }

    private function resolveLocation(?string $ip): mixed
    {
        if (! $ip) {
            return null;
        }

        try {
            $location = Location::get($ip);

            return $location === false ? null : $location;
        } catch (\Throwable $e) {
            Log::warning('Signup location lookup failed.', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
};
?>

<x-slot:title>{{ __('Sign Up') }} | {{ __('MET KURD') }}</x-slot:title>

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/css/intlTelInput.css">

<style>
    #password-contain { display:block !important; visibility:visible !important; }
    #password-contain p.valid { color:#16a34a; }
    #password-contain p.invalid { color:#dc2626; }

    .iti {
        width: 100%;
    }

    .iti input {
        width: 100%;
    }  
</style>
<style>
    .iti {
        width: 100%;
        display: block;
        z-index: 9999;
    }

    .iti input {
        width: 100% !important;
    }

    /* Dark dropdown shell */
    .iti__dropdown-content {
        background: #111827 !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        border-radius: 14px !important;
        box-shadow: 0 18px 40px rgba(0,0,0,0.45) !important;
        color: #e5e7eb !important;
    }
    .iti .iti__selected-dial-code {
        margin-right: 4px;
    }
    /* Country list area */
    .iti__country-list {
        background: #111827 !important;
        color: #e5e7eb !important;
    }

    /* Each country row */
    .iti__country {
        padding: 10px 12px !important;
        transition: background-color .18s ease, color .18s ease;
    }

    .iti__country:hover {
        background: rgba(255,255,255,0.06) !important;
    }

    /* Highlighted / active row */
    .iti__country.iti__highlight,
    .iti__country.iti__active {
        background: rgba(204, 0, 34, 0.18) !important;
        color: #ffffff !important;
    }

    /* Country name */
    .iti__country-name {
        color: #f3f4f6 !important;
    }

    /* Dial code */
    .iti__dial-code {
        color: #9ca3af !important;
    }

    .iti__country.iti__highlight .iti__dial-code,
    .iti__country:hover .iti__dial-code {
        color: #d1d5db !important;
    }

    /* Search box wrapper */
    .iti__search-input {
        background: #0f172a !important;
        border: 1px solid rgba(255,255,255,0.08) !important;
        color: #f9fafb !important;
        border-radius: 10px !important;
        padding: 10px 12px !important;
        outline: none !important;
        box-shadow: none !important;
    }

    .iti__search-input::placeholder {
        color: #6b7280 !important;
    }

    .iti__search-input:focus {
        border-color: rgba(204, 0, 34, 0.55) !important;
        box-shadow: 0 0 0 3px rgba(204, 0, 34, 0.15) !important;
    }

    /* Selected flag button area */
    .iti__selected-country {
        background: #1f2937 !important;
        border-right: 1px solid rgba(255,255,255,0.06);
    }

    .iti__selected-country:hover {
        background: #243041 !important;
    }

    /* Arrow color */
    .iti__arrow {
        border-top-color: #d1d5db !important;
    }

    /* Scrollbar */
    .iti__country-list::-webkit-scrollbar {
        width: 10px;
    }

    .iti__country-list::-webkit-scrollbar-track {
        background: #0b1220;
    }

    .iti__country-list::-webkit-scrollbar-thumb {
        background: #374151;
        border-radius: 999px;
    }

    .iti__country-list::-webkit-scrollbar-thumb:hover {
        background: #4b5563;
    }
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

                                <div class="mt-4">
                                    <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove>{{ __('Sign Up') }}</span>
                                        <span wire:loading>
                                            <span class="spinner-border spinner-border-sm me-2"></span>
                                            {{ __('Creating...') }}
                                        </span>
                                    </button>
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
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/js/intlTelInput.min.js"></script>
<script>
(() => {
    let iti = null;

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
        const pwd     = document.getElementById('password');
        const confirm = document.getElementById('password_confirmation');
        const el      = document.getElementById('pass-match');
        if (!pwd || !confirm || !el) return;
        const bothFilled = pwd.value.length > 0 && confirm.value.length > 0;
        setState(el, bothFilled && pwd.value === confirm.value);
    }

    function showPhoneClientError(message = '') {
        const el = document.getElementById('phone_number_client_error');
        if (!el) return;

        if (message) {
            el.textContent = message;
            el.style.display = 'block';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function syncPhoneValue({ validate = false } = {}) {
        const input = document.getElementById('phone_number');
        const hidden = document.getElementById('phone_number_hidden');

        if (!input || !hidden || !iti) return false;

        const rawValue = input.value.trim();
        const fullNumber = iti.getNumber() || '';

        if (!rawValue) {
            hidden.value = '';
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
            showPhoneClientError('');
            return false;
        }

        // do not aggressively fail while user is still typing
        if (validate) {
            const utilsReady = typeof window.intlTelInputUtils !== 'undefined';

            if (utilsReady && typeof iti.isValidNumber === 'function' && !iti.isValidNumber()) {
                hidden.value = '';
                hidden.dispatchEvent(new Event('input', { bubbles: true }));
                showPhoneClientError('{{ __("Please enter a valid phone number.") }}');
                return false;
            }
        }

        if (fullNumber) {
            hidden.value = fullNumber;
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        }

        showPhoneClientError('');
        return true;
    }

    function initPhoneInput() {
        const input = document.getElementById('phone_number');
        const hidden = document.getElementById('phone_number_hidden');

        if (!input || !hidden || typeof window.intlTelInput === 'undefined') return;

        if (input.dataset.itiInitialized === 'true') {
            if (iti) syncPhoneValue();
            return;
        }

        iti = window.intlTelInput(input, {
            initialCountry: 'iq',
            preferredCountries: ['iq', 'de', 'us'],
            onlyCountries: ['iq', 'tr', 'us', 'de', 'ir', 'fr', 'se', 'at', 'be', 'dk', 'it', 'nl', 'es', 'ch', 'gb', 'ax', 'au', 'ca'],
            nationalMode: false,
            separateDialCode: true,
            autoPlaceholder: 'polite',
            formatAsYouType: true,
            strictMode: false,
            loadUtils: () => import('https://cdn.jsdelivr.net/npm/intl-tel-input@26.9.1/build/js/utils.js'),
        });

        input.dataset.itiInitialized = 'true';

        if (hidden.value) {
            try {
                iti.setNumber(hidden.value);
            } catch (e) {}
        }

        input.addEventListener('input', () => syncPhoneValue());
        input.addEventListener('blur', () => syncPhoneValue({ validate: true }));
        input.addEventListener('countrychange', () => syncPhoneValue({ validate: true }));

        syncPhoneValue();
    }

    function bindFormSubmit() {
        const form = document.querySelector('form[wire\\:submit\\.prevent="signUp"]');
        if (!form || form.dataset.phoneSubmitBound === 'true') return;

        form.addEventListener('submit', (e) => {
            const ok = syncPhoneValue({ validate: true });
            if (!ok) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        form.dataset.phoneSubmitBound = 'true';
    }

    if (!window.__signupToggleBound) {
        window.__signupToggleBound = true;

        document.addEventListener('click', e => {
            const btn = e.target.closest('.password-addon');
            if (!btn) return;

            const input = btn.closest('.input-group')?.querySelector('input');
            const icon  = btn.querySelector('i');
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

        document.addEventListener('input', e => {
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
        bindFormSubmit();
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
    document.addEventListener('livewire:initialized', init);

    if (window.Livewire && typeof window.Livewire.hook === 'function' && !window.__signupPhoneMorphHookBound) {
        window.__signupPhoneMorphHookBound = true;
        window.Livewire.hook('morphed', () => {
            requestAnimationFrame(init);
        });
    }

    init();
})();
</script>
@endpush
