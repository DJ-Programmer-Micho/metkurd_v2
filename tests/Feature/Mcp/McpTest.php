<?php

use App\Jobs\ReconcileMlJob;
use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\CreditWallet;
use App\Models\Customer;
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
    expect(config('database.default'))->toBe('sqlite')
        ->and(Illuminate\Support\Facades\DB::connection()->getDatabaseName())->toBe(':memory:');
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

    $sub = app(\App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    $payment = \App\Domain\Payments\Models\Payment::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
        'customer_id' => $this->customer->id, 'provider' => 'fib', 'purchase_type' => 'plan_subscription',
        'payment_mode' => 'recurring', 'provider_object_type' => 'subscription', 'status' => 'paid',
        'internal_status' => 'applied', 'local_reference' => (string) \Illuminate\Support\Str::uuid(),
        'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'amount' => 25000, 'currency' => 'IQD',
        'purchasable_type' => ServicePlan::class, 'purchasable_id' => $plan->id, 'paid_at' => now(), 'active_until' => now()->addMonth()]);
    $sub->update(['payment_id' => $payment->id, 'source' => 'fib', 'ends_at' => now()->addMonth()]);
    // Exercise the existing post-cutover authority, rather than relying on its
    // intentionally permissive pre-cutover compatibility branch.
    App\Models\AdminAuditEvent::create(['action' => App\Services\Billing\BillingReportingBoundary::ACTION,
        'target_type' => App\Services\Billing\PaymentDomainCutover::class, 'target_id' => (string) Illuminate\Support\Str::uuid(),
        'after_state' => ['reporting_boundary' => ['starts_at' => now()->subDay()->toDateTimeString(), 'credit_order_id' => 0, 'payment_id' => 0]]]);
    config(['mcp.enabled' => true, 'mcp.public_url' => 'https://metkurd.test/mcp', 'mcp.issuer' => 'https://metkurd.test',
        'mcp.origins' => ['https://metkurd.test'], 'mcp.session_store' => 'array']);
    static $rsa;
    $rsa ??= \phpseclib3\Crypt\RSA::createKey(2048);
    config(['passport.private_key' => $rsa->toString('PKCS8'), 'passport.public_key' => $rsa->getPublicKey()->toString('PKCS8')]);
    $this->oauthClient = app(\Laravel\Passport\ClientRepository::class)->createAuthorizationCodeGrantClient('Test MCP', ['https://client.example.test/callback'], false);
    $this->oauthClient->forceFill(['provider' => 'customers'])->save();
    $this->connection = \App\Models\CustomerMcpConnection::create(['customer_id' => $this->customer->id, 'client_id' => $this->oauthClient->id,
        'name' => 'Test MCP', 'scopes' => ['v2:speech', 'v2:jobs:read', 'v2:files:download'], 'status' => 'active']);
    $this->principal = new \App\Services\Mcp\McpConnectionPrincipal($this->connection->id, $this->connection->scopes);
    $repository = app(\Laravel\Passport\Bridge\AccessTokenRepository::class);
    $entity = $repository->getNewToken(app(\Laravel\Passport\Bridge\ClientRepository::class)->getClientEntity($this->oauthClient->id),
        array_map(fn ($s) => new \Laravel\Passport\Bridge\Scope($s), $this->connection->scopes), (string) $this->customer->id);
    $entity->setIdentifier(bin2hex(random_bytes(40)));
    $entity->setExpiryDateTime(new \DateTimeImmutable('+10 minutes'));
    $entity->setPrivateKey(new \League\OAuth2\Server\CryptKey($rsa->toString('PKCS8'), null, false));
    $repository->persistNewAccessToken($entity);
    $this->token = $entity->toString();
});

it('accepts current manual grants and still denies revoked connections', function () {
    $access = app(\App\Services\Mcp\CustomerMcpAccessService::class);
    expect($access->eligible($this->customer))->toBeTrue();
    $sub = app(\App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    $sub->update(['payment_id' => null, 'source' => 'admin_manual_grant', 'meta' => ['revenue_excluded' => true]]);
    expect($access->eligible($this->customer))->toBeTrue();
    $validator = new \App\Services\Mcp\OAuth\TokenValidator;
    $jwtResult = (new \Mcp\Server\Transport\Http\OAuth\JwtTokenValidator(config('mcp.issuer'), config('mcp.public_url'), $validator, algorithms: ['RS256'], scopeClaim: 'scopes'))->validate($this->token);
    expect($jwtResult->getErrorDescription())->toBeNull();
    expect($validator->validate($this->token)->getErrorDescription())->toBeNull();
    app(\App\Services\Mcp\OAuth\Connections::class)->revoke($this->customer, $this->connection->id);
    expect((new \App\Services\Mcp\OAuth\TokenValidator)->validate($this->token)->isAllowed())->toBeFalse();
});

it('protects discovery and rejects missing bearer tokens using SDK challenges', function () {
    $this->getJson('https://metkurd.test/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', 'https://metkurd.test/mcp');
    $this->postJson('https://metkurd.test/mcp', [])->assertUnauthorized()->assertHeader('WWW-Authenticate');
    config(['mcp.enabled' => false]);
    $this->postJson('https://metkurd.test/mcp', [])->assertStatus(503);
});

it('uses the official client over HTTP for discovery and one idempotent API-wallet speech job', function () {
    Illuminate\Support\Facades\Queue::fake([ReconcileMlJob::class]);
    $this->mock(RunPodProvider::class)->shouldReceive('run')->once()->andReturn(['id' => 'mcp-native-one', 'status' => 'IN_QUEUE']);
    $before = (int) CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $client = \Mcp\Client::builder()->setClientInfo('metkurd-tests', '1')->setMaxRetries(0)->setInitTimeout(3)->setRequestTimeout(3)->build();
    $client->connect(new \Mcp\Client\Transport\HttpTransport('https://metkurd.test/mcp',
        ['Authorization' => 'Bearer '.$this->token], new \Tests\Support\McpHttpClient));
    expect(array_map(fn ($t) => $t->name, $client->listTools()->tools))->toContain('metkurd_speak');
    $args = $this->speech + ['request_id' => (string) \Illuminate\Support\Str::uuid()];
    $first = $client->callTool('metkurd_speak', $args);
    expect($first->isError)->toBeFalse();
    $second = $client->callTool('metkurd_speak', $args);
    expect($second->structuredContent['job_id'])->toBe($first->structuredContent['job_id']);
    expect(ApiJob::count())->toBe(1)->and(MlJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1);
    expect(ApiJob::first()->api_key_id)->toBeNull();
    expect(MlJob::first()->provider_job_id)->toBe('mcp-native-one')->and(MlJob::first()->failure_stage)->toBeNull();
    expect((int) CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($before);
    expect($client->callTool('metkurd_get_job', ['job_id' => $first->structuredContent['job_id']])->isError)->toBeFalse();
});

it('completes PKCE consent exchange and rotating refresh with resource binding', function ($origin) {
    if ($origin === 'manual') {
        app(App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription']
            ->update(['payment_id' => null, 'source' => 'admin_manual_grant', 'meta' => ['revenue_excluded' => true]]);
    }
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $verifier = str_repeat('a', 64);
    $params = ['client_id' => $this->oauthClient->id, 'redirect_uri' => 'https://client.example.test/callback',
        'response_type' => 'code', 'scope' => 'v2:speech v2:jobs:read', 'state' => 'test-state',
        'resource' => config('mcp.public_url'), 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    $this->actingAs($this->customer, 'app')->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $approval = $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => session('authToken')])->assertRedirect();
    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('test-state');
    $form = ['client_id' => $this->oauthClient->id, 'grant_type' => 'authorization_code', 'code' => $query['code'],
        'redirect_uri' => $params['redirect_uri'], 'code_verifier' => $verifier, 'resource' => config('mcp.public_url')];
    $tokens = $this->post('https://metkurd.test/oauth/token', $form)->assertOk()->json();
    expect((new \App\Services\Mcp\OAuth\TokenValidator)->validate($tokens['access_token'])->isAllowed())->toBeTrue();
    $this->post('https://metkurd.test/oauth/token', $form)->assertStatus(400);
    $refresh = ['client_id' => $this->oauthClient->id, 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'resource' => config('mcp.public_url')];
    $new = $this->post('https://metkurd.test/oauth/token', $refresh)->assertOk()->json();
    expect($new['refresh_token'])->not->toBe($tokens['refresh_token']);
    $this->post('https://metkurd.test/oauth/token', $refresh)->assertStatus(400);
    $this->post('https://metkurd.test/oauth/token', array_replace($refresh, ['resource' => 'https://other.test']))->assertStatus(400);
    app(\App\Services\Mcp\OAuth\Connections::class)->revoke($this->customer, $this->connection->id);
    expect((new \App\Services\Mcp\OAuth\TokenValidator)->validate($new['access_token'])->isAllowed())->toBeFalse();
    $this->post('https://metkurd.test/oauth/token', array_replace($refresh, ['refresh_token' => $new['refresh_token']]))->assertStatus(400);
})->with(['online', 'manual']);

it('accepts configured current online plans without relying on their names', function ($code) {
    $plan = ServicePlan::where('code', $code)->firstOrFail();
    $plan->update(['api_enabled' => true, 'api_allowed_tools' => ['v2:speech'], 'api_requests_per_minute' => 60]);
    PlanEntitlement::updateOrCreate(['service_plan_id' => $plan->id, 'tool_action_id' => ToolAction::where('full_code', 'xomni-v2.generate')->value('id'), 'entitlement_channel' => 'api'], ['allowed' => true]);
    $sub = app(App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    $sub->payment->update(['purchasable_id' => $plan->id]);
    $sub->update(['service_plan_id' => $plan->id]);
    expect(app(App\Services\Mcp\CustomerMcpAccessService::class)->eligible($this->customer))->toBeTrue();
    expect(app(App\Services\Mcp\CustomerMcpAccessService::class)->scopes($this->customer))->toContain('v2:speech');
})->with(['student', 'pro', 'premium']);

it('accepts configured manual and custom plans without creating payment evidence', function ($code, $source) {
    $plan = ServicePlan::where('code', $code === 'custom' ? 'pro' : $code)->firstOrFail();
    if ($code === 'custom') {
        $plan = $plan->replicate();
        $plan->code = 'media-partner-custom';
        $plan->name = 'Media Partner';
        $plan->save();
    }
    $plan->update(['is_active' => true, 'is_free' => false, 'api_enabled' => true, 'api_allowed_tools' => ['v2:speech'], 'api_requests_per_minute' => 60]);
    PlanEntitlement::updateOrCreate(['service_plan_id' => $plan->id,
        'tool_action_id' => ToolAction::where('full_code', 'xomni-v2.generate')->value('id'), 'entitlement_channel' => 'api'], ['allowed' => true]);
    $counts = fn () => collect(['payments', 'payment_intents', 'payment_events'])
        ->mapWithKeys(fn ($table) => [$table => Illuminate\Support\Facades\DB::table($table)->count()])->all();
    $before = $counts();
    $customer = Customer::create(['username' => 'manual-mcp', 'email' => 'manual-mcp@example.test', 'password' => 'fixture', 'status' => 1]);
    App\Models\CustomerServiceSubscription::create(['customer_id' => $customer->id, 'service_plan_id' => $plan->id,
        'status' => 'active', 'source' => $source, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        'meta' => ['revenue_excluded' => true]]);
    $access = app(App\Services\Mcp\CustomerMcpAccessService::class);
    $access->assertEligible($customer);
    expect($access->scopes($customer))->toContain('v2:speech', 'v2:jobs:read', 'v2:files:download')
        ->and($counts())->toBe($before)
        ->and(App\Domain\Payments\Models\Payment::where('customer_id', $customer->id)->count())->toBe(0);
    $plan->update(['is_active' => false]);
    expect($access->eligible($customer))->toBeFalse();
    $plan->update(['is_active' => true]);
    App\Models\CustomerServiceSubscription::where('customer_id', $customer->id)->update(['ends_at' => now()->subSecond()]);
    expect($access->eligible($customer))->toBeFalse();
})->with([['student', 'admin_manual_grant'], ['pro', 'admin_manual_grant'], ['premium', 'admin_manual_grant'],
    ['custom', 'admin_manual_grant'], ['custom', 'internal_non_revenue']]);

it('accepts only a bound active commercial agreement', function () {
    $sub = app(App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    $sub->update(['payment_id' => null, 'source' => App\Services\Billing\ServiceAgreementLifecycle::SOURCE]);
    $admin = App\Models\User::forceCreate(['name' => 'Fixture', 'email' => 'agreement@example.test', 'password' => 'fixture', 'status' => 1]);
    $operation = App\Models\AdminOperation::create(['id' => (string) Illuminate\Support\Str::uuid(), 'admin_id' => $admin->id, 'action' => 'agreement.record', 'customer_id' => $this->customer->id, 'status' => 'completed', 'payload_hash' => str_repeat('a', 64), 'requested' => [], 'reason' => 'Isolated test fixture']);
    $agreement = App\Models\ServicePlanAgreement::create(['customer_id' => $this->customer->id, 'service_plan_id' => $sub->service_plan_id, 'subscription_id' => $sub->id,
        'admin_id' => $admin->id, 'operation_id' => $operation->id, 'reference' => 'test-only', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        'app_monthly_credits' => 1, 'api_monthly_credits' => 1, 'reason' => 'Isolated test fixture']);
    expect(app(App\Services\Mcp\CustomerMcpAccessService::class)->eligible($this->customer))->toBeTrue();
    $agreement->update(['status' => 'scheduled']);
    expect(app(App\Services\Mcp\CustomerMcpAccessService::class)->eligible($this->customer))->toBeFalse();
});

it('rejects unsafe callback registration and runtime redirect changes', function ($uri) {
    expect(App\Services\Mcp\OAuth\RedirectPolicy::allows($uri))->toBeFalse();
    $before = Laravel\Passport\Client::count();
    $this->artisan('mcp:register-client', ['name' => 'Invalid', 'redirect_uri' => [$uri]])->assertFailed();
    expect(Laravel\Passport\Client::count())->toBe($before);
})->with(['http://client.example.test/callback', 'https://localhost/callback', 'https://127.0.0.1/callback', 'https://CLIENT.LOCALHOST./callback', 'https://client.example.test/*', 'https://user:pass@client.example.test/callback', 'https://client.example.test/callback#fragment']);

it('enforces browser consent identity and PKCE before issuing tokens', function () {
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $params = ['client_id' => $this->oauthClient->id, 'redirect_uri' => 'https://client.example.test/callback', 'response_type' => 'code',
        'scope' => 'v2:speech', 'state' => 'test', 'resource' => config('mcp.public_url'), 'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('a', 43)];
    $this->actingAs($this->customer, 'app');
    foreach (['redirect_uri' => 'https://evil.example.test/callback', 'code_challenge_method' => 'plain', 'resource' => 'https://other.example.test'] as $field => $value) {
        $response = $this->get('https://metkurd.test/oauth/authorize?'.http_build_query(array_replace($params, [$field => $value])));
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
        expect($response->headers->get('Location'))->toBeNull();
    }
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => 'wrong'])->assertForbidden();
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $response = $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => session('authToken')])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->post('https://metkurd.test/oauth/token', ['client_id' => $this->oauthClient->id, 'grant_type' => 'authorization_code', 'code' => $query['code'],
        'redirect_uri' => $params['redirect_uri'], 'code_verifier' => str_repeat('b', 64), 'resource' => config('mcp.public_url')])->assertStatus(400);
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $authToken = session('authToken');
    $other = Customer::create(['username' => 'oauth-other', 'email' => 'oauth-other@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true, 'phone_verified_at' => now()]);
    $this->actingAs($other, 'app')->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => $authToken])->assertForbidden();
});

it('hides connect for Free balances and unconfigured non-Free plans', function () {
    config(['metkurd_v2.enabled' => true]);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    ServicePlan::whereKey($this->customer->currentServicePlanId())->update(['api_allowed_tools' => []]);
    $this->actingAs($this->customer, 'app')->get('/en/app-v2/mcp')->assertOk()->assertSee(__('mcp.configuration_unavailable'))->assertDontSee('class="btn btn-primary"', false);
    $sub = app(App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    $sub->update(['service_plan_id' => ServicePlan::where('is_free', true)->value('id')]);
    $this->get('/en/app-v2/mcp')->assertOk()->assertSee(__('mcp.paid_only'))->assertDontSee('class="btn btn-primary"', false);
    $this->get('https://metkurd.test/oauth/authorize')->assertForbidden();
    expect(Laravel\Passport\AuthCode::count())->toBe(0);
});

it('protects uploads from invalid MIME size and another signed-in customer', function () {
    mcpAllScopes($this);
    config(['metkurd_v2.enabled' => true]);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $files = app(App\Services\Mcp\Files::class);
    $session = $files->createUpload($this->principal, ['purpose' => 'voice_reference', 'request_id' => (string) Illuminate\Support\Str::uuid()]);
    foreach ([UploadedFile::fake()->createWithContent('fake.wav', 'not an audio file'), UploadedFile::fake()->create('huge.wav', 20481, 'audio/wav')] as $file) {
        expect(fn () => $files->upload($this->principal, $session['upload_session_id'], $file))->toThrow(Illuminate\Validation\ValidationException::class);
    }
    $this->actingAs($this->customer, 'app')->get($session['upload_url'])->assertOk()->assertDontSee($this->token);
    $other = Customer::create(['username' => 'upload-other', 'email' => 'upload-other@example.test', 'password' => 'fixture', 'status' => 1, 'email_verify' => true, 'phone_verify' => true, 'phone_verified_at' => now()]);
    $this->actingAs($other, 'app')->get($session['upload_url'])->assertNotFound();
    expect(CustomerFile::count())->toBe(0);
});

it('serves persisted result resources through the official client and protected binary download', function () {
    $provider = $this->mock(RunPodProvider::class);
    $provider->shouldReceive('run')->once()->andReturn(['id' => 'mcp-result']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['audio_base64' => base64_encode('wave result')]]);
    $client = mcpTestClient($this);
    $args = $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()];
    $job = $client->callTool('metkurd_speak', $args)->structuredContent;
    (new ReconcileMlJob(MlJob::first()->id))->handle();
    $result = $client->callTool('metkurd_get_job', ['job_id' => $job['job_id']])->structuredContent;
    expect($result['status'])->toBe('completed');
    expect(ApiCreditReservation::first()->status)->toBe('settled');
    $file = $result['result']['files'][0];
    $resource = $client->readResource($file['resource_uri']);
    expect(json_encode($resource))->toContain('OAuth bearer token required')->not->toContain('storage.example.test', 's3', 'RunPod');
    $this->get($file['download_url'], ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertStreamedContent('wave result');
    $this->get($file['download_url'])->assertUnauthorized();
    expect($client->callTool('metkurd_speak', array_replace($args, ['text' => 'changed']))->structuredContent['error']['code'])->toBe('idempotency_conflict');
    $other = Customer::create(['username' => 'result-other', 'email' => 'result-other@example.test', 'password' => 'fixture', 'status' => 1]);
    CustomerFile::first()->update(['customer_id' => $other->id]);
    $this->get($file['download_url'], ['Authorization' => 'Bearer '.$this->token])->assertNotFound();
    expect(json_encode($client->readResource($file['resource_uri'])))->toContain('invalid_file');
    CustomerFile::first()->update(['customer_id' => $this->customer->id]);
    ApiJob::first()->update(['customer_id' => $other->id]);
    expect($client->callTool('metkurd_get_job', ['job_id' => $job['job_id']])->structuredContent['error']['code'])->toBe('job_not_found');
    ApiJob::first()->update(['customer_id' => $this->customer->id]);
    CustomerFile::first()->update(['expires_at' => now()->subSecond()]);
    $this->get($file['download_url'], ['Authorization' => 'Bearer '.$this->token])->assertNotFound();
    app(App\Services\Mcp\OAuth\Connections::class)->revoke($this->customer, $this->connection->id);
    $this->get($file['download_url'], ['Authorization' => 'Bearer '.$this->token])->assertUnauthorized();
});

it('keeps MCP job and resource reads financially and operationally read only', function ($terminal) {
    Illuminate\Support\Facades\Queue::fake();
    $this->mock(RunPodProvider::class)->shouldReceive('run')->once()->andReturn(['id' => 'read-only-job']);
    $client = mcpTestClient($this);
    $job = $client->callTool('metkurd_speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()])->structuredContent;
    $completed = str_starts_with($terminal, 'completed');
    MlJob::first()->update(['status' => $completed ? 'done' : $terminal]);
    if ($completed) {
        ApiJob::first()->update(['status' => 'completed']);
        $file = CustomerFile::create(['customer_id' => $this->customer->id, 'disk' => 's3', 'path' => 'private/unlinked.wav',
            'purpose' => 'render', 'status' => 'active', 'size_bytes' => 10, 'mime' => 'audio/wav',
            'meta' => ['job_id' => MlJob::first()->id]]);
        if ($terminal === 'completed-primary') {
            App\Models\ApiResultFile::create(['id' => 'file_existing_primary', 'api_job_id' => ApiJob::first()->id,
                'storage_file_id' => $file->id, 'customer_id' => $this->customer->id, 'result_kind' => 'primary']);
        }
    }
    $tables = ['credit_wallets', 'credit_ledgers', 'api_credit_reservations', 'api_jobs', 'ml_jobs', 'customer_files', 'api_result_files', 'api_usage_logs'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    Storage::shouldReceive('disk')->never();
    $writes = [];
    Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes, $tables) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter)/i', $query->sql)) {
            foreach ($tables as $table) {
                if (str_contains($query->sql, $table)) {
                    $writes[] = $query->sql;
                }
            }
        }
    });
    $result = $client->callTool('metkurd_get_job', ['job_id' => $job['job_id']]);
    expect($result->isError)->toBeFalse()->and($result->structuredContent['status'])->toBe($completed ? 'completed' : 'queued');
    if ($terminal === 'completed-primary') {
        expect($result->structuredContent['result']['files'][0]['id'])->toBe('file_existing_primary');
    }
    if ($terminal === 'completed') {
        expect($result->structuredContent['result']['files'])->toBe([]);
    }
    $client->readResource('metkurd://jobs/'.$job['job_id']);
    expect($writes)->toBe([])->and($snapshot())->toBe($before);
    Illuminate\Support\Facades\Http::assertNothingSent();
    Illuminate\Support\Facades\Queue::assertNothingPushed();
})->with(['done', 'failed', 'completed', 'completed-primary']);

it('completes native PKCE with exact identity and the permitted port policy', function ($registered, $actual) {
    $this->oauthClient->forceFill(['mcp_application_type' => 'native', 'redirect_uris' => [$registered]])->save();
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $verifier = str_repeat('n', 64);
    $params = ['client_id' => $this->oauthClient->id, 'redirect_uri' => $actual, 'response_type' => 'code',
        'scope' => 'v2:speech', 'state' => 'native-state', 'resource' => config('mcp.public_url'),
        'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    $this->actingAs($this->customer, 'app')->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $response = $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => session('authToken')])->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith($actual.'?');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('native-state');
    $form = ['client_id' => $this->oauthClient->id, 'grant_type' => 'authorization_code', 'code' => $query['code'],
        'redirect_uri' => $actual, 'code_verifier' => $verifier, 'resource' => config('mcp.public_url')];
    $this->post('https://metkurd.test/oauth/token', array_replace($form, ['redirect_uri' => $actual.'/changed']))->assertStatus(400);
    $this->post('https://metkurd.test/oauth/token', $form)->assertOk();
})->with([
    ['http://127.0.0.1/callback/id', 'http://127.0.0.1:53219/callback/id'],
    ['http://[::1]/callback', 'http://[::1]:53219/callback'],
    ['http://localhost:8080/callback', 'http://localhost:8080/callback'],
]);

it('uses a CIMD URL through consent codes JWT refresh and metadata change revocation', function () {
    $id = 'https://client.example.test/oauth/client.json';
    $metadata = ['client_id' => $id, 'client_name' => 'Metadata client', 'redirect_uris' => ['https://client.example.test/callback'],
        'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'scope' => 'v2:speech v2:jobs:read'];
    $this->mock(App\Services\Mcp\OAuth\PublicMetadataDns::class)->shouldReceive('resolve')->andReturn('93.184.216.34');
    Illuminate\Support\Facades\Http::fake(function () use (&$metadata) {
        return Illuminate\Support\Facades\Http::response($metadata, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    });
    // Unauthenticated token requests cannot discover/persist arbitrary new clients.
    $clientsBefore = Laravel\Passport\Client::count();
    $this->post('https://metkurd.test/oauth/token', ['client_id' => $id, 'grant_type' => 'refresh_token',
        'refresh_token' => 'invalid', 'resource' => config('mcp.public_url')])->assertUnauthorized();
    expect(Laravel\Passport\Client::count())->toBe($clientsBefore);
    Illuminate\Support\Facades\Http::assertNothingSent();
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $verifier = str_repeat('c', 64);
    $params = ['client_id' => $id, 'redirect_uri' => $metadata['redirect_uris'][0], 'response_type' => 'code',
        'scope' => 'v2:speech v2:jobs:read', 'state' => 'cimd-state', 'resource' => config('mcp.public_url'), 'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    $this->getJson('https://metkurd.test/.well-known/oauth-authorization-server')->assertJsonPath('client_id_metadata_document_supported', true);
    $this->actingAs($this->customer, 'app')->get('https://metkurd.test/oauth/authorize?'.http_build_query(array_replace($params, ['redirect_uri' => 'https://evil.example.test/callback'])))->assertStatus(400);
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query(array_replace($params, ['scope' => 'v2:ocr'])))->assertStatus(400);
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk()->assertSee($id);
    $approval = $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => session('authToken')])->assertRedirect();
    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
    expect(Laravel\Passport\AuthCode::latest('expires_at')->first()->client_id)->toBe($id);
    $form = ['client_id' => $id, 'grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => $params['redirect_uri'],
        'code_verifier' => $verifier, 'resource' => config('mcp.public_url')];
    $tokens = $this->post('https://metkurd.test/oauth/token', $form)->assertOk()->json();
    expect((new App\Services\Mcp\OAuth\TokenValidator)->validate($tokens['access_token'])->isAllowed())->toBeTrue();
    $refresh = ['client_id' => $id, 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'resource' => config('mcp.public_url')];
    $tokens = $this->post('https://metkurd.test/oauth/token', $refresh)->assertOk()->json();
    $metadata['redirect_uris'] = ['https://client.example.test/new-callback'];
    $this->post('https://metkurd.test/oauth/token', array_replace($refresh, ['refresh_token' => $tokens['refresh_token']]))->assertStatus(400);
    expect((new App\Services\Mcp\OAuth\TokenValidator)->validate($tokens['access_token'])->isAllowed())->toBeFalse();
    expect(App\Models\CustomerMcpConnection::where('client_id', $id)->first()->status)->toBe('revoked');
    $params['redirect_uri'] = $metadata['redirect_uris'][0];
    $this->get('https://metkurd.test/oauth/authorize?'.http_build_query($params))->assertOk();
    $metadata['client_name'] = 'Changed while consent was open';
    $this->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve', 'auth_token' => session('authToken')])->assertForbidden();
});

it('reports readiness without changing plans wallets or permissions', function () {
    $readiness = app(App\Services\Mcp\Readiness::class);
    $plan = ServicePlan::where('code', 'pro')->firstOrFail();
    $before = $plan->getRawOriginal();
    $report = $readiness->plan($plan);
    expect($report['recognized_scopes'])->toContain('v2:speech');
    $context = ['chars' => 10, 'metric_code' => 'character', 'language' => 'ckb'];
    expect($readiness->customerAction($this->customer, 'xomni-v2.generate', $context)['ready'])->toBeTrue();
    expect($plan->fresh()->getRawOriginal())->toBe($before);
    $wallet = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->first();
    $wallet->update(['balance_credits' => 0, 'subscription_balance_credits' => 0, 'addon_balance_credits' => 0]);
    expect($readiness->customerAction($this->customer, 'xomni-v2.generate', $context)['ready'])->toBeFalse();
    $plan->update(['api_allowed_tools' => []]);
    expect($readiness->customerAction($this->customer, 'xomni-v2.generate', $context)['allowed'])->toBeFalse();
    expect($readiness->plan($plan)['missing_family_scopes'])->toContain('v2:speech');
    $plan->update(['api_allowed_tools' => ['v2:speech']]);
    PricingRule::where('tool_action_id', ToolAction::where('full_code', 'xomni-v2.generate')->value('id'))->whereIn('pricing_channel', ['api', 'all'])->delete();
    expect($readiness->customerAction($this->customer, 'xomni-v2.generate', $context)['pricing_configured'])->toBeFalse();
    expect(collect($readiness->plan($plan)['actions'])->firstWhere('action', 'xomni-v2.generate')['pricing'])->toBe('missing');
});

function mcpTestClient($test, \Mcp\Schema\Enum\ProtocolVersion $version = \Mcp\Schema\Enum\ProtocolVersion::V2025_11_25): \Mcp\Client
{
    $client = \Mcp\Client::builder()->setClientInfo('metkurd-tests', '1')->setProtocolVersion($version)->setMaxRetries(0)->setInitTimeout(3)->setRequestTimeout(3)->build();
    $client->connect(new \Mcp\Client\Transport\HttpTransport('https://metkurd.test/mcp', ['Authorization' => 'Bearer '.$test->token], new \Tests\Support\McpHttpClient));

    return $client;
}

it('renders the localized MCP portal and handles owned revocation', function ($locale) {
    config(['metkurd_v2.enabled' => true]);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $this->actingAs($this->customer, 'app')->get('/'.$locale.'/app-v2/mcp')->assertOk()
        ->assertSee('metkurd_theta')->assertSee('Test MCP')->assertSee('data-v2-confirm', false)
        ->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false)->assertDontSee($this->token)->assertDontSee('mcp.paid_only');
    Livewire\Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.mcp.app-mcp')->call('revoke', $this->connection->id)->assertDispatched('alert');
    expect($this->connection->fresh()->status)->toBe('revoked');
})->with(['en', 'ar', 'ku']);

it('denies tokens immediately after effective plan or account authority is removed', function ($case) {
    $sub = app(\App\Services\Billing\CustomerBillingStateService::class)->servicePlanState($this->customer)['subscription'];
    match ($case) {
        'free' => $sub->update(['service_plan_id' => ServicePlan::where('is_free', true)->value('id')]),
        'expired' => $sub->payment->update(['active_until' => now()->subMinute()]),
        'historical' => $sub->update(['ends_at' => now()->subMinute(), 'status' => 'expired']),
        'inactive_plan' => $sub->servicePlan->update(['is_active' => false]),
        'expired_manual' => $sub->update(['payment_id' => null, 'source' => 'admin_manual_grant', 'ends_at' => now()->subMinute()]),
        'api_disabled' => $sub->servicePlan->update(['api_enabled' => false]),
        'scope_removed' => $sub->servicePlan->update(['api_allowed_tools' => []]),
        'suspended' => $this->customer->update(['status' => 0]),
        'audience' => config(['mcp.public_url' => 'https://wrong.example.test/mcp']),
        'token_expired' => Illuminate\Support\Facades\DB::table('oauth_access_tokens')->update(['expires_at' => now()->subMinute()]),
    };
    expect((new \App\Services\Mcp\OAuth\TokenValidator)->validate($this->token)->isAllowed())->toBeFalse();
})->with(['free', 'expired', 'historical', 'inactive_plan', 'expired_manual', 'api_disabled', 'scope_removed', 'suspended', 'audience', 'token_expired']);

it('rejects invalid protocol requests and hidden worker arguments without billing', function () {
    $client = mcpTestClient($this);
    expect(fn () => $client->callTool('metkurd_speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid(), 'audio_url' => 'http://127.0.0.1/private']))->toThrow(\Mcp\Exception\RequestException::class);
    expect(ApiJob::count())->toBe(0);
    $this->postJson('https://metkurd.test/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'not/a/method'], ['Authorization' => 'Bearer '.$this->token])->assertStatus(400);
    $this->postJson('https://metkurd.test/mcp', [], ['Origin' => 'https://evil.example.test'])->assertForbidden();
});

it('serves modern stateless MCP through the official client', function () {
    $client = mcpTestClient($this, \Mcp\Schema\Enum\ProtocolVersion::V2026_07_28);
    expect(count($client->listTools()->tools))->toBe(14);
    expect($client->callTool('metkurd_list_voices')->isError)->toBeFalse();
});

it('retains ambiguous reservations and releases confirmed rejections without duplicate dispatch', function ($ambiguous) {
    $failure = $ambiguous ? new RuntimeException('PRIVATE PROVIDER BODY') : new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(422, [], 'PRIVATE PROVIDER BODY')));
    $this->mock(RunPodProvider::class)->shouldReceive('run')->once()->andThrow($failure);
    $client = mcpTestClient($this);
    $args = $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()];
    $first = $client->callTool('metkurd_speak', $args);
    $second = $client->callTool('metkurd_speak', $args);
    expect($second->structuredContent['job_id'])->toBe($first->structuredContent['job_id']);
    expect(ApiCreditReservation::first()->status)->toBe($ambiguous ? 'reserved' : 'released');
    expect(json_encode($second))->not->toContain('PRIVATE PROVIDER BODY', 'RunPod');
})->with([true, false]);

it('requires per-tool scopes before any side effect', function ($tool) {
    $result = app(\App\Services\Mcp\Tools::class)->call(new \App\Services\Mcp\McpConnectionPrincipal($this->connection->id, []), $tool, []);
    expect($result->structuredContent['error']['code'])->toBe('scope_not_allowed');
    expect(ApiJob::count())->toBe(0);
})->with(['speak', 'clone_voice', 'zeta', 'theta', 'transcribe', 'caption', 'ocr', 'harakat', 'stem', 'get_job', 'list_services', 'list_voices', 'list_recent_files', 'create_upload_session']);

function mcpWav(): string
{
    return 'RIFF'.pack('V', 40).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', 4).str_repeat("\0", 4);
}

function mcpAllScopes($test): void
{
    $scopes = [...app(App\Services\CustomerApi\V2\ApiCatalog::class)->serviceScopes(), 'v2:jobs:read', 'v2:files:download', 'mcp:uploads'];
    $test->connection->update(['scopes' => $scopes]);
    $test->principal = new App\Services\Mcp\McpConnectionPrincipal($test->connection->id, $scopes);
}

it('maps every remaining processing tool to one native API job and honors replay', function ($tool, $action, $outcome) {
    mcpAllScopes($this);
    config(['runpod.endpoints.stem' => 'test-stem', 'runpod.endpoints.kocr_v2' => 'test-ocr', 'runpod.endpoints.tashkeel_v1' => 'test-harakat']);
    $this->mock(AudioProbeService::class)->shouldReceive('probeUploadedFile')->andReturn(['duration_sec' => 1]);
    Illuminate\Support\Facades\Process::fake(['*' => Illuminate\Support\Facades\Process::result(output: "Pages: 2\n", exitCode: 0)]);
    $provider = $this->mock(RunPodProvider::class)->shouldReceive($tool === 'ocr' ? 'runWithPolicy' : 'run')->once();
    if ($outcome === 'accepted') {
        $provider->andReturn(['id' => 'native-mcp-'.$tool]);
    } else {
        $provider->andThrow($outcome === 'ambiguous' ? new RuntimeException('PRIVATE PROVIDER BODY')
            : new Illuminate\Http\Client\RequestException(new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(422, [], 'PRIVATE PROVIDER BODY'))));
    }
    $args = ['request_id' => (string) Illuminate\Support\Str::uuid()];
    $files = app(App\Services\Mcp\Files::class);
    if (in_array($tool, ['clone_voice', 'theta', 'transcribe', 'caption', 'ocr', 'stem'])) {
        $purpose = match ($tool) {
            'clone_voice', 'theta' => 'voice_reference', 'transcribe' => 'transcription', default => $tool
        };
        $session = $files->createUpload($this->principal, ['purpose' => $purpose, 'request_id' => (string) Illuminate\Support\Str::uuid()]);
        $file = $tool === 'ocr' ? UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n%test\n") : UploadedFile::fake()->createWithContent('input.wav', mcpWav());
        $record = $files->upload($this->principal, $session['upload_session_id'], $file);
        $args += in_array($tool, ['clone_voice', 'theta']) ? ['reference_id' => $record->id] : ['file_id' => $record->id];
    }
    if ($tool === 'clone_voice') {
        $args += ['text' => 'Hello', 'language' => 'en'];
    }
    if ($tool === 'harakat') {
        $args += ['text' => 'مرحبا'];
    }
    if ($tool === 'stem') {
        $args += ['mode' => 4];
    }
    if (in_array($tool, ['zeta', 'theta'])) {
        $args['segments'] = [['text' => 'سڵاو', 'language' => 'ckb', 'pause_after_ms' => 0] + ($tool === 'zeta' ? ['voice' => 'api-v2-voice'] : ['reference_id' => $args['reference_id']])];
        unset($args['reference_id']);
    }
    $before = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $result = app(App\Services\Mcp\Tools::class)->call($this->principal, $tool, $args);
    if ($outcome === 'rejected') {
        // Reads no longer perform settlement. The durable owner advances API state.
        (new ReconcileMlJob(MlJob::first()->id))->handle();
        $result = app(App\Services\Mcp\Tools::class)->call($this->principal, 'get_job', ['job_id' => ApiJob::first()->id]);
        expect($result->structuredContent['error']['code'])->toBe('processing_failed');
    } else {
        expect($result->structuredContent)->not->toHaveKey('error');
    }
    expect($result->isError)->toBeFalse();
    $again = app(App\Services\Mcp\Tools::class)->call($this->principal, $tool, $args);
    expect($again->structuredContent['job_id'])->toBe($result->structuredContent['job_id']);
    expect(MlJob::count())->toBe(1)->and(ApiJob::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(1);
    expect(MlJob::first()->toolAction->full_code)->toBe($action);
    expect(MlJob::first()->provider_job_id)->toBe($outcome === 'accepted' ? 'native-mcp-'.$tool : null);
    expect(ApiCreditReservation::first()->status)->toBe($outcome === 'rejected' ? 'released' : 'reserved');
    expect(json_encode($again))->not->toContain('PRIVATE PROVIDER BODY', 'RunPod', 'storage.example.test');
    expect(data_get(MlJob::first()->input, 'wallet_type'))->toBe('api');
    expect(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($before);
})->with([['clone_voice', 'vector-v2.generate'], ['zeta', 'zeta.generate'], ['theta', 'theta.generate'],
    ['transcribe', 'leo.transcribe'], ['caption', 'caption.standard'], ['ocr', 'ocr.standard'], ['harakat', 'harakat.diacritize'], ['stem', 'stem.sep4']])->with(['accepted', 'rejected', 'ambiguous']);

it('enforces upload expiry purpose single-use and file ownership', function () {
    mcpAllScopes($this);
    $files = app(App\Services\Mcp\Files::class);
    $this->mock(AudioProbeService::class)->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 1]);
    $args = ['purpose' => 'transcription', 'request_id' => (string) Illuminate\Support\Str::uuid()];
    $session = $files->createUpload($this->principal, $args);
    expect($files->createUpload($this->principal, $args)['upload_session_id'])->toBe($session['upload_session_id']);
    $file = UploadedFile::fake()->createWithContent('input.wav', mcpWav());
    $record = $files->upload($this->principal, $session['upload_session_id'], $file);
    expect($files->upload($this->principal, $session['upload_session_id'], $file)->id)->toBe($record->id);
    expect(CustomerFile::count())->toBe(1)->and(ApiCreditReservation::count())->toBe(0);
    expect(fn () => $files->withInput($this->principal, $record->id, 'ocr', fn () => null))->toThrow(App\Services\CustomerApi\V2\ApiProblem::class);
    $other = Customer::create(['username' => 'mcp-other', 'email' => 'mcp-other@example.test', 'password' => 'test', 'status' => 1]);
    $record->update(['customer_id' => $other->id]);
    expect(fn () => $files->owned($this->principal, $record->id))->toThrow(App\Services\CustomerApi\V2\ApiProblem::class);
    $record->update(['customer_id' => $this->customer->id, 'expires_at' => now()->subSecond()]);
    expect(fn () => $files->owned($this->principal, $record->id))->toThrow(App\Services\CustomerApi\V2\ApiProblem::class);
    $record->update(['expires_at' => null, 'status' => 'deleted']);
    expect(fn () => $files->owned($this->principal, $record->id))->toThrow(App\Services\CustomerApi\V2\ApiProblem::class);
    Illuminate\Support\Facades\DB::table('mcp_upload_sessions')->update(['expires_at' => now()->subSecond()]);
    expect(fn () => $files->upload($this->principal, $session['upload_session_id'], $file))->toThrow(App\Services\CustomerApi\V2\ApiProblem::class);
});

it('shares API rate and concurrency limits and preserves new deliberate intents', function () {
    $this->mock(RunPodProvider::class)->shouldReceive('run')->twice()->andReturn(['id' => 'separate-native']);
    $tools = app(App\Services\Mcp\Tools::class);
    $first = $tools->call($this->principal, 'speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()]);
    $second = $tools->call($this->principal, 'speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()]);
    expect($second->structuredContent['job_id'])->not->toBe($first->structuredContent['job_id']);
    ServicePlan::whereKey($this->customer->currentServicePlanId())->update(['api_concurrent_jobs' => 2]);
    $third = $tools->call($this->principal, 'speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()]);
    expect($third->structuredContent['error']['code'])->toBe('concurrency_limit_exceeded');
    Illuminate\Support\Facades\RateLimiter::increment('customer-api-rate:'.$this->customer->id, 60, 101);
    expect($tools->call($this->principal, 'list_services', [])->structuredContent['error']['code'])->toBe('rate_limit');
    expect(ApiJob::count())->toBe(2);
});

it('rechecks a removed family scope while other configured families remain usable', function () {
    mcpAllScopes($this);
    $client = mcpTestClient($this);
    ServicePlan::whereKey($this->customer->currentServicePlanId())->update(['api_allowed_tools' => ['v2:ocr']]);
    expect((new App\Services\Mcp\OAuth\TokenValidator)->validate($this->token)->isAllowed())->toBeTrue();
    $this->mock(RunPodProvider::class)->shouldNotReceive('run');
    $result = $client->callTool('metkurd_speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()]);
    expect($result->structuredContent['error']['code'])->toBe('scope_not_allowed');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
});

it('checks the independent API action entitlement for each processing tool', function ($tool, $action) {
    mcpAllScopes($this);
    PlanEntitlement::where('service_plan_id', $this->customer->currentServicePlanId())
        ->where('tool_action_id', ToolAction::where('full_code', $action)->value('id'))->where('entitlement_channel', 'api')->update(['allowed' => false]);
    $result = app(App\Services\Mcp\Tools::class)->call($this->principal, $tool, ['request_id' => (string) Illuminate\Support\Str::uuid(), 'mode' => 4]);
    expect($result->structuredContent['error']['code'])->toBe('scope_not_allowed');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
})->with([['speak', 'xomni-v2.generate'], ['clone_voice', 'vector-v2.generate'], ['zeta', 'zeta.generate'], ['theta', 'theta.generate'],
    ['transcribe', 'leo.transcribe'], ['caption', 'caption.standard'], ['ocr', 'ocr.standard'], ['harakat', 'harakat.diacritize'], ['stem', 'stem.sep4']]);

it('keeps independent rollout gates and denies oversize bodies before processing', function () {
    config(['customer_api.v2_enabled' => false, 'metkurd_v2.enabled' => false]);
    expect(mcpTestClient($this)->callTool('metkurd_list_voices')->isError)->toBeFalse();
    $this->call('POST', 'https://metkurd.test/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 262145))->assertStatus(413);
    expect(ApiJob::count())->toBe(0);
});

it('does not use App credits when the API wallet is empty', function () {
    CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->update(['balance_credits' => 0, 'subscription_balance_credits' => 0, 'addon_balance_credits' => 0]);
    $appBefore = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits');
    $this->mock(RunPodProvider::class)->shouldNotReceive('run');
    $client = mcpTestClient($this);
    expect($client->callTool('metkurd_list_services')->isError)->toBeFalse();
    expect($client->callTool('metkurd_list_voices')->isError)->toBeFalse();
    $result = app(App\Services\Mcp\Tools::class)->call($this->principal, 'speak', $this->speech + ['request_id' => (string) Illuminate\Support\Str::uuid()]);
    expect($result->structuredContent['error']['code'])->toBe('insufficient_credits');
    expect(MlJob::count())->toBe(0)->and(ApiCreditReservation::count())->toBe(0);
    expect(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe($appBefore);
});

it('keeps real browser CSRF enforcement on consent without applying it to bearer transport', function () {
    $middleware = new class(app(), app('encrypter')) extends Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
    app()->instance(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $middleware);
    $this->customer->forceFill(['phone_verified_at' => now()])->save();
    $this->actingAs($this->customer, 'app')->post('https://metkurd.test/oauth/authorize', ['decision' => 'approve'])->assertStatus(419);
    expect(mcpTestClient($this)->callTool('metkurd_list_voices')->isError)->toBeFalse();
});
