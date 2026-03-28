<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public string $email = '';
    public ?string $status = null;

    public function send(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $status = Password::broker('customers')->sendResetLink(['email' => $this->email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = __($status);
            $this->dispatch('alert', type: 'success', message: $this->status);
            return;
        }

        $this->dispatch('alert', type: 'error', message: __($status));
    }
};

?>

<x-slot:title>{{ __('Forgot Password') }} | {{ __('MET KURD') }}</x-slot:title>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card mt-4">
            <div class="card-body p-4">
                <div class="text-center mt-2">
                    <h5 class="text-primary">{{ __('Reset your password') }}</h5>
                    <p class="text-muted">{{ __('Enter your verified email address and we\'ll send you a reset link.') }}</p>
                </div>

                <div class="p-2">
                    <form wire:submit.prevent="send" novalidate>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Email *') }}</label>
                            <input type="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   wire:model.defer="email"
                                   placeholder="{{ __('youremail@example.com') }}"
                                   autocomplete="email"
                                   required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mt-4">
                            <button class="btn btn-success w-100" type="submit" wire:loading.attr="disabled">
                                <span wire:loading.remove>{{ __('Send reset link') }}</span>
                                <span wire:loading>
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    {{ __('Sending...') }}
                                </span>
                            </button>
                        </div>
                    </form>

                    @if ($status)
                        <div class="alert alert-success mt-3 mb-0">{{ $status }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-4 text-center">
            <p class="mb-0">{{ __('Wait, I remember my password...') }}
                <a wire:navigate href="{{ route('app.signin') }}" class="fw-semibold text-primary text-decoration-underline">{{ __('Sign in') }}</a>
            </p>
        </div>
    </div>
</div>
