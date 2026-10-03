<?php

use App\Jobs\ReconcileMlJob;
use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Models\Voice;
use App\Services\Billing\PlanSwitcher;
use App\Services\CustomerApi\CustomerApiKeyService;
use App\Services\Media\AudioProbeService;
use App\Services\Providers\RunPodProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    config()->set('customer_api.v2_enabled', true);
    Storage::fake('s3')->buildTemporaryUrlsUsing(fn () => 'https://storage.example.test/input?test=1');
    config()->set('runpod.endpoints.omni_v2', 'test-omni');
    config()->set('runpod.endpoints.qasr_v2', 'test-qasr');
    config()->set('runpod.v2_input_hosts', ['storage.example.test']);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $plan->update(['api_enabled' => true, 'api_allowed_tools' => ['*'], 'api_requests_per_minute' => 100, 'api_concurrent_jobs' => 10]);
    $this->customer = Customer::create(['username' => 'api-v2-owner', 'email' => 'api-v2@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    app(PlanSwitcher::class)->switchServicePlan($this->customer, $plan->id, ['provider' => 'fake', 'billing_cycle' => 'monthly']);
    $this->customer = $this->customer->fresh();
    foreach (ToolAction::whereIn('full_code', ['xomni.generate', 'xomni-v2.generate', 'clone_xomni.generate', 'vector-v2.generate', 'leo.transcribe', 'caption.standard', 'ocr.standard', 'stem.sep2', 'stem.sep4', 'zeta.generate', 'theta.generate', 'harakat.diacritize'])->get() as $action) {
        $action->update(['is_active' => true]);
        $action->tool->update(['is_active' => true]);
        PlanEntitlement::updateOrCreate(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'api'], ['allowed' => true]);
        if (! PricingRule::where('tool_action_id', $action->id)->where('pricing_channel', 'api')->exists()) {
            $rule = PricingRule::where('tool_action_id', $action->id)->first();
            if ($rule) {
                $copy = $rule->replicate();
                $copy->pricing_channel = 'api';
                $copy->save();
            }
        }
    }
    CreditWallet::updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => 'api'], ['balance_credits' => 1000000, 'subscription_balance_credits' => 1000000, 'addon_balance_credits' => 0]);
    Voice::create(['code' => 'api-v2-voice', 'name' => 'Test voice', 'is_public' => true, 'is_active' => true, 'meta' => ['engine' => 'xomni', 'ref_audio' => 'voices/test.wav']]);
    $issued = app(CustomerApiKeyService::class)->issueV2($this->customer, 'V2 test');
    $this->key = $issued['api_key'];
    $this->headers = ['Authorization' => 'Bearer '.$issued['plain_text_key'], 'Idempotency-Key' => 'test-intent'];
    $this->speech = ['text' => 'Hello', 'voice' => 'api-v2-voice', 'language' => 'en', 'model' => '2.0'];
});

it('requires an active hashed customer key', function () {
    $this->getJson('/api/v2/services')->assertUnauthorized()->assertJsonPath('error.code', 'authentication_failed');
    $this->getJson('/api/v2/services', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    $this->getJson('/api/v2/services', $this->headers)->assertOk()->assertDontSee('translation');
    expect($this->key->toArray())->not->toHaveKey('key_hash');
    app(CustomerApiKeyService::class)->revoke($this->key);
    $this->getJson('/api/v2/services', $this->headers)->assertUnauthorized();
});

it('discovers stable safe voice codes with effective plan access and shared invalidation', function () {
    $planId = $this->customer->currentServicePlanId();
    $otherPlan = ServicePlan::where('id', '!=', $planId)->where('is_free', false)->where('is_active', true)->firstOrFail();
    $create = fn ($code, $extra = []) => Voice::create(array_replace_recursive([
        'code' => $code, 'name' => 'Friendly '.$code, 'is_public' => false, 'is_active' => true,
        'meta' => ['engine' => 'xomni', 'ref_audio' => 'voices/private-file.wav', 'ref_text' => 'PRIVATE TRANSCRIPT', 'provider' => 'PRIVATE PROVIDER', 'preview_audio' => 'https://example.test/private?signature=secret'],
    ], $extra));
    $allowed = $create('plan-allowed');
    $foreign = $create('other-plan-only');
    $denied = $create('inactive-grant');
    $create('inactive-voice', ['is_public' => true, 'is_active' => false]);
    $create('wrong-engine', ['is_public' => true, 'meta' => ['engine' => 'xtts']]);
    $create('missing-reference', ['is_public' => true, 'meta' => ['ref_audio' => '']]);
    $create('unsafe-reference', ['is_public' => true, 'meta' => ['ref_audio' => '../private.wav']]);
    $grant = App\Models\PlanVoiceAccess::create(['service_plan_id' => $planId, 'voice_id' => $allowed->id, 'is_active' => true]);
    App\Models\PlanVoiceAccess::create(['service_plan_id' => $otherPlan->id, 'voice_id' => $foreign->id, 'is_active' => true]);
    App\Models\PlanVoiceAccess::create(['service_plan_id' => $planId, 'voice_id' => $denied->id, 'is_active' => false]);

    $response = $this->getJson('/api/v2/voices', $this->headers)->assertOk();
    expect(array_keys($response->json()))->toBe(['voices']);
    foreach ($response->json('voices') as $voice) {
        expect(array_keys($voice))->toBe(['id', 'name'])->and($voice['id'])->toBeString();
    }
    expect(array_column($response->json('voices'), 'id'))->toContain('api-v2-voice', 'plan-allowed')
        ->not->toContain('other-plan-only', 'inactive-grant', 'inactive-voice', 'wrong-engine', 'missing-reference', 'unsafe-reference');
    foreach (['ref_audio', 'ref_text', 'private-file.wav', 'PRIVATE TRANSCRIPT', 'PRIVATE PROVIDER', 'signature=', 'preview_url', 'model'] as $private) {
        $response->assertDontSee($private);
    }
    $this->postJson('/api/v2/speech', array_merge($this->speech, ['voice' => 'other-plan-only']), $this->headers)->assertUnprocessable();
    $project = apiV2Project('zeta');
    $project['segments'][0]['voice'] = 'other-plan-only';
    $this->postJson('/api/v2/zeta', $project, $this->headers)->assertUnprocessable();
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
    $grant->update(['is_active' => false]);
    expect(array_column($this->getJson('/api/v2/voices', $this->headers)->assertOk()->json('voices'), 'id'))->not->toContain('plan-allowed');
    $allowed->update(['is_public' => true]);
    expect(array_column($this->getJson('/api/v2/voices', $this->headers)->assertOk()->json('voices'), 'id'))->toContain('plan-allowed');

    $otherPlan->update(['api_enabled' => true, 'api_allowed_tools' => ['v2:speech'], 'api_requests_per_minute' => 100]);
    app(PlanSwitcher::class)->switchServicePlan($this->customer->fresh(), $otherPlan->id, ['provider' => 'fake', 'billing_cycle' => 'monthly']);
    expect(array_column($this->getJson('/api/v2/voices', $this->headers)->assertOk()->json('voices'), 'id'))->toContain('other-plan-only');
    $this->key->update(['scopes' => ['v2:jobs:read']]);
    $this->getJson('/api/v2/voices', $this->headers)->assertForbidden();
    $this->getJson('/api/v2/voices')->assertUnauthorized();
});

it('accepts discovered voice IDs for Apollo and Zeta without changing native resolution', function (string $service, ?string $model) {
    $voices = $this->getJson('/api/v2/voices', $this->headers)->assertOk()->json('voices');
    $voice = collect($voices)->firstWhere('id', 'api-v2-voice')['id'];
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) use ($service, $model) {
        expect($input['model'])->toBe($model === '1.5' ? 'model_1' : 'model_2');
        expect($input['mode'])->toBe($service === 'zeta' ? 'builtin_ref_batch' : 'builtin_ref');
        expect($service === 'zeta' ? $input['segments'][0]['ref_audio'] : $input['ref_audio'])->toBe('voices/test.wav');

        return true;
    }), Mockery::type('int'))->andReturn(['id' => 'discovered-voice-job']);
    app()->instance(RunPodProvider::class, $provider);
    $payload = $service === 'zeta' ? ['segments' => [['voice' => $voice, 'text' => 'سڵاو', 'language' => 'ckb', 'pause_after_ms' => 0]]]
        : array_merge($this->speech, ['voice' => $voice, 'model' => $model]);
    $this->postJson('/api/v2/'.$service, $payload, $this->headers)->assertAccepted();
    expect(MlJob::count())->toBe(1);
})->with([['speech', '1.5'], ['speech', '2.0'], ['zeta', null]]);

it('rejects an unknown Zeta Voice ID before jobs or credit reservations', function () {
    $payload = apiV2Project('zeta');
    $payload['segments'][0]['voice'] = 'not-a-voice';
    $this->postJson('/api/v2/zeta', $payload, $this->headers)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_request');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
});

it('shows only customer voice display data with copy controls and existing previews in every locale', function (string $locale) {
    config()->set('metkurd_v2.enabled', true);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $voice = Voice::where('code', 'api-v2-voice')->firstOrFail();
    $voice->update(['meta' => ['engine' => 'xomni', 'ref_audio' => 'secret-reference.wav', 'ref_text' => 'SECRET TRANSCRIPT', 'avatar' => 'private-avatar.png', 'preview_audio' => 'private-preview.wav']]);
    Voice::create(['code' => 'hidden-from-portal', 'name' => 'Restricted voice', 'is_public' => false, 'is_active' => true, 'meta' => ['engine' => 'xomni', 'ref_audio' => 'secret-other.wav']]);
    $response = $this->actingAs($this->customer, 'app')->get('/'.$locale.'/app-v2/api')->assertOk();
    $response->assertSee('api-v2-voice')->assertSee('id="available-voices"', false)
        ->assertSee(__('api_v2.your_voices'))->assertSee(__('api_v2.copy_voice_id'))
        ->assertSee('preload="none"', false)->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false)
        ->assertSee('/'.$locale.'/app/xomni/speakers/api-v2-voice/preview?proxy=1', false)
        ->assertSee('copy(', false)->assertSee('YOUR_API_KEY');
    foreach (['hidden-from-portal', 'secret-reference.wav', 'SECRET TRANSCRIPT', 'private-avatar.png', 'private-preview.wav', $this->headers['Authorization']] as $private) {
        $response->assertDontSee($private);
    }
    $component = Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.api.app-api');
    foreach ($component->instance()->availableVoices as $publicVoice) {
        expect(array_keys($publicVoice))->toBe(['code', 'name', 'avatar_url', 'preview_url']);
    }
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
})->with(['en', 'ar', 'ku']);

it('previews existing samples for API-only customers without jobs or credits and blocks other-plan assets', function () {
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $voice = Voice::where('code', 'api-v2-voice')->firstOrFail();
    $voice->update(['meta' => ['engine' => 'xomni', 'ref_audio' => 'voices/test.wav', 'preview_audio' => 'sample.wav', 'avatar' => 'avatar.png']]);
    Storage::disk('s3')->put('metkurd_audio_data/omni/sample.wav', apiV2Wav());
    Storage::disk('s3')->put('metkurd_audio_data/avatar.png', 'test-avatar');
    foreach (ToolAction::whereIn('full_code', ['xomni.generate', 'xomni-v2.generate', 'zeta.generate'])->get() as $action) {
        PlanEntitlement::updateOrCreate(['service_plan_id' => $this->customer->currentServicePlanId(), 'tool_action_id' => $action->id, 'entitlement_channel' => 'app'], ['allowed' => false]);
    }
    $this->customer = $this->customer->fresh();
    expect($this->customer->canAccessTool('xomni'))->toBeFalse()->and($this->customer->canAccessTool('zeta'))->toBeFalse();
    $wallets = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    foreach (['preview', 'avatar'] as $asset) {
        $this->actingAs($this->customer, 'app')->get('/en/app/xomni/speakers/api-v2-voice/'.$asset.'?proxy=1')->assertOk();
    }
    $voice->update(['is_public' => false]);
    foreach (['preview', 'avatar'] as $asset) {
        $this->get('/en/app/xomni/speakers/api-v2-voice/'.$asset.'?proxy=1')->assertNotFound();
    }
    $voice->update(['is_public' => true]);
    ServicePlan::whereKey($this->customer->currentServicePlanId())->update(['api_allowed_tools' => ['v2:ocr']]);
    $this->get('/en/app/xomni/speakers/api-v2-voice/preview?proxy=1')->assertForbidden();
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0)
        ->and(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
    Illuminate\Support\Facades\Http::assertNothingSent();
});

it('documents the stable voices response and authenticated GET in four languages', function () {
    $docs = app(App\Services\CustomerApi\V2\ApiDocumentation::class);
    $examples = $docs->voiceExamples();
    expect(array_keys($examples))->toBe(['cURL', 'PHP', 'Python', 'JavaScript']);
    foreach ($examples as $example) {
        expect($example)->toContain('/api/v2/voices', 'YOUR_API_KEY')->not->toContain('Idempotency-Key', 'POST', 'ref_audio');
    }
    $response = json_decode($docs->voiceResponseExample(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($response))->toBe(['voices'])->and(array_keys($response['voices'][0]))->toBe(['id', 'name']);
});

it('submits through native OMNI once charges only the API wallet and persists without a client polling', function (string $model, string $storageMode) {
    $this->speech['model'] = $model;
    $this->speech['storage_mode'] = $storageMode;
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(fn ($input) => $input['model'] === ($model === '1.5' ? 'model_1' : 'model_2')), Mockery::type('int'))->andReturn(['id' => 'remote-v2']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['audio_base64' => base64_encode('wave result')]]);
    app()->instance(RunPodProvider::class, $provider);
    $appBefore = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $response = $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertAccepted();
    $id = $response->json('id');
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $this->postJson('/api/v2/speech', array_merge($this->speech, ['text' => 'different']), $this->headers)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    $api = ApiJob::findOrFail($id);
    expect(MlJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1)
        ->and(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($appBefore);
    expect($api->ml_job_id)->not->toBeNull();
    expect(MlJob::first()->failure_stage)->toBeNull();
    (new ReconcileMlJob($api->ml_job_id))->handle();
    $result = $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('status', 'completed');
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk();
    $file = CustomerFile::firstOrFail();
    expect(CustomerFile::count())->toBe(1)->and($file->retention_mode)->toBe($storageMode)
        ->and($file->counts_toward_quota)->toBe($storageMode === 'permanent')
        ->and($file->customer_id)->toBe($this->customer->id)
        ->and($file->source_type)->toBe('api_job')->and($file->source_id)->toBe($id)
        ->and(data_get($file->meta, 'job_id'))->toBe($api->ml_job_id)
        ->and($file->mime)->toBe('audio/wav')
        ->and($file->expires_at?->toIso8601String())->toBe(data_get($api->meta, 'expires_at'))
        ->and(Storage::disk($file->disk)->get($file->path))->toBe('wave result')
        ->and($result->json('result.files'))->toHaveCount(1)
        ->and($result->json('result.files.0.expires_at'))->toBe($file->expires_at?->toIso8601String());
    $link = \App\Models\ApiResultFile::findOrFail($result->json('result.files.0.id'));
    expect($link->customer_id)->toBe($this->customer->id)->and($link->storage_file_id)->toBe($file->id);
    $used = (int) \App\Models\CustomerUsage::where('customer_id', $this->customer->id)->value('storage_used_bytes');
    expect($used)->toBe($storageMode === 'permanent' ? $file->size_bytes : 0);
    config()->set('metkurd_v2.enabled', true);
    \Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.storage.app-storage')
        ->set('type', 'audio')->set('sort', 'newest')->assertSee('job:'.$api->ml_job_id)
        ->assertSee($storageMode === 'temporary' ? 'Temporary' : 'Permanent')
        ->set('product', $model === '2.0' ? 'apollo-2' : 'apollo-1')
        ->assertSee($model === '2.0' ? 'Apollo 2.0v' : 'Apollo 1.5v')
        ->call('openFolder', 'job:'.$api->ml_job_id)->assertSee('API')
        ->assertSee($file->expires_at?->toIso8601String() ?? 'Permanent');
    expect((int) \App\Models\CustomerUsage::where('customer_id', $this->customer->id)->value('storage_used_bytes'))->toBe($used);
    $this->get(route('app.v2.storage.download', ['locale' => 'en', 'file' => $file->id]))->assertRedirect();
    $result->assertDontSee($file->path)->assertDontSee('remote-v2')->assertDontSee('storage.example.test');
    $url = $result->json('result.files.0.download_url');
    expect($url)->toContain('/api/v2/files/');
    $this->get($url, $this->headers)->assertOk();
    $other = Customer::create(['username' => 'download-other', 'email' => 'download-other@example.test', 'password' => 'Secret123!', 'status' => 1]);
    app(PlanSwitcher::class)->switchServicePlan($other, ServicePlan::where('code', 'pro')->value('id'), ['provider' => 'fake', 'billing_cycle' => 'monthly']);
    $otherKey = app(CustomerApiKeyService::class)->issueV2($other->fresh(), 'Other');
    $otherHeaders = ['Authorization' => 'Bearer '.$otherKey['plain_text_key']];
    $this->getJson('/api/v2/jobs/'.$id, $otherHeaders)->assertNotFound();
    $this->getJson($url, $otherHeaders)->assertNotFound();
    CustomerFile::first()->update(['expires_at' => now()->subSecond()]);
    $this->getJson($url, $this->headers)->assertNotFound();
    $api->refresh()->update(['meta' => array_merge($api->meta, ['expires_at' => now()->subSecond()->toIso8601String()])]);
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('result.expired', true)->assertJsonPath('result.files', []);
})->with([['1.5', 'temporary'], ['2.0', 'temporary'], ['1.5', 'permanent'], ['2.0', 'permanent']]);

it('rejects invalid speech inputs before creating jobs', function (array $change) {
    $this->postJson('/api/v2/speech', array_merge($this->speech, $change), $this->headers)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_request');
    expect(ApiJob::count())->toBe(0)->and(MlJob::count())->toBe(0);
})->with([[['voice' => 'foreign']], [['model' => ['2.0']]], [['model' => '1.0']], [['text' => str_repeat('x', 401)]], [['language' => 'invalid']]]);

it('does not replay or refund ambiguous paid submissions', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andThrow(new RuntimeException('private provider timeout'));
    app()->instance(RunPodProvider::class, $provider);
    $id = $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertAccepted()->json('id');
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertOk()->assertJsonPath('id', $id);
    expect(MlJob::first()->failure_stage)->toBe('provider_submission_unknown')->and(ApiCreditReservation::first()->status)->toBe('reserved');
});

it('probes uploads and maps each native audio service without trusting billing metadata', function (string $service, array $extra, string $action) {
    config()->set('runpod.endpoints.stem', 'test-stem');
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 121, 'billable_min' => 1]);
    app()->instance(AudioProbeService::class, $probe);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'remote-audio']);
    app()->instance(RunPodProvider::class, $provider);
    $file = UploadedFile::fake()->create('audio.wav', 1, 'audio/wav');
    $request = array_merge(['file' => $file, 'language' => 'en', 'duration_sec' => 1, 'billable_minutes' => 1, 'audio_path' => 'foreign/private.wav'], $extra);
    $id = $this->postJson('/api/v2/'.$service, $request, $this->headers)->assertAccepted()->json('id');
    $this->postJson('/api/v2/'.$service, $request, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $job = MlJob::firstOrFail();
    expect($job->status)->toBe('running')->and($job->toolAction->full_code)->toBe($action)
        ->and(data_get($job->input, 'wallet_type'))->toBe('api');
    expect(json_encode($job->input))->not->toContain('foreign/private.wav');
    $reservation = ApiCreditReservation::firstOrFail();
    $context = $service === 'stem' ? ['metric_code' => 'stem_output', 'outputs' => (int) $extra['mode'], 'stem_outputs' => (int) $extra['mode'], 'separation_mode' => (int) $extra['mode'], 'minutes' => 3, 'seconds' => 121] : ['metric_code' => 'minute', 'minutes' => 3, 'seconds' => 121];
    expect($reservation->amount)->toBe($this->customer->priceCreditsFor($action, array_merge($context, ['channel' => 'api'])));
    expect(CustomerFile::where('customer_id', '!=', $this->customer->id)->count())->toBe(0)
        ->and(CustomerFile::first()->retention_mode)->toBe('temporary');
})->with([
    ['transcriptions', [], 'leo.transcribe'], ['captions', [], 'caption.standard'],
    ['stem', ['mode' => '2'], 'stem.sep2'], ['stem', ['mode' => '4'], 'stem.sep4'],
]);

it('counts OCR pages on the server validates ranges and preserves requested exports', function () {
    config()->set('runpod.endpoints.kocr_v2', 'test-ocr');
    Illuminate\Support\Facades\Process::fake(['*' => Illuminate\Support\Facades\Process::result(output: "Pages: 4\n", exitCode: 0)]);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->andReturn(['id' => 'remote-ocr']);
    app()->instance(RunPodProvider::class, $provider);
    $file = \Tests\Support\PdfFixture::upload(4);
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '1-5'], $this->headers)->assertUnprocessable();
    expect(ApiJob::count())->toBe(0);
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '1-3', 'estimated_pages' => 1, 'page_count' => 1, 'exports' => ['txt', 'html']], $this->headers)->assertAccepted();
    $job = MlJob::firstOrFail();
    expect($job->status)->toBe('running')->and(data_get($job->input, 'page_range'))->toBe('1,2,3')
        ->and(data_get($job->input, 'exports.export_html'))->toBeTrue()->and(data_get($job->input, 'exports.export_docx'))->toBeFalse();
    expect(ApiCreditReservation::first()->amount)->toBe($this->customer->priceCreditsFor('ocr.standard', ['channel' => 'api', 'metric_code' => 'page', 'pages' => 3, 'page_count' => 3, 'files' => 1, 'file_count' => 1]));
});

it('enforces the twenty page OCR limit for API requests before reserving credits', function () {
    config()->set('runpod.endpoints.kocr_v2', 'test-ocr');
    Illuminate\Support\Facades\Process::fake(['*' => Illuminate\Support\Facades\Process::result(output: "Pages: 100\n", exitCode: 0)]);
    $provider = $this->mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->with('test-ocr', Mockery::on(fn ($input) => $input['options']['pages'] === implode(',', range(21, 40))), Mockery::type('array'), Mockery::type('int'))->andReturn(['id' => 'api-twenty-pages']);
    $file = \Tests\Support\PdfFixture::upload(100);
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '1-21'], $this->headers)->assertUnprocessable();
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '21-40'], $this->headers)->assertAccepted();
    expect(MlJob::sole()->input['pages_estimated'])->toBe(20);
});

it('documents OCR local uploads in five clients using authoritative formats and limits', function () {
    $docs = app(App\Services\CustomerApi\V2\ApiDocumentation::class);
    $contract = $docs->ocrContract();
    expect($contract['extensions'])->toBe(App\Services\MetKurd\V2\InputBoundary::DOCUMENT_EXTENSIONS)
        ->and($contract['max_mib'])->toBe(App\Services\MetKurd\V2\InputBoundary::DOCUMENT_MAX_KIB / 1024)
        ->and($contract['max_pages'])->toBe(App\Services\OCR\OcrDocumentProbe::MAX_PAGES)
        ->and($contract['exports'])->toBe(App\Services\CustomerApi\V2\ApiSubmission::OCR_EXPORTS)
        ->and($contract['default_exports'])->toBe(['txt', 'docx']);
    config()->set('customer_api.temporary_file_ttl_days', 11);
    expect($docs->ocrContract()['temporary_days'])->toBe(11);
    $examples = $docs->examples($docs->services()['ocr']);
    expect(array_keys($examples))->toBe(['cURL', 'PowerShell', 'PHP', 'Python', 'JavaScript']);
    foreach ($examples as $example) {
        expect($example)->toContain('/api/v2/ocr', 'YOUR_API_KEY', 'UNIQUE_REQUEST_ID', 'document.pdf')
            ->not->toContain('file_url', 'Content-Type:', 'runpod', 'options', 'export_formats');
    }
    expect($examples['cURL'])->toContain('file=@./document.pdf', 'exports[]=txt', 'exports[]=docx', 'intelligent=1', 'storage_mode=temporary');
    expect($examples['PowerShell'])->toStartWith('curl.exe ')->toContain("`\n", 'file=@C:\\Documents\\document.pdf')->not->toContain("\\\n");
    expect($examples['PHP'])->toContain("new CURLFile(__DIR__ . '/document.pdf', 'application/pdf', 'document.pdf')", 'CURLOPT_POSTFIELDS => $data');
    expect($examples['Python'])->toContain("open('document.pdf', 'rb')", "('document.pdf', f, 'application/pdf')", "'exports[]': ['txt', 'docx']", 'files=files');
    expect($examples['JavaScript'])->toContain("await readFile('./document.pdf')", 'new FormData()', 'new Blob(', "body.append('exports[]', 'txt')");
    foreach ($docs->ocrFollowupExamples() as $example) {
        expect($example)->toContain('Authorization: Bearer YOUR_API_KEY')->not->toContain('file_url');
    }
});

it('renders a localized OCR walkthrough with local uploads and an isolated five-language picker', function (string $locale) {
    config()->set('metkurd_v2.enabled', true);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $response = $this->actingAs($this->customer, 'app')->get('/'.$locale.'/app-v2/api')->assertOk();
    foreach (['ocr_upload_intro', 'ocr_quick', 'ocr_local_explanation', 'ocr_internal_note', 'ocr_async_help', 'ocr_pages_help', 'ocr_exports_help'] as $key) {
        $response->assertSee(__('api_v2.'.$key))->assertDontSee('api_v2.'.$key);
    }
    $response->assertSee('data-api-ocr-documentation', false)->assertSee('data-api-ocr-examples', false)
        ->assertSee('multipart/form-data')->assertSee('file=@./document.pdf')->assertSee('curl.exe')
        ->assertSee('x-model="ocrLanguage"', false)->assertSee('<option>PowerShell</option>', false)
        ->assertSee('dir="ltr"', false)->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false)
        ->assertSee('GET /api/v2/jobs/{id}')->assertSee('result.files')->assertSee('/api/v2/files/file_YOUR_FILE_ID/download')
        ->assertDontSee('file_url')->assertDontSee('RunPod')->assertDontSee(substr($this->headers['Authorization'], 7));
})->with(['en', 'ar', 'ku']);

it('rejects OCR remote URLs as a substitute for a multipart file before jobs or charges', function (string $url) {
    $wallets = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $this->postJson('/api/v2/ocr', ['file_url' => $url, 'pages' => '1'], $this->headers)
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_file');
    expect(MlJob::count())->toBe(0)->and(ApiJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0)
        ->and(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
    Illuminate\Support\Facades\Http::assertNothingSent();
})->with(['https://untrusted.example.test/document.pdf', 'http://169.254.169.254/latest/meta-data/']);

it('keeps OCR uploaded bytes server signed URLs billing and persisted authenticated result flow', function () {
    config()->set('runpod.endpoints.kocr_v2', 'test-ocr');
    Illuminate\Support\Facades\Process::fake(['*' => Illuminate\Support\Facades\Process::result(output: "Pages: 4\n", exitCode: 0)]);
    $appBefore = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->with('test-ocr', Mockery::on(function ($input) {
        expect($input['file_url'])->toBe('https://storage.example.test/input?test=1');
        expect($input['options']['pages'])->toBe('1')->and($input['options']['intelligent'])->toBe(1)
            ->and($input['options']['dpi'])->toBe(160);
        expect(json_encode($input))->not->toContain('untrusted.example', 'foreign-job');

        return true;
    }), ['executionTimeout' => 900000, 'ttl' => 1200000], Mockery::type('int'))->andReturn(['id' => 'remote-ocr-docs']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['text' => 'Extracted document text']]);
    app()->instance(RunPodProvider::class, $provider);
    $file = \Tests\Support\PdfFixture::upload(4);
    $payload = ['file' => $file, 'pages' => '1', 'exports' => ['txt'], 'intelligent' => '1', 'storage_mode' => 'temporary',
        'file_url' => 'https://untrusted.example/document.pdf', 'job_id' => 'foreign-job', 'options' => ['dpi' => 999]];
    $accepted = $this->postJson('/api/v2/ocr', $payload, $this->headers)->assertAccepted()->assertJsonPath('result', null);
    $id = $accepted->json('id');
    $this->postJson('/api/v2/ocr', $payload, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $job = MlJob::firstOrFail();
    Storage::disk('s3')->assertExists($job->input['file_path']);
    expect(Storage::disk('s3')->get($job->input['file_path']))->toBe(file_get_contents($file->getRealPath()));
    expect(MlJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1)
        ->and(ApiCreditReservation::first()->amount)->toBe($this->customer->priceCreditsFor('ocr.standard', ['channel' => 'api', 'metric_code' => 'page', 'pages' => 1, 'page_count' => 1, 'files' => 1, 'file_count' => 1]));
    (new ReconcileMlJob($job->id))->handle();
    $result = $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.text', 'Extracted document text')->assertDontSee('file_url')->assertDontSee('storage.example.test');
    $download = $result->json('result.files.0.download_url');
    expect($download)->toContain('/api/v2/files/');
    $this->getJson($download)->assertUnauthorized();
    $this->get($download, $this->headers)->assertOk();
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('status', 'completed');
    $output = CustomerFile::where('purpose', 'render')->firstOrFail();
    expect($output->retention_mode)->toBe('temporary')->and($output->counts_toward_quota)->toBeFalse()
        ->and(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($appBefore);
});

it('validates Vector reference ownership and probes saved audio before dispatch', function () {
    $bytes = 'RIFF'.pack('V', 40).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 4).str_repeat("\0", 4);
    Storage::disk('s3')->put('reference.wav', $bytes);
    $reference = CustomerFile::create(['customer_id' => $this->customer->id, 'tool_code' => 'vector-v2', 'disk' => 's3', 'path' => 'reference.wav', 'mime' => 'audio/wav', 'size_bytes' => strlen($bytes), 'purpose' => 'reference', 'status' => 'active']);
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 21]);
    app()->instance(AudioProbeService::class, $probe);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'remote-vector']);
    app()->instance(RunPodProvider::class, $provider);
    $this->postJson('/api/v2/voice-clone', ['text' => 'Hello', 'reference_id' => $reference->id + 1], $this->headers)->assertUnprocessable();
    $id = $this->postJson('/api/v2/voice-clone', ['text' => 'Hello', 'reference_id' => $reference->id, 'reference_text' => 'Reference transcript'], $this->headers)->assertAccepted()->json('id');
    expect(MlJob::first()->status)->toBe('running')->and(data_get(MlJob::first()->input, 'ref_text'))->toBe('Reference transcript');
    $other = Customer::create(['username' => 'api-other', 'email' => 'other@example.test', 'password' => 'Secret123!', 'status' => 1]);
    $reference->update(['customer_id' => $other->id]);
    $this->postJson('/api/v2/voice-clone', ['text' => 'Hello', 'reference_id' => $reference->id], array_merge($this->headers, ['Idempotency-Key' => 'foreign-reference']))->assertUnprocessable();
    expect(ApiJob::count())->toBe(1);
});

it('rejects unsupported and forged inputs before a debit', function (string $service, array $input) {
    $input['file'] = UploadedFile::fake()->create('malware.exe', 1, 'application/octet-stream');
    $this->postJson('/api/v2/'.$service, $input, $this->headers)->assertUnprocessable();
    expect(ApiCreditReservation::count())->toBe(0)->and(MlJob::count())->toBe(0);
})->with([['voice-clone', ['text' => 'Hi']], ['transcriptions', ['language' => 'xx']], ['captions', []], ['ocr', []], ['stem', ['mode' => '8']]]);

it('uploads an owned temporary Vector 1.5 reference through the existing clone core', function () {
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 10]);
    app()->instance(AudioProbeService::class, $probe);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(fn ($input) => $input['mode'] === 'audio_url' && $input['model'] === 'model_1' && $input['ref_max_sec'] === 20), Mockery::type('int'))->andReturn(['id' => 'remote-vector-15']);
    app()->instance(RunPodProvider::class, $provider);
    $this->postJson('/api/v2/voice-clone', ['text' => 'Hello', 'model' => '1.5', 'file' => UploadedFile::fake()->create('reference.wav', 1, 'audio/wav')], $this->headers)->assertAccepted();
    expect(MlJob::first()->status)->toBe('running')->and(MlJob::first()->toolAction->full_code)->toBe('clone_xomni.generate');
    $file = CustomerFile::firstOrFail();
    expect($file->customer_id)->toBe($this->customer->id)->and($file->retention_mode)->toBe('temporary')->and($file->counts_toward_quota)->toBeFalse();
});

it('isolates owned jobs and downloads and hides provider errors', function () {
    $other = Customer::create(['username' => 'api-foreign', 'email' => 'foreign@example.test', 'password' => 'Secret123!', 'status' => 1]);
    $job = ApiJob::create(['api_key_id' => $this->key->id, 'id' => 'job_foreign', 'customer_id' => $other->id, 'status' => 'failed', 'tool_code' => 'xomni', 'tool_action' => 'xomni.generate', 'meta' => ['api_version' => 2, 'service' => 'speech'], 'error_message' => 'RunPod endpoint private.example.test']);
    $this->getJson('/api/v2/jobs/'.$job->id, $this->headers)->assertNotFound()->assertJsonPath('error.code', 'job_not_found')->assertDontSee('RunPod');
    $this->getJson('/api/v2/files/file_foreign/download', $this->headers)->assertNotFound()->assertJsonPath('error.code', 'file_not_found');
    $job->update(['customer_id' => $this->customer->id]);
    $this->getJson('/api/v2/jobs/'.$job->id, $this->headers)->assertOk()->assertJsonPath('status', 'failed')->assertDontSee('RunPod')->assertDontSee('private.example');
    $this->key->update(['scopes' => ['v2:speech']]);
    $this->getJson('/api/v2/jobs/'.$job->id, $this->headers)->assertForbidden();
});

it('applies plan limits preserves empty API wallets and expires abandoned claims', function () {
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['balance_credits' => 0, 'subscription_balance_credits' => 0, 'addon_balance_credits' => 0]);
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_credits');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
    $claim = ApiJob::create(['api_key_id' => $this->key->id, 'id' => 'job_abandoned', 'customer_id' => $this->customer->id, 'status' => 'accepted', 'tool_code' => 'xomni', 'tool_action' => 'xomni.generate', 'meta' => ['api_version' => 2, 'service' => 'speech'], 'created_at' => now()->subMinutes(16)]);
    $claim->forceFill(['created_at' => now()->subMinutes(16)])->save();
    $this->artisan('ml-jobs:reconcile')->assertSuccessful();
    expect($claim->fresh()->status)->toBe('failed');
    ServicePlan::where('code', 'pro')->update(['api_requests_per_minute' => 1]);
    $this->getJson('/api/v2/services', $this->headers)->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
});

it('renders the localized V2 portal with placeholder LTR examples and one-time secrets', function (string $locale) {
    config()->set('metkurd_v2.enabled', true);
    ServicePlan::where('code', 'pro')->update(['api_allowed_tools' => ['v2:speech']]);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $response = $this->actingAs($this->customer, 'app')->get('/'.$locale.'/app-v2/api')->assertOk()->assertSee('dir="ltr"', false)->assertSee('YOUR_API_KEY')->assertDontSee('RunPod');
    foreach (['zeta', 'theta', 'harakat'] as $service) {
        $response->assertSee('/api/v2/'.$service)->assertSee(__('api_v2.'.$service.'_description'))->assertDontSee('api_v2.'.$service.'_description');
    }
    $response->assertSee('v2:harakat')->assertSee('/api/v2/references')->assertSee('reference_id')->assertSee('pause_after_ms');
    app()->setLocale($locale);
    $component = Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.api.app-api');
    $component->set('keyName', 'My server')->call('createKey')->assertHasNoErrors()->assertDispatched('api-key-created');
    expect(get_object_vars($component->instance()))->not->toHaveKey('justCreatedKey');
    $newKey = CustomerApiKey::where('name', 'My server')->firstOrFail();
    $component->call('revokeKey', $newKey->id);
    expect($newKey->fresh()->status)->toBe('revoked');
    $this->get('/'.$locale.'/app/api')->assertRedirect('/'.$locale.'/app-v2/api');
})->with(['en', 'ar', 'ku']);

it('releases a known failed API submission once without touching app credits', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andThrow(new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(422, [], 'private worker validation details'))));
    app()->instance(RunPodProvider::class, $provider);
    $wallets = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $response = $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertAccepted()->assertJsonPath('status', 'failed')->assertDontSee('worker validation');
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertOk()->assertJsonPath('id', $response->json('id'));
    (new ReconcileMlJob(MlJob::first()->id))->handle();
    expect(ApiCreditReservation::first()->status)->toBe('released')
        ->and(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
});

it('enforces concurrency scope rollout and authentication failure limits', function () {
    config()->set('customer_api.v2_enabled', false);
    $this->getJson('/api/v2/services', $this->headers)->assertNotFound();
    config()->set('customer_api.v2_enabled', true);
    ServicePlan::where('code', 'pro')->update(['api_concurrent_jobs' => 0]);
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertStatus(429)->assertJsonPath('error.code', 'concurrency_limit_exceeded');
    expect(MlJob::count())->toBe(0);
    $this->key->update(['scopes' => ['v2:jobs:read']]);
    $this->postJson('/api/v2/speech', $this->speech, $this->headers)->assertForbidden();
    config()->set('customer_api.v2_auth_failures_per_minute', 1);
    $this->getJson('/api/v2/services', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    $this->getJson('/api/v2/services', ['Authorization' => 'Bearer invalid'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
});

it('prevents foreign key revocation and caps active keys on the server', function () {
    $other = Customer::create(['username' => 'key-other', 'email' => 'key-other@example.test', 'password' => 'Secret123!', 'status' => 1]);
    $foreign = $this->key->replicate();
    $foreign->customer_id = $other->id;
    $foreign->key_hash = hash('sha256', 'test-only-foreign-secret');
    $foreign->save();
    $component = Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.api.app-api');
    expect(fn () => $component->call('revokeKey', $foreign->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($foreign->fresh()->status)->toBe('active');
    config()->set('customer_api.max_keys', 1);
    Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.api.app-api')->set('keyName', 'Over limit')->call('createKey')->assertHasErrors('keyName')->assertNotDispatched('api-key-created');
    expect(CustomerApiKey::where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('returns safe malformed JSON errors and rejects oversized uploads before hashing', function () {
    $this->call('POST', '/api/v2/speech', [], [], [], ['HTTP_AUTHORIZATION' => $this->headers['Authorization'], 'CONTENT_TYPE' => 'application/json'], '{"text":')
        ->assertStatus(400)->assertJsonPath('error.code', 'invalid_request');
    $boundary = Mockery::mock(App\Services\MetKurd\V2\InputBoundary::class);
    $boundary->shouldNotReceive('hash');
    app()->instance(App\Services\MetKurd\V2\InputBoundary::class, $boundary);
    $this->postJson('/api/v2/transcriptions', ['file' => UploadedFile::fake()->create('too-large.wav', 102401, 'audio/wav')], $this->headers)
        ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_file');
    expect(ApiJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
});

function apiV2Project(string $service, ?int $reference = null): array
{
    return ['segments' => array_map(fn ($i) => [
        'text' => $i === 0 ? '  سڵاو  ' : 'جیهان', 'language' => 'ckb', 'pause_after_ms' => $i === 0 ? 500 : 2000,
    ] + ($service === 'theta' ? ['reference_id' => $reference, 'reference_text' => 'سڵاو'] : ['voice' => 'api-v2-voice']), [0, 1])];
}

function apiV2Reference($test): CustomerFile
{
    Storage::disk('s3')->put('owned.wav', apiV2Wav());

    return CustomerFile::create(['customer_id' => $test->customer->id, 'tool_code' => 'vector-v2', 'disk' => 's3',
        'path' => 'owned.wav', 'mime' => 'audio/wav', 'size_bytes' => strlen(apiV2Wav()), 'purpose' => 'reference', 'status' => 'active']);
}

function apiV2Wav(): string
{
    return 'RIFF'.pack('V', 40).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 4).str_repeat("\0", 4);
}

it('submits and serializes one multi-speaker API project with one reservation and reusable references', function (string $service) {
    $reference = $service === 'theta' ? apiV2Reference($this) : null;
    if ($reference) {
        $probe = Mockery::mock(AudioProbeService::class);
        // Preflight plus the native pre-dispatch recheck, once per distinct ID each time.
        $probe->shouldReceive('probeUploadedFile')->twice()->andReturn(['duration_sec' => 1]);
        app()->instance(AudioProbeService::class, $probe);
    }
    $signatures = 0;
    Storage::disk('s3')->buildTemporaryUrlsUsing(function () use (&$signatures) {
        return 'https://storage.example.test/reference?signature='.(++$signatures);
    });
    $mode = $service === 'theta' ? 'audio_url_batch' : 'builtin_ref_batch';
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-omni', Mockery::on(function ($input) use ($service, $mode) {
        expect($input['model'])->toBe('model_2')->and($input['mode'])->toBe($mode)
            ->and(array_column($input['segments'], 'pause_after_ms'))->toBe([500, 0])
            ->and(array_column($input['segments'], 'text'))->toBe(['سڵاو', 'جیهان']);
        if ($service === 'theta') {
            expect($input['segments'][0]['audio_url'])->toBe($input['segments'][1]['audio_url'])
                ->and($input['segments'][0]['ref_text'])->toBe('سڵاو');
        } else {
            expect($input['segments'][0]['ref_audio'])->toBe('voices/test.wav');
        }

        return true;
    }), Mockery::any())->andReturn(['id' => 'batch-remote']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => [
        'success' => true, 'model' => 'model_2', 'mode' => $mode, 'segment_count' => 2,
        'duration' => 3.5, 'mime_type' => 'audio/wav', 'audio_base64' => base64_encode(apiV2Wav()),
    ]]);
    app()->instance(RunPodProvider::class, $provider);
    $before = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $data = apiV2Project($service, $reference?->id);
    $id = $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertAccepted()->json('id');
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $reorderedKeys = $data;
    $reorderedKeys['segments'] = array_map(fn ($segment) => array_reverse($segment, true), $data['segments']);
    $this->postJson('/api/v2/'.$service, $reorderedKeys, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $changed = $data;
    $changed['segments'][1]['text'] = 'different';
    $this->postJson('/api/v2/'.$service, $changed, $this->headers)->assertStatus(409);
    expect(ApiJob::count())->toBe(1)->and(MlJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1)
        ->and($signatures)->toBe($reference ? 1 : 0);
    $chars = mb_strlen('سڵاوجیهان');
    expect(ApiCreditReservation::first()->amount)->toBe($this->customer->priceCreditsFor($service.'.generate', ['channel' => 'api', 'metric_code' => 'character', 'chars' => $chars, 'language' => 'ckb']));
    $job = MlJob::firstOrFail();
    expect(json_encode($job->input))->not->toContain('signature=');
    (new ReconcileMlJob($job->id))->handle();
    $response = $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.segment_count', 2)->assertJsonPath('result.total_chars', $chars)->assertJsonPath('result.duration', 3.5)
        ->assertJsonCount(1, 'result.files')->assertDontSee('model_2')->assertDontSee('batch-remote')->assertDontSee('signature=');
    $this->get($response->json('result.files.0.download_url'), $this->headers)->assertOk();
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk();
    expect(ApiCreditReservation::first()->status)->toBe('settled')
        ->and(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($before);
})->with(['zeta', 'theta']);

it('validates new tool fields and batch limits before claiming or reserving', function (string $case) {
    $service = 'zeta';
    $data = apiV2Project($service);
    switch ($case) {
        case 'empty': $data['segments'] = [];
            break;
        case 'many': $data['segments'] = array_fill(0, 26, $data['segments'][0]);
            break;
        case 'long': $data['segments'][0]['text'] = str_repeat('a', 501);
            break;
        case 'total': $data['segments'] = array_fill(0, 11, array_merge($data['segments'][0], ['text' => str_repeat('a', 500)]));
            break;
        case 'language': $data['segments'][0]['language'] = 'invalid';
            break;
        case 'pause': $data['segments'][1]['pause_after_ms'] = 300;
            break;
        case 'voice': $data['segments'][0]['voice'] = 'foreign';
            break;
        case 'private_voice':
            Voice::where('code', 'api-v2-voice')->update(['is_public' => false]);
            break;
        case 'worker_field': $data['segments'][0]['ref_audio'] = 'private/path';
            break;
        case 'model': $data['model'] = 'model_2';
            break;
        case 'url': $service = 'theta';
            $data = apiV2Project($service, 1);
            $data['segments'][0]['audio_url'] = 'https://example.test/a.wav';
            break;
        case 'harakat_empty': $service = 'harakat';
            $data = ['text' => '   '];
            break;
        case 'harakat_long': $service = 'harakat';
            $data = ['text' => str_repeat('ع', 5001)];
            break;
        case 'harakat_type': $service = 'harakat';
            $data = ['text' => ['bad']];
            break;
        case 'source_mode': $service = 'harakat';
            $data = ['text' => 'مرحبا', 'source_mode' => 'text'];
            break;
        case 'harakat_file': $service = 'harakat';
            $data = ['text' => 'مرحبا', 'file_url' => 'https://example.test/a.txt'];
            break;
    }
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertUnprocessable();
    expect(ApiJob::count())->toBe(0)->and(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
})->with(['empty', 'many', 'long', 'total', 'language', 'pause', 'voice', 'private_voice', 'worker_field', 'model', 'url', 'harakat_empty', 'harakat_long', 'harakat_type', 'source_mode', 'harakat_file']);

it('rejects unavailable or invalid Theta references before charging', function (string $case) {
    $reference = apiV2Reference($this);
    match ($case) {
        'foreign' => $reference->update(['customer_id' => Customer::create(['username' => 'foreign-ref', 'email' => 'foreign-ref@example.test', 'password' => 'test'])->id]),
        'expired' => $reference->update(['expires_at' => now()->subDay()]),
        'inactive' => $reference->update(['status' => 'deleted']),
        'missing' => Storage::disk('s3')->delete($reference->path),
        'not_audio' => Storage::disk('s3')->put($reference->path, 'plain text'),
        'project_limit' => config(['metkurd_v2.multi_speaker.max_reference_bytes' => 1]),
    };
    if ($case === 'project_limit') {
        $probe = Mockery::mock(AudioProbeService::class);
        $probe->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 1]);
        app()->instance(AudioProbeService::class, $probe);
    }
    $this->postJson('/api/v2/theta', apiV2Project('theta', $reference->id), $this->headers)->assertUnprocessable();
    expect(ApiJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
})->with(['foreign', 'expired', 'inactive', 'missing', 'not_audio', 'project_limit']);

it('uploads reusable Theta references through shared storage without generation or credits', function () {
    $probe = Mockery::mock(AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->twice()->andReturn(['duration_sec' => 1]);
    app()->instance(AudioProbeService::class, $probe);
    $wallets = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $data = ['file' => UploadedFile::fake()->createWithContent('reference.wav', apiV2Wav())];
    $id = $this->postJson('/api/v2/references', $data, $this->headers)->assertCreated()->assertDontSee('path')->json('reference_id');
    $this->postJson('/api/v2/references', $data, $this->headers)->assertCreated()->assertJsonPath('reference_id', $id);
    expect(CustomerFile::count())->toBe(1)->and(CustomerFile::first()->purpose)->toBe('reference')
        ->and(CustomerFile::first()->counts_toward_quota)->toBeTrue()
        ->and(ApiJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0)
        ->and(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
    $this->postJson('/api/v2/references', ['file' => UploadedFile::fake()->create('large.wav', 20481, 'audio/wav')], $this->headers)->assertUnprocessable();
});

it('maps Harakat to its endpoint and returns owned text metadata and TXT after persistence', function () {
    config(['runpod.endpoints.tashkeel_v1' => 'test-tashkeel']);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->with('test-tashkeel', Mockery::on(fn ($input) => array_keys($input) === ['job_id', 'source_mode', 'text'] && $input['source_mode'] === 'text' && $input['text'] === 'مرحبا بكم'), Mockery::any())->andReturn(['id' => 'text-remote']);
    $provider->shouldReceive('status')->once()->andReturnUsing(fn () => ['status' => 'COMPLETED', 'output' => [
        'success' => true, 'job_id' => MlJob::first()->id, 'source_mode' => 'text', 'text' => 'مَرْحَبًا بِكُمْ', 'chunks' => 1,
    ]]);
    app()->instance(RunPodProvider::class, $provider);
    $before = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $data = ['text' => '  مرحبا بكم  '];
    $id = $this->postJson('/api/v2/harakat', $data, $this->headers)->assertAccepted()->json('id');
    $this->postJson('/api/v2/harakat', $data, $this->headers)->assertOk()->assertJsonPath('id', $id);
    expect(ApiJob::count())->toBe(1)->and(MlJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1)
        ->and(ApiCreditReservation::first()->amount)->toBe($this->customer->priceCreditsFor('harakat.diacritize', ['channel' => 'api', 'metric_code' => 'character', 'chars' => mb_strlen('مرحبا بكم'), 'language' => 'ar']));
    (new ReconcileMlJob(MlJob::first()->id))->handle();
    $response = $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.text', 'مَرْحَبًا بِكُمْ')->assertJsonPath('result.characters', mb_strlen('مَرْحَبًا بِكُمْ'))
        ->assertJsonPath('result.words', 2)->assertJsonPath('result.lines', 1)->assertJsonPath('result.chunks', 1)->assertJsonCount(1, 'result.files');
    $this->get($response->json('result.files.0.download_url'), $this->headers)->assertOk();
    expect(ApiCreditReservation::first()->status)->toBe('settled')
        ->and(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($before);
    CustomerFile::query()->update(['expires_at' => now()->subDay()]);
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk()->assertJsonMissingPath('result.text')->assertJsonCount(0, 'result.files');
});

it('requires both the new service family scope and its independent API entitlement', function (string $service, string $scope, string $action) {
    $data = $service === 'harakat' ? ['text' => 'مرحبا'] : apiV2Project($service, 1);
    $this->key->update(['scopes' => ['v2:jobs:read']]);
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertForbidden();
    $this->key->update(['scopes' => [$scope]]);
    PlanEntitlement::where('service_plan_id', ServicePlan::where('code', 'pro')->value('id'))
        ->where('tool_action_id', ToolAction::where('full_code', $action)->value('id'))->where('entitlement_channel', 'api')->update(['allowed' => false]);
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertForbidden();
    expect(ApiJob::count())->toBe(0);
})->with([['zeta', 'v2:speech', 'zeta.generate'], ['theta', 'v2:voice-clone', 'theta.generate'], ['harakat', 'v2:harakat', 'harakat.diacritize']]);

it('keeps ambiguous new submissions reserved and releases known failures once', function (string $service, bool $ambiguous) {
    config(['runpod.endpoints.tashkeel_v1' => 'test-tashkeel']);
    $provider = Mockery::mock(RunPodProvider::class);
    $error = $ambiguous ? new RuntimeException('private timeout') : new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(422)));
    $provider->shouldReceive('run')->once()->andThrow($error);
    app()->instance(RunPodProvider::class, $provider);
    $wallets = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $data = $service === 'harakat' ? ['text' => 'مرحبا'] : apiV2Project('zeta');
    $id = $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertAccepted()->json('id');
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertOk()->assertJsonPath('id', $id);
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertJsonPath('status', $ambiguous ? 'processing' : 'failed');
    expect(ApiCreditReservation::count())->toBe(1)->and(ApiCreditReservation::first()->status)->toBe($ambiguous ? 'reserved' : 'released')
        ->and(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($wallets['app']);
    if (! $ambiguous) {
        expect(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($wallets);
    }
})->with([['zeta', true], ['zeta', false], ['harakat', true], ['harakat', false]]);

it('discovers new tools safely without rewriting plan or existing key scopes', function () {
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $plan->update(['api_allowed_tools' => ['v2:speech', 'v2:voice-clone', 'v2:ocr']]);
    $this->key->update(['scopes' => ['v2:speech', 'v2:jobs:read']]);
    $response = $this->getJson('/api/v2/services', $this->headers)->assertOk()
        ->assertJsonPath('service_details.zeta.scope', 'v2:speech')->assertJsonPath('service_details.theta.scope', 'v2:voice-clone')
        ->assertJsonPath('service_details.harakat.scope', 'v2:harakat')->assertDontSee('model_2')->assertDontSee('builtin_ref_batch')->assertDontSee('tashkeel');
    expect($response->json('services'))->toContain('zeta', 'theta', 'harakat');
    $catalog = app(App\Services\CustomerApi\V2\ApiCatalog::class);
    expect($catalog->scopes($this->customer))->not->toContain('v2:harakat', 'v2:zeta', 'v2:theta');
    $this->postJson('/api/v2/harakat', ['text' => 'مرحبا'], $this->headers)->assertForbidden();
    expect($plan->fresh()->api_allowed_tools)->toBe(['v2:speech', 'v2:voice-clone', 'v2:ocr'])
        ->and($this->key->fresh()->scopes)->toBe(['v2:speech', 'v2:jobs:read']);
});

it('releases failed new processing jobs without publishing partial results', function (string $service) {
    config(['runpod.endpoints.tashkeel_v1' => 'test-tashkeel']);
    $reference = $service === 'theta' ? apiV2Reference($this) : null;
    if ($reference) {
        $probe = Mockery::mock(AudioProbeService::class);
        $probe->shouldReceive('probeUploadedFile')->twice()->andReturn(['duration_sec' => 1]);
        app()->instance(AudioProbeService::class, $probe);
    }
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'failed-remote']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => [
        'success' => false, 'completed_segments' => 1, 'failed_segment' => ['index' => 1],
        'error' => 'private provider path', 'audio_base64' => base64_encode(apiV2Wav()), 'text' => 'partial',
    ]]);
    app()->instance(RunPodProvider::class, $provider);
    $before = CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all();
    $data = $service === 'harakat' ? ['text' => 'مرحبا'] : apiV2Project($service, $reference?->id);
    $id = $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertAccepted()->json('id');
    (new ReconcileMlJob(MlJob::first()->id))->handle();
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertJsonPath('status', 'failed')->assertJsonPath('result', null)->assertDontSee('private provider');
    $this->getJson('/api/v2/jobs/'.$id, $this->headers)->assertOk();
    expect(ApiCreditReservation::first()->status)->toBe('released')->and(CustomerFile::where('purpose', 'render')->count())->toBe(0)
        ->and(CreditWallet::where('customer_id', $this->customer->id)->pluck('balance_credits', 'wallet_type')->all())->toBe($before);
})->with(['zeta', 'theta', 'harakat']);

it('applies existing API concurrency and wallet limits to new tools', function (string $service) {
    $data = $service === 'harakat' ? ['text' => 'مرحبا'] : apiV2Project('zeta');
    ServicePlan::where('code', 'pro')->update(['api_concurrent_jobs' => 0]);
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertStatus(429)->assertJsonPath('error.code', 'concurrency_limit_exceeded');
    expect(ApiJob::count())->toBe(0);
    ServicePlan::where('code', 'pro')->update(['api_concurrent_jobs' => 10]);
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['balance_credits' => 0, 'subscription_balance_credits' => 0, 'addon_balance_credits' => 0]);
    $this->postJson('/api/v2/'.$service, $data, $this->headers)->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_credits');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
})->with(['zeta', 'harakat']);

it('documents valid nested requests and localized field contracts without real secrets', function () {
    $docs = app(App\Services\CustomerApi\V2\ApiDocumentation::class);
    foreach (['zeta', 'theta', 'harakat'] as $name) {
        $service = $docs->services()[$name];
        $examples = $docs->examples($service);
        expect(array_keys($examples))->toBe(['cURL', 'PHP', 'Python', 'JavaScript']);
        foreach ($examples as $example) {
            expect($example)->toContain('YOUR_API_KEY', '/api/v2/'.$name)->not->toContain('model_2', 'ref_audio', 'audio_url_batch');
        }
        preg_match("/-d '(.*)'/s", $examples['cURL'], $match);
        $input = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        expect(app(App\Services\CustomerApi\V2\ToolInput::class)->validate($name, $input))->toHaveKey('storage_mode');
        expect(json_decode($docs->responseExample($name), true, 512, JSON_THROW_ON_ERROR))->toHaveKeys(['id', 'status', 'service', 'result']);
    }
    $english = require resource_path('lang/en/api_v2.php');
    foreach (['ar', 'ku'] as $locale) {
        $translated = require resource_path('lang/'.$locale.'/api_v2.php');
        expect(array_keys($translated))->toBe(array_keys($english));
        foreach ($english as $key => $value) {
            preg_match_all('/:[a-z_]+/', $value, $source);
            preg_match_all('/:[a-z_]+/', $translated[$key], $target);
            expect($target[0])->toBe($source[0]);
        }
    }
});
