<?php

use App\Domain\Payments\Models\Payment;
use App\Models\CreditOrder;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\CustomerServiceSubscription;
use App\Models\MlJob;
use App\Models\ServicePlan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    config(['metkurd_v2.enabled' => true]);
    Storage::fake('s3');
    Storage::fake('public');
    Mail::fake();
    Http::preventStrayRequests();
    $this->customer = Customer::create(['username' => 'v2_account_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    CustomerProfile::create(['customer_id' => $this->customer->id, 'first_name' => 'V2', 'last_name' => 'Customer', 'country' => 'IQ', 'phone_number' => '+9647701234567']);
    $this->actingAs($this->customer->fresh(), 'app');
});

it('renders both gated V2 account routes and navigation in each locale', function (string $locale) {
    foreach (['profile', 'billing'] as $page) {
        $this->get(route('app.v2.'.$page, ['locale' => $locale]))->assertOk()
            ->assertSee('v2-account', false)->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false)
            ->assertSee(route('app.v2.profile', ['locale' => $locale]), false)
            ->assertSee(route('app.v2.billing', ['locale' => $locale]), false)->assertDontSee('account_v2.');
    }
})->with(['en', 'ar', 'ku']);

it('retains authentication verification and feature gates', function () {
    auth('app')->logout();
    $this->get('/en/app-v2/profile')->assertRedirect(route('app.signin'));
    $this->actingAs($this->customer, 'app');
    config(['metkurd_v2.enabled' => false]);
    $this->get('/en/app-v2/my-billing')->assertNotFound();
    config(['metkurd_v2.enabled' => true]);
    $this->customer->update(['phone_verify' => false]);
    $this->get('/en/app-v2/profile')->assertRedirect(route('app.phone.otp'));
});

it('saves V2 profile fields with shared validation and preserves verification for an unchanged number', function () {
    Livewire::test('app::v2.pages.account.app-profile')->set('firstName', 'Updated')->set('lastName', 'Account')
        ->set('phoneDialCode', '964')->call('saveProfile')->assertHasNoErrors();
    expect($this->customer->fresh()->profile->first_name)->toBe('Updated')->and($this->customer->fresh()->phone_verify)->toBeTrue();
    Livewire::test('app::v2.pages.account.app-profile')->set('username', 'bad spaces')->set('phoneDialCode', '964')->call('saveProfile')->assertHasErrors(['username']);
});

it('invalidates a changed phone and completes the shared OTP flow back to V2', function () {
    Livewire::test('app::v2.pages.account.app-profile')->set('phoneNumber', '+9647712345678')->set('phoneDialCode', '964')
        ->call('saveProfile')->assertRedirect(route('app.phone.otp'));
    expect($this->customer->fresh()->phone_verify)->toBeFalse()->and(session('phone_verification_return_url'))->toBe(route('app.v2.profile', ['locale' => 'en']));
    $this->customer->refresh()->forceFill(['phone_otp_number' => '123456'])->save();
    $this->actingAs($this->customer, 'app');
    \Illuminate\Support\Facades\Cache::put('phone_otp_expires_'.$this->customer->id, now()->addMinutes(5)->timestamp, 360);
    \Illuminate\Support\Facades\Notification::fake();
    Livewire::test('app::auth.phone-otp')->set('otpCode', '123456')->call('confirm')
        ->assertRedirect(route('app.v2.profile', ['locale' => 'en']));
    expect($this->customer->fresh()->phone_verify)->toBeTrue();
});

it('uploads and replaces an avatar independently and rejects invalid images', function () {
    $component = Livewire::test('app::v2.pages.account.app-profile')->set('avatar', UploadedFile::fake()->image('first.png')->size(100))->call('saveAvatar')->assertHasNoErrors();
    $old = $this->customer->fresh()->profile->avatar;
    Storage::disk('s3')->assertExists($old);
    $component->set('avatar', UploadedFile::fake()->image('second.jpg')->size(200))->call('saveAvatar')->assertHasNoErrors();
    Storage::disk('s3')->assertMissing($old);
    Storage::disk('s3')->assertExists($this->customer->fresh()->profile->avatar);
    $component->set('avatar', UploadedFile::fake()->create('bad.txt', 2))->call('saveAvatar')->assertHasErrors(['avatar']);
});

it('requires the current password and strong matching replacement then clears password fields', function () {
    $component = Livewire::test('app::v2.pages.account.app-profile')->set('currentPassword', 'wrong')->set('newPassword', 'Updated123!')->set('newPasswordConfirmation', 'Updated123!')
        ->call('updatePassword')->assertHasErrors(['currentPassword']);
    $component->set('currentPassword', 'Secret123!')->set('newPassword', 'short')->call('updatePassword')->assertHasErrors(['newPassword']);
    $component->set('newPassword', 'Updated123!')->set('newPasswordConfirmation', 'Updated123!')->call('updatePassword')->assertHasNoErrors()
        ->assertSet('currentPassword', '')->assertSet('newPassword', '');
    expect(Hash::check('Updated123!', $this->customer->fresh()->password))->toBeTrue();
});

it('shows separate App API buckets and safe empty histories without writes', function () {
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->update(['balance_credits' => 123, 'subscription_balance_credits' => 100, 'addon_balance_credits' => 23]);
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['balance_credits' => 456, 'subscription_balance_credits' => 400, 'addon_balance_credits' => 56]);
    $before = DB::table('credit_wallets')->get()->toJson();
    $component = Livewire::test('app::v2.pages.account.app-billing')->assertSee('123')->assertSee('456')->assertSee(__('account_v2.no_payments'));
    $data = $component->get('dashboard');
    expect($data['available'])->toBeTrue()->and($data['wallets']['app'])->toBe(['balance' => 123, 'subscription' => 100, 'addon' => 23])
        ->and($data['wallets']['api'])->toBe(['balance' => 456, 'subscription' => 400, 'addon' => 56])
        ->and(DB::table('credit_wallets')->get()->toJson())->toBe($before);
});

it('filters usage charts and paginates only owned jobs with bookmarked dates', function () {
    $other = Customer::create(['username' => 'other_account', 'email' => 'other@example.test', 'password' => 'fixture']);
    foreach (range(1, 12) as $i) {
        MlJob::create(['id' => Str::uuid(), 'customer_id' => $this->customer->id, 'job_kind' => 'leo', 'status' => 'done', 'credits_charged' => 10]);
    }
    $hidden = MlJob::create(['id' => Str::uuid(), 'customer_id' => $other->id, 'job_kind' => 'leo', 'status' => 'done', 'credits_charged' => 99999]);
    $component = Livewire::withQueryParams(['period' => 'custom', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString(), 'tool' => 'leo'])
        ->test('app::v2.pages.account.app-billing')->assertSet('toolFilter', 'leo')->assertDontSee($hidden->id);
    expect($component->get('dashboard')['stats']['period_credits_spent'])->toBe(120)->and($component->get('dashboard')['jobs']->total())->toBe(12);
    $component->call('setPage', 2, 'jobsPage');
    expect($component->get('dashboard')['jobs']->count())->toBe(2);
    $component->set('toolFilter', 'vector-v2');
    expect($component->get('dashboard')['stats']['jobs_count'])->toBe(0)->and($component->get('dashboard')['timeline'])->toBeEmpty();
    $component->set('toolFilter', 'leo')->set('statusFilter', 'failed');
    expect($component->get('dashboard')['stats']['jobs_count'])->toBe(0);
    $component->set('statusFilter', 'all')->set('search', $hidden->id);
    expect($component->get('dashboard')['jobs']->total())->toBe(0);
    $component->set('search', '')->set('dateFrom', '2020-01-01')->set('dateTo', '2020-01-31');
    expect($component->get('dashboard')['stats']['jobs_count'])->toBe(0);
});

it('keeps complimentary grants out of payment history and shows cancellation state for paid access', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    CustomerServiceSubscription::where('customer_id', $this->customer->id)->update(['status' => 'ended']);
    $sub = CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => $plan->id, 'source' => 'admin_manual_grant', 'status' => 'active',
        'starts_at' => now(), 'ends_at' => now()->addMonth(), 'meta' => ['revenue_record' => false, 'billing_cycle' => 'monthly']]);
    CreditOrder::create(['customer_id' => $this->customer->id, 'order_type' => 'subscription', 'source_type' => 'service_plan', 'service_plan_id' => $plan->id, 'status' => 'paid', 'provider' => 'admin_manual']);
    $component = Livewire::test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.complimentary'));
    expect($component->get('dashboard')['payments']->total())->toBe(0);
    $sub->update(['source' => 'fib', 'canceled_at' => now(), 'meta' => ['billing_cycle' => 'monthly']]);
    Livewire::test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.cancel_pending'));
});

it('projects only owned payment rows and never provider references or payloads', function () {
    $other = Customer::create(['username' => 'private_account', 'email' => 'private@example.test', 'password' => 'fixture']);
    foreach ([$this->customer, $other] as $owner) {
        Payment::create(['uuid' => Str::uuid(), 'customer_id' => $owner->id, 'provider' => 'fib', 'status' => 'pending', 'internal_status' => 'pending',
            'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(),
            'amount' => $owner->id === $other->id ? 987654 : 12345, 'currency' => 'IQD', 'fib_payment_id' => Str::uuid(), 'meta' => ['secret' => 'PRIVATE-BANKING-DETAILS']]);
    }
    $component = Livewire::test('app::v2.pages.account.app-billing')->assertSee('12,345')->assertDontSee('987,654')->assertDontSee('PRIVATE-BANKING-DETAILS');
    expect($component->get('dashboard')['payments']->total())->toBe(1);
});

it('shows a safe retry state on query failure', function () {
    \Illuminate\Support\Facades\Schema::drop('subscription_credit_allocations');
    // This table is not required by billing reads; a genuine missing payment table is.
    DB::statement('DROP TABLE payment_events');
    \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
    DB::statement('DROP TABLE payments');
    Livewire::test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.unavailable'))->assertDontSee('SQLSTATE');
});

it('preserves shared phone sending resend cooldown and unverified state until confirmation', function () {
    config(['services.standingtech.base' => 'https://otp.example.test', 'services.standingtech.token' => 'fixture-token', 'services.standingtech.sender' => 'fixture']);
    Http::fake(['otp.example.test/*' => Http::response(['success' => true])]);
    $this->customer->update(['phone_verify' => false]);
    $this->actingAs($this->customer->fresh(), 'app');
    $component = Livewire::test('app::auth.phone-otp')->set('phone_dial_code', '964')->call('sendCode', 'sms')->assertHasNoErrors()->assertSet('flag', 1);
    Http::assertSentCount(1);
    $component->call('sendCode', 'sms');
    Http::assertSentCount(1);
    $this->travel(61)->seconds();
    $component->call('sendCode', 'sms');
    Http::assertSentCount(2);
    expect($this->customer->fresh()->phone_verify)->toBeFalse();
});

it('delegates cancellation only on explicit action and sanitizes service failures', function () {
    $service = Mockery::mock(\App\Services\Billing\ScheduleServicePlanCancellation::class);
    $service->shouldReceive('handle')->once()->with(Mockery::on(fn ($customer) => $customer->id === $this->customer->id))->andThrow(new RuntimeException('PRIVATE-PROVIDER-FAILURE'));
    app()->instance(\App\Services\Billing\ScheduleServicePlanCancellation::class, $service);
    Livewire::test('app::v2.pages.account.app-billing')->call('cancelSubscription')->assertHasErrors(['cancellation'])->assertDontSee('PRIVATE-PROVIDER-FAILURE');
});

it('labels an ended complimentary term without promising a scheduled payment', function () {
    CustomerServiceSubscription::where('customer_id', $this->customer->id)->update(['status' => 'ended']);
    CustomerServiceSubscription::create(['customer_id' => $this->customer->id, 'service_plan_id' => ServicePlan::where('code', 'pro')->value('id'),
        'source' => 'admin_manual_grant', 'status' => 'active', 'starts_at' => now()->subMonths(2), 'ends_at' => null, 'cycle_ends_on' => now()->subMonth(), 'auto_renew' => true, 'meta' => ['revenue_record' => false]]);
    Livewire::test('app::v2.pages.account.app-billing')->assertSee(__('account_v2.recorded_end'))->assertSee(__('account_v2.internal_access'))->assertDontSee(__('account_v2.auto_renew_on'));
});

it('paginates owned payments using the existing stored amount and currency', function () {
    foreach (range(1, 12) as $index) {
        Payment::create(['uuid' => Str::uuid(), 'customer_id' => $this->customer->id, 'provider' => 'fib', 'status' => 'pending', 'internal_status' => 'pending',
            'purchase_type' => 'plan_subscription', 'payment_mode' => 'one_time', 'local_reference' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'amount' => 12345, 'currency' => 'IQD']);
    }
    $component = Livewire::test('app::v2.pages.account.app-billing')->assertSee('12,345 IQD');
    expect($component->get('dashboard')['payments']->total())->toBe(12)->and($component->get('dashboard')['payments']->count())->toBe(10);
    $component->call('nextPage', 'billingPage');
    expect($component->get('dashboard')['payments']->count())->toBe(2);
});
