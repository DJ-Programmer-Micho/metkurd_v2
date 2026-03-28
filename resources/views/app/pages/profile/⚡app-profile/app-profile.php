<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout('app::layouts.app')]
class extends Component
{
    use WithFileUploads;

    // ---------------------------------------------------------------------
    // Page data (keep in-memory to reduce repeated auth() calls in the view)
    // ---------------------------------------------------------------------
    public $user;
    public $profile;

    // ---------------------------------------------------------------------
    // Edit modal fields
    // ---------------------------------------------------------------------
    public int $avatarVersion = 1;

    public string $fNameEdit = '';
    public string $lNameEdit = '';
    public string $usernameEdit = '';
    public string $functionEdit = '';
    public string $jobTitleEdit = '';
    public string $phoneEdit  = '';
    public string $emailEdit = '';

    public array $jobTitleOptions = [
        'developer' => 'Developer',
        'designer'  => 'Designer',
        'manager'   => 'Manager',
        'other'     => 'Other',
    ];

    // Avatar upload
    public $avatar = null; // TemporaryUploadedFile|null

    // Phone verification flags
    public bool $phoneChanged = false;
    public bool $phoneVerified = true;

    // OTP modal
    public int $otpStep = 0; // 0 provider, 1 code
    public string $channel = 'sms';

    public string $digit1 = '';
    public string $digit2 = '';
    public string $digit3 = '';
    public string $digit4 = '';
    public string $digit5 = '';
    public string $digit6 = '';

    // Password tab
    public string $old_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------
    public function mount(): void
    {
        $this->hydrateUser();
        $this->syncEditFieldsFromUser();
    }

    // Volt-safe (public) helpers
    public function hydrateUser(): void
    {
        // One loadMissing instead of many auth() calls in the Blade
        $this->user = auth('app')->user()?->loadMissing('profile');
        $this->profile = $this->user?->profile;
    }

    public function syncEditFieldsFromUser(): void
    {
        $u = $this->user ?: auth('app')->user();
        $p = $u?->profile;

        $this->fNameEdit     = (string) ($p?->first_name ?? '');
        $this->lNameEdit     = (string) ($p?->last_name ?? '');
        $this->usernameEdit  = (string) ($u?->username ?? '');
        $this->phoneEdit     = (string) ($p?->phone_number ?? '');
        $this->jobTitleEdit  = (string) ($p?->job_title ?? 'other');
        $this->emailEdit     = (string) ($u?->email ?? '');

        $this->phoneChanged  = false;
        $this->phoneVerified = true;

        $this->resetValidation();
        $this->reset('avatar');
    }

    // Called from JS after modal is already opened (instant modal)
    public function prepareEditForm(): void
    {
        $this->hydrateUser();
        $this->syncEditFieldsFromUser();
    }

    public function customerFolderSlug(): string
    {
        $u = $this->user ?: auth('app')->user();
        $p = $u?->profile;

        $name = trim(($p?->first_name ?? '') . ' ' . ($p?->last_name ?? ''));
        $nameSlug = Str::slug($name, '_');

        // rule: customer_id + first_name + last_name (via CustomerFolder slug)
        return $u->customer_id . '_' . $nameSlug;
    }

    public function currentAvatarUrl(): string
    {
        if ($this->avatar) {
            return $this->avatar->temporaryUrl();
        }

        $p = $this->profile ?: auth('app')->user()?->profile;

        $url = $p?->avatar_url ?: app('userImg');

        // cache bust
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $this->avatarVersion;
    }

    // ---------------------------------------------------------------------
    // Edit modal actions (NOTE: modal open is handled instantly in JS)
    // ---------------------------------------------------------------------
    public function updatedPhoneEdit($value): void
    {
        $current = (string) (($this->profile?->phone_number) ?? '');
        $new = trim((string) $value);

        $this->phoneChanged  = ($new !== '' && $new !== $current);
        $this->phoneVerified = !$this->phoneChanged;
    }

    public function updateUser(): void
    {
        $u = $this->user ?: auth('app')->user();
        $p = $u->profile;

        $this->validate([
            'fNameEdit'    => ['required', 'string', 'min:2', 'max:50'],
            'lNameEdit'    => ['required', 'string', 'min:2', 'max:50'],
            'usernameEdit' => [
                'required', 'string', 'min:3', 'max:30',
                Rule::unique('users', 'username')->ignore($u->id),
            ],
            'phoneEdit'    => ['nullable', 'string', 'max:30'],
            'jobTitleEdit' => ['required', 'string'],
            'avatar'       => ['nullable', 'image', 'max:2048'],
        ]);

        if ($this->phoneChanged && !$this->phoneVerified) {
            $this->dispatch('alert', type: 'error', message: __('Phone changed. Please verify before saving.'));
            return;
        }

        $u->username = $this->usernameEdit;
        $u->save();

        $p->first_name   = $this->fNameEdit;
        $p->last_name    = $this->lNameEdit;
        $p->phone_number = $this->phoneEdit;
        $p->job_title    = $this->jobTitleEdit;

        if ($this->avatar) {
            $folder = 'customers/' . $this->customerFolderSlug() . '/avatars';

            // Best-effort delete old avatar if stored under /storage/ (public disk)
            if ($p->avatar_url && str_contains($p->avatar_url, '/storage/')) {
                $oldPath = Str::after($p->avatar_url, '/storage/');
                if ($oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $path = $this->avatar->store($folder, 'public');
            $p->avatar_url = Storage::disk('public')->url($path);
        }

        $p->save();

        // Refresh local state
        $this->hydrateUser();

        // Reset upload + force browser refresh (cache-bust)
        $this->reset('avatar');
        $this->avatarVersion++;

        $this->dispatch('alert', type: 'success', message: __('Profile updated successfully.'));
        $this->dispatch('bs:modal:hide', id: 'updateUserModal');
    }

    // ---------------------------------------------------------------------
    // OTP modal actions (stub hooks - plug your provider logic)
    // ---------------------------------------------------------------------
    public function openPhoneOtpProviders(): void
    {
        $this->otpStep = 0;
        $this->reset(['digit1','digit2','digit3','digit4','digit5','digit6']);
        $this->dispatch('bs:modal:show', id: 'phoneOtpModal');
    }

    public function closePhoneOtpModal(): void
    {
        $this->dispatch('bs:modal:hide', id: 'phoneOtpModal');
    }

    public function sendPhoneOtp(string $channel): void
    {
        $this->channel = $channel;
        // TODO: send OTP via your provider
        $this->otpStep = 1;

        $this->dispatch('alert', type: 'info', message: __('Verification code sent via :channel.', ['channel' => __(ucfirst($channel))]));
    }

    public function backToProviders(): void
    {
        $this->otpStep = 0;
    }

    public function resendPhoneOtp(): void
    {
        // TODO: resend OTP via chosen channel
        $this->dispatch('alert', type: 'info', message: __('Code resent via :channel.', ['channel' => __(ucfirst($this->channel))]));
    }

    public function verifyPhoneOtp(): void
    {
        $code = $this->digit1.$this->digit2.$this->digit3.$this->digit4.$this->digit5.$this->digit6;

        if (strlen($code) !== 6) {
            $this->dispatch('alert', type: 'error', message: __('Please enter the 6-digit code.'));
            return;
        }

        // TODO: verify OTP with provider
        $ok = true;

        if (!$ok) {
            $this->dispatch('alert', type: 'error', message: __('Invalid code. Try again.'));
            return;
        }

        $this->phoneVerified = true;
        $this->phoneChanged = true;

        $this->dispatch('alert', type: 'success', message: __('Phone verified successfully.'));
        $this->dispatch('bs:modal:hide', id: 'phoneOtpModal');
    }

    // ---------------------------------------------------------------------
    // Password update (inline)
    // ---------------------------------------------------------------------
    public function updatePassword(): void
    {
        $this->validate([
            'old_password' => ['required','string'],
            'new_password' => ['required','string','min:8','confirmed'],
        ]);

        $u = auth('app')->user();

        if (!\Illuminate\Support\Facades\Hash::check($this->old_password, $u->password)) {
            $this->addError('old_password', __('Old password is incorrect.'));
            return;
        }

        $u->password = \Illuminate\Support\Facades\Hash::make($this->new_password);
        $u->save();

        $this->reset(['old_password','new_password','new_password_confirmation']);

        $this->dispatch('alert', type: 'success', message: __('Password changed successfully.'));
    }
};
