{{-- resources/views/app/auth/âš¡signin-one.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

new #[Layout('admin::layouts.app-auth')] class extends Component
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

        $key = 'admin_login:' . request()->ip() . ':' . strtolower($this->login);

        if (RateLimiter::tooManyAttempts($key, 8)) {
            $seconds = RateLimiter::availableIn($key);
            $this->dispatch('alert', type: 'error', message: __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]));
            return;
        }

        $credentialsEmail = ['email' => $this->login, 'password' => $this->password];
        $credentialsUser  = ['username' => $this->login, 'password' => $this->password];

        $ok =
            Auth::guard('admin')->attempt($credentialsEmail, $this->remember) ||
            Auth::guard('admin')->attempt($credentialsUser, $this->remember);

        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => __('Invalid credentials.')]);
        }

        RateLimiter::clear($key);
        request()->session()->regenerate();

        $user = Auth::guard('admin')->user();
        $this->dispatch('alert', type: 'success', message: __('Welcome back!'));
        return redirect()->to(route('admin.home',['locale' => app()->getLocale()]));
    }
};

?>

<div class="row">
    <div class="col-lg-12">
        <div class="card overflow-hidden" style="box-shadow: -12px -6px 45px 10px rgb(44 0 204 / 0.15);">
            <div class="row g-0">
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
                                            <p class="fs-15 fst-italic">{{ __('" Great! Clean code, clean design, easy for customization. Thanks very much! "') }}</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">{{ __('" The theme is really great with an amazing customer support."') }}</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">{{ __('" Great! Clean code, clean design, easy for customization. Thanks very much! "') }}</p>
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
                            <h5 class="text-primary">{{ __('Welcome Back Emp!') }}</h5>
                            <p class="text-muted">{{ __('Sign in to continue to :brand.', ['brand' => 'MET KURD']) }}</p>
                        </div>
                                @php
                                    print(Hash::make('321321321'))
                                @endphp
                        <div class="mt-4">
                            <form wire:submit.prevent="signIn">
                                <div class="mb-3">
                                    <label for="login" class="form-label">{{ __('Email or Username') }}</label>
                                    <input type="text" class="form-control @error('login') is-invalid @enderror"
                                           id="login" wire:model="login" placeholder="{{ __('Enter email or username') }}" autofocus>
                                    @error('login') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="password">{{ __('Password') }}</label>

                                    <div class="input-group">
                                        <input type="password"
                                               class="form-control pe-5 @error('password') is-invalid @enderror"
                                               id="password" wire:model="password" placeholder="{{ __('Enter password') }}">
                                        <button class="btn btn-outline-secondary password-addon" type="button" aria-label="{{ __('Toggle password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember" wire:model="remember">
                                    <label class="form-check-label" for="remember">{{ __('Remember me') }}</label>
                                </div>

                                <div class="mt-4">
                                    <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove>{{ __('Sign In') }}</span>
                                        <span wire:loading>
                                            <span class="spinner-border spinner-border-sm me-2"></span>
                                            {{ __('Signing in...') }}
                                        </span>
                                    </button>
                                </div>
                            </form>
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
