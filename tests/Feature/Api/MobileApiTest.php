<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerProfile;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\Voice;
use App\Services\Auth\CustomerSocialAuthService;
use App\Services\Billing\PlanSwitcher;
use App\Services\Media\AudioProbeService;
use App\Services\Mobile\MobileApiTokenService;
use App\Services\Providers\RunPodProvider;
use App\Support\AppToolCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Cache::flush();
    $this->seed();
    configureMobileSubmissionConfig();
});

function mobileApiCustomer(?string $email = null, ?string $username = null, bool $verified = true): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "mobile_api_{$suffix}",
        'email' => $email ?? "mobile-api-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => $verified,
        'phone_verify' => $verified,
    ])->fresh(['profile', 'usage', 'wallet', 'activeServiceSubscription.servicePlan']);
}

function assignMobilePlan(Customer $customer, string $code = 'premium'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function assignMobileStoragePlan(Customer $customer, string $code = 'free-512'): StoragePlan
{
    $plan = StoragePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchStoragePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function mobileApiToken(Customer $customer, ?string $appSlug = null): string
{
    return app(MobileApiTokenService::class)->issue($customer, 'test-device', $appSlug)['plain_text_token'];
}

function fakeMobileProviderUser(string $providerId, string $email, string $name = 'Mobile Social User'): \Laravel\Socialite\Contracts\User
{
    $providerUser = \Mockery::mock(\Laravel\Socialite\Contracts\User::class);
    $providerUser->shouldReceive('getId')->andReturn($providerId);
    $providerUser->shouldReceive('getEmail')->andReturn($email);
    $providerUser->shouldReceive('getName')->andReturn($name);
    $providerUser->shouldReceive('getNickname')->andReturn(null);
    $providerUser->shouldReceive('getAvatar')->andReturn(null);

    return $providerUser;
}

function createMobileJob(
    Customer $customer,
    string $jobKind,
    string $toolCode,
    string $status = 'done',
    bool $liveLock = false,
): MlJob {
    return MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) Tool::query()->where('code', $toolCode)->value('id'),
        'status' => $status,
        'job_kind' => $jobKind,
        'started_at' => now(),
        'finished_at' => $status === 'done' ? now() : null,
        'lock_expires_at' => $liveLock ? now()->addMinutes(30) : null,
        'execution_scope' => $liveLock ? 'customer' : null,
        'input' => ['audio_name' => strtoupper($jobKind).' Input'],
        'output' => ['text' => strtoupper($jobKind).' Output'],
    ]);
}

function configureMobileSubmissionConfig(): void
{
    config()->set('runpod.timeout', 5);
    config()->set('runpod.endpoints.xtts', 'endpoint-xtts');
    config()->set('runpod.endpoints.ftts', 'endpoint-ftts');
    config()->set('runpod.endpoints.wasr', 'endpoint-wasr');
    config()->set('runpod.endpoints.qasr', 'endpoint-qasr');
    config()->set('runpod.endpoints.stem', 'endpoint-stem');
    config()->set('runpod.endpoints.kocr', 'endpoint-ocr');
    config()->set('runpod.endpoints.tran', 'endpoint-tran');
}

function fakeRunpodSubmission(array $providerJobIds): void
{
    $mock = \Mockery::mock(RunPodProvider::class);

    foreach ($providerJobIds as $providerJobId) {
        $mock->shouldReceive('run')
            ->once()
            ->andReturn(['id' => $providerJobId]);
    }

    app()->instance(RunPodProvider::class, $mock);
}

function fakeAudioProbe(array $overrides = []): void
{
    $mock = \Mockery::mock(AudioProbeService::class);
    $mock->shouldReceive('probeUploadedFile')
        ->andReturn(array_merge([
            'duration_sec' => 91.5,
            'duration_min' => 1.525,
            'billable_min' => 2,
            'size_bytes' => 4096,
            'bit_rate' => 128000,
            'format_name' => 'mp3',
            'codec_name' => 'mp3',
            'sample_rate' => 44100,
            'channels' => 2,
            'audio_ext' => 'mp3',
        ], $overrides));

    app()->instance(AudioProbeService::class, $mock);
}

function firstPlanSpeaker(ServicePlan $plan, string $engine): string
{
    $voices = app(AppToolCatalog::class)->voiceOptionsForPlanAndEngine((int) $plan->id, $engine);

    expect($voices)->not->toBeEmpty();

    return (string) array_key_first($voices);
}

function ensurePlanSpeaker(ServicePlan $plan, string $engine, string $code, string $name): void
{
    $existing = Voice::query()
        ->join('plan_voice_access as pva', 'pva.voice_id', '=', 'voices.id')
        ->where('pva.service_plan_id', (int) $plan->id)
        ->where('pva.is_active', true)
        ->where('voices.is_active', true)
        ->where('voices.meta->engine', $engine)
        ->exists();

    if ($existing) {
        return;
    }

    $voice = Voice::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => $name,
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 999,
            'meta' => ['engine' => $engine],
        ]
    );

    if ((string) data_get($voice->meta, 'engine') !== $engine) {
        $voice->update([
            'is_active' => true,
            'meta' => array_merge((array) ($voice->meta ?? []), ['engine' => $engine]),
        ]);
    }

    PlanVoiceAccess::query()->updateOrCreate(
        [
            'service_plan_id' => (int) $plan->id,
            'voice_id' => (int) $voice->id,
        ],
        [
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 999,
        ]
    );

    Cache::flush();
}

it('issues a mobile api token for email password login and revokes it on logout', function () {
    $customer = mobileApiCustomer('mobile-login@example.com', 'mobile_login_user');
    assignMobilePlan($customer, 'premium');

    $login = $this->postJson('/api/mobile/auth/login', [
        'email' => $customer->email,
        'password' => 'Secret123!',
        'device_name' => 'iPhone 16 Pro',
        'app_slug' => 'tts',
    ]);

    $login->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', $customer->email);

    $token = (string) $login->json('token');

    expect(PersonalAccessToken::query()->count())->toBe(1);

    $this->withToken($token)
        ->getJson('/api/mobile/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $customer->email)
        ->assertJsonPath('data.token.abilities.1', 'mobile:tts');

    $this->withToken($token)
        ->postJson('/api/mobile/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('blocks mobile login until verification is complete', function () {
    $customer = mobileApiCustomer('mobile-unverified@example.com', 'mobile_unverified_user', verified: false);
    assignMobilePlan($customer, 'premium');

    $this->postJson('/api/mobile/auth/login', [
        'email' => $customer->email,
        'password' => 'Secret123!',
        'app_slug' => 'tts',
    ])->assertStatus(403)
        ->assertJsonPath('message', 'Complete your account verification on the website before using the mobile apps.');
});

it('returns onboarding state from mobile social login when phone number is missing', function () {
    $customer = mobileApiCustomer('mobile-social-onboarding@example.com', 'mobile_social_onboarding_user', verified: false);
    $customer->update([
        'email_verify' => true,
        'phone_verify' => false,
        'status' => 1,
    ]);
    CustomerProfile::query()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['phone_number' => null]
    );

    $providerUser = fakeMobileProviderUser('google-id-100', $customer->email);

    $socialAuthMock = \Mockery::mock(CustomerSocialAuthService::class);
    $socialAuthMock->shouldReceive('isSupportedProvider')->once()->with('google')->andReturnTrue();
    $socialAuthMock->shouldReceive('fetchProviderUserFromToken')->once()->with('google', 'provider-token')->andReturn($providerUser);
    $socialAuthMock->shouldReceive('customerExistsForProviderUser')->once()->with($providerUser, 'google')->andReturnFalse();
    $socialAuthMock->shouldReceive('authenticateProviderUser')->once()->with($providerUser, 'google', true)->andReturn($customer->fresh(['profile', 'usage', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']));
    app()->instance(CustomerSocialAuthService::class, $socialAuthMock);

    $response = $this->postJson('/api/mobile/auth/social/google', [
        'access_token' => 'provider-token',
        'device_name' => 'Pixel 9',
    ]);

    $response->assertOk()
        ->assertJsonPath('state', 'needs_phone_number')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities.0', 'mobile:onboarding')
        ->assertJsonPath('phone.exists', false)
        ->assertJsonPath('phone.verified', false);
});

it('returns authenticated state from mobile social login when verification is complete', function () {
    $customer = mobileApiCustomer('mobile-social-verified@example.com', 'mobile_social_verified_user', verified: true);
    assignMobilePlan($customer, 'premium');

    $providerUser = fakeMobileProviderUser('google-id-200', $customer->email);

    $socialAuthMock = \Mockery::mock(CustomerSocialAuthService::class);
    $socialAuthMock->shouldReceive('isSupportedProvider')->once()->with('google')->andReturnTrue();
    $socialAuthMock->shouldReceive('fetchProviderUserFromToken')->once()->with('google', 'provider-token')->andReturn($providerUser);
    $socialAuthMock->shouldReceive('customerExistsForProviderUser')->once()->with($providerUser, 'google')->andReturnTrue();
    $socialAuthMock->shouldReceive('authenticateProviderUser')->once()->with($providerUser, 'google', true)->andReturn($customer->fresh(['profile', 'usage', 'activeServiceSubscription.servicePlan', 'activeStorageSubscription.storagePlan']));
    app()->instance(CustomerSocialAuthService::class, $socialAuthMock);

    $response = $this->postJson('/api/mobile/auth/social/google', [
        'access_token' => 'provider-token',
        'device_name' => 'iPhone 15 Pro',
        'app_slug' => 'tts',
    ]);

    $response->assertOk()
        ->assertJsonPath('state', 'authenticated')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities.0', 'mobile')
        ->assertJsonPath('abilities.1', 'mobile:tts')
        ->assertJsonPath('user.email', $customer->email);
});

it('completes mobile phone onboarding and returns a full token after otp verification', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    config()->set('services.standingtech.base', 'https://standingtech.test');
    config()->set('services.standingtech.token', 'test-token');
    config()->set('services.standingtech.sender', 'METKURD');

    $customer = mobileApiCustomer('mobile-social-phone-otp@example.com', 'mobile_social_phone_otp_user', verified: false);
    assignMobilePlan($customer, 'premium');
    $customer->update([
        'email_verify' => true,
        'phone_verify' => false,
        'status' => 1,
    ]);

    $onboardingToken = app(MobileApiTokenService::class)->issueOnboarding($customer, 'Android Device')['plain_text_token'];

    $savePhone = $this->withToken($onboardingToken)->postJson('/api/mobile/auth/phone', [
        'phone' => '+9647501234567',
        'phone_country' => 'iq',
        'phone_dial_code' => '964',
        'channel' => 'sms',
    ]);

    $savePhone->assertOk()
        ->assertJsonPath('state', 'needs_phone_otp')
        ->assertJsonPath('otp_sent', true)
        ->assertJsonPath('phone.exists', true);

    $customer->refresh();
    $otpCode = (string) $customer->phone_otp_number;

    expect($otpCode)->toMatch('/^\d{6}$/');

    $verify = $this->withToken($onboardingToken)->postJson('/api/mobile/auth/phone/otp/verify', [
        'otp_code' => $otpCode,
        'device_name' => 'Android Device',
        'app_slug' => 'asr',
    ]);

    $verify->assertOk()
        ->assertJsonPath('state', 'phone_verified')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('abilities.0', 'mobile')
        ->assertJsonPath('abilities.1', 'mobile:asr')
        ->assertJsonPath('phone.verified', true);

    $customer->refresh();

    expect((bool) $customer->phone_verify)->toBeTrue()
        ->and($customer->phone_otp_number)->toBeNull();
});

it('blocks app routes when using onboarding-only token', function () {
    $customer = mobileApiCustomer('mobile-onboarding-token-block@example.com', 'mobile_onboarding_token_block_user', verified: false);
    assignMobilePlan($customer, 'premium');
    $customer->update([
        'email_verify' => true,
        'phone_verify' => false,
        'status' => 1,
    ]);

    $token = app(MobileApiTokenService::class)->issueOnboarding($customer, 'test-device')['plain_text_token'];

    $this->withToken($token)
        ->getJson('/api/mobile/tts/voices')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Complete your account verification before using this mobile API.');
});

it('returns a json 401 response for unauthenticated mobile job creation even without an accept header', function () {
    $response = $this->post('/api/mobile/tts/jobs', [
        'tool_code' => 'tts',
        'text' => 'Unauthorized request',
    ]);

    $response->assertStatus(401)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns a json 401 response for unauthenticated mobile account usage access', function () {
    $this->getJson('/api/mobile/account/usage')
        ->assertStatus(401)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns the authenticated customer mobile account usage summary with pricing metadata', function () {
    $customer = mobileApiCustomer('mobile-usage@example.com', 'mobile_usage_user');
    $servicePlan = assignMobilePlan($customer, 'premium');
    $storagePlan = assignMobileStoragePlan($customer, 'premium-10240');

    $servicePlan->update(['monthly_credits' => 5000]);
    $storagePlan->update(['quota_mb' => 1024]);

    $customer->wallet()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['balance_credits' => 1200]
    );
    $customer->usage()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['storage_used_bytes' => 100 * 1024 * 1024]
    );

    $response = $this->withToken(mobileApiToken($customer))
        ->getJson('/api/mobile/account/usage');

    $response->assertOk()
        ->assertJsonPath('data.credits.balance', 1200)
        ->assertJsonPath('data.credits.monthly', 5000)
        ->assertJsonPath('data.credits.used', 3800)
        ->assertJsonPath('data.credits.percent_used', 76)
        ->assertJsonPath('data.credits.percent_remaining', 24)
        ->assertJsonPath('data.storage.used_bytes', 104857600)
        ->assertJsonPath('data.storage.used_mb', 100)
        ->assertJsonPath('data.storage.quota_bytes', 1073741824)
        ->assertJsonPath('data.storage.quota_mb', 1024)
        ->assertJsonPath('data.storage.remaining_bytes', 968884224)
        ->assertJsonPath('data.storage.remaining_mb', 924)
        ->assertJsonPath('data.storage.percent_used', 10)
        ->assertJsonPath('data.storage.percent_remaining', 90)
        ->assertJsonPath('data.storage.over_quota', false)
        ->assertJsonPath('data.storage.upload_blocked', false)
        ->assertJsonPath('data.pricing.currency', 'credits')
        ->assertJsonPath('data.meta.plan_code', 'premium')
        ->assertJsonPath('data.meta.plan_name', 'Premium');

    $pricingVersion = (string) $response->json('data.pricing.version');
    $rules = collect($response->json('data.pricing.rules'));
    $ttsRule = $rules->firstWhere('tool_action', 'tts.standard');
    $fttsRule = $rules->firstWhere('tool_action', 'ftts.standard');

    expect($pricingVersion)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($rules)->not->toBeEmpty()
        ->and($ttsRule)->not->toBeNull()
        ->and(data_get($ttsRule, 'tool_code'))->toBe('tts')
        ->and(data_get($ttsRule, 'metric_code'))->toBe('chars')
        ->and(data_get($ttsRule, 'unit_label'))->toBe('characters')
        ->and(data_get($ttsRule, 'billing_unit'))->toBe(1)
        ->and(data_get($ttsRule, 'credits_per_unit'))->toBe(1)
        ->and(data_get($ttsRule, 'minimum_credits'))->toBe(1)
        ->and(data_get($ttsRule, 'rounding_mode'))->toBe('ceil')
        ->and($fttsRule)->not->toBeNull()
        ->and(data_get($fttsRule, 'metric_code'))->toBe('chars');
});

it('returns over quota state in the mobile account usage summary', function () {
    $customer = mobileApiCustomer('mobile-usage-over-quota@example.com', 'mobile_usage_over_quota_user');
    assignMobilePlan($customer, 'premium');
    $storagePlan = assignMobileStoragePlan($customer, 'premium-10240');

    $storagePlan->update(['quota_mb' => 10]);

    $customer->usage()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['storage_used_bytes' => 12 * 1024 * 1024]
    );

    $this->withToken(mobileApiToken($customer))
        ->getJson('/api/mobile/account/usage')
        ->assertOk()
        ->assertJsonPath('data.storage.quota_mb', 10)
        ->assertJsonPath('data.storage.used_mb', 12)
        ->assertJsonPath('data.storage.remaining_bytes', 0)
        ->assertJsonPath('data.storage.remaining_mb', 0)
        ->assertJsonPath('data.storage.percent_used', 100)
        ->assertJsonPath('data.storage.percent_remaining', 0)
        ->assertJsonPath('data.storage.over_quota', true)
        ->assertJsonPath('data.storage.upload_blocked', true);
});

it('returns null percentages when monthly credits or storage quota have no positive denominator', function () {
    $customer = mobileApiCustomer('mobile-usage-zero@example.com', 'mobile_usage_zero_user');
    $servicePlan = assignMobilePlan($customer, 'premium');
    $storagePlan = assignMobileStoragePlan($customer, 'premium-10240');

    $servicePlan->update(['monthly_credits' => 0]);
    $storagePlan->update(['quota_mb' => 0]);

    $customer->wallet()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['balance_credits' => 0]
    );
    $customer->usage()->updateOrCreate(
        ['customer_id' => (int) $customer->id],
        ['storage_used_bytes' => 0]
    );

    $this->withToken(mobileApiToken($customer))
        ->getJson('/api/mobile/account/usage')
        ->assertOk()
        ->assertJsonPath('data.credits.monthly', 0)
        ->assertJsonPath('data.credits.used', 0)
        ->assertJsonPath('data.credits.percent_used', null)
        ->assertJsonPath('data.credits.percent_remaining', null)
        ->assertJsonPath('data.storage.quota_bytes', 0)
        ->assertJsonPath('data.storage.quota_mb', 0)
        ->assertJsonPath('data.storage.remaining_bytes', 0)
        ->assertJsonPath('data.storage.remaining_mb', 0)
        ->assertJsonPath('data.storage.percent_used', null)
        ->assertJsonPath('data.storage.percent_remaining', null)
        ->assertJsonPath('data.storage.over_quota', false)
        ->assertJsonPath('data.storage.upload_blocked', false);
});

it('returns only pricing rules for tool actions the customer is allowed to use', function () {
    $customer = mobileApiCustomer('mobile-usage-pricing-access@example.com', 'mobile_usage_pricing_access_user');
    $plan = assignMobilePlan($customer, 'premium');

    PlanEntitlement::query()->updateOrCreate(
        [
            'service_plan_id' => (int) $plan->id,
            'tool_action_id' => (int) ToolAction::query()->where('full_code', 'ftts.standard')->value('id'),
        ],
        [
            'allowed' => false,
        ]
    );

    $rules = collect(
        $this->withToken(mobileApiToken($customer))
            ->getJson('/api/mobile/account/usage')
            ->assertOk()
            ->json('data.pricing.rules')
    );

    expect($rules->pluck('tool_action')->all())->toContain('tts.standard')
        ->and($rules->pluck('tool_action')->all())->not->toContain('ftts.standard');
});

it('excludes inactive pricing rules from the mobile account usage summary', function () {
    $customer = mobileApiCustomer('mobile-usage-pricing-inactive@example.com', 'mobile_usage_pricing_inactive_user');
    assignMobilePlan($customer, 'premium');

    $ttsActionId = (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id');

    PricingRule::query()
        ->where('tool_action_id', $ttsActionId)
        ->update(['is_active' => false]);

    $rules = collect(
        $this->withToken(mobileApiToken($customer))
            ->getJson('/api/mobile/account/usage')
            ->assertOk()
            ->json('data.pricing.rules')
    );

    expect($rules->pluck('tool_action')->all())->not->toContain('tts.standard');
});

it('requires authentication for the mobile tts voices endpoint', function () {
    $this->getJson('/api/mobile/tts/voices')
        ->assertStatus(401)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('lists mobile tts voices with the normalized flutter payload', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-voices@example.com', 'mobile_voices_user');
    $plan = assignMobilePlan($customer, 'premium');

    ensurePlanSpeaker($plan, 'ftts', 'mobile_f5_voice', 'Mobile F5 Voice');

    $xttsSpeaker = firstPlanSpeaker($plan, 'xtts');
    $xttsVoice = Voice::query()->where('code', $xttsSpeaker)->firstOrFail();
    $xttsVoice->update([
        'meta' => array_merge((array) ($xttsVoice->meta ?? []), [
            'avatar' => 'xtts/mobile_xtts_voice.png',
            'preview_audio' => 'xtts/mobile_xtts_voice_preview.mp3',
            'description' => 'Warm Kurdish narration voice.',
            'language_codes' => ['ku', 'ar', 'en'],
            'is_featured' => true,
        ]),
    ]);

    Storage::disk('s3')->put('metkurd_audio_data/xtts/mobile_xtts_voice.png', 'fake-image-bytes');
    Storage::disk('s3')->put('metkurd_audio_data/xtts/mobile_xtts_voice_preview.mp3', 'fake-preview-bytes');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/voices');

    $voices = collect($response->json('data.voices'));
    $xttsPayload = $voices->firstWhere('speaker_id', $xttsSpeaker);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'voices' => [[
                    'speaker_id',
                    'tool_code',
                    'engine',
                    'name',
                    'description',
                    'language_codes',
                    'gender',
                    'sort_order',
                    'is_featured',
                    'avatar',
                    'preview',
                ]],
            ],
        ]);

    expect($voices)->not->toBeEmpty()
        ->and($voices->pluck('tool_code')->all())->toContain('tts')
        ->and($voices->pluck('tool_code')->all())->toContain('ftts')
        ->and($xttsPayload)->not->toBeNull()
        ->and(data_get($xttsPayload, 'tool_code'))->toBe('tts')
        ->and(data_get($xttsPayload, 'engine'))->toBe('xtts')
        ->and(data_get($xttsPayload, 'description'))->toBe('Warm Kurdish narration voice.')
        ->and(data_get($xttsPayload, 'language_codes'))->toBe(['ku', 'ar', 'en'])
        ->and(data_get($xttsPayload, 'is_featured'))->toBeTrue()
        ->and(data_get($xttsPayload, 'avatar.path'))->toBe('metkurd_audio_data/xtts/mobile_xtts_voice.png')
        ->and(data_get($xttsPayload, 'avatar.url'))->toBe(route('api.mobile.tts.voices.avatar', ['speakerId' => $xttsSpeaker]))
        ->and(data_get($xttsPayload, 'preview.available'))->toBeTrue()
        ->and(data_get($xttsPayload, 'preview.file_id'))->toBe('mobile_xtts_voice_preview.mp3')
        ->and(data_get($xttsPayload, 'preview.download_endpoint'))->toBe(route('api.mobile.tts.voices.preview', ['speakerId' => $xttsSpeaker]));
});

it('filters mobile tts voices by tool code', function () {
    $customer = mobileApiCustomer('mobile-voices-filter@example.com', 'mobile_voices_filter_user');
    $plan = assignMobilePlan($customer, 'premium');

    ensurePlanSpeaker($plan, 'ftts', 'mobile_f5_voice', 'Mobile F5 Voice');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/voices?tool_code=ftts');

    $toolCodes = collect($response->json('data.voices'))->pluck('tool_code')->unique()->values()->all();

    $response->assertOk();

    expect($toolCodes)->toBe(['ftts']);
});

it('returns only voices for the engines the customer is entitled to use', function () {
    $customer = mobileApiCustomer('mobile-voices-entitlement@example.com', 'mobile_voices_entitlement_user');
    $plan = assignMobilePlan($customer, 'premium');

    ensurePlanSpeaker($plan, 'ftts', 'mobile_f5_voice', 'Mobile F5 Voice');

    PlanEntitlement::query()->updateOrCreate(
        [
            'service_plan_id' => (int) $plan->id,
            'tool_action_id' => (int) ToolAction::query()->where('full_code', 'ftts.standard')->value('id'),
        ],
        [
            'allowed' => false,
        ]
    );

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/voices');

    $toolCodes = collect($response->json('data.voices'))->pluck('tool_code')->unique()->values()->all();

    $response->assertOk();

    expect($toolCodes)->toBe(['tts']);
});

it('streams mobile voice avatars through the protected endpoint', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-voice-avatar@example.com', 'mobile_voice_avatar_user');
    $plan = assignMobilePlan($customer, 'premium');

    $speaker = firstPlanSpeaker($plan, 'xtts');
    $voice = Voice::query()->where('code', $speaker)->firstOrFail();
    $voice->update([
        'meta' => array_merge((array) ($voice->meta ?? []), [
            'avatar' => 'xtts/mobile_voice_avatar.png',
        ]),
    ]);

    Storage::disk('s3')->put('metkurd_audio_data/xtts/mobile_voice_avatar.png', 'mobile-avatar-image');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->get('/api/mobile/tts/voices/'.$speaker.'/avatar');

    $response->assertOk()
        ->assertHeader('content-type', 'image/png');

    expect($response->streamedContent())->toBe('mobile-avatar-image');
});

it('returns 404 when a protected mobile voice avatar is missing', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-voice-avatar-missing@example.com', 'mobile_voice_avatar_missing_user');
    $plan = assignMobilePlan($customer, 'premium');

    $speaker = firstPlanSpeaker($plan, 'xtts');
    $voice = Voice::query()->where('code', $speaker)->firstOrFail();
    $voice->update([
        'meta' => array_merge((array) ($voice->meta ?? []), [
            'avatar' => 'xtts/missing_mobile_voice_avatar.png',
        ]),
    ]);

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/voices/'.$speaker.'/avatar')
        ->assertStatus(404)
        ->assertHeader('content-type', 'application/json');
});

it('streams mobile voice previews through the protected endpoint', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-voice-preview@example.com', 'mobile_voice_preview_user');
    $plan = assignMobilePlan($customer, 'premium');

    $speaker = firstPlanSpeaker($plan, 'xtts');
    $voice = Voice::query()->where('code', $speaker)->firstOrFail();
    $voice->update([
        'meta' => array_merge((array) ($voice->meta ?? []), [
            'preview_audio' => 'xtts/mobile_voice_preview.mp3',
        ]),
    ]);

    Storage::disk('s3')->put('metkurd_audio_data/xtts/mobile_voice_preview.mp3', 'mobile-preview-audio');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->get('/api/mobile/tts/voices/'.$speaker.'/preview');

    $response->assertOk()
        ->assertHeader('content-type', 'audio/mpeg');

    expect($response->streamedContent())->toBe('mobile-preview-audio');
});

it('returns 404 when a protected mobile voice preview is missing', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-voice-preview-missing@example.com', 'mobile_voice_preview_missing_user');
    $plan = assignMobilePlan($customer, 'premium');

    $speaker = firstPlanSpeaker($plan, 'xtts');
    $voice = Voice::query()->where('code', $speaker)->firstOrFail();
    $voice->update([
        'meta' => array_merge((array) ($voice->meta ?? []), [
            'preview_audio' => 'xtts/missing_mobile_voice_preview.mp3',
        ]),
    ]);

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/voices/'.$speaker.'/preview')
        ->assertStatus(404)
        ->assertHeader('content-type', 'application/json');
});

it('lists only the jobs that belong to the requested mobile app', function () {
    $customer = mobileApiCustomer('mobile-jobs@example.com', 'mobile_jobs_user');
    assignMobilePlan($customer, 'premium');

    $tts = createMobileJob($customer, 'tts', 'tts');
    $ftts = createMobileJob($customer, 'ftts', 'ftts');
    $clone = createMobileJob($customer, 'clone_tts', 'clone_tts');
    $wasr = createMobileJob($customer, 'wasr', 'asr');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/jobs');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain((string) $tts->id)
        ->and($ids)->toContain((string) $ftts->id)
        ->and($ids)->not->toContain((string) $clone->id)
        ->and($ids)->not->toContain((string) $wasr->id);
});

it('syncs an active mobile tts job on the detail endpoint when runpod has completed it', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-sync@example.com', 'mobile_sync_user');
    assignMobilePlan($customer, 'premium');

    $job = MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) Tool::query()->where('code', 'tts')->value('id'),
        'tool_action_id' => (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id'),
        'status' => 'running',
        'job_kind' => 'tts',
        'provider' => 'runpod',
        'provider_job_id' => 'runpod-status-tts-1',
        'started_at' => now(),
        'input' => [
            'text' => 'Sync this TTS job from RunPod.',
            'speaker_id' => 'xtts_female_1',
            'language' => 'ar',
        ],
    ]);

    $mock = \Mockery::mock(RunPodProvider::class);
    $mock->shouldReceive('status')
        ->once()
        ->andReturn([
            'status' => 'COMPLETED',
            'output' => [
                'wav_b64' => base64_encode('fake-wav-audio'),
            ],
        ]);
    app()->instance(RunPodProvider::class, $mock);

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/jobs/'.$job->id);

    $outputFile = CustomerFile::query()
        ->where('customer_id', (int) $customer->id)
        ->where('tool_code', 'tts')
        ->where('meta->job_id', (string) $job->id)
        ->latest('id')
        ->firstOrFail();

    $response->assertOk()
        ->assertJsonPath('data.id', (string) $job->id)
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.result.has_output', true)
        ->assertJsonPath('data.result.has_error', false)
        ->assertJsonPath('data.result.primary_output.id', (int) $outputFile->id)
        ->assertJsonPath('data.result.primary_output.role', 'audio')
        ->assertJsonPath('data.result.primary_output.download_endpoint', route('api.mobile.apps.files.download', [
            'app' => 'tts',
            'fileId' => (int) $outputFile->id,
        ]))
        ->assertJsonPath('data.result.outputs.0.id', (int) $outputFile->id);

    $job->refresh();

    expect((string) $job->status)->toBe('done')
        ->and($job->finished_at)->not->toBeNull()
        ->and((string) data_get($job->output, 'path'))->toContain('/tts/'.$job->id.'/');
});

it('returns a clean 404 json response when a job uuid is used in a numeric file download route', function () {
    $customer = mobileApiCustomer('mobile-file-404@example.com', 'mobile_file_404_user');
    assignMobilePlan($customer, 'premium');

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->getJson('/api/mobile/tts/files/'.Str::uuid().'/download');

    $response->assertStatus(404)
        ->assertHeader('content-type', 'application/json');
});

it('includes consistent output references for completed ocr jobs', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-ocr-output@example.com', 'mobile_ocr_output_user');
    assignMobilePlan($customer, 'premium');

    Storage::disk('s3')->put('renders/customer-folder/ocr/job-1/text.txt', 'Recognized text');
    Storage::disk('s3')->put('renders/customer-folder/ocr/job-1/result.json', '{"text":"Recognized text"}');

    $job = MlJob::create([
        'id' => (string) Str::uuid(),
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) Tool::query()->where('code', 'ocr')->value('id'),
        'tool_action_id' => (int) ToolAction::query()->where('full_code', 'ocr.standard')->value('id'),
        'status' => 'done',
        'job_kind' => 'ocr',
        'started_at' => now()->subMinutes(1),
        'finished_at' => now(),
        'input' => [
            'file_name' => 'scan.pdf',
            'page_range' => '1-2',
        ],
        'output' => [
            'disk' => 's3',
            'text' => [
                'path' => 'renders/customer-folder/ocr/job-1/text.txt',
                'bytes' => 15,
                'mime' => 'text/plain; charset=UTF-8',
            ],
            'json' => [
                'path' => 'renders/customer-folder/ocr/job-1/result.json',
                'bytes' => 26,
                'mime' => 'application/json',
            ],
        ],
    ]);

    $textFile = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'ocr',
        'disk' => 's3',
        'path' => 'renders/customer-folder/ocr/job-1/text.txt',
        'size_bytes' => 15,
        'mime' => 'text/plain; charset=UTF-8',
        'status' => 'active',
        'meta' => [
            'job_id' => (string) $job->id,
            'role' => 'text',
        ],
    ]);

    $jsonFile = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'ocr',
        'disk' => 's3',
        'path' => 'renders/customer-folder/ocr/job-1/result.json',
        'size_bytes' => 26,
        'mime' => 'application/json',
        'status' => 'active',
        'meta' => [
            'job_id' => (string) $job->id,
            'role' => 'json',
        ],
    ]);

    $this->withToken(mobileApiToken($customer, 'ocr'))
        ->getJson('/api/mobile/ocr/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('data.result.has_output', true)
        ->assertJsonPath('data.result.primary_output.id', (int) $textFile->id)
        ->assertJsonPath('data.result.primary_output.role', 'text')
        ->assertJsonPath('data.result.outputs.0.id', (int) $textFile->id)
        ->assertJsonPath('data.result.outputs.1.id', (int) $jsonFile->id)
        ->assertJsonPath('data.result.outputs.1.role', 'json');
});

it('lists and signs downloads only for files inside the requested mobile app scope', function () {
    Storage::fake('local');

    $customer = mobileApiCustomer('mobile-files@example.com', 'mobile_files_user');
    assignMobilePlan($customer, 'premium');

    Storage::disk('local')->put('mobile/tts/output.txt', 'hello');
    Storage::disk('local')->put('mobile/asr/output.txt', 'world');

    $ttsFile = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'tts',
        'disk' => 'local',
        'path' => 'mobile/tts/output.txt',
        'size_bytes' => 5,
        'mime' => 'text/plain',
        'status' => 'active',
    ]);

    CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'qasr',
        'disk' => 'local',
        'path' => 'mobile/asr/output.txt',
        'size_bytes' => 5,
        'mime' => 'text/plain',
        'status' => 'active',
    ]);

    $token = mobileApiToken($customer, 'tts');

    $list = $this->withToken($token)->getJson('/api/mobile/tts/files');
    $list->assertOk();

    $ids = collect($list->json('data'))->pluck('id')->all();

    expect($ids)->toBe([(int) $ttsFile->id]);

    $this->withToken($token)
        ->getJson('/api/mobile/tts/files/'.$ttsFile->id.'/download')
        ->assertOk()
        ->assertJsonPath('data.file.id', (int) $ttsFile->id)
        ->assertJsonPath('data.file.tool_code', 'tts');
});

it('stores an app scoped upload for apps that allow direct uploads', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-upload@example.com', 'mobile_upload_user');
    assignMobilePlan($customer, 'premium');

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'ocr'))
        ->post('/api/mobile/ocr/files/upload', [
            'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.tool_code', 'ocr')
        ->assertJsonPath('data.purpose', 'input_document');

    $record = CustomerFile::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();

    expect((string) $record->tool_code)->toBe('ocr');
    Storage::disk('s3')->assertExists((string) $record->path);
});

it('submits xtts mobile jobs with the web payload contract', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-xtts@example.com', 'mobile_xtts_user');
    $plan = assignMobilePlan($customer, 'premium');
    $speaker = firstPlanSpeaker($plan, 'xtts');

    fakeRunpodSubmission(['runpod-xtts-1']);

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'tool_code' => 'tts',
            'text' => 'Hello from the XTTS mobile endpoint.',
            'speaker_id' => $speaker,
            'language' => 'ar',
            'split' => true,
            'max_words' => 20,
            'fade_ms' => 60,
            'temperature' => 0.7,
            'top_k' => 40,
            'top_p' => 0.85,
            'repetition_penalty' => 2.1,
            'length_penalty' => 1.2,
            'speed' => 1.0,
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'XTTS job started.')
        ->assertJsonPath('data.job.app', 'tts')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.tool_code', 'tts')
        ->assertJsonPath('data.job.tool_action', 'tts.standard')
        ->assertJsonPath('data.job.input.speaker_id', $speaker)
        ->assertJsonPath('data.next_actions.recommended_poll_interval_seconds', 5);

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->job_kind)->toBe('tts')
        ->and((string) $job->provider_job_id)->toBe('runpod-xtts-1')
        ->and((string) data_get($job->input, 'speaker_id'))->toBe($speaker)
        ->and((bool) data_get($job->input, 'split'))->toBeTrue();
});

it('validates speaker ids against the selected tts engine', function () {
    $customer = mobileApiCustomer('mobile-engine-speaker-validation@example.com', 'mobile_engine_speaker_validation_user');
    $plan = assignMobilePlan($customer, 'premium');

    ensurePlanSpeaker($plan, 'ftts', 'mobile_f5_voice', 'Mobile F5 Voice');

    $xttsSpeaker = firstPlanSpeaker($plan, 'xtts');
    $fttsSpeaker = firstPlanSpeaker($plan, 'ftts');

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'tool_code' => 'tts',
            'text' => 'This should fail because the speaker belongs to F5TTS.',
            'speaker_id' => $fttsSpeaker,
            'language' => 'ar',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['speaker_id']);

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'tool_code' => 'ftts',
            'text' => 'This should fail because the speaker belongs to XTTS.',
            'speaker_id' => $xttsSpeaker,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['speaker_id']);
});

it('submits f5tts mobile jobs with the web payload contract', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-f5tts@example.com', 'mobile_f5tts_user');
    $plan = assignMobilePlan($customer, 'premium');
    ensurePlanSpeaker($plan, 'ftts', 'mobile_f5_voice', 'Mobile F5 Voice');
    $speaker = firstPlanSpeaker($plan, 'ftts');

    fakeRunpodSubmission(['runpod-ftts-1']);

    $response = $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'tool_code' => 'ftts',
            'text' => 'Hello from the F5TTS mobile endpoint.',
            'speaker_id' => $speaker,
            'use_ema' => false,
            'nfe_step' => 48,
            'cfg_strength' => 2.4,
            'speed' => 1.1,
            'remove_silence' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'F5TTS job started.')
        ->assertJsonPath('data.job.app', 'tts')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'ftts')
        ->assertJsonPath('data.job.tool_code', 'ftts')
        ->assertJsonPath('data.job.tool_action', 'ftts.standard');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-ftts-1')
        ->and((string) data_get($job->input, 'mode'))->toBe('f5')
        ->and((string) data_get($job->input, 'speaker_key'))->toBe($speaker)
        ->and((bool) data_get($job->input, 'remove_silence'))->toBeTrue();
});

it('submits clone tts mobile jobs with direct multipart reference audio', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-ctts@example.com', 'mobile_ctts_user');
    assignMobilePlan($customer, 'premium');

    fakeRunpodSubmission(['runpod-clone-1']);

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'ctts'))
        ->post('/api/mobile/ctts/jobs', [
            'text' => 'Clone this voice into a new Kurdish phrase.',
            'language' => 'ar',
            'split' => true,
            'max_words' => 24,
            'fade_ms' => 80,
            'temperature' => 0.65,
            'top_k' => 50,
            'top_p' => 0.8,
            'repetition_penalty' => 2.0,
            'length_penalty' => 1.0,
            'speed' => 1.0,
            'referenceAudio' => UploadedFile::fake()->create('voice.mp3', 512, 'audio/mpeg'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Clone XTTS job started.')
        ->assertJsonPath('data.job.app', 'ctts')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'clone_tts')
        ->assertJsonPath('data.job.tool_code', 'clone_tts')
        ->assertJsonPath('data.job.tool_action', 'clone_tts.standard')
        ->assertJsonPath('data.job.input.reference_audio_name', 'voice.mp3');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-clone-1')
        ->and((string) $job->job_kind)->toBe('clone_tts')
        ->and((string) data_get($job->input, 'reference_audio_name'))->toBe('voice.mp3')
        ->and((string) data_get($job->input, 'reference_audio_path'))->toContain('/clone-tts/'.$job->id.'/')
        ->and($job->lock_expires_at)->not->toBeNull();
});

it('submits wasr mobile jobs with direct multipart audio', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-wasr@example.com', 'mobile_wasr_user');
    assignMobilePlan($customer, 'premium');

    fakeAudioProbe();
    fakeRunpodSubmission(['runpod-wasr-1']);

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'asr'))
        ->post('/api/mobile/asr/jobs', [
            'tool_code' => 'wasr',
            'language' => 'ckb',
            'chunkLengthS' => 45,
            'strideLeftS' => 5,
            'strideRightS' => 5,
            'beamSize' => 4,
            'audioFile' => UploadedFile::fake()->create('speech.mp3', 1024, 'audio/mpeg'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'WASR job started.')
        ->assertJsonPath('data.job.app', 'asr')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'wasr')
        ->assertJsonPath('data.job.tool_action', 'asr.standard');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-wasr-1')
        ->and((string) data_get($job->input, 'lang'))->toBe('ckb')
        ->and((int) data_get($job->input, 'beam_size'))->toBe(4)
        ->and($job->lock_expires_at)->not->toBeNull();
});

it('submits qasr mobile jobs with direct multipart audio', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-qasr@example.com', 'mobile_qasr_user');
    assignMobilePlan($customer, 'premium');

    fakeAudioProbe([
        'duration_sec' => 30.0,
        'duration_min' => 0.5,
        'billable_min' => 1,
        'audio_ext' => 'wav',
    ]);
    fakeRunpodSubmission(['runpod-qasr-1']);

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'asr'))
        ->post('/api/mobile/asr/jobs', [
            'tool_code' => 'qasr',
            'modelVariant' => 'fine_tuned',
            'audioFile' => UploadedFile::fake()->create('speech.wav', 1024, 'audio/wav'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'QASR job started.')
        ->assertJsonPath('data.job.app', 'asr')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'qasr')
        ->assertJsonPath('data.job.input.model_variant', 'fine_tuned')
        ->assertJsonPath('data.job.tool_action', 'qasr.standard');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-qasr-1')
        ->and((string) data_get($job->input, 'model_variant'))->toBe('fine_tuned')
        ->and((string) data_get($job->input, 'audio_path'))->toContain('/qasr/'.$job->id.'/');
});

it('submits stem mobile jobs with direct multipart audio', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-stem@example.com', 'mobile_stem_user');
    assignMobilePlan($customer, 'premium');

    fakeAudioProbe([
        'duration_sec' => 125.0,
        'duration_min' => 2.0833,
        'billable_min' => 3,
        'audio_ext' => 'mp3',
    ]);
    fakeRunpodSubmission(['runpod-stem-1']);

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'stem'))
        ->post('/api/mobile/stem/jobs', [
            'stems' => 2,
            'model' => 'htdemucs_ft',
            'stemCodec' => 'mp3',
            'stemBitrate' => '192k',
            'audioFile' => UploadedFile::fake()->create('song.mp3', 2048, 'audio/mpeg'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'STEM job submitted.')
        ->assertJsonPath('data.job.app', 'stem')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'stem')
        ->assertJsonPath('data.job.input.stems', 2)
        ->assertJsonPath('data.job.tool_action', 'stem.sep2');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-stem-1')
        ->and((int) data_get($job->input, 'stems'))->toBe(2)
        ->and((string) data_get($job->input, 'stem_codec'))->toBe('mp3')
        ->and($job->lock_expires_at)->not->toBeNull();
});

it('submits ocr mobile jobs with direct multipart pdf upload', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-ocr@example.com', 'mobile_ocr_user');
    assignMobilePlan($customer, 'premium');

    fakeRunpodSubmission(['runpod-ocr-1']);

    $response = $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'ocr'))
        ->post('/api/mobile/ocr/jobs', [
            'lang' => 'ckb+ara+eng',
            'pageRange' => '1-2',
            'dpi' => 300,
            'psm' => 6,
            'oem' => 3,
            'normalize' => false,
            'grayscale' => true,
            'autocontrast' => true,
            'sharpen' => true,
            'binarize' => false,
            'clientPdfPageCount' => 4,
            'documentFile' => UploadedFile::fake()->create('scan.pdf', 1024, 'application/pdf'),
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'OCR job submitted.')
        ->assertJsonPath('data.job.app', 'ocr')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'ocr')
        ->assertJsonPath('data.job.input.file_name', 'scan.pdf')
        ->assertJsonPath('data.job.input.page_range', '1-2')
        ->assertJsonPath('data.job.tool_action', 'ocr.standard');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-ocr-1')
        ->and((string) data_get($job->input, 'page_range'))->toBe('1-2')
        ->and((int) data_get($job->input, 'pages_estimated'))->toBe(2)
        ->and($job->lock_expires_at)->not->toBeNull();
});

it('submits translation mobile jobs with the web payload contract', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-tran@example.com', 'mobile_tran_user');
    assignMobilePlan($customer, 'premium');

    fakeRunpodSubmission(['runpod-tran-1']);

    $response = $this->withToken(mobileApiToken($customer, 'tran'))
        ->postJson('/api/mobile/tran/jobs', [
            'text' => 'Nav nivisina min e.',
            'sourceLang' => 'ku',
            'targetLang' => 'en',
            'maxNewTokens' => 300,
            'chunkChars' => 1000,
        ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Translation job started.')
        ->assertJsonPath('data.job.app', 'tran')
        ->assertJsonPath('data.job.status', 'running')
        ->assertJsonPath('data.job.job_kind', 'tran')
        ->assertJsonPath('data.job.tool_action', 'tran.standard')
        ->assertJsonPath('data.job.input.source_lang', 'ku')
        ->assertJsonPath('data.job.input.target_lang', 'en');

    $job = MlJob::query()->findOrFail((string) $response->json('data.job.id'));

    expect((string) $job->provider_job_id)->toBe('runpod-tran-1')
        ->and((string) data_get($job->input, 'source_lang'))->toBe('ku')
        ->and((string) data_get($job->input, 'target_lang'))->toBe('en')
        ->and((string) data_get($job->input, 'source_path'))->toContain('/tran/'.$job->id.'/source.txt');
});

it('returns a validation error when tts tool selection is missing', function () {
    $customer = mobileApiCustomer('mobile-validation@example.com', 'mobile_validation_user');
    assignMobilePlan($customer, 'premium');

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'text' => 'Missing selector.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['tool_code']);
});

it('returns a validation error when asr audio is missing', function () {
    $customer = mobileApiCustomer('mobile-asr-validation@example.com', 'mobile_asr_validation_user');
    assignMobilePlan($customer, 'premium');

    $this->withToken(mobileApiToken($customer, 'asr'))
        ->postJson('/api/mobile/asr/jobs', [
            'tool_code' => 'wasr',
            'language' => 'ckb',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['audioFile']);
});

it('returns a concurrency error when the customer is already at the plan limit', function () {
    $customer = mobileApiCustomer('mobile-concurrency@example.com', 'mobile_concurrency_user');
    $plan = assignMobilePlan($customer, 'premium');
    $plan->update(['concurrent_jobs_limit' => 1]);

    createMobileJob($customer, 'tts', 'tts', status: 'running');

    $speaker = firstPlanSpeaker($plan->fresh(), 'xtts');

    $this->withToken(mobileApiToken($customer, 'tts'))
        ->postJson('/api/mobile/tts/jobs', [
            'tool_code' => 'tts',
            'text' => 'This should hit the concurrency limit.',
            'speaker_id' => $speaker,
            'language' => 'ar',
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'You reached your concurrent job limit for the current plan.');
});

it('returns an ocr specific conflict when another ocr job is already active', function () {
    Storage::fake('s3');

    $customer = mobileApiCustomer('mobile-ocr-conflict@example.com', 'mobile_ocr_conflict_user');
    assignMobilePlan($customer, 'premium');

    createMobileJob($customer, 'ocr', 'ocr', status: 'running', liveLock: true);

    $this
        ->withHeader('Accept', 'application/json')
        ->withToken(mobileApiToken($customer, 'ocr'))
        ->post('/api/mobile/ocr/jobs', [
            'documentFile' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'You already have an OCR job in progress.');
});
