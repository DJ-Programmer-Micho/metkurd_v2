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
    foreach (ToolAction::whereIn('full_code', ['xomni.generate', 'xomni-v2.generate', 'clone_xomni.generate', 'vector-v2.generate', 'leo.transcribe', 'caption.standard', 'ocr.standard', 'stem.sep2', 'stem.sep4'])->get() as $action) {
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

it('submits through native OMNI once charges only the API wallet and persists without a client polling', function (string $model) {
    $this->speech['model'] = $model;
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
    expect(CustomerFile::count())->toBe(1)->and(CustomerFile::first()->retention_mode)->toBe('temporary')
        ->and(CustomerFile::first()->counts_toward_quota)->toBeFalse();
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
})->with(['1.5', '2.0']);

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
    $file = UploadedFile::fake()->create('document.pdf', 1, 'application/pdf');
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '1-5'], $this->headers)->assertUnprocessable();
    expect(ApiJob::count())->toBe(0);
    $this->postJson('/api/v2/ocr', ['file' => $file, 'pages' => '1-3', 'estimated_pages' => 1, 'page_count' => 1, 'exports' => ['txt', 'html']], $this->headers)->assertAccepted();
    $job = MlJob::firstOrFail();
    expect($job->status)->toBe('running')->and(data_get($job->input, 'page_range'))->toBe('1,2,3')
        ->and(data_get($job->input, 'exports.export_html'))->toBeTrue()->and(data_get($job->input, 'exports.export_docx'))->toBeFalse();
    expect(ApiCreditReservation::first()->amount)->toBe($this->customer->priceCreditsFor('ocr.standard', ['channel' => 'api', 'metric_code' => 'page', 'pages' => 3, 'page_count' => 3, 'files' => 1, 'file_count' => 1]));
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
    $this->actingAs($this->customer, 'app')->get('/'.$locale.'/app-v2/api')->assertOk()->assertSee('dir="ltr"', false)->assertSee('YOUR_API_KEY')->assertDontSee('RunPod');
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
