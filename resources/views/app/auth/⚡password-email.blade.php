{{-- resources/views/app/auth/⚡password-email.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Password;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public ?string $status = null;

    public function getEmailProperty(): string
    {
        return auth('app')->user()?->email ?? '';
    }

    public function send(): void
    {
        $email = $this->email;

        if (!$email) {
            $this->dispatch('alert', type: 'error', message: __('No email found for your account.'));
            return;
        }

        // Optional: if you only want verified emails to receive reset links
        // if (!auth('app')->user()?->email_verify) {
        //     $this->dispatch('alert', type: 'error', message: __('Please verify your email first.'));
        //     return;
        // }

        $status = Password::broker('customers')->sendResetLink(['email' => $email]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->status = __($status);
            $this->dispatch('alert', type: 'success', message: $this->status);
            return;
        }

        $this->dispatch('alert', type: 'error', message: __($status));
    }
};

?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card mt-4">
            <div class="card-body p-4">
                <div class="text-center mt-2">
                    <h5 class="text-primary">Send reset link</h5>
                    <p class="text-muted mb-0">
                        We’ll send a password reset link to your email.
                    </p>
                </div>

                <div class="p-2 mt-3">
                    <div class="mb-3">
                        <label class="form-label">Your email</label>
                        <input type="email"
                               class="form-control"
                               value="{{ $this->email }}"
                               disabled>
                    </div>

                    <button class="btn btn-success w-100" wire:click="send" wire:loading.attr="disabled">
                        <span wire:loading.remove>Send reset link</span>
                        <span wire:loading>
                            <span class="spinner-border spinner-border-sm me-2"></span>
                            Sending...
                        </span>
                    </button>

                    @if ($status)
                        <div class="alert alert-success mt-3 mb-0">{{ $status }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-4 text-center">
            <a wire:navigate href="{{ route('app.profile', ['locale' => app()->getLocale()]) }}"
               class="fw-semibold text-primary text-decoration-underline">
                Back to Profile
            </a>
        </div>
    </div>
</div>