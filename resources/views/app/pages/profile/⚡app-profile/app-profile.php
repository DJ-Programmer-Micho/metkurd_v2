<?php

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Support\AvatarFallbackUrl;
use App\Support\RegistrationPhoneCountryManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('app::layouts.app')]
class extends Component
{
    use WithFileUploads;

    public ?Customer $user = null;

    public ?CustomerProfile $profile = null;

    public int $avatarVersion = 1;

    public array $allowedPhoneCountries = [];

    public array $preferredPhoneCountries = [];

    public string $firstName = '';

    public string $lastName = '';

    public string $username = '';

    public string $jobTitle = '';

    public string $phoneNumber = '';

    public string $phoneCountry = '';

    public string $phoneDialCode = '';

    public string $emailAddress = '';

    public mixed $avatar = null;

    public ?string $avatarPreviewUrl = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $this->allowedPhoneCountries = RegistrationPhoneCountryManager::enabledCountryCodes();

        if ($this->allowedPhoneCountries === []) {
            $this->allowedPhoneCountries = RegistrationPhoneCountryManager::defaultEnabledCountryCodes();
        }

        $this->preferredPhoneCountries = array_slice(
            $this->allowedPhoneCountries,
            0,
            min(3, count($this->allowedPhoneCountries))
        );

        $this->hydrateUser();
        $this->syncProfileFormFromUser();

        $statusMessage = trim((string) session()->pull('profile_status_message', ''));

        if ($statusMessage !== '') {
            $this->dispatch('alert', type: 'success', message: $statusMessage);
        }
    }

    public function hydrateUser(): void
    {
        $this->user = auth('app')->user()?->loadMissing([
            'profile',
            'usage',
            'wallet',
            'activeServiceSubscription.servicePlan',
            'activeStorageSubscription.storagePlan',
        ]);

        $this->profile = $this->user?->profile;
    }

    public function syncProfileFormFromUser(): void
    {
        $user = $this->user ?: auth('app')->user();
        $profile = $user?->profile;

        $this->firstName = trim((string) ($profile?->first_name ?? ''));
        $this->lastName = trim((string) ($profile?->last_name ?? ''));
        $this->username = trim((string) ($user?->username ?? ''));
        $this->jobTitle = trim((string) ($profile?->job_title ?? ''));
        $this->phoneNumber = trim((string) ($profile?->phone_number ?? ''));
        $this->phoneCountry = RegistrationPhoneCountryManager::normalizeIso2(
            (string) ($profile?->country ?? '')
        ) ?: ($this->preferredPhoneCountries[0] ?? $this->allowedPhoneCountries[0] ?? 'iq');
        $this->phoneDialCode = '';
        $this->emailAddress = trim((string) ($user?->email ?? ''));

        $this->resetValidation();
        $this->resetErrorBag();
        $this->reset('avatar');
        $this->avatarPreviewUrl = null;
    }

    #[Computed]
    public function displayName(): string
    {
        $displayName = trim($this->firstName . ' ' . $this->lastName);

        return $displayName !== '' ? $displayName : (string) ($this->user?->username ?? __('Customer'));
    }

    #[Computed]
    public function serviceState(): array
    {
        return $this->user instanceof Customer ? $this->user->servicePlanState() : [];
    }

    #[Computed]
    public function storageState(): array
    {
        return $this->user instanceof Customer ? $this->user->storageQuotaState() : [];
    }

    #[Computed]
    public function phoneRequiresVerification(): bool
    {
        $current = $this->normalizePhone((string) ($this->profile?->phone_number ?? ''));
        $pending = $this->normalizePhone($this->phoneNumber);

        return $pending !== '' && $pending !== $current;
    }

    public function currentAvatarUrl(): string
    {
        if (is_string($this->avatarPreviewUrl) && trim($this->avatarPreviewUrl) !== '') {
            return $this->avatarPreviewUrl;
        }

        $url = (string) ($this->profile?->avatar_url ?: app(AvatarFallbackUrl::class)->customer());

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $this->avatarVersion;
    }

    public function updatedAvatar(): void
    {
        try {
            $this->avatarPreviewUrl = $this->avatar?->temporaryUrl();
        } catch (\Throwable) {
            // Keep existing avatar UI stable when the uploaded file is invalid/non-previewable.
            $this->avatarPreviewUrl = null;
        }
    }

    public function saveProfile()
    {
        $this->hydrateUser();

        $user = $this->user ?: auth('app')->user();

        if (! $user instanceof Customer) {
            abort(403);
        }

        $profile = $this->profileRecord($user);
        $originalPhone = $this->normalizePhone((string) ($profile->phone_number ?? ''));

        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);
        $this->username = trim($this->username);
        $this->jobTitle = trim($this->jobTitle);
        $this->phoneNumber = $this->normalizePhone($this->phoneNumber);
        $this->phoneCountry = $this->normalizePhoneCountry($this->phoneCountry);
        $this->phoneDialCode = $this->normalizeDialCode($this->phoneDialCode);

        $this->validate($this->profileRules($user, $profile), [
            'phoneCountry.required' => __('Please choose your phone country.'),
            'phoneCountry.size' => __('Please choose a valid phone country.'),
            'phoneDialCode.required' => __('Please choose your phone country code.'),
            'phoneDialCode.regex' => __('Please choose a valid phone country code.'),
        ]);

        if (! RegistrationPhoneCountryManager::isCountryAllowed($this->phoneCountry)) {
            throw ValidationException::withMessages([
                'phoneNumber' => __('Please select a valid phone country.'),
            ]);
        }

        if (! RegistrationPhoneCountryManager::matchesDialCode($this->phoneNumber, $this->phoneDialCode)) {
            throw ValidationException::withMessages([
                'phoneNumber' => __('Phone country code and number do not match.'),
            ]);
        }

        $phoneChanged = $this->phoneNumber !== '' && $this->phoneNumber !== $originalPhone;
        $oldAvatar = (string) ($profile->avatar ?? '');

        $user->username = $this->username;
        $user->save();

        $profile->fill([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'job_title' => $this->jobTitle !== '' ? $this->jobTitle : null,
            'phone_number' => $this->phoneNumber,
            'country' => strtoupper($this->phoneCountry),
        ]);

        if ($this->avatar) {
            $folder = 'customers/' . $this->customerFolderSlug($user) . '/avatars';
            $this->deleteStoredAvatar($oldAvatar);
            $profile->avatar = $this->avatar->storePublicly($folder, 's3');
        }

        $profile->save();

        if (! $user->relationLoaded('profile')) {
            $user->setRelation('profile', $profile);
        }

        if ($phoneChanged) {
            $user->forceFill([
                'phone_verify' => false,
                'phone_verified_at' => null,
                'phone_otp_number' => null,
            ])->save();

            $this->hydrateUser();
            $this->syncProfileFormFromUser();
            $this->avatarVersion++;

            return $this->redirectToPhoneVerification(__('Your new phone number was saved. Please verify it to continue.'));
        }

        $this->hydrateUser();
        $this->syncProfileFormFromUser();
        $this->avatarVersion++;

        $this->dispatch('alert', type: 'success', message: __('Your profile details were updated successfully.'));

        return null;
    }

    public function redirectToPhoneVerification(?string $message = null)
    {
        $this->hydrateUser();

        $user = $this->user ?: auth('app')->user();

        if (! $user instanceof Customer) {
            abort(403);
        }

        session()->put('phone_verification_return_url', route('app.profile', ['locale' => app()->getLocale()]));

        if ($message !== null && trim($message) !== '') {
            session()->flash('phone_verification_notice', $message);
        }

        return redirect()->to(route('app.phone.otp'));
    }

    public function updatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'same:newPasswordConfirmation', Password::min(8)->mixedCase()->numbers()->symbols()],
            'newPasswordConfirmation' => ['required', 'string'],
        ]);

        $user = auth('app')->user();

        if (! $user instanceof Customer) {
            abort(403);
        }

        if (! Hash::check($this->currentPassword, (string) $user->password)) {
            $this->addError('currentPassword', __('Current password is incorrect.'));

            return;
        }

        if (Hash::check($this->newPassword, (string) $user->password)) {
            $this->addError('newPassword', __('Choose a password different from your current password.'));

            return;
        }

        $user->password = $this->newPassword;
        $user->save();

        $this->reset([
            'currentPassword',
            'newPassword',
            'newPasswordConfirmation',
        ]);

        $this->resetValidation([
            'currentPassword',
            'newPassword',
            'newPasswordConfirmation',
        ]);

        $this->dispatch('alert', type: 'success', message: __('Your password was changed successfully.'));
    }

    public function formatDateTime(mixed $value, string $format = 'Y-m-d H:i'): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            $date = $value instanceof Carbon ? $value : Carbon::parse((string) $value);

            return $date->timezone(config('app.timezone'))->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function profileRules(Customer $user, CustomerProfile $profile): array
    {
        return [
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
            'username' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('customers', 'username')->ignore($user->id),
            ],
            'jobTitle' => ['nullable', 'string', 'max:60'],
            'phoneNumber' => [
                'required',
                'string',
                'max:30',
                'regex:/^\+\d{10,15}$/',
                Rule::unique('customer_profiles', 'phone_number')->ignore($profile->id),
            ],
            'phoneCountry' => ['required', 'string', 'size:2'],
            'phoneDialCode' => ['required', 'string', 'max:4', 'regex:/^\d{1,4}$/'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    protected function profileRecord(Customer $user): CustomerProfile
    {
        if ($user->profile instanceof CustomerProfile) {
            return $user->profile;
        }

        return CustomerProfile::firstOrNew([
            'customer_id' => $user->id,
        ]);
    }

    protected function customerFolderSlug(Customer $user): string
    {
        $nameSlug = Str::slug(trim($this->displayName), '_');

        return $user->id . ($nameSlug !== '' ? '_' . $nameSlug : '');
    }

    protected function deleteStoredAvatar(?string $storedAvatar): void
    {
        $storedAvatar = trim((string) $storedAvatar);

        if ($storedAvatar === '' || Str::startsWith($storedAvatar, ['http://', 'https://', 'data:'])) {
            return;
        }

        $path = $storedAvatar;

        if (Str::startsWith($path, ['/storage/', 'storage/'])) {
            $path = Str::after(ltrim($path, '/'), 'storage/');
        }

        $path = ltrim($path, '/');

        if ($path !== '') {
            Storage::disk('s3')->delete($path);
            Storage::disk('public')->delete($path);
        }
    }

    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (str_starts_with((string) $digits, '00')) {
            $digits = substr((string) $digits, 2);
        }

        return $digits ? '+' . $digits : '';
    }

    protected function normalizePhoneCountry(?string $country): string
    {
        return RegistrationPhoneCountryManager::normalizeIso2($country);
    }

    protected function normalizeDialCode(?string $dialCode): string
    {
        return RegistrationPhoneCountryManager::normalizeDialCode($dialCode);
    }
};
