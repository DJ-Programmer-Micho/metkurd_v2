<?php

use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerProfile;
use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\ToolAction;
use App\Services\Billing\PlanSwitcher;
use App\Services\CustomerApi\CustomerApiAccessService;
use App\Services\CustomerApi\CustomerApiKeyService;
use App\Support\AppShellData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('metkurd_v2.enabled', false); // Exercise the retained legacy page with rollout disabled.
    Cache::flush();
    $this->seed();
    app()->setLocale('en');
});

function apiAccessPageCustomer(?string $email = null, ?string $username = null): Customer
{
    $suffix = Str::lower(Str::random(8));

    $customer = Customer::create([
        'username' => $username ?? "api_access_{$suffix}",
        'email' => $email ?? "api-access-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ]);

    $customer->forceFill([
        'phone_verified_at' => now(),
    ])->save();

    CustomerProfile::create([
        'customer_id' => $customer->id,
        'first_name' => 'Api',
        'last_name' => 'Customer',
        'phone_number' => '+9647701234567',
        'country' => 'IQ',
    ]);

    return $customer->fresh(['profile', 'wallet', 'apiWallet', 'activeServiceSubscription.servicePlan']);
}

function assignApiAccessPlan(Customer $customer, string $code = 'pro'): ServicePlan
{
    $plan = ServicePlan::query()->where('code', $code)->firstOrFail();

    app(PlanSwitcher::class)->switchServicePlan($customer, $plan->id, [
        'provider' => 'fake',
        'billing_cycle' => 'monthly',
    ]);

    return $plan->fresh();
}

function seedApiAccessWallet(Customer $customer, string $walletType, int $subscriptionCredits, int $addonCredits = 0): CreditWallet
{
    return CreditWallet::query()->updateOrCreate(
        [
            'customer_id' => (int) $customer->id,
            'wallet_type' => $walletType,
        ],
        [
            'balance_credits' => $subscriptionCredits + $addonCredits,
            'subscription_balance_credits' => $subscriptionCredits,
            'addon_balance_credits' => $addonCredits,
        ]
    );
}

it('shows the api button with a subscribe badge for free customers', function () {
    $customer = apiAccessPageCustomer('free-api-button@example.com', 'free_api_button');

    $this->actingAs($customer, 'app')
        ->get(route('app.home', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('API')
        ->assertSee('Subscribe')
        ->assertSee(route('subscription-plan', ['locale' => 'en']));
});

it('shows an active api button for paid customers', function () {
    $customer = apiAccessPageCustomer('paid-api-button@example.com', 'paid_api_button');
    assignApiAccessPlan($customer, 'pro');

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.home', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('API')
        ->assertSee(route('app.api-access', ['locale' => 'en']));
});

it('keeps free customer app sidebar links visible even when api access is unavailable', function () {
    $customer = apiAccessPageCustomer('free-sidebar-links@example.com', 'free_sidebar_links');

    $this->actingAs($customer->fresh(), 'app');

    $shell = app(AppShellData::class)->forCurrentCustomer(true);

    expect((bool) data_get($shell, 'access_map.tts'))->toBeTrue()
        ->and((bool) data_get($shell, 'access_map.tran'))->toBeTrue()
        ->and((bool) data_get($shell, 'api_access_enabled'))->toBeFalse();

    $this->get(route('app.home', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee(route('app.xtts', ['locale' => 'en']))
        ->assertSee(route('app.tran', ['locale' => 'en']));
});

it('keeps paid customer app sidebar links visible when api is disabled and scopes are empty', function () {
    $customer = apiAccessPageCustomer('paid-sidebar-links@example.com', 'paid_sidebar_links');
    $plan = assignApiAccessPlan($customer, 'pro');
    $plan->update([
        'api_enabled' => false,
        'api_allowed_tools' => [],
    ]);

    $this->actingAs($customer->fresh(), 'app');

    $shell = app(AppShellData::class)->forCurrentCustomer(true);

    expect((bool) data_get($shell, 'access_map.tts'))->toBeTrue()
        ->and((bool) data_get($shell, 'access_map.tran'))->toBeTrue()
        ->and((bool) data_get($shell, 'api_access_enabled'))->toBeFalse();

    $this->get(route('app.home', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee(route('app.xtts', ['locale' => 'en']))
        ->assertSee(route('app.tran', ['locale' => 'en']));
});

it('shows a locked api access page for free customers and does not create keys', function () {
    $customer = apiAccessPageCustomer('free-api-page@example.com', 'free_api_page');

    $this->actingAs($customer, 'app')
        ->get(route('app.api-access', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('API Access is available for paid subscriptions only.')
        ->assertSee('Docs Preview')
        ->assertSee('Endpoint Catalog')
        ->assertSee('/api/v1/me')
        ->assertSee('/api/v1/translate')
        ->assertSee('View Plans');

    Livewire::actingAs($customer, 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Blocked Key')
        ->call('createKey')
        ->assertSet('statusMessage', 'API access is available for paid subscriptions only.');

    expect(CustomerApiKey::query()->where('customer_id', (int) $customer->id)->count())->toBe(0);
});

it('does not allow free plans to receive scopes or issue api keys even if api settings are tampered', function () {
    $customer = apiAccessPageCustomer('free-api-hard-block@example.com', 'free_api_hard_block');
    $freePlan = ServicePlan::query()->where('code', 'free')->firstOrFail();

    $freePlan->update([
        'api_enabled' => true,
        'api_monthly_credits' => 5000,
        'api_requests_per_minute' => 99,
        'api_concurrent_jobs' => 3,
        'api_allowed_tools' => ['usage:read', 'tts:apollo-1-0v'],
    ]);

    $access = app(CustomerApiAccessService::class);
    $config = $access->configForCustomer($customer->fresh());

    expect((bool) $config['api_enabled'])->toBeFalse()
        ->and((int) $config['requests_per_minute'])->toBe(0)
        ->and((int) $config['concurrent_jobs'])->toBe(0)
        ->and((array) $config['allowed_tools'])->toBe([])
        ->and($access->availableScopesForCustomer($customer->fresh()))->toBe([])
        ->and($access->customerHasApiAccess($customer->fresh()))->toBeFalse();

    expect(fn () => app(CustomerApiKeyService::class)->issue($customer->fresh(), 'Blocked Free Key'))
        ->toThrow(RuntimeException::class, 'API access is available only on paid plans.');

    expect(CustomerApiKey::query()->where('customer_id', (int) $customer->id)->count())->toBe(0);
});

it('lets a paid customer open the api page and generate a key', function () {
    $customer = apiAccessPageCustomer('paid-api-page@example.com', 'paid_api_page');
    assignApiAccessPlan($customer, 'pro');

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.api-access', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Generate API Key')
        ->assertSee('API Keys')
        ->assertSee('Documented API Scope Catalog')
        ->assertSee('Request Examples')
        ->assertSee('Response Examples');

    $component = Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Production Key')
        ->call('createKey')
        ->assertSet('statusType', 'success');

    $plainKey = (string) $component->instance()->justCreatedKey;
    $record = CustomerApiKey::query()->where('customer_id', (int) $customer->id)->latest('id')->firstOrFail();

    expect($plainKey)->toStartWith('mk_live_')
        ->and((string) $record->key_hash)->not->toBe($plainKey)
        ->and((string) $record->key_prefix)->toStartWith('mk_live_');
});

it('shows the full api key only once and keeps only the prefix after refresh', function () {
    $customer = apiAccessPageCustomer('api-key-once@example.com', 'api_key_once');
    assignApiAccessPlan($customer, 'pro');

    $component = Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'One-Time Key')
        ->call('createKey');

    $plainKey = (string) $component->instance()->justCreatedKey;
    $prefix = (string) CustomerApiKey::query()->where('customer_id', (int) $customer->id)->latest('id')->value('key_prefix');

    expect($plainKey)->not->toBe('');

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.api-access', ['locale' => 'en']))
        ->assertOk()
        ->assertSee($prefix)
        ->assertDontSee($plainKey);
});

it('creates a ui generated key that works with the public customer api', function () {
    $customer = apiAccessPageCustomer('api-page-me@example.com', 'api_page_me');
    assignApiAccessPlan($customer, 'pro');

    $component = Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Me Endpoint Key')
        ->call('createKey');

    $plainKey = (string) $component->instance()->justCreatedKey;

    $this->withToken($plainKey)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('customer.id', (int) $customer->id);
});

it('shows api wallet credits on the api access page while the app shell keeps app wallet credits', function () {
    $customer = apiAccessPageCustomer('api-balance-split@example.com', 'api_balance_split');
    assignApiAccessPlan($customer, 'pro');
    seedApiAccessWallet($customer, CreditWallet::TYPE_APP, 1111, 0);
    seedApiAccessWallet($customer, CreditWallet::TYPE_API, 2222, 0);

    $this->actingAs($customer->fresh(), 'app');

    $shell = app(AppShellData::class)->forCurrentCustomer(true);

    expect((int) data_get($shell, 'credit_balance'))->toBe(1111);

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->assertSet('creditBalance', 2222)
        ->assertSet('monthlyAllowance', 200000)
        ->assertSee('API Credits Remaining')
        ->assertDontSee('1,111');
});

it('renders grouped public api documentation with availability badges and copy helpers', function () {
    $customer = apiAccessPageCustomer('api-doc-groups@example.com', 'api_doc_groups');
    assignApiAccessPlan($customer, 'pro');

    $this->actingAs($customer->fresh(), 'app')
        ->get(route('app.api-access', ['locale' => 'en']))
        ->assertOk()
        ->assertSee('Core')
        ->assertSee('TTS')
        ->assertSee('ASR')
        ->assertSee('Caption')
        ->assertSee('OCR')
        ->assertSee('Translation')
        ->assertSee('STEM')
        ->assertSee('/api/v1/me')
        ->assertSee('/api/v1/usage')
        ->assertSee('/api/v1/tts/apollo-1-0v')
        ->assertSee('/api/v1/tts/apollo-1-5v')
        ->assertSee('/api/v1/tts/delta-1-0v')
        ->assertSee('/api/v1/tts/vector-1-0')
        ->assertSee('/api/v1/tts/vector-1-5')
        ->assertSee('/api/v1/asr/wasr')
        ->assertSee('/api/v1/caption/qasr')
        ->assertSee('/api/v1/ocr')
        ->assertSee('/api/v1/translate')
        ->assertSee('/api/v1/stem')
        ->assertDontSee('/api/v1/tts/xtts')
        ->assertDontSee('/api/v1/tts/xomni')
        ->assertDontSee('/api/v1/tts/f5tts')
        ->assertDontSee('/api/v1/tts/clone-xtts')
        ->assertDontSee('/api/v1/tts/clone-xomni')
        ->assertSee('Copy Path')
        ->assertSee('Copy Full URL')
        ->assertSee('Copy cURL')
        ->assertSee('Available')
        ->assertSee('tts:apollo-1-0v')
        ->assertSee('tts:vector-1-5')
        ->assertSee('translation:generate')
        ->assertSee('data-copy=', false);
});

it('lets a customer revoke their own key and the revoked key stops working', function () {
    $customer = apiAccessPageCustomer('api-revoke-own@example.com', 'api_revoke_own');
    assignApiAccessPlan($customer, 'pro');

    $component = Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Revoke Me')
        ->call('createKey');

    $plainKey = (string) $component->instance()->justCreatedKey;
    $key = CustomerApiKey::query()->where('customer_id', (int) $customer->id)->latest('id')->firstOrFail();

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->call('revokeKey', (int) $key->id)
        ->assertSet('statusType', 'success');

    $key->refresh();

    expect($key->status)->toBe('revoked')
        ->and($key->revoked_at)->not->toBeNull();

    $this->withToken($plainKey)
        ->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'invalid_api_key');
});

it('does not let a customer revoke another customers key', function () {
    $customer = apiAccessPageCustomer('api-owner@example.com', 'api_owner');
    $otherCustomer = apiAccessPageCustomer('api-other@example.com', 'api_other');
    assignApiAccessPlan($customer, 'pro');
    assignApiAccessPlan($otherCustomer, 'pro');

    $otherKey = app(\App\Services\CustomerApi\CustomerApiKeyService::class)
        ->issue($otherCustomer->fresh(), 'Other Key')['api_key'];

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->call('revokeKey', (int) $otherKey->id)
        ->assertSet('statusType', 'warning');

    expect($otherKey->fresh()->status)->toBe('active')
        ->and($otherKey->fresh()->revoked_at)->toBeNull();
});

it('rejects scopes outside the customers plan entitlement', function () {
    $customer = apiAccessPageCustomer('api-invalid-scope@example.com', 'api_invalid_scope');
    assignApiAccessPlan($customer, 'student');

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Invalid Scope Key')
        ->set('selectedScopes', ['tts:apollo-1-0v', 'scope:admin'])
        ->call('createKey')
        ->assertHasErrors(['selectedScopes.1']);

    expect(CustomerApiKey::query()->where('customer_id', (int) $customer->id)->count())->toBe(0);
});

it('does not expose api scopes that are blocked by api entitlements', function () {
    $customer = apiAccessPageCustomer('api-entitlement-scope@example.com', 'api_entitlement_scope');
    $plan = assignApiAccessPlan($customer, 'pro');
    $actionId = (int) ToolAction::query()->where('full_code', 'tts.standard')->value('id');

    PlanEntitlement::query()->updateOrCreate(
        [
            'service_plan_id' => (int) $plan->id,
            'tool_action_id' => $actionId,
            'entitlement_channel' => 'api',
        ],
        [
            'allowed' => false,
            'limits' => null,
        ]
    );

    $plan->update([
        'api_allowed_tools' => ['tts:apollo-1-0v', 'usage:read'],
    ]);

    $component = Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access');

    expect($component->get('availableScopes'))->not->toContain('tts:apollo-1-0v')
        ->and($component->get('availableScopes'))->toContain('usage:read');

    $component
        ->set('keyName', 'Blocked Scope Key')
        ->set('selectedScopes', ['tts:apollo-1-0v'])
        ->call('createKey')
        ->assertHasErrors(['selectedScopes.0']);
});

it('enforces the maximum number of active keys', function () {
    config()->set('customer_api.max_keys', 1);

    $customer = apiAccessPageCustomer('api-max-keys@example.com', 'api_max_keys');
    assignApiAccessPlan($customer, 'pro');

    app(\App\Services\CustomerApi\CustomerApiKeyService::class)->issue($customer->fresh(), 'First Key');

    Livewire::actingAs($customer->fresh(), 'app')
        ->test('app::pages.api.app-api-access')
        ->set('keyName', 'Second Key')
        ->call('createKey')
        ->assertSet('statusMessage', 'You have reached the maximum number of active API keys.');

    expect(CustomerApiKey::query()->where('customer_id', (int) $customer->id)->count())->toBe(1);
});
