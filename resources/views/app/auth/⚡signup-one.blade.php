<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Support\CustomerOnboarding;

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

            // Single source of truth for defaults:
            // wallet, usage, free subscriptions, free monthly credits, etc.
            CustomerOnboarding::provision($customer);

            return $customer->fresh(['profile', 'wallet', 'usage']);
        });

        Auth::guard('app')->login($customer);
        request()->session()->regenerate();

        $this->dispatch('alert', type: 'success', message: 'Account created! Please verify your email.');

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
};
?>

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
                                    <img src="{{ app('logo_1024_tran') }}" alt="" height="25">
                                    MET KURD
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
                                            <p class="fs-15 fst-italic">" Great! Clean code, clean design, easy for customization. Thanks very much! "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" The theme is really great with an amazing customer support."</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" Great! Clean code, clean design, easy for customization. Thanks very much! "</p>
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
                            <h5 class="text-primary">Register Account</h5>
                            <p class="text-muted">Get your Free <b class="text-danger">MET KURD</b> account now.</p>
                        </div>

                        <div class="mt-4">
                            <form wire:submit.prevent="signUp">

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">First name *</label>
                                        <input type="text" class="form-control @error('first_name') is-invalid @enderror" wire:model="first_name">
                                        @error('first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Last name *</label>
                                        <input type="text" class="form-control @error('last_name') is-invalid @enderror" wire:model="last_name">
                                        @error('last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Username *</label>
                                        <input type="text" class="form-control @error('username') is-invalid @enderror" wire:model="username">
                                        @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Job title</label>
                                        <select class="form-select @error('job_title') is-invalid @enderror" wire:model="job_title">
                                            <option value="">Select…</option>
                                            <option value="Student">Student</option>
                                            <option value="Teacher">Teacher</option>
                                            <option value="Developer">Developer</option>
                                        </select>
                                        @error('job_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Phone *</label>
                                    <input type="tel" class="form-control @error('phone_number') is-invalid @enderror"
                                           wire:model="phone_number" placeholder="+9647XXXXXXXX">
                                    @error('phone_number') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" class="form-control @error('email') is-invalid @enderror"
                                           wire:model="email" placeholder="youremail@example.com">
                                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                                            id="password" wire:model="password">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="Toggle password">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Confirm Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control"
                                            id="password_confirmation" wire:model="password_confirmation">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="Toggle confirm password">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                </div>

                                <div id="password-contain" class="p-3 bg-light mb-3 rounded">
                                    <h5 class="fs-13">Password must contain:</h5>
                                    <p id="pass-lower"   class="invalid fs-12 mb-2">At least one lowercase letter</p>
                                    <p id="pass-upper"   class="invalid fs-12 mb-2">At least one uppercase letter</p>
                                    <p id="pass-number"  class="invalid fs-12 mb-2">At least one number</p>
                                    <p id="pass-special" class="invalid fs-12 mb-2">At least one special character</p>
                                    <p id="pass-length"  class="invalid fs-12 mb-2">At least 8 characters</p>
                                    <p id="pass-match"   class="invalid fs-12 mb-0">Passwords match</p>  {{-- ← new --}}
                                </div>

                                <div class="mt-4">
                                    <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove>Sign Up</span>
                                        <span wire:loading>
                                            <span class="spinner-border spinner-border-sm me-2"></span>
                                            Creating...
                                        </span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="mt-5 text-center">
                            <p class="mb-0">Already have an account ?
                                <a wire:navigate.hover href="{{ route('app.signin') }}" class="fw-semibold text-primary text-decoration-underline"> Sign in</a>
                            </p>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
(() => {
    // ── Helpers ──────────────────────────────────────────────────────────────────
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

    // ── Eye toggle — same pattern as signin ──────────────────────────────────────
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
                icon.classList.toggle('ri-eye-fill',     !toText);
                icon.classList.toggle('ri-eye-off-fill',  toText);
            }
        });
    }

    // ── Password strength ────────────────────────────────────────────────────────
    if (!window.__signupStrengthBound) {
        window.__signupStrengthBound = true;

        document.addEventListener('input', e => {
            if (e.target?.id === 'password')              applyPasswordRules(e.target.value);
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
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
})();
</script>
@endpush