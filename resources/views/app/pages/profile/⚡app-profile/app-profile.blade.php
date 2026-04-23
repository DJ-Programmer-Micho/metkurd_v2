@include('app.auth.partials.intl-tel-input-shared')

<div>
    <x-slot:title>{{ __('Profile') }} | {{ __('MET KURD') }}</x-slot:title>

    @php
        $serviceState = $this->serviceState;
        $storageState = $this->storageState;
        $servicePlan = data_get($serviceState, 'current_plan');
        $storagePlan = data_get($storageState, 'current_plan');
        $servicePeriodEnd = $this->formatDateTime(data_get($serviceState, 'period_ends_at'));
        $storagePeriodEnd = $this->formatDateTime(data_get($storageState, 'period_ends_at'));
        $joinedAt = $this->formatDateTime($user?->created_at);
        $fallbackAvatarUrl = app(\App\Support\AvatarFallbackUrl::class)->customer();
    @endphp

    @push('styles')
        <style>
            .profile-wid-bg::before {
                content: "";
                position: absolute;
                inset: 0;
                opacity: .78;
                background: linear-gradient(180deg, rgba(204, 0, 34, .74), rgba(12, 18, 34, .92));
            }

            .profile-summary-card {
                margin-top: 0px;
                position: relative;
                z-index: 2;
            }

            .profile-avatar-preview {
                width: 96px;
                height: 96px;
                object-fit: cover;
                border-radius: 999px;
                border: 4px solid rgba(255, 255, 255, .08);
                box-shadow: 0 20px 35px rgba(0, 0, 0, .28);
            }

            .profile-sidebar-label {
                font-size: .76rem;
                letter-spacing: .08em;
                text-transform: uppercase;
                color: rgba(255, 255, 255, .56);
            }

            .profile-helper-card {
                border: 1px solid rgba(255, 255, 255, .06);
                background: rgba(255, 255, 255, .02);
            }

            .profile-form-card .card-header,
            .password-form-card .card-header {
                background: rgba(255, 255, 255, .03);
                border-bottom: 1px solid rgba(255, 255, 255, .06);
            }

            .password-rule-list p.valid {
                color: #22c55e;
            }

            .password-rule-list p.invalid {
                color: #f87171;
            }

            .readonly-field {
                background: rgba(255, 255, 255, .03) !important;
                border-color: rgba(255, 255, 255, .07) !important;
                color: rgba(255, 255, 255, .7) !important;
            }
        </style>
    @endpush

    <div class="container-fluid">
        <div class="profile-foreground position-relative mx-n4 mt-n4">
            <div class="profile-wid-bg">
                <img
                    src="https://images.pexels.com/photos/3389614/pexels-photo-3389614.jpeg"
                    alt=""
                    class="profile-wid-img"
                />
            </div>
        </div>

        <div class="row g-4 pb-4">
            <div class="col-xxl-3">
                <div class="card profile-summary-card overflow-hidden">
                    <div class="card-body text-center p-4">
                        <img
                            src="{{ $this->currentAvatarUrl() }}"
                            alt="{{ $this->displayName }}"
                            class="profile-avatar-preview mb-3"
                            onerror="this.onerror=null;this.src='{{ e($fallbackAvatarUrl) }}';"
                        >

                        <h3 class="mb-1">{{ $this->displayName }}</h3>

                        <p class="text-muted mb-3">
                            {{ $jobTitle !== '' ? $jobTitle : __('No job title added yet') }}
                        </p>

                        <div class="d-flex justify-content-center flex-wrap gap-2 mb-3">
                            <span class="badge bg-{{ $user?->email_verify ? 'success' : 'warning' }}-subtle text-{{ $user?->email_verify ? 'success' : 'warning' }}">
                                {{ $user?->email_verify ? __('Email Verified') : __('Email Pending') }}
                            </span>
                            <span class="badge bg-{{ $user?->phone_verify ? 'success' : 'warning' }}-subtle text-{{ $user?->phone_verify ? 'success' : 'warning' }}">
                                {{ $user?->phone_verify ? __('Phone Verified') : __('Phone Pending') }}
                            </span>
                        </div>

                        <div class="border-top border-secondary-subtle pt-3 text-start">
                            <div class="mb-3">
                                <div class="profile-sidebar-label">{{ __('Username') }}</div>
                                <div class="fw-semibold">{{ $user?->username ?? __('Not set') }}</div>
                            </div>

                            <div class="mb-3">
                                <div class="profile-sidebar-label">{{ __('Email') }}</div>
                                <div class="fw-semibold text-break">{{ $user?->email ?? __('Not set') }}</div>
                            </div>

                            <div class="mb-3">
                                <div class="profile-sidebar-label">{{ __('Phone') }}</div>
                                <div class="fw-semibold">{{ $profile?->phone_number ?: __('Not set') }}</div>
                            </div>

                            <div>
                                <div class="profile-sidebar-label">{{ __('Joined') }}</div>
                                <div class="fw-semibold">{{ $joinedAt ?: __('Unknown') }}</div>
                                <div class="text-muted small">{{ __('Baghdad Time (GMT+3)') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card profile-helper-card">
                    <div class="card-body">
                        <h5 class="card-title mb-3">{{ __('Current Billing') }}</h5>

                        <div class="mb-3">
                            <div class="profile-sidebar-label">{{ __('Main Plan') }}</div>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="fw-semibold">{{ data_get($servicePlan, 'name', __('Free')) }}</span>
                                @if (data_get($serviceState, 'cancellation_scheduled'))
                                    <span class="badge bg-warning-subtle text-warning">{{ __('Scheduled') }}</span>
                                @elseif (data_get($serviceState, 'has_active_paid_main_plan'))
                                    <span class="badge bg-success-subtle text-success">{{ __('Active') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('Free') }}</span>
                                @endif
                            </div>
                            <div class="text-muted small mt-1">
                                @if ($servicePeriodEnd)
                                    {{ data_get($serviceState, 'cancellation_scheduled')
                                        ? __('Access remains until :date', ['date' => $servicePeriodEnd])
                                        : __('Current cycle ends on :date', ['date' => $servicePeriodEnd]) }}
                                @else
                                    {{ __('No paid plan renewal is scheduled right now.') }}
                                @endif
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="profile-sidebar-label">{{ __('Storage Plan') }}</div>
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <span class="fw-semibold">{{ data_get($storagePlan, 'name', __('Free Storage')) }}</span>
                                @if (data_get($storageState, 'cancellation_scheduled'))
                                    <span class="badge bg-warning-subtle text-warning">{{ __('Scheduled') }}</span>
                                @elseif (data_get($storageState, 'has_paid_storage_plan'))
                                    <span class="badge bg-info-subtle text-info">{{ __('Active') }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">{{ __('Default') }}</span>
                                @endif
                            </div>
                            <div class="text-muted small mt-1">
                                @if ($storagePeriodEnd)
                                    {{ data_get($storageState, 'cancellation_scheduled')
                                        ? __('Storage stays active until :date', ['date' => $storagePeriodEnd])
                                        : __('Current storage cycle ends on :date', ['date' => $storagePeriodEnd]) }}
                                @else
                                    {{ __('No storage renewal boundary is scheduled right now.') }}
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="profile-sidebar-label">{{ __('Usage') }}</div>
                            <div class="fw-semibold">
                                {{ __(':used / :total', [
                                    'used' => number_format((int) data_get($storageState, 'used_mb', 0)) . ' MB',
                                    'total' => number_format((int) data_get($storageState, 'current_limit_mb', 512)) . ' MB',
                                ]) }}
                            </div>
                            <div class="text-muted small mt-1">
                                {{ __('Credits balance and detailed payment activity are available on the billing page.') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-9">
                <div class="card profile-form-card mb-4">
                    <div class="card-header p-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <h4 class="card-title mb-1">{{ __('Personal Details') }}</h4>
                                <p class="text-muted mb-0">
                                    {{ __('Update your account details here. Email stays locked, and changing your phone number will require a fresh verification.') }}
                                </p>
                            </div>
                            <span class="badge bg-primary-subtle text-primary">{{ __('Server-side validated') }}</span>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <form wire:submit.prevent="saveProfile" id="profile-details-form">
                            @if ($this->phoneRequiresVerification)
                                <div class="alert alert-warning d-flex flex-column flex-md-row gap-3 align-items-md-start">
                                    <div class="d-flex gap-2 align-items-start flex-grow-1">
                                        <i class="ri-alert-line fs-5"></i>
                                        <div>
                                            <div class="fw-semibold">{{ __('Phone verification required') }}</div>
                                            <div class="small mb-0">
                                                {{ __('Save these changes and we will take you straight to the phone OTP verification screen for the new number.') }}
                                            </div>
                                        </div>
                                    </div>
                                    <button
                                        type="submit"
                                        class="btn btn-warning btn-sm align-self-md-center"
                                        wire:loading.attr="disabled"
                                        wire:target="saveProfile"
                                    >
                                        <span wire:loading.remove wire:target="saveProfile">{{ __('Save & Continue to Verify Phone') }}</span>
                                        <span
                                            class="d-none align-items-center gap-2"
                                            wire:loading.class.remove="d-none"
                                            wire:loading.class="d-inline-flex"
                                            wire:target="saveProfile"
                                        >
                                            <span class="spinner-border spinner-border-sm"></span>
                                            {{ __('Saving...') }}
                                        </span>
                                    </button>
                                </div>
                            @endif

                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('First Name') }}</label>
                                    <input
                                        type="text"
                                        class="form-control @error('firstName') is-invalid @enderror"
                                        wire:model.defer="firstName"
                                        autocomplete="given-name"
                                    >
                                    @error('firstName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('Last Name') }}</label>
                                    <input
                                        type="text"
                                        class="form-control @error('lastName') is-invalid @enderror"
                                        wire:model.defer="lastName"
                                        autocomplete="family-name"
                                    >
                                    @error('lastName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('Username') }}</label>
                                    <input
                                        type="text"
                                        class="form-control @error('username') is-invalid @enderror"
                                        wire:model.defer="username"
                                        autocomplete="username"
                                    >
                                    <div class="form-text">{{ __('Letters, numbers, dashes, and underscores only.') }}</div>
                                    @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('Job Title') }}</label>
                                    <input
                                        type="text"
                                        class="form-control @error('jobTitle') is-invalid @enderror"
                                        wire:model.defer="jobTitle"
                                        placeholder="{{ __('Student, Teacher, Developer...') }}"
                                    >
                                    @error('jobTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('Phone Number') }}</label>

                                    <input type="hidden" id="profile_phone_number_hidden" wire:model.live.debounce.400ms="phoneNumber">
                                    <input type="hidden" id="profile_phone_country_hidden" wire:model.defer="phoneCountry">
                                    <input type="hidden" id="profile_phone_dial_code_hidden" wire:model.defer="phoneDialCode">

                                    <div wire:ignore>
                                        <input
                                            id="profile_phone_number"
                                            type="tel"
                                            class="form-control @error('phoneNumber') is-invalid @enderror"
                                            placeholder="{{ __('Phone Number') }}"
                                            dir="ltr"
                                            autocomplete="tel"
                                            inputmode="tel"
                                        >
                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                        <span class="badge bg-{{ $user?->phone_verify ? 'success' : 'warning' }}-subtle text-{{ $user?->phone_verify ? 'success' : 'warning' }}">
                                            {{ $user?->phone_verify ? __('Currently verified') : __('Verification pending') }}
                                        </span>
                                        <span class="text-muted small">{{ __('Use your international phone number format.') }}</span>
                                        @if (! $user?->phone_verify && ! $this->phoneRequiresVerification)
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-soft-warning"
                                                wire:click="redirectToPhoneVerification"
                                                wire:loading.attr="disabled"
                                                wire:target="redirectToPhoneVerification"
                                            >
                                                <span wire:loading.remove wire:target="redirectToPhoneVerification">{{ __('Verify Phone') }}</span>
                                                <span
                                                    class="d-none align-items-center gap-2"
                                                    wire:loading.class.remove="d-none"
                                                    wire:loading.class="d-inline-flex"
                                                    wire:target="redirectToPhoneVerification"
                                                >
                                                    <span class="spinner-border spinner-border-sm"></span>
                                                    {{ __('Opening...') }}
                                                </span>
                                            </button>
                                        @endif
                                    </div>

                                    @error('phoneNumber') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    @error('phoneCountry') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    @error('phoneDialCode') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                    <div id="profile_phone_number_client_error" class="invalid-feedback d-block" style="display:none;"></div>
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label">{{ __('Email Address') }}</label>
                                    <input
                                        type="email"
                                        class="form-control readonly-field"
                                        value="{{ $emailAddress }}"
                                        readonly
                                        disabled
                                    >
                                    <div class="form-text">{{ __('Email changes are locked here for account security.') }}</div>
                                </div>

                                <div class="col-12">
                                    <div class="border rounded-4 p-3 d-flex flex-column flex-md-row align-items-md-center gap-3">
                                        <img
                                            src="{{ $this->currentAvatarUrl() }}"
                                            alt="{{ $this->displayName }}"
                                            class="rounded-circle"
                                            style="width:72px;height:72px;object-fit:cover;"
                                            onerror="this.onerror=null;this.src='{{ e($fallbackAvatarUrl) }}';"
                                        >

                                        <div class="flex-grow-1">
                                            <label class="form-label mb-1">{{ __('Profile Photo') }}</label>
                                            <input
                                                type="file"
                                                class="form-control @error('avatar') is-invalid @enderror"
                                                wire:model="avatar"
                                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                            >
                                            <div class="form-text">{{ __('PNG, JPG, or WEBP up to 2 MB. Stored securely in your cloud profile storage.') }}</div>
                                            @error('avatar') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                            <div
                                                class="text-muted small mt-2 d-none"
                                                wire:loading.class.remove="d-none"
                                                wire:loading.class="d-flex"
                                                wire:target="avatar"
                                            >
                                                <span class="spinner-border spinner-border-sm me-2"></span>
                                                {{ __('Preparing image preview...') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                                <button
                                    type="button"
                                    class="btn btn-light"
                                    wire:click="syncProfileFormFromUser"
                                    wire:loading.attr="disabled"
                                    wire:target="syncProfileFormFromUser"
                                >
                                    {{ __('Reset Changes') }}
                                </button>

                                <button
                                    type="submit"
                                    class="btn btn-primary"
                                    wire:loading.attr="disabled"
                                    wire:target="saveProfile"
                                >
                                    <span wire:loading.remove wire:target="saveProfile">
                                        {{ $this->phoneRequiresVerification ? __('Save & Continue to Verify Phone') : __('Save Personal Details') }}
                                    </span>
                                    <span
                                        class="d-none align-items-center gap-2"
                                        wire:loading.class.remove="d-none"
                                        wire:loading.class="d-inline-flex"
                                        wire:target="saveProfile"
                                    >
                                        <span class="spinner-border spinner-border-sm"></span>
                                        {{ $this->phoneRequiresVerification ? __('Saving and preparing verification...') : __('Saving...') }}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card password-form-card">
                    <div class="card-header p-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <h4 class="card-title mb-1">{{ __('Change Password') }}</h4>
                                <p class="text-muted mb-0">
                                    {{ __('Use a strong password with mixed case, numbers, and symbols, just like the account signup flow.') }}
                                </p>
                            </div>
                            <a href="{{ route('app.password.email') }}" class="btn btn-soft-secondary">
                                {{ __('Send Reset Link') }}
                            </a>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <form wire:submit.prevent="updatePassword">
                            <div class="row g-4">
                                <div class="col-xl-4">
                                    <label class="form-label">{{ __('Current Password') }}</label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="profile_current_password"
                                            class="form-control @error('currentPassword') is-invalid @enderror"
                                            wire:model.defer="currentPassword"
                                            autocomplete="current-password"
                                        >
                                        <button class="btn btn-outline-secondary password-addon" type="button" data-target="#profile_current_password" aria-label="{{ __('Toggle password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('currentPassword') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-xl-4">
                                    <label class="form-label">{{ __('New Password') }}</label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="profile_new_password"
                                            class="form-control @error('newPassword') is-invalid @enderror"
                                            wire:model.defer="newPassword"
                                            autocomplete="new-password"
                                        >
                                        <button class="btn btn-outline-secondary password-addon" type="button" data-target="#profile_new_password" aria-label="{{ __('Toggle password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('newPassword') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-xl-4">
                                    <label class="form-label">{{ __('Confirm New Password') }}</label>
                                    <div class="input-group">
                                        <input
                                            type="password"
                                            id="profile_new_password_confirmation"
                                            class="form-control @error('newPasswordConfirmation') is-invalid @enderror"
                                            wire:model.defer="newPasswordConfirmation"
                                            autocomplete="new-password"
                                        >
                                        <button class="btn btn-outline-secondary password-addon" type="button" data-target="#profile_new_password_confirmation" aria-label="{{ __('Toggle password') }}">
                                            <i class="ri-eye-fill align-middle"></i>
                                        </button>
                                    </div>
                                    @error('newPasswordConfirmation') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                </div>

                                <div class="col-12">
                                    <div id="profile-password-contain" class="password-rule-list p-3 bg-light rounded">
                                        <h5 class="fs-13">{{ __('Password must contain:') }}</h5>
                                        <p id="profile-pass-lower" class="invalid fs-12 mb-2">{{ __('At least one lowercase letter') }}</p>
                                        <p id="profile-pass-upper" class="invalid fs-12 mb-2">{{ __('At least one uppercase letter') }}</p>
                                        <p id="profile-pass-number" class="invalid fs-12 mb-2">{{ __('At least one number') }}</p>
                                        <p id="profile-pass-special" class="invalid fs-12 mb-2">{{ __('At least one special character') }}</p>
                                        <p id="profile-pass-length" class="invalid fs-12 mb-2">{{ __('At least 8 characters') }}</p>
                                        <p id="profile-pass-match" class="invalid fs-12 mb-0">{{ __('Passwords match') }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button
                                    type="submit"
                                    class="btn btn-success"
                                    wire:loading.attr="disabled"
                                    wire:target="updatePassword"
                                >
                                    <span wire:loading.remove wire:target="updatePassword">{{ __('Change Password') }}</span>
                                    <span
                                        class="d-none align-items-center gap-2"
                                        wire:loading.class.remove="d-none"
                                        wire:loading.class="d-inline-flex"
                                        wire:target="updatePassword"
                                    >
                                        <span class="spinner-border spinner-border-sm"></span>
                                        {{ __('Updating...') }}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @php
            $profilePhoneConfig = [
                'invalidPhoneMessage' => __('Please enter a valid phone number.'),
                'assetErrorMessage' => __('Phone input failed to load. Please refresh and try again.'),
                'allowedCountries' => $allowedPhoneCountries ?? [],
                'preferredCountries' => $preferredPhoneCountries ?? [],
            ];
        @endphp

        <script>
            window.profilePhoneConfig = @json($profilePhoneConfig);
        </script>

        @once
            <script>
                (() => {
                    const config = window.profilePhoneConfig || {};
                    const invalidPhoneMessage = typeof config.invalidPhoneMessage === 'string' && config.invalidPhoneMessage.trim() !== ''
                        ? config.invalidPhoneMessage
                        : 'Please enter a valid phone number.';
                    const assetErrorMessage = typeof config.assetErrorMessage === 'string' && config.assetErrorMessage.trim() !== ''
                        ? config.assetErrorMessage
                        : 'Phone input failed to load. Please refresh and try again.';
                    const allowedCountries = Array.isArray(config.allowedCountries) ? config.allowedCountries : [];
                    const preferredCountries = Array.isArray(config.preferredCountries) ? config.preferredCountries : [];

                    let profileInitQueued = false;

                    function setRuleState(elementId, ok) {
                        const element = document.getElementById(elementId);

                        if (!element) {
                            return;
                        }

                        element.classList.toggle('valid', ok);
                        element.classList.toggle('invalid', !ok);
                    }

                    function updatePasswordRules() {
                        const password = document.getElementById('profile_new_password');
                        const confirmation = document.getElementById('profile_new_password_confirmation');
                        const value = password?.value || '';

                        setRuleState('profile-pass-length', value.length >= 8);
                        setRuleState('profile-pass-lower', /[a-z]/.test(value));
                        setRuleState('profile-pass-upper', /[A-Z]/.test(value));
                        setRuleState('profile-pass-number', /\d/.test(value));
                        setRuleState('profile-pass-special', /[^A-Za-z0-9]/.test(value));

                        const passwordsMatch = value.length > 0
                            && (confirmation?.value || '').length > 0
                            && value === confirmation.value;

                        setRuleState('profile-pass-match', passwordsMatch);
                    }

                    async function initProfilePhoneInput() {
                        if (!window.MetIntlTelInput) {
                            return;
                        }

                        const initialCountry = document.getElementById('profile_phone_country_hidden')?.value
                            || preferredCountries[0]
                            || allowedCountries[0]
                            || 'iq';

                        await window.MetIntlTelInput.init({
                            key: 'profile-phone-number',
                            inputSelector: '#profile_phone_number',
                            hiddenPhoneSelector: '#profile_phone_number_hidden',
                            hiddenCountrySelector: '#profile_phone_country_hidden',
                            hiddenDialCodeSelector: '#profile_phone_dial_code_hidden',
                            formSelector: '#profile-details-form',
                            errorSelector: '#profile_phone_number_client_error',
                            invalidMessage: invalidPhoneMessage,
                            assetErrorMessage,
                            initialCountry,
                            onlyCountries: allowedCountries,
                            preferredCountries,
                        });
                    }

                    function initPasswordToggles() {
                        if (window.__PROFILE_PASSWORD_TOGGLES_BOUND__) {
                            return;
                        }

                        window.__PROFILE_PASSWORD_TOGGLES_BOUND__ = true;

                        document.addEventListener('click', (event) => {
                            const button = event.target.closest('.password-addon');

                            if (!button) {
                                return;
                            }

                            const selector = button.getAttribute('data-target');
                            const input = selector ? document.querySelector(selector) : button.closest('.input-group')?.querySelector('input');
                            const icon = button.querySelector('i');

                            if (!input) {
                                return;
                            }

                            const showText = input.type === 'password';
                            input.type = showText ? 'text' : 'password';

                            if (icon) {
                                icon.classList.toggle('ri-eye-fill', !showText);
                                icon.classList.toggle('ri-eye-off-fill', showText);
                            }
                        });
                    }

                    function queueProfileInit() {
                        if (profileInitQueued) {
                            return;
                        }

                        profileInitQueued = true;

                        requestAnimationFrame(() => {
                            profileInitQueued = false;
                            updatePasswordRules();
                            initProfilePhoneInput();
                        });
                    }

                    initPasswordToggles();

                    document.addEventListener('input', (event) => {
                        if (event.target?.id === 'profile_new_password' || event.target?.id === 'profile_new_password_confirmation') {
                            updatePasswordRules();
                        }
                    }, true);

                    document.addEventListener('DOMContentLoaded', queueProfileInit);
                    document.addEventListener('met:intl-tel-input-ready', queueProfileInit);
                    document.addEventListener('livewire:navigated', queueProfileInit);
                    document.addEventListener('livewire:initialized', queueProfileInit);

                    if (window.Livewire && typeof window.Livewire.hook === 'function' && !window.__PROFILE_PHONE_MORPH_HOOK_BOUND__) {
                        window.__PROFILE_PHONE_MORPH_HOOK_BOUND__ = true;

                        window.Livewire.hook('morphed', ({ component, el }) => {
                            const target = component?.el || el;

                            if (!target || typeof target.querySelector !== 'function') {
                                return;
                            }

                            if (!target.querySelector('#profile-details-form')) {
                                return;
                            }

                            queueProfileInit();
                        });
                    }

                    queueProfileInit();
                })();
            </script>
        @endonce
    @endpush
</div>
