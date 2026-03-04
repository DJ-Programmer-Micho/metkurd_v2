{{-- resources/views/app/auth/⚡signin-one.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $login = '';
    public string $password = '';
    public bool $remember = false;

    public function mount()
    {
        $this->login = old('login', '');
    }

    public function signIn()
    {
        $this->validate([
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['boolean'],
        ]);

        $key = 'app_login:' . request()->ip() . ':' . strtolower($this->login);

        if (RateLimiter::tooManyAttempts($key, 8)) {
            $seconds = RateLimiter::availableIn($key);
            $this->dispatch('alert', type: 'error', message: "Too many attempts. Try again in {$seconds}s.");
            return;
        }

        $credentialsEmail = ['email' => $this->login, 'password' => $this->password];
        $credentialsUser  = ['username' => $this->login, 'password' => $this->password];

        $ok =
            Auth::guard('app')->attempt($credentialsEmail, $this->remember) ||
            Auth::guard('app')->attempt($credentialsUser, $this->remember);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }

        RateLimiter::clear($key);
        request()->session()->regenerate();

        $user = Auth::guard('app')->user();

        // ✅ Decide if this is a “social” account (no real password usage)
        $isSocial = !empty($user->g_id) || !empty($user->h_id);

        // ✅ Your flow rules
        if (!$isSocial) {
            if (! $user->email_verify) return redirect()->to(route('app.email.otp'));
            if (! $user->phone_verify) return redirect()->to(route('app.phone.otp'));
        } else {
            // Social: only phone must be verified
            if (! $user->phone_verify) return redirect()->to(route('app.phone.otp'));
        }

        $this->dispatch('alert', type: 'success', message: 'Welcome back!');
        return redirect()->to(route('app.home',['locale' => app()->getLocale()]));
    }
};

?>

<div class="row">
    <div class="col-lg-12">
        <div class="card overflow-hidden" style="box-shadow: -12px -6px 45px 10px rgb(204 0 34 / 0.15);">
            <div class="row g-0">
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
                            <h5 class="text-primary">Welcome Back !</h5>
                            <p class="text-muted">Sign in to continue to <b class="text-danger">MET KURD</b>.</p>
                        </div>

                        <div class="mt-4">
                            <form wire:submit.prevent="signIn">
                                <div class="mb-3">
                                    <label for="login" class="form-label">Email or Username</label>
                                    <input type="text" class="form-control @error('login') is-invalid @enderror"
                                           id="login" wire:model="login" placeholder="Enter email or username" autofocus>
                                    @error('login') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <div class="float-end">
                                        <a wire:navigate href="{{ route('app.password.request') }}" class="text-muted">Forgot password?</a>
                                    </div>
                                    <label class="form-label" for="password">Password</label>

                                    <div class="input-group">
                                        <input type="password"
                                               class="form-control pe-5 @error('password') is-invalid @enderror"
                                               id="password" wire:model="password" placeholder="Enter password">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="Toggle password">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember" wire:model="remember">
                                    <label class="form-check-label" for="remember">Remember me</label>
                                </div>

                                <div class="mt-4">
                                    <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove>Sign In</span>
                                        <span wire:loading>
                                            <span class="spinner-border spinner-border-sm me-2"></span>
                                            Signing in...
                                        </span>
                                    </button>
                                </div>

                                <div class="mt-4 text-center">
                                    <div class="signin-other-title">
                                        <h5 class="fs-13 mb-4 title">Sign In with</h5>
                                    </div>

                                    <div>
                                        <a href="{{ route('social.google.redirect') }}" class="btn btn-primary btn-icon waves-effect waves-light" aria-label="Sign in with Google">
                                            <i class="ri-google-fill fs-16"></i>
                                        </a>
                                        <a href="{{ route('social.github.redirect') }}" class="btn btn-dark btn-icon waves-effect waves-light" aria-label="Sign in with GitHub">
                                            <i class="ri-github-fill fs-16"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="mt-5 text-center">
                            <p class="mb-0">Don't have an account ?
                                <a wire:navigate.hover href="{{ route('app.signup') }}" class="fw-semibold text-primary text-decoration-underline"> Signup</a>
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
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
</script>