<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $status = Password::broker('customers')->reset(
            [
                'email'                 => $this->email,
                'password'              => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token'                 => $this->token,
            ],
            function ($user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            $this->dispatch('alert', type: 'success', message: __($status));
            $this->reset(['password', 'password_confirmation']);
            $this->redirectRoute('app.signin');
            return;
        }

        $this->addError('email', __($status));
    }
};
?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card mt-4">
            <div class="card-body p-4">
                <div class="text-center mt-2">
                    <h5 class="text-primary">Create new password</h5>
                    <p class="text-muted">Your new password must be different from the previous one.</p>
                </div>

                <div class="p-2">
                    <form wire:submit="resetPassword" novalidate>

                        <input type="hidden" wire:model="token">

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   value="{{ $email }}"
                                   readonly>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       id="rp_password"
                                       wire:model="password"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary password-addon" type="button" aria-label="Toggle password">
                                    <i class="ri-eye-fill align-middle"></i>
                                </button>
                            </div>
                            @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="rp_password_confirmation"
                                       wire:model="password_confirmation"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary password-addon" type="button" aria-label="Toggle confirm password">
                                    <i class="ri-eye-fill align-middle"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div id="password-contain" class="p-3 bg-light mb-3 rounded">
                                <h5 class="fs-13">Password must contain:</h5>
                                <p id="pass-lower"   class="invalid fs-12 mb-2">At least one lowercase letter</p>
                                <p id="pass-upper"   class="invalid fs-12 mb-2">At least one uppercase letter</p>
                                <p id="pass-number"  class="invalid fs-12 mb-2">At least one number</p>
                                <p id="pass-special" class="invalid fs-12 mb-2">At least one special character</p>
                                <p id="pass-length"  class="invalid fs-12 mb-2">At least 8 characters</p>
                                <p id="pass-match"   class="invalid fs-12 mb-0">Passwords match</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                <span wire:loading.remove>Reset Password</span>
                                <span wire:loading>
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    Resetting...
                                </span>
                            </button>
                        </div>

                        <div class="mt-4 text-center">
                            <p class="mb-0">Wait, I remember my password...
                                <a wire:navigate href="{{ route('app.signin') }}" class="fw-semibold text-primary text-decoration-underline">Click here</a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="mt-4 text-center">
            <p class="mb-0">Don't have an account?
                <a wire:navigate href="{{ route('app.signup') }}" class="fw-semibold text-primary text-decoration-underline">Signup</a>
            </p>
        </div>
    </div>
</div>

@push('styles')
<style>
    #password-contain { display:block !important; visibility:visible !important; }
    #password-contain p.valid   { color: #16a34a; }
    #password-contain p.invalid { color: #dc2626; }
</style>
@endpush

@push('scripts')
<script>
(() => {
    // ── Helpers ──────────────────────────────────────────────────────────────────
    function setState(el, ok) {
        if (!el) return;
        el.classList.toggle('valid',   ok);
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
        const pwd     = document.getElementById('rp_password');
        const confirm = document.getElementById('rp_password_confirmation');
        const el      = document.getElementById('pass-match');
        if (!pwd || !confirm || !el) return;
        const bothFilled = pwd.value.length > 0 && confirm.value.length > 0;
        setState(el, bothFilled && pwd.value === confirm.value);
    }

    // ── Eye toggle — same pattern as signin ──────────────────────────────────────
    if (!window.__rpToggleBound) {
        window.__rpToggleBound = true;

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
    if (!window.__rpStrengthBound) {
        window.__rpStrengthBound = true;

        document.addEventListener('input', e => {
            if (e.target?.id === 'rp_password')              applyPasswordRules(e.target.value);
            if (e.target?.id === 'rp_password_confirmation') checkMatch();
        }, true);

        document.addEventListener('livewire:updated', () => {
            const pwd = document.getElementById('rp_password');
            if (pwd) applyPasswordRules(pwd.value);
        });
    }

    function init() {
        const pwd = document.getElementById('rp_password');
        if (pwd) applyPasswordRules(pwd.value);
    }

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
})();
</script>
@endpush