<?php

use App\Console\Commands\CleanupExpiredApiFiles;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\ApiUsageLog;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\PlanVoiceAccess;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\Voice;
use App\Services\Billing\PlanSwitcher;
use App\Services\CustomerApi\CustomerApiJobSyncService;
use App\Services\CustomerApi\CustomerApiKeyService;
use App\Services\Media\AudioProbeService;
use App\Services\Providers\RunPodProvider;
use App\Services\Storage\StorageFileDeletionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    $this->seed();

    config()->set('customer_api.temporary_file_ttl_days', 7);
    config()->set('customer_api.download_url_ttl_minutes', 5);
    config()->set('runpod.timeout', 5);
    config()->set('runpod.endpoints.xtts', 'endpoint-xtts');
    config()->set('runpod.endpoints.omni', 'endpoint-omni');
    config()->set('runpod.endpoints.ftts', 'endpoint-ftts');
    config()->set('runpod.endpoints.wasr', 'endpoint-wasr');
    config()->set('runpod.endpoints.qasr', 'endpoint-qasr');
    config()->set('runpod.endpoints.kocr', 'endpoint-ocr');
    config()->set('runpod.endpoints.tran', 'endpoint-tran');
    config()->set('runpod.endpoints.stem', 'endpoint-stem');
});

function publicApiCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "public_api_{$suffix}",
        'email' => $email ?? "public-api-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'usage', 'wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);
}

function assignPublicPlan(Customer $customer, string $code = 'pro'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function seedPublicWallet(Customer $customer, int $subscriptionCredits = 5000, int $addonCredits = 0): CreditWallet
{
    return CreditWallet::query()->updateOrCreate(
        [
            'customer_id' => (int) $customer->id,
            'wallet_type' => CreditWallet::TYPE_API,
        ],
        [
            'balance_credits' => $subscriptionCredits + $addonCredits,
            'subscription_balance_credits' => $subscriptionCredits,
            'addon_balance_credits' => $addonCredits,
            'lifetime_spent' => 0,
            'lifetime_refunded' => 0,
        ]
    );
}

function issuePublicApiKey(Customer $customer, array $scopes = ['*']): array
{
    return app(CustomerApiKeyService::class)->issue($customer, 'Test Key', $scopes);
}

function issueManualPublicApiKey(Customer $customer, array $scopes = ['usage:read']): string
{
    $plain = (string) config('customer_api.key_prefix', 'mk_live_').Str::random(40);

    CustomerApiKey::create([
        'customer_id' => (int) $customer->id,
        'name' => 'Manual Test Key',
        'key_prefix' => substr($plain, 0, 16),
        'key_hash' => app(CustomerApiKeyService::class)->hashKey($plain),
        'scopes' => array_values(array_unique(array_filter(array_map(
            fn (mixed $scope): string => strtolower(trim((string) $scope)),
            $scopes
        )))),
        'status' => 'active',
    ]);

    return $plain;
}

function ensurePublicVoice(ServicePlan $plan, string $engine, string $code, string $name, array $meta = []): Voice
{
    $voice = Voice::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => $name,
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 999,
            'meta' => array_merge(['engine' => $engine], $meta),
        ]
    );

    $voice->update([
        'name' => $name,
        'is_public' => true,
        'is_active' => true,
        'sort_order' => 999,
        'meta' => array_merge((array) ($voice->meta ?? []), ['engine' => $engine], $meta),
    ]);

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

    return $voice->fresh();
}

function firstPublicVoice(ServicePlan $plan, string $engine): string
{
    $voice = Voice::query()
        ->select('voices.code')
        ->join('plan_voice_access', 'plan_voice_access.voice_id', '=', 'voices.id')
        ->where('plan_voice_access.service_plan_id', (int) $plan->id)
        ->where('plan_voice_access.is_active', true)
        ->where('voices.is_active', true)
        ->where('voices.meta->engine', $engine)
        ->orderBy('plan_voice_access.sort_order')
        ->orderBy('voices.sort_order')
        ->firstOrFail();

    return (string) $voice->code;
}

function fakePublicRunpodSubmission(array $providerJobIds): void
{
    $mock = \Mockery::mock(RunPodProvider::class);

    foreach ($providerJobIds as $providerJobId) {
        $mock->shouldReceive('run')
            ->once()
            ->andReturn(['id' => $providerJobId]);
    }

    app()->instance(RunPodProvider::class, $mock);
}

function fakePublicRunpodStatus(string $providerJobId, array $output = []): void
{
    $mock = \Mockery::mock(RunPodProvider::class);
    $mock->shouldReceive('status')
        ->once()
        ->withAnyArgs()
        ->andReturn([
            'id' => $providerJobId,
            'status' => 'COMPLETED',
            'output' => array_merge([
                'wav_b64' => base64_encode('fake-wav-data'),
                'mime_type' => 'audio/wav',
                'output_filename' => 'result.wav',
                'success' => true,
            ], $output),
        ]);

    app()->instance(RunPodProvider::class, $mock);
}

function fakePublicAudioProbe(array $overrides = []): void
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

function ensurePublicApiPricingRule(string $actionCode, string $metricCode, int $creditsPerUnit = 1): void
{
    $actionId = (int) ToolAction::query()->where('full_code', $actionCode)->value('id');

    PricingRule::query()->updateOrCreate(
        [
            'tool_action_id' => $actionId,
            'service_plan_id' => null,
            'pricing_channel' => 'api',
            'rule_scope' => 'global',
            'rule_type' => 'unit',
            'metric_code' => $metricCode,
        ],
        [
            'priority' => 100,
            'unit_size' => 1,
            'credits_per_unit' => $creditsPerUnit,
            'rounding_mode' => 'ceil',
            'rounding_step' => 1,
            'minimum_credits' => 1,
            'is_active' => true,
        ]
    );
}

it('retains Apollo submissions and aliases with an explicitly provisioned catalog', function (string $endpoint, string $engine, string $tool, string $action, string $scope) {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    // The legacy suite's default seed predates Omni; provision only this isolated fixture.
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    Storage::fake('s3');
    $customer = publicApiCustomer();
    $plan = assignPublicPlan($customer);
    $plan->update(['api_allowed_tools' => ['*']]);
    $toolAction = ToolAction::where('full_code', $action)->firstOrFail();
    \App\Models\PlanEntitlement::updateOrCreate([
        'service_plan_id' => $plan->id,
        'tool_action_id' => $toolAction->id,
        'entitlement_channel' => 'api',
    ], ['allowed' => true]);
    ensurePublicApiPricingRule($action, 'character');
    ensurePublicVoice($plan, $engine, 'compatibility-voice', 'Compatibility voice', ['ref_audio' => 'voices/fixture.wav']);
    seedPublicWallet($customer, 20000);
    $appBalance = CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $key = issuePublicApiKey($customer->fresh(), [$scope])['plain_text_key'];
    fakePublicRunpodSubmission(['compatibility-provider-job']);
    $this->withToken($key)->getJson($endpoint.'/voices')->assertOk()->assertJsonFragment(['speaker_id' => 'compatibility-voice']);
    $headers = ['Idempotency-Key' => 'compatibility-intent'];
    $payload = ['text' => 'Compatibility request.', 'speaker_id' => 'compatibility-voice', 'language' => 'ar'];
    $response = $this->withToken($key)->postJson($endpoint, $payload, $headers)->assertAccepted();
    $job = ApiJob::findOrFail($response->json('job_id'));
    expect($job->tool_code)->toBe($tool)->and($job->tool_action)->toBe($action)
        ->and($job->engine)->toBe($engine)->and($job->mlJob->job_kind)->toBe($tool);
    $chargedBalance = CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'api')->value('balance_credits');
    expect((int) $chargedBalance)->toBeLessThan(20000);
    $this->withToken($key)->postJson($endpoint, $payload, $headers)->assertOk()->assertJsonPath('job_id', $job->id);
    expect(ApiJob::count())->toBe(1)
        ->and(CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'api')->value('balance_credits'))->toBe($chargedBalance)
        ->and(CreditWallet::where('customer_id', $customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($appBalance);
})->with([
    'Apollo 1.0' => ['/api/v1/tts/apollo-1-0v', 'xtts', 'tts', 'tts.standard', 'tts:apollo-1-0v'],
    'XTTS alias' => ['/api/v1/tts/xtts', 'xtts', 'tts', 'tts.standard', 'tts:xtts'],
    'Apollo 1.5' => ['/api/v1/tts/apollo-1-5v', 'xomni', 'xomni', 'xomni.generate', 'tts:apollo-1-5v'],
    'XOMNI alias' => ['/api/v1/tts/xomni', 'xomni', 'xomni', 'xomni.generate', 'tts:xomni'],
]);

it('blocks free plan customers from the public api', function () {
    $customer = publicApiCustomer('public-free@example.com', 'public_free_user');
    seedPublicWallet($customer, 1000);
    $key = issueManualPublicApiKey($customer, ['usage:read']);

    $this->withToken($key)
        ->getJson('/api/v1/me')
        ->assertStatus(403)
        ->assertJsonPath('message', 'API access is available only on paid plans.')
        ->assertJsonPath('code', 'api_access_required');
});

it('returns public api identity and plan limits for a paid customer', function () {
    $customer = publicApiCustomer('public-me@example.com', 'public_me_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 24000);

    $issued = issuePublicApiKey($customer, ['tts:apollo-1-0v', 'tts:apollo-1-5v', 'usage:read']);
    $key = $issued['plain_text_key'];
    $record = $issued['api_key']->fresh();

    expect((string) $record->key_hash)->not->toBe($key)
        ->and((string) $record->key_prefix)->toStartWith('mk_live_');

    $this->withToken($key)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('customer.id', (int) $customer->id)
        ->assertJsonPath('plan.code', 'pro')
        ->assertJsonPath('plan.api_enabled', true)
        ->assertJsonPath('credits.balance', 24000)
        ->assertJsonPath('rate_limit.requests_per_minute', 300)
        ->assertJsonPath('rate_limit.concurrent_jobs', 10)
        ->assertJsonPath('scopes.0', 'tts:apollo-1-0v');
});

it('uses api channel pricing for public api jobs and debits only the api wallet', function () {
    $customer = publicApiCustomer('public-api-pricing@example.com', 'public_api_pricing_user');
    $plan = assignPublicPlan($customer, 'pro');
    ensurePublicVoice($plan->fresh(), 'xtts', 'public_api_pricing_voice', 'Public API Pricing Voice');
    seedPublicWallet($customer, 5000);
    $speaker = firstPublicVoice($plan->fresh(), 'xtts');
    $key = issuePublicApiKey($customer, ['tts:apollo-1-0v'])['plain_text_key'];
    $actionId = (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id');

    PricingRule::query()->where('tool_action_id', $actionId)->delete();

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'app',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 1,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    PricingRule::query()->create([
        'tool_action_id' => $actionId,
        'service_plan_id' => null,
        'pricing_channel' => 'api',
        'rule_scope' => 'global',
        'rule_type' => 'unit',
        'priority' => 100,
        'metric_code' => 'character',
        'unit_size' => 1,
        'credits_per_unit' => 3,
        'rounding_mode' => 'ceil',
        'rounding_step' => 1,
        'minimum_credits' => 1,
        'is_active' => true,
    ]);

    fakePublicRunpodSubmission(['runpod-public-pricing-1']);

    $response = $this->withToken($key)
        ->postJson('/api/v1/tts/apollo-1-0v', [
            'text' => 'API priced text.',
            'speaker_id' => $speaker,
            'language' => 'ar',
        ]);

    $response->assertStatus(202);

    $jobId = (string) $response->json('job_id');
    $expectedCredits = mb_strlen('API priced text.') * 3;

    expect((int) ApiJob::query()->findOrFail($jobId)->estimated_credits)->toBe($expectedCredits)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->balance_credits)->toBe(5000 - $expectedCredits);
});

it('rejects invalid and revoked public api keys', function () {
    $customer = publicApiCustomer('public-auth@example.com', 'public_auth_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 2000);

    $this->withToken('mk_live_invalid_key')
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key');

    $issued = issuePublicApiKey($customer);
    $issued['api_key']->forceFill([
        'status' => 'revoked',
        'revoked_at' => now(),
    ])->save();

    $this->withToken($issued['plain_text_key'])
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key');
});

it('enforces the plan based public api rate limit', function () {
    $customer = publicApiCustomer('public-rate@example.com', 'public_rate_user');
    $plan = assignPublicPlan($customer, 'student');
    $plan->update(['api_requests_per_minute' => 2]);
    seedPublicWallet($customer, 3000);
    $key = issuePublicApiKey($customer, ['usage:read'])['plain_text_key'];

    $this->withToken($key)->getJson('/api/v1/me')->assertOk();
    $this->withToken($key)->getJson('/api/v1/me')->assertOk();
    $this->withToken($key)
        ->getJson('/api/v1/me')
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limit_exceeded');
});

it('enforces the public api concurrency limit before creating a new job', function () {
    $customer = publicApiCustomer('public-concurrency@example.com', 'public_concurrency_user');
    $plan = assignPublicPlan($customer, 'student');
    $plan->update(['api_concurrent_jobs' => 1]);
    seedPublicWallet($customer, 4000);
    ensurePublicVoice($plan->fresh(), 'xtts', 'public_xtts_voice', 'Public XTTS Voice');
    $speaker = firstPublicVoice($plan->fresh(), 'xtts');
    $key = issuePublicApiKey($customer, ['tts:apollo-1-0v'])['plain_text_key'];

    $tool = Tool::query()->where('code', 'tts')->firstOrFail();
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();
    $mlJobId = (string) Str::uuid();

    MlJob::create([
        'id' => $mlJobId,
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) $tool->id,
        'tool_action_id' => (int) $action->id,
        'job_kind' => 'tts',
        'status' => 'running',
        'provider' => 'runpod',
        'provider_job_id' => 'already-running',
        'input' => ['text' => 'Busy'],
        'credits_charged' => 10,
        'started_at' => now(),
    ]);

    ApiJob::create([
        'id' => 'job_'.Str::lower((string) Str::ulid()),
        'customer_id' => (int) $customer->id,
        'api_key_id' => (int) issuePublicApiKey($customer)['api_key']->id,
        'ml_job_id' => $mlJobId,
        'tool_code' => 'tts',
        'tool_action' => 'tts.standard',
        'engine' => 'xtts',
        'status' => 'processing',
        'input_hash' => hash('sha256', 'busy'),
        'estimated_credits' => 10,
        'reserved_credits' => 10,
        'storage_mode' => 'temporary',
        'started_at' => now(),
    ]);

    $this->withToken($key)
        ->postJson('/api/v1/tts/apollo-1-0v', [
            'text' => 'This should hit the API concurrency limit.',
            'speaker_id' => $speaker,
            'language' => 'ar',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'concurrency_limit_exceeded');
});

it('submits xtts api jobs idempotently and stores api job metadata on the ml job', function () {
    $customer = publicApiCustomer('public-xtts@example.com', 'public_xtts_user');
    $plan = assignPublicPlan($customer, 'pro');
    ensurePublicVoice($plan->fresh(), 'xtts', 'public_xtts_voice', 'Public XTTS Voice');
    seedPublicWallet($customer, 5000);
    $speaker = firstPublicVoice($plan->fresh(), 'xtts');
    $key = issuePublicApiKey($customer, ['tts:apollo-1-0v'])['plain_text_key'];

    fakePublicRunpodSubmission(['runpod-public-xtts-1']);

    $payload = [
        'text' => 'Hello from the public XTTS API.',
        'speaker_id' => $speaker,
        'language' => 'ar',
        'storage' => ['mode' => 'temporary'],
    ];

    $first = $this
        ->withHeader('Idempotency-Key', 'public-xtts-1')
        ->withToken($key)
        ->postJson('/api/v1/tts/apollo-1-0v', $payload);

    $first->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('storage.mode', 'temporary')
        ->assertJsonPath('storage.expires_in_days', 7);

    $jobId = (string) $first->json('job_id');
    $apiJob = ApiJob::query()->findOrFail($jobId);
    $mlJob = MlJob::query()->findOrFail((string) $apiJob->ml_job_id);

    expect((string) data_get($mlJob->input, 'api_job_id'))->toBe($jobId)
        ->and((string) data_get($mlJob->input, 'api_storage_mode'))->toBe('temporary')
        ->and((string) $mlJob->provider_job_id)->toBe('runpod-public-xtts-1');

    $second = $this
        ->withHeader('Idempotency-Key', 'public-xtts-1')
        ->withToken($key)
        ->postJson('/api/v1/tts/apollo-1-0v', $payload);

    $second->assertOk()
        ->assertJsonPath('job_id', $jobId);

    expect(ApiJob::query()->count())->toBe(1)
        ->and(ApiUsageLog::query()->count())->toBe(1);
});

it('lists product-route voices for paid scoped keys', function () {
    $customer = publicApiCustomer('public-product-voices@example.com', 'public_product_voices_user');
    $plan = assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 5000);

    ensurePublicVoice($plan->fresh(), 'xtts', 'public_apollo10_voice', 'Public Apollo 1.0 Voice');
    ensurePublicVoice($plan->fresh(), 'xomni', 'public_apollo15_voice', 'Public Apollo 1.5 Voice', ['ref_audio' => 'voices/ref.wav']);
    ensurePublicVoice($plan->fresh(), 'ftts', 'public_delta10_voice', 'Public Delta 1.0 Voice');

    $key = issuePublicApiKey($customer, ['tts:apollo-1-0v', 'tts:apollo-1-5v', 'tts:delta-1-0v'])['plain_text_key'];

    $this->withToken($key)
        ->getJson('/api/v1/tts/apollo-1-0v/voices')
        ->assertOk()
        ->assertJsonFragment(['speaker_id' => 'public_apollo10_voice']);

    $this->withToken($key)
        ->getJson('/api/v1/tts/apollo-1-5v/voices')
        ->assertOk()
        ->assertJsonFragment(['speaker_id' => 'public_apollo15_voice']);

    $this->withToken($key)
        ->getJson('/api/v1/tts/delta-1-0v/voices')
        ->assertOk()
        ->assertJsonFragment(['speaker_id' => 'public_delta10_voice']);
});

it('submits renamed tts product routes to the expected internal jobs', function () {
    $customer = publicApiCustomer('public-product-submit@example.com', 'public_product_submit_user');
    $plan = assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 20000);

    ensurePublicVoice($plan->fresh(), 'xtts', 'public_apollo10_submit_voice', 'Public Apollo 1.0 Submit Voice');
    ensurePublicVoice($plan->fresh(), 'xomni', 'public_apollo15_submit_voice', 'Public Apollo 1.5 Submit Voice', ['ref_audio' => 'voices/ref.wav']);
    ensurePublicVoice($plan->fresh(), 'ftts', 'public_delta10_submit_voice', 'Public Delta 1.0 Submit Voice');

    ensurePublicApiPricingRule('tts.standard', 'character');
    ensurePublicApiPricingRule('xomni.generate', 'character');
    ensurePublicApiPricingRule('ftts.standard', 'character');

    fakePublicRunpodSubmission(['runpod-apollo10-1', 'runpod-apollo15-1', 'runpod-delta10-1']);

    $apolloKey = issuePublicApiKey($customer, ['tts:apollo-1-0v'])['plain_text_key'];
    $apollo15Key = issuePublicApiKey($customer, ['tts:apollo-1-5v'])['plain_text_key'];
    $deltaKey = issuePublicApiKey($customer, ['tts:delta-1-0v'])['plain_text_key'];

    $apollo = $this->withToken($apolloKey)->postJson('/api/v1/tts/apollo-1-0v', [
        'text' => 'Apollo 1.0 request.',
        'speaker_id' => 'public_apollo10_submit_voice',
        'language' => 'ar',
    ]);
    $apollo->assertStatus(202);

    $apolloJob = ApiJob::query()->findOrFail((string) $apollo->json('job_id'));
    expect((string) $apolloJob->tool_code)->toBe('tts')
        ->and((string) $apolloJob->tool_action)->toBe('tts.standard')
        ->and((string) $apolloJob->engine)->toBe('xtts')
        ->and((string) MlJob::query()->findOrFail((string) $apolloJob->ml_job_id)->job_kind)->toBe('tts');

    $apollo15 = $this->withToken($apollo15Key)->postJson('/api/v1/tts/apollo-1-5v', [
        'text' => 'Apollo 1.5 request.',
        'speaker_id' => 'public_apollo15_submit_voice',
        'language' => 'ckb',
    ]);
    $apollo15->assertStatus(202);

    $apollo15Job = ApiJob::query()->findOrFail((string) $apollo15->json('job_id'));
    expect((string) $apollo15Job->tool_code)->toBe('xomni')
        ->and((string) $apollo15Job->tool_action)->toBe('xomni.generate')
        ->and((string) $apollo15Job->engine)->toBe('xomni')
        ->and((string) MlJob::query()->findOrFail((string) $apollo15Job->ml_job_id)->job_kind)->toBe('xomni');

    $delta = $this->withToken($deltaKey)->postJson('/api/v1/tts/delta-1-0v', [
        'text' => 'Delta 1.0 request.',
        'speaker_id' => 'public_delta10_submit_voice',
    ]);
    $delta->assertStatus(202);

    $deltaJob = ApiJob::query()->findOrFail((string) $delta->json('job_id'));
    expect((string) $deltaJob->tool_code)->toBe('ftts')
        ->and((string) $deltaJob->tool_action)->toBe('ftts.standard')
        ->and((string) $deltaJob->engine)->toBe('ftts')
        ->and((string) MlJob::query()->findOrFail((string) $deltaJob->ml_job_id)->job_kind)->toBe('ftts');
});

it('submits vector clone routes to the expected internal jobs', function () {
    Storage::fake('s3');

    $customer = publicApiCustomer('public-vector-submit@example.com', 'public_vector_submit_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 20000);

    ensurePublicApiPricingRule('clone_tts.standard', 'character');
    ensurePublicApiPricingRule('clone_xomni.generate', 'character');
    fakePublicRunpodSubmission(['runpod-vector10-1', 'runpod-vector15-1']);

    $vector10Key = issuePublicApiKey($customer, ['tts:vector-1-0'])['plain_text_key'];
    $vector15Key = issuePublicApiKey($customer, ['tts:vector-1-5'])['plain_text_key'];

    $vector10 = $this
        ->withToken($vector10Key)
        ->post('/api/v1/tts/vector-1-0', [
            'text' => 'Vector 1.0 request.',
            'language' => 'ar',
            'referenceAudio' => UploadedFile::fake()->create('vector10.mp3', 256, 'audio/mpeg'),
        ]);
    $vector10->assertStatus(202);

    $vector10Job = ApiJob::query()->findOrFail((string) $vector10->json('job_id'));
    expect((string) $vector10Job->tool_code)->toBe('clone_tts')
        ->and((string) $vector10Job->tool_action)->toBe('clone_tts.standard')
        ->and((string) MlJob::query()->findOrFail((string) $vector10Job->ml_job_id)->job_kind)->toBe('clone_tts');

    $vector15 = $this
        ->withToken($vector15Key)
        ->post('/api/v1/tts/vector-1-5', [
            'text' => 'Vector 1.5 request.',
            'language' => 'ckb',
            'referenceAudio' => UploadedFile::fake()->create('vector15.mp3', 256, 'audio/mpeg'),
        ]);
    $vector15->assertStatus(202);

    $vector15Job = ApiJob::query()->findOrFail((string) $vector15->json('job_id'));
    expect((string) $vector15Job->tool_code)->toBe('clone_xomni')
        ->and((string) $vector15Job->tool_action)->toBe('clone_xomni.generate')
        ->and((string) MlJob::query()->findOrFail((string) $vector15Job->ml_job_id)->job_kind)->toBe('clone_xomni');
});

it('submits the remaining public api tools through api-only wallet billing', function () {
    Storage::fake('s3');

    $customer = publicApiCustomer('public-remaining-tools@example.com', 'public_remaining_tools_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 50000);
    $initialAppBalance = (int) $customer->fresh()->wallet()->firstOrFail()->balance_credits;

    ensurePublicApiPricingRule('asr.standard', 'minute');
    ensurePublicApiPricingRule('qasr.standard', 'minute');
    ensurePublicApiPricingRule('caption.standard', 'minute');
    ensurePublicApiPricingRule('ocr.standard', 'page');
    ensurePublicApiPricingRule('tran.standard', 'character');
    ensurePublicApiPricingRule('stem.sep2', 'stem_output');

    fakePublicAudioProbe();
    fakePublicRunpodSubmission([
        'runpod-wasr-public-1',
        'runpod-qasr-public-1',
        'runpod-caption-public-1',
        'runpod-ocr-public-1',
        'runpod-tran-public-1',
        'runpod-stem-public-1',
    ]);

    $wasrKey = issuePublicApiKey($customer, ['asr:wasr'])['plain_text_key'];
    $qasrKey = issuePublicApiKey($customer, ['asr:qasr'])['plain_text_key'];
    $captionKey = issuePublicApiKey($customer, ['caption:qasr'])['plain_text_key'];
    $ocrKey = issuePublicApiKey($customer, ['ocr:generate'])['plain_text_key'];
    $translateKey = issuePublicApiKey($customer, ['translation:generate'])['plain_text_key'];
    $stemKey = issuePublicApiKey($customer, ['stem:generate'])['plain_text_key'];

    $releaseQueuedJob = function (string $apiJobId): void {
        $apiJob = ApiJob::query()->findOrFail($apiJobId);

        MlJob::query()->where('id', (string) $apiJob->ml_job_id)->update([
            'status' => 'completed',
            'finished_at' => now(),
            'lock_expires_at' => null,
            'execution_scope' => null,
            'locked_by_session_id' => null,
            'locked_by_fingerprint' => null,
        ]);

        ApiJob::query()->where('id', $apiJobId)->update([
            'status' => 'completed',
        ]);
    };

    $wasr = $this->withToken($wasrKey)->post('/api/v1/asr/wasr', [
        'audioFile' => UploadedFile::fake()->create('speech.mp3', 256, 'audio/mpeg'),
        'language' => 'ckb',
    ]);
    $wasr->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $wasr->json('job_id'))->tool_action)->toBe('asr.standard');
    $releaseQueuedJob((string) $wasr->json('job_id'));

    $qasr = $this
        ->withHeader('Accept', 'application/json')
        ->withToken($qasrKey)
        ->post('/api/v1/asr/qasr', [
            'audioFile' => UploadedFile::fake()->create('speech-qasr.mp3', 256, 'audio/mpeg'),
            'modelVariant' => 'fine_tuned',
            'language' => 'ckb',
        ]);
    $qasr->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $qasr->json('job_id'))->tool_action)->toBe('qasr.standard');
    $releaseQueuedJob((string) $qasr->json('job_id'));

    $caption = $this
        ->withHeader('Accept', 'application/json')
        ->withToken($captionKey)
        ->post('/api/v1/caption/qasr', [
            'audioFile' => UploadedFile::fake()->create('caption.mp3', 256, 'audio/mpeg'),
            'modelVariant' => 'fine_tuned',
            'language' => 'ckb',
            'outputFormat' => 'srt',
            'returnSrt' => 'true',
            'returnSegments' => 'true',
            'maxWordsPerCaption' => 8,
            'maxCaptionSeconds' => 6,
            'minCaptionSeconds' => 1,
        ]);
    $caption->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $caption->json('job_id'))->tool_action)->toBe('caption.standard');
    $releaseQueuedJob((string) $caption->json('job_id'));

    $ocr = $this->withToken($ocrKey)->post('/api/v1/ocr', [
        'documentFile' => UploadedFile::fake()->create('scan.pdf', 256, 'application/pdf'),
        'lang' => 'ckb+ara+eng',
    ]);
    $ocr->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $ocr->json('job_id'))->tool_action)->toBe('ocr.standard');

    $translate = $this->withToken($translateKey)->postJson('/api/v1/translate', [
        'text' => 'Nav nivisina min e.',
        'sourceLang' => 'ku',
        'targetLang' => 'en',
    ]);
    $translate->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $translate->json('job_id'))->tool_action)->toBe('tran.standard');

    $stem = $this->withToken($stemKey)->post('/api/v1/stem', [
        'audioFile' => UploadedFile::fake()->create('song.mp3', 256, 'audio/mpeg'),
        'stems' => 2,
        'model' => 'htdemucs_ft',
        'stemCodec' => 'mp3',
        'stemBitrate' => '192k',
    ]);
    $stem->assertStatus(202);
    expect((string) ApiJob::query()->findOrFail((string) $stem->json('job_id'))->tool_action)->toBe('stem.sep2')
        ->and((int) $customer->fresh()->wallet()->firstOrFail()->balance_credits)->toBe($initialAppBalance)
        ->and((int) $customer->fresh()->apiWallet()->firstOrFail()->balance_credits)->toBeLessThan(50000);
});

it('syncs temporary xtts outputs without counting against storage quota', function () {
    Storage::fake('s3');

    $customer = publicApiCustomer('public-temp-output@example.com', 'public_temp_output_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 4000);
    $apiKey = issuePublicApiKey($customer)['api_key'];

    $tool = Tool::query()->where('code', 'tts')->firstOrFail();
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();
    $mlJobId = (string) Str::uuid();
    $apiJobId = 'job_'.Str::lower((string) Str::ulid());

    MlJob::create([
        'id' => $mlJobId,
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) $tool->id,
        'tool_action_id' => (int) $action->id,
        'job_kind' => 'tts',
        'status' => 'running',
        'provider' => 'runpod',
        'provider_job_id' => 'sync-temp-job',
        'input' => [
            'text' => 'Temporary output',
            'speaker_id' => 'voice-temp',
            'api_job_id' => $apiJobId,
            'api_storage_mode' => 'temporary',
            'api_expires_at' => now()->addDays(7)->toIso8601String(),
        ],
        'credits_charged' => 12,
        'started_at' => now(),
    ]);

    ApiJob::create([
        'id' => $apiJobId,
        'customer_id' => (int) $customer->id,
        'api_key_id' => (int) $apiKey->id,
        'ml_job_id' => $mlJobId,
        'tool_code' => 'tts',
        'tool_action' => 'tts.standard',
        'engine' => 'xtts',
        'status' => 'processing',
        'input_hash' => hash('sha256', 'temp-output'),
        'estimated_credits' => 12,
        'reserved_credits' => 12,
        'storage_mode' => 'temporary',
        'started_at' => now(),
    ]);

    fakePublicRunpodStatus('sync-temp-job');

    /** @var ApiJob $fresh */
    $fresh = app(CustomerApiJobSyncService::class)->refresh(ApiJob::query()->findOrFail($apiJobId));
    $file = CustomerFile::query()->where('source_id', $apiJobId)->firstOrFail();

    expect((string) $fresh->status)->toBe('completed')
        ->and((string) $file->retention_mode)->toBe('temporary')
        ->and($file->expires_at)->not->toBeNull()
        ->and((bool) $file->counts_toward_quota)->toBeFalse()
        ->and((int) ($customer->usage()->first()?->storage_used_bytes ?? 0))->toBe(0);

    expect(ApiResultFile::query()->where('api_job_id', $apiJobId)->exists())->toBeTrue();
});

it('syncs permanent xtts outputs into customer storage and counts quota', function () {
    Storage::fake('s3');

    $customer = publicApiCustomer('public-permanent-output@example.com', 'public_permanent_output_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 4000);
    $apiKey = issuePublicApiKey($customer)['api_key'];

    $tool = Tool::query()->where('code', 'tts')->firstOrFail();
    $action = ToolAction::query()->where('full_code', 'tts.standard')->firstOrFail();
    $mlJobId = (string) Str::uuid();
    $apiJobId = 'job_'.Str::lower((string) Str::ulid());

    MlJob::create([
        'id' => $mlJobId,
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) $tool->id,
        'tool_action_id' => (int) $action->id,
        'job_kind' => 'tts',
        'status' => 'running',
        'provider' => 'runpod',
        'provider_job_id' => 'sync-permanent-job',
        'input' => [
            'text' => 'Permanent output',
            'speaker_id' => 'voice-permanent',
            'api_job_id' => $apiJobId,
            'api_storage_mode' => 'permanent',
            'api_expires_at' => null,
        ],
        'credits_charged' => 12,
        'started_at' => now(),
    ]);

    ApiJob::create([
        'id' => $apiJobId,
        'customer_id' => (int) $customer->id,
        'api_key_id' => (int) $apiKey->id,
        'ml_job_id' => $mlJobId,
        'tool_code' => 'tts',
        'tool_action' => 'tts.standard',
        'engine' => 'xtts',
        'status' => 'processing',
        'input_hash' => hash('sha256', 'permanent-output'),
        'estimated_credits' => 12,
        'reserved_credits' => 12,
        'storage_mode' => 'permanent',
        'started_at' => now(),
    ]);

    fakePublicRunpodStatus('sync-permanent-job');

    app(CustomerApiJobSyncService::class)->refresh(ApiJob::query()->findOrFail($apiJobId));
    $file = CustomerFile::query()->where('source_id', $apiJobId)->firstOrFail();

    expect((string) $file->retention_mode)->toBe('permanent')
        ->and($file->expires_at)->toBeNull()
        ->and((bool) $file->counts_toward_quota)->toBeTrue()
        ->and((int) ($customer->usage()->first()?->storage_used_bytes ?? 0))->toBe((int) $file->size_bytes);
});

it('invalidates api downloads when a my storage file is deleted', function () {
    Storage::fake('s3');

    $customer = publicApiCustomer('public-delete@example.com', 'public_delete_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 4000);
    $key = issuePublicApiKey($customer)['plain_text_key'];

    Storage::disk('s3')->put('renders/public/delete/output.wav', 'hello-world');

    $file = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'tts',
        'disk' => 's3',
        'path' => 'renders/public/delete/output.wav',
        'size_bytes' => 11,
        'mime' => 'audio/wav',
        'status' => 'active',
        'retention_mode' => 'permanent',
        'counts_toward_quota' => true,
        'source_type' => 'api_job',
        'source_id' => 'job_delete_test',
    ]);

    $result = ApiResultFile::create([
        'id' => 'file_'.Str::lower((string) Str::ulid()),
        'customer_id' => (int) $customer->id,
        'api_job_id' => 'job_delete_test',
        'storage_file_id' => (int) $file->id,
        'result_kind' => 'primary',
    ]);

    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    app(StorageFileDeletionService::class)->delete($file, 'customer_deleted');

    $this->withToken($key)
        ->getJson('/api/v1/files/'.$result->id.'/download')
        ->assertStatus(404)
        ->assertJsonPath('code', 'file_unavailable');
});

it('cleans up expired temporary api files and skips already deleted records', function () {
    Storage::fake('s3');
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);

    $customer = publicApiCustomer('public-cleanup@example.com', 'public_cleanup_user');
    assignPublicPlan($customer, 'pro');
    seedPublicWallet($customer, 4000);

    Storage::disk('s3')->put('renders/public/cleanup/expired.wav', 'expired');
    Storage::disk('s3')->put('renders/public/cleanup/deleted.wav', 'deleted');

    $expired = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'tts',
        'disk' => 's3',
        'path' => 'renders/public/cleanup/expired.wav',
        'size_bytes' => 7,
        'mime' => 'audio/wav',
        'status' => 'active',
        'retention_mode' => 'temporary',
        'expires_at' => now()->subMinute(),
        'counts_toward_quota' => false,
    ]);

    $alreadyDeleted = CustomerFile::create([
        'customer_id' => (int) $customer->id,
        'purpose' => 'render',
        'tool_code' => 'tts',
        'disk' => 's3',
        'path' => 'renders/public/cleanup/deleted.wav',
        'size_bytes' => 7,
        'mime' => 'audio/wav',
        'status' => 'deleted',
        'retention_mode' => 'temporary',
        'expires_at' => now()->subMinute(),
        'deleted_at' => now()->subMinute(),
        'delete_reason' => 'customer_deleted',
        'counts_toward_quota' => false,
    ]);

    $this->artisan(CleanupExpiredApiFiles::class, ['--limit' => 10])
        ->assertSuccessful();

    expect($expired->fresh()->status)->toBe('deleted')
        ->and($expired->fresh()->delete_reason)->toBe('expired')
        ->and($alreadyDeleted->fresh()->delete_reason)->toBe('customer_deleted');
});
