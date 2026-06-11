{{-- resources/views/app/auth/⚡signin-one.blade.php --}}
<?php

use App\Rules\ValidTurnstile;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $login = '';
    public string $password = '';
    public string $cfTurnstileResponse = '';
    public bool $remember = false;

    public function mount()
    {
        $this->login = old('login', '');
    }

    public function signIn()
    {
        $this->resetErrorBag('form');

        try {
            $this->validate([
                'login' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
                'cfTurnstileResponse' => ['bail', 'required', 'string', new ValidTurnstile()],
                'remember' => ['boolean'],
            ], [
                'cfTurnstileResponse.required' => __('Please complete the human verification challenge.'),
            ]);
        } catch (ValidationException $e) {
            $this->resetTurnstileChallenge();

            throw $e;
        }

        $key = $this->signInRateLimitKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('form', __('Too many sign in attempts. Please wait :seconds seconds and try again.', ['seconds' => $seconds]));
            $this->resetTurnstileChallenge();
            return;
        }

        $credentialsEmail = ['email' => $this->login, 'password' => $this->password];
        $credentialsUser  = ['username' => $this->login, 'password' => $this->password];

        $ok =
            Auth::guard('app')->attempt($credentialsEmail, $this->remember) ||
            Auth::guard('app')->attempt($credentialsUser, $this->remember);

        if (! $ok) {
            RateLimiter::hit($key, 120);
            $this->resetTurnstileChallenge();
            throw ValidationException::withMessages(['login' => __('Invalid credentials.')]);
        }

        RateLimiter::clear($key);
        $this->resetTurnstileChallenge();
        request()->session()->regenerate();

        $user = Auth::guard('app')->user();

        if ($nextVerificationRoute = $user->nextVerificationRouteName()) {
            return redirect()->to(route($nextVerificationRoute));
        }

        $this->dispatch('alert', type: 'success', message: __('Welcome back!'));
        return redirect()->to(route('app.home',['locale' => app()->getLocale()]));
    }

    public function updatedCfTurnstileResponse(): void
    {
        $this->resetValidation('cfTurnstileResponse');
        $this->resetErrorBag('form');
    }

    protected function signInRateLimitKey(): string
    {
        return 'app_login:' . sha1((string) request()->ip() . '|' . strtolower(trim($this->login)));
    }

    protected function resetTurnstileChallenge(): void
    {
        $this->cfTurnstileResponse = '';
        $this->dispatch('turnstile-reset');
    }
};

?>

<x-slot:title>{{ __('Sign In') }} | {{ __('MET KURD') }}</x-slot:title>

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
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="3"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="4"></button>
                                        <button type="button" data-bs-target="#qoutescarouselIndicators" data-bs-slide-to="5"></button>
                                    </div>
                                    <div class="carousel-inner text-center text-white pb-5">
                                        <div class="carousel-item active">
                                            <p class="fs-15 fst-italic">" {{ __('Tip: Add punctuation like (. , …) in Apollo 1.5V for more natural speech.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('For better Kurdish voice cloning, use a clean reference audio with no music, echo, or background noise.') }}"</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('Voice cloning works best when the reference audio is in Kurdish Sorani.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('Tip: For Auto Caption, clear audio gives more accurate SRT files.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('If the voice sounds too fast or unnatural, try adding commas and short pauses in the text.') }} "</p>
                                        </div>
                                        <div class="carousel-item">
                                            <p class="fs-15 fst-italic">" {{ __('MetKurd works better with clean Kurdish text and correct spelling.') }} "</p>
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
                            <h5 class="text-primary">{{ __('Welcome Back!') }}</h5>
                            <p class="text-muted">{{ __('Sign in to continue to') }} <b class="text-danger">{{ __('MET KURD') }}</b>.</p>
                        </div>

                        <div class="mt-4">
                            <form wire:submit.prevent="signIn">
                                @error('form')
                                    <div class="alert alert-danger">{{ $message }}</div>
                                @enderror

                                <div class="mb-3">
                                    <label for="login" class="form-label">{{ __('Email or Username') }}</label>
                                    <input type="text" class="form-control @error('login') is-invalid @enderror"
                                           id="login" wire:model="login" placeholder="{{ __('Enter email or username') }}" autofocus>
                                    @error('login') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="mb-3">
                                    <div class="float-end">
                                        <a wire:navigate href="{{ route('app.password.request') }}" class="text-muted">{{ __('Forgot password?') }}</a>
                                    </div>
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

                                <div class="mt-3">
                                    <x-turnstile-widget model="cfTurnstileResponse" theme="dark" />
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

                                <div class="mt-4 text-center">
                                    <div class="signin-other-title">
                                        <h5 class="fs-13 mb-4 title">{{ __('Sign In with') }}</h5>
                                    </div>

                                    <div>
                                        <a href="{{ route('social.google.redirect') }}" class="btn btn-primary btn-icon waves-effect waves-light" aria-label="{{ __('Sign in with Google') }}">
                                            <i class="ri-google-fill fs-16"></i>
                                        </a>
                                        <a href="{{ route('social.github.redirect') }}" class="btn btn-dark btn-icon waves-effect waves-light" aria-label="{{ __('Sign in with GitHub') }}">
                                            <i class="ri-github-fill fs-16"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="mt-5 text-center">
                            <p class="mb-0">{{ __("Don't have an account?") }}
                                <a wire:navigate href="{{ route('app.signup') }}" class="fw-semibold text-primary text-decoration-underline"> {{ __('Sign Up') }}</a>
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

