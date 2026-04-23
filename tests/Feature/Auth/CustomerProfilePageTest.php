<?php

use App\Models\Customer;
use App\Models\CustomerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    app()->setLocale('en');
});

function customerProfileTestCustomer(array $customerOverrides = [], array $profileOverrides = []): Customer
{
    $suffix = Str::lower(Str::random(8));
    $phoneVerifiedAt = $customerOverrides['phone_verified_at'] ?? now();
    unset($customerOverrides['phone_verified_at']);

    $customer = Customer::create(array_merge([
        'username' => "profile_{$suffix}",
        'email' => "profile-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ], $customerOverrides));

    $customer->forceFill([
        'phone_verified_at' => $phoneVerifiedAt,
    ])->save();

    CustomerProfile::create(array_merge([
        'customer_id' => $customer->id,
        'first_name' => 'Old',
        'last_name' => 'Name',
        'job_title' => 'Student',
        'phone_number' => '+9647701234567',
        'country' => 'IQ',
    ], $profileOverrides));

    return $customer->fresh(['profile']);
}

it('saves updated personal details correctly', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('firstName', 'Aso')
        ->set('lastName', 'Kurd')
        ->set('username', 'aso_kurd')
        ->set('jobTitle', 'Developer')
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $customer->refresh()->load('profile');

    expect($customer->username)->toBe('aso_kurd')
        ->and($customer->profile?->first_name)->toBe('Aso')
        ->and($customer->profile?->last_name)->toBe('Kurd')
        ->and($customer->profile?->job_title)->toBe('Developer')
        ->and($customer->profile?->phone_number)->toBe('+9647701234567');
});

it('rejects invalid personal detail input', function () {
    $customer = customerProfileTestCustomer();
    $otherCustomer = customerProfileTestCustomer([
        'username' => 'taken_username',
        'email' => 'taken@example.com',
    ], [
        'phone_number' => '+9647711111111',
    ]);

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('username', $otherCustomer->username)
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertHasErrors(['username']);
});

it('marks the phone as unverified when the number changes', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('firstName', 'Old')
        ->set('lastName', 'Name')
        ->set('username', $customer->username)
        ->set('jobTitle', 'Student')
        ->set('phoneNumber', '+9647712345678')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertRedirect(route('app.phone.otp'));

    $customer->refresh()->load('profile');

    expect($customer->phone_verify)->toBeFalse()
        ->and($customer->phone_verified_at)->toBeNull()
        ->and($customer->profile?->phone_number)->toBe('+9647712345678')
        ->and(session('phone_verification_return_url'))->toBe(route('app.profile', ['locale' => 'en']));
});

it('requires phone re-verification after the phone number changes', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('firstName', 'Old')
        ->set('lastName', 'Name')
        ->set('username', $customer->username)
        ->set('jobTitle', 'Student')
        ->set('phoneNumber', '+9647712345678')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertRedirect(route('app.phone.otp'));

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.profile', ['locale' => 'en']))
        ->assertRedirect(route('app.phone.otp'));
});

it('shows a clear save and verify action when the phone number changes on the profile page', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('phoneNumber', '+9647712345678')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->assertSee('Phone verification required')
        ->assertSee('Save & Continue to Verify Phone');
});

it('keeps the verified phone state when the phone number is unchanged', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('firstName', 'Updated')
        ->set('lastName', 'Person')
        ->set('username', 'updated_person')
        ->set('jobTitle', 'Engineer')
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $customer->refresh();

    expect($customer->phone_verify)->toBeTrue()
        ->and($customer->phone_verified_at)->not->toBeNull();
});

it('provides a direct verification action for a pending phone state', function () {
    $customer = customerProfileTestCustomer([
        'phone_verify' => false,
        'phone_verified_at' => null,
    ]);

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->call('redirectToPhoneVerification')
        ->assertRedirect(route('app.phone.otp'));

    expect(session('phone_verification_return_url'))->toBe(route('app.profile', ['locale' => 'en']));
});

it('verifies an updated phone and returns the customer to the profile page', function () {
    $customer = customerProfileTestCustomer([
        'phone_verify' => false,
        'phone_verified_at' => null,
        'phone_otp_number' => '123456',
    ], [
        'phone_number' => '+9647712345678',
    ]);

    session(['phone_verification_return_url' => route('app.profile', ['locale' => 'en'])]);

    $this->actingAs($customer, 'app');

    Livewire::test('app::auth.phone-otp')
        ->set('digit1', '1')
        ->set('digit2', '2')
        ->set('digit3', '3')
        ->set('digit4', '4')
        ->set('digit5', '5')
        ->set('digit6', '6')
        ->call('confirm')
        ->assertRedirect(route('app.profile', ['locale' => 'en']));

    $customer->refresh();

    expect($customer->phone_verify)->toBeTrue()
        ->and($customer->phone_verified_at)->not->toBeNull()
        ->and($customer->phone_otp_number)->toBeNull();
});

it('stores avatar uploads on the s3 disk and updates the profile avatar path', function () {
    Storage::fake('s3');

    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    $avatar = UploadedFile::fake()->image('avatar.png', 240, 240)->size(512);

    Livewire::test('app::pages.profile.app-profile')
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->set('avatar', $avatar)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $profile = $customer->fresh()->profile;
    $storedPath = (string) $profile?->avatar;

    expect($storedPath)->not->toBe('');
    Storage::disk('s3')->assertExists($storedPath);
    expect($profile?->avatar_url)->toContain(basename($storedPath));
});

it('rejects invalid avatar uploads', function () {
    Storage::fake('s3');

    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    $avatar = UploadedFile::fake()->create('avatar.pdf', 256, 'application/pdf');

    Livewire::test('app::pages.profile.app-profile')
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->set('avatar', $avatar)
        ->call('saveProfile')
        ->assertHasErrors(['avatar']);
});

it('changes the password with valid input', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('currentPassword', 'Secret123!')
        ->set('newPassword', 'BetterPass123!')
        ->set('newPasswordConfirmation', 'BetterPass123!')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('BetterPass123!', (string) $customer->fresh()->password))->toBeTrue();
});

it('rejects the password change when the current password is invalid', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('currentPassword', 'WrongSecret123!')
        ->set('newPassword', 'BetterPass123!')
        ->set('newPasswordConfirmation', 'BetterPass123!')
        ->call('updatePassword')
        ->assertHasErrors(['currentPassword']);

    expect(Hash::check('Secret123!', (string) $customer->fresh()->password))->toBeTrue();
});

it('rejects the password change when the confirmation does not match', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('currentPassword', 'Secret123!')
        ->set('newPassword', 'BetterPass123!')
        ->set('newPasswordConfirmation', 'DifferentPass123!')
        ->call('updatePassword')
        ->assertHasErrors(['newPassword']);

    expect(Hash::check('Secret123!', (string) $customer->fresh()->password))->toBeTrue();
});

it('renders updated values on the profile page after saving', function () {
    $customer = customerProfileTestCustomer();

    $this->actingAs($customer, 'app');

    Livewire::test('app::pages.profile.app-profile')
        ->set('firstName', 'Sara')
        ->set('lastName', 'Ahmed')
        ->set('username', 'sara_ahmed')
        ->set('jobTitle', 'Teacher')
        ->set('phoneNumber', '+9647701234567')
        ->set('phoneCountry', 'iq')
        ->set('phoneDialCode', '964')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.profile', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Sara Ahmed')
        ->assertSee('sara_ahmed')
        ->assertSee('Teacher');
});
