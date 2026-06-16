<?php

use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Services\CustomerApi\CustomerApiKeyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    $this->seed();

    registerCustomerApiGuardStub('POST', '/asr/wasr');
    registerCustomerApiGuardStub('POST', '/asr/qasr');
    registerCustomerApiGuardStub('POST', '/caption/qasr');
    registerCustomerApiGuardStub('POST', '/ocr');
    registerCustomerApiGuardStub('POST', '/translate');
    registerCustomerApiGuardStub('POST', '/stem');
});

function freeGuardCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => $username ?? "free_guard_{$suffix}",
        'email' => $email ?? "free-guard-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);
}

function freeGuardManualApiKey(Customer $customer, array $scopes = ['usage:read']): string
{
    $plain = (string) config('customer_api.key_prefix', 'mk_live_').Str::random(40);

    CustomerApiKey::create([
        'customer_id' => (int) $customer->id,
        'name' => 'Free Guard Test Key',
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

function registerCustomerApiGuardStub(string $method, string $uri): void
{
    $method = strtoupper($method);
    $fullUri = 'api/v1'.(str_starts_with($uri, '/') ? $uri : '/'.$uri);

    $existing = collect(Route::getRoutes()->getRoutes())->contains(function ($route) use ($method, $fullUri): bool {
        return strtoupper($route->uri()) === strtoupper($fullUri)
            && in_array($method, $route->methods(), true);
    });

    if ($existing) {
        return;
    }

    Route::prefix('api/v1')
        ->middleware([
            'customer.api',
            'customer.api.access',
            'customer.api.rate_limit',
            'customer.api.concurrency',
        ])
        ->group(function () use ($method, $uri): void {
            Route::match([$method], ltrim($uri, '/'), fn () => response()->json([
                'success' => true,
                'stubbed' => true,
            ]));
        });
}

function assertFreePlanApiAccessBlocked(\Illuminate\Testing\TestResponse $response): void
{
    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'message' => 'API access is available only on paid plans.',
            'code' => 'api_access_required',
        ]);
}

it('blocks free customers from /api/v1/me even when a key already exists', function () {
    $customer = freeGuardCustomer('free-guard-me@example.com', 'free_guard_me');
    $key = freeGuardManualApiKey($customer, ['usage:read']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->getJson('/api/v1/me')
    );
});

it('blocks free customers from /api/v1/usage even when a key already exists', function () {
    $customer = freeGuardCustomer('free-guard-usage@example.com', 'free_guard_usage');
    $key = freeGuardManualApiKey($customer, ['usage:read']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->getJson('/api/v1/usage')
    );
});

it('blocks free customers from public tts endpoints even when a key already exists', function () {
    $customer = freeGuardCustomer('free-guard-tts@example.com', 'free_guard_tts');
    $key = freeGuardManualApiKey($customer, ['tts:apollo-1-0v', 'tts:apollo-1-5v', 'tts:delta-1-0v', 'tts:vector-1-0', 'tts:vector-1-5']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->postJson('/api/v1/tts/apollo-1-0v', [
            'text' => 'Blocked free XTTS call',
        ])
    );

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->postJson('/api/v1/tts/apollo-1-5v', [
            'text' => 'Blocked free XOmni call',
        ])
    );

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->postJson('/api/v1/tts/delta-1-0v', [
            'text' => 'Blocked free Delta call',
        ])
    );

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/tts/vector-1-0')
    );

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/tts/vector-1-5')
    );
});

it('blocks free customers from asr endpoints through the shared customer api guard', function () {
    $customer = freeGuardCustomer('free-guard-asr@example.com', 'free_guard_asr');
    $key = freeGuardManualApiKey($customer, ['asr:wasr', 'asr:qasr']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/asr/wasr')
    );

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/asr/qasr')
    );
});

it('blocks free customers from caption endpoints through the shared customer api guard', function () {
    $customer = freeGuardCustomer('free-guard-caption@example.com', 'free_guard_caption');
    $key = freeGuardManualApiKey($customer, ['caption:qasr']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/caption/qasr')
    );
});

it('blocks free customers from ocr endpoints through the shared customer api guard', function () {
    $customer = freeGuardCustomer('free-guard-ocr@example.com', 'free_guard_ocr');
    $key = freeGuardManualApiKey($customer, ['ocr:generate']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/ocr')
    );
});

it('blocks free customers from translation endpoints through the shared customer api guard', function () {
    $customer = freeGuardCustomer('free-guard-translate@example.com', 'free_guard_translate');
    $key = freeGuardManualApiKey($customer, ['translation:generate']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->postJson('/api/v1/translate', [
            'text' => 'Blocked free translation call',
        ])
    );
});

it('blocks free customers from stem endpoints through the shared customer api guard', function () {
    $customer = freeGuardCustomer('free-guard-stem@example.com', 'free_guard_stem');
    $key = freeGuardManualApiKey($customer, ['stem:generate']);

    assertFreePlanApiAccessBlocked(
        $this->withToken($key)->post('/api/v1/stem')
    );
});
