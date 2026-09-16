<?php

use Livewire\Attributes\Layout;

new #[Layout('app::v2.layouts.app')] class extends \App\Livewire\Account\ProfilePage {
    protected function profileRouteName(): string { return 'app.v2.profile'; }
};
?>

<div class="v2-account" data-v2-profile
     data-phone-config="{{ json_encode(['allowedCountries' => $allowedPhoneCountries, 'preferredCountries' => $preferredPhoneCountries, 'invalidMessage' => __('Please enter a valid phone number.'), 'assetErrorMessage' => __('Phone input failed to load. Please refresh and try again.')]) }}">
    <x-slot:title>{{ __('account_v2.profile_title') }} | MetKurd</x-slot:title>
    @include('app.auth.partials.intl-tel-input-shared')
    @include('app.v2.pages.account.navigation', ['active' => 'profile'])
    <header class="v2-account-heading"><span class="v2-account-eyebrow">{{ __('account_v2.account') }}</span><h1>{{ __('account_v2.profile_title') }}</h1><p>{{ __('account_v2.profile_intro') }}</p></header>
    <div class="v2-account-grid v2-account-profile-grid">
        <aside class="v2-account-panel v2-account-identity">
            <img class="v2-account-avatar" src="{{ $this->currentAvatarUrl() }}" alt="{{ __('account_v2.avatar') }}" wire:key="avatar-{{ $avatarVersion }}">
            <h2 dir="auto">{{ $this->displayName }}</h2><p dir="ltr">{{ $user?->email }}</p>
            <form wire:submit="saveAvatar" class="v2-account-stack">
                <label class="v2-account-label" for="v2-avatar">{{ __('account_v2.change_avatar') }}</label>
                <input id="v2-avatar" type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp" class="form-control">
                <small>{{ __('account_v2.avatar_help') }}</small>
                @error('avatar') <p class="v2-account-error" role="alert">{{ $message }}</p> @enderror
                <button class="btn btn-info" type="submit" wire:loading.attr="disabled" wire:target="avatar,saveAvatar">{{ __('account_v2.save_avatar') }}</button>
                <span wire:loading wire:target="avatar,saveAvatar" role="status">{{ __('account_v2.saving') }}</span>
            </form>
            <div class="v2-account-divider"></div>
            <span class="v2-account-label">{{ __('Current Plan') }}</span><strong dir="auto">{{ $this->serviceState['current_plan']?->name ?? __('Free') }}</strong>
            <a wire:navigate href="{{ route('app.v2.billing', ['locale' => app()->getLocale()]) }}">{{ __('account_v2.view_billing') }} <i class="ri-arrow-right-up-line" aria-hidden="true"></i></a>
        </aside>
        <div class="v2-account-stack">
            <form id="profile-details-form" wire:submit="saveProfile" class="v2-account-panel">
                <h2>{{ __('account_v2.personal_details') }}</h2><p>{{ __('account_v2.personal_help') }}</p>
                <div class="v2-account-fields">
                    @foreach(['firstName' => 'First Name', 'lastName' => 'Last Name', 'username' => 'Username', 'jobTitle' => 'Job Title'] as $field => $label)
                        <div><label class="v2-account-label" for="v2-{{ $field }}">{{ __($label) }}</label><input id="v2-{{ $field }}" class="form-control" wire:model="{{ $field }}" dir="{{ $field === 'username' ? 'ltr' : 'auto' }}" autocomplete="{{ $field === 'firstName' ? 'given-name' : ($field === 'lastName' ? 'family-name' : ($field === 'username' ? 'username' : 'organization-title')) }}">
                        @error($field) <p class="v2-account-error" role="alert">{{ $message }}</p> @enderror</div>
                    @endforeach
                </div>
                <div class="v2-account-divider"></div><h2>{{ __('account_v2.contact') }}</h2>
                <div class="v2-account-fields">
                    <div><label class="v2-account-label" for="profile_phone_number">{{ __('Phone Number') }} / {{ __('account_v2.country') }}</label>
                        <input type="hidden" id="profile_phone_number_hidden" wire:model.live.debounce.400ms="phoneNumber">
                        <input type="hidden" id="profile_phone_country_hidden" wire:model="phoneCountry">
                        <input type="hidden" id="profile_phone_dial_code_hidden" wire:model="phoneDialCode">
                        <div wire:ignore dir="ltr"><input id="profile_phone_number" type="tel" class="form-control" autocomplete="tel" inputmode="tel" aria-label="{{ __('Phone Number') }}"></div>
                        <div id="profile_phone_number_client_error" class="v2-account-error" role="alert" style="display:none"></div>
                        @foreach(['phoneNumber', 'phoneCountry', 'phoneDialCode'] as $field) @error($field) <p class="v2-account-error" role="alert">{{ $message }}</p> @enderror @endforeach
                        <div class="v2-account-inline"><span class="v2-account-badge {{ $user?->phone_verify ? 'is-good' : 'is-pending' }}">{{ $user?->phone_verify ? __('account_v2.verified') : __('account_v2.unverified') }}</span>
                        @if(!$user?->phone_verify && !$this->phoneRequiresVerification)<button type="button" class="btn btn-outline-info btn-sm" wire:click="redirectToPhoneVerification" wire:loading.attr="disabled">{{ __('Verify Phone') }}</button>@endif</div>
                        @if($this->phoneRequiresVerification)<p class="v2-account-notice">{{ __('account_v2.phone_changed') }}</p>@endif
                    </div>
                    <div><label class="v2-account-label" for="v2-email">{{ __('Email Address') }}</label><input id="v2-email" class="form-control" value="{{ $user?->email }}" type="email" dir="ltr" readonly>
                        <div class="v2-account-inline"><span class="v2-account-badge {{ $user?->email_verify ? 'is-good' : 'is-pending' }}">{{ $user?->email_verify ? __('account_v2.verified') : __('account_v2.unverified') }}</span>
                        @if(!$user?->email_verify)<a href="{{ route('app.email.otp') }}" class="btn btn-outline-info btn-sm">{{ __('Verify Email') }}</a>@endif</div><small>{{ __('account_v2.email_help') }}</small>
                    </div>
                </div>
                <footer class="v2-account-actions"><button type="button" class="btn btn-outline-secondary" wire:click="syncProfileFormFromUser" wire:loading.attr="disabled">{{ __('Reset Changes') }}</button><button type="submit" class="btn btn-info" wire:loading.attr="disabled" wire:target="saveProfile,avatar">{{ $this->phoneRequiresVerification ? __('Save & Continue to Verify Phone') : __('account_v2.save_profile') }}</button><span wire:loading wire:target="saveProfile" role="status">{{ __('account_v2.saving') }}</span></footer>
            </form>
            <form wire:submit="updatePassword" class="v2-account-panel">
                <h2>{{ __('account_v2.security') }}</h2><p>{{ __('account_v2.password_help') }}</p>
                <div class="v2-account-fields">
                    @foreach(['currentPassword' => 'Current Password', 'newPassword' => 'New Password', 'newPasswordConfirmation' => 'Confirm Password'] as $field => $label)
                        <div><label class="v2-account-label" for="v2-{{ $field }}">{{ __($label) }}</label><input id="v2-{{ $field }}" class="form-control" wire:model="{{ $field }}" type="password" autocomplete="{{ $field === 'currentPassword' ? 'current-password' : 'new-password' }}" dir="ltr">
                        @error($field)<p class="v2-account-error" role="alert">{{ $message }}</p>@enderror</div>
                    @endforeach
                </div><footer class="v2-account-actions"><button class="btn btn-outline-info" type="submit" wire:loading.attr="disabled" wire:target="updatePassword">{{ __('Change Password') }}</button><a class="btn btn-link" href="{{ route('app.password.email') }}">{{ __('Send Reset Link') }}</a><span wire:loading wire:target="updatePassword" role="status">{{ __('account_v2.saving') }}</span></footer>
            </form>
        </div>
    </div>
</div>
