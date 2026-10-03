<?php

use App\Models\AdminAuditEvent;
use App\Models\ApiCreditReservation;
use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\Customer;
use App\Models\CustomerApiKey;
use App\Models\CustomerFile;
use App\Models\CustomerMcpConnection;
use App\Models\MlJob;
use App\Models\ToolAction;
use App\Models\User;
use App\Support\Admin\AdminDeveloperWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('s3');
    $this->seed();
    $this->operator = User::forceCreate(['name' => 'Developer support', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->operator->profile()->create(['first_name' => 'Developer', 'last_name' => 'Support']);
    $this->actingAs($this->operator, 'admin');
    $this->owner = Customer::create(['username' => 'dev_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'uid' => Str::uuid(), 'password' => 'fixture', 'status' => 1]);
    $this->reader = app(AdminDeveloperWorkspace::class);
});

function devJob(Customer $owner, array $values = []): MlJob
{
    $action = ToolAction::where('full_code', 'ocr.standard')->firstOrFail();

    return MlJob::create($values + ['id' => (string) Str::uuid(), 'customer_id' => $owner->id, 'tool_id' => $action->tool_id, 'tool_action_id' => $action->id, 'status' => 'running', 'input' => ['text' => 'PRIVATE_SOURCE'], 'output' => ['text' => 'PRIVATE_RESULT'], 'error' => ['body' => 'PRIVATE_ERROR']]);
}
function devApi(Customer $owner, ?MlJob $job = null, array $values = []): ApiJob
{
    return ApiJob::create($values + ['id' => 'job_'.Str::lower(Str::random(24)), 'customer_id' => $owner->id, 'api_key_id' => null, 'ml_job_id' => $job?->id, 'tool_code' => 'ocr', 'tool_action' => 'ocr.standard', 'status' => 'processing', 'storage_mode' => 'temporary', 'meta' => ['arguments' => 'PRIVATE_ARGUMENTS'], 'error_message' => 'PRIVATE_PROVIDER_BODY']);
}
function devConnection(Customer $owner): CustomerMcpConnection
{
    return CustomerMcpConnection::create(['customer_id' => $owner->id, 'client_id' => (string) Str::uuid(), 'name' => 'Fixture assistant', 'scopes' => ['v2:ocr'], 'status' => 'active']);
}

it('keeps age a read-only review marker until stale lifecycle resolution', function () {
    $job = devJob($this->owner, ['provider' => 'runpod', 'provider_job_id' => 'fixture-remote', 'created_at' => now()->subHours(3), 'started_at' => now()->subHours(3)]);
    $job->forceFill(['created_at' => now()->subHours(3)])->save();
    $rows = fn () => $this->reader->rows($this->reader->query('jobs', ['job' => $job->id])->get())->first();
    expect($rows()['problems'])->toContain('stale');
    expect($job->fresh()->status)->toBe('running');
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('Updated: 1')->assertSuccessful();
    expect($rows()['problems'])->not->toContain('stale');
    expect($job->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('classifies persisted App API MCP origins without inferring MCP from missing keys', function () {
    $app = devJob($this->owner);
    $rest = devJob($this->owner);
    $mcp = devJob($this->owner);
    devApi($this->owner, $rest);
    $connection = devConnection($this->owner);
    devApi($this->owner, $mcp, ['meta' => ['mcp_connection_id' => $connection->id, 'arguments' => 'PRIVATE_ARGUMENTS']]);
    foreach (['app' => $app, 'api' => $rest, 'mcp' => $mcp] as $channel => $job) {
        $models = $this->reader->query('jobs', ['channel' => $channel, 'customer' => $this->owner->id])->get();
        expect($models->pluck('id')->all())->toBe([$job->id]);
        expect($this->reader->rows($models)->first()['channel'])->toBe($channel);
        expect(app(\App\Services\Admin\AdminOperations::class)->row($models->first())['channel'])->toBe($channel);
    }
    $orphan = devJob($this->owner, ['input' => ['wallet_type' => 'api', 'text' => 'PRIVATE_SOURCE']]);
    expect($this->reader->rows($this->reader->query('jobs', ['job' => $orphan->id])->get())->first()['channel'])->toBe('api');
});

it('rejects foreign customer traces and does not follow corrupt cross-customer relations', function () {
    $other = Customer::create(['username' => 'other_'.Str::random(8), 'email' => Str::uuid().'@example.test', 'uid' => Str::uuid(), 'password' => 'fixture']);
    $job = devJob($other);
    $api = devApi($this->owner, $job);
    $connection = devConnection($other);
    $row = $this->reader->rows($this->reader->query('api', ['apiJob' => $api->id, 'customer' => $this->owner->id])->get())->first();
    expect($row['ml_job_id'])->toBeNull();
    foreach (['api', 'jobs', 'reservations', 'files', 'mcp', 'keys', 'audit'] as $section) {
        $this->get(route('admin.operations', ['locale' => 'en', 'section' => $section, 'customerFilter' => $this->owner->id, 'connectionId' => $connection->id]))->assertNotFound();
    }
});

it('projects reservations results and safe linked evidence with no external calls or writes', function () {
    $job = devJob($this->owner, ['status' => 'saving', 'failure_stage' => 'provider_submission_unknown', 'output' => ['provider_success' => true, 'text' => 'PRIVATE_RESULT']]);
    $api = devApi($this->owner, $job);
    $reservation = ApiCreditReservation::create(['customer_id' => $this->owner->id, 'api_job_id' => $api->id, 'amount' => 100, 'status' => 'settled', 'meta' => ['final_amount' => 70, 'secret' => 'PRIVATE_RESERVATION']]);
    $file = CustomerFile::create(['customer_id' => $this->owner->id, 'disk' => 's3', 'path' => 'PRIVATE_OBJECT_PATH', 'purpose' => 'render', 'mime' => 'text/plain', 'size_bytes' => 99, 'status' => 'active', 'retention_mode' => 'temporary', 'source_type' => 'api_job', 'source_id' => $api->id, 'expires_at' => now()->addDay(), 'meta' => ['original_name' => 'PRIVATE_FILENAME']]);
    ApiResultFile::create(['id' => (string) Str::uuid(), 'customer_id' => $this->owner->id, 'api_job_id' => $api->id, 'storage_file_id' => $file->id, 'result_kind' => 'text']);
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        $queries[] = $event->sql;
    });
    Storage::shouldReceive('disk')->never();
    $row = $this->reader->rows($this->reader->query('api', ['apiJob' => $api->id])->get())->first();
    expect($row)->toMatchArray(['ml_job_id' => $job->id, 'reservation_state' => 'settled', 'held_amount' => 0, 'settled_amount' => 70, 'released_amount' => 30, 'result_count' => 1]);
    expect($row['problems'])->toContain('unknown_submission', 'persistence');
    $results = $this->reader->rows($this->reader->query('files', ['apiJob' => $api->id])->get());
    expect($results->first())->toMatchArray(['mime' => 'text/plain', 'size_bytes' => 99, 'result_kind' => 'text', 'object_present' => 'not_checked']);
    foreach (['api', 'jobs', 'reservations', 'files'] as $section) {
        $this->get(route('admin.operations', ['locale' => 'en', 'section' => $section, 'apiJob' => $api->id]))->assertOk()->assertDontSee('PRIVATE_');
    }
    expect(collect($queries)->filter(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $sql))->all())->toBe([]);
    Http::assertNothingSent();
});

it('shows key and connection metadata without credential columns or token queries', function () {
    $key = CustomerApiKey::create(['customer_id' => $this->owner->id, 'name' => 'Fixture key', 'key_prefix' => 'mk_fixture', 'key_hash' => 'PRIVATE_HASH', 'scopes' => ['v2:ocr'], 'status' => 'active']);
    $connection = devConnection($this->owner);
    DB::table('oauth_clients')->insert(['id' => $connection->client_id, 'name' => 'Fixture client', 'secret' => 'PRIVATE_CLIENT_SECRET', 'redirect_uris' => '[]', 'grant_types' => '[]', 'revoked' => false]);
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'keys']))->assertOk()->assertSee('mk_fixture')->assertDontSee('PRIVATE_HASH');
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'mcp']))->assertOk()->assertSee('Fixture assistant')->assertSee($connection->client_id)->assertDontSee('PRIVATE_CLIENT_SECRET');
    expect(implode(' ', $sql))->not->toContain('oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes', 'key_hash');
});

it('keeps exact origin traces linked and audit customer scoped', function () {
    $connection = devConnection($this->owner);
    $api = devApi($this->owner, devJob($this->owner), ['meta' => ['mcp_connection_id' => $connection->id]]);
    $other = devApi($this->owner, devJob($this->owner));
    AdminAuditEvent::create(['admin_id' => $this->operator->id, 'action' => 'fixture.read', 'target_type' => ApiJob::class, 'target_id' => $api->id, 'reason' => 'Fixture evidence']);
    expect($this->reader->query('api', ['connectionId' => $connection->id])->pluck('id')->all())->toBe([$api->id]);
    expect($this->reader->query('mcp', ['apiJob' => $api->id])->pluck('id')->all())->toBe([$connection->id]);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'audit', 'customerFilter' => $this->owner->id, 'apiJob' => $api->id]))->assertOk()->assertSee('fixture.read');
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'audit', 'customerFilter' => $this->owner->id, 'apiJob' => $other->id]))->assertOk()->assertDontSee('fixture.read');
});

it('bounds pagination and keeps projection query count independent of page rows', function () {
    foreach (range(1, 26) as $n) {
        devApi($this->owner, devJob($this->owner));
    }
    $query = $this->reader->query('api', []);
    $page = $query->paginate(25);
    expect($page)->toHaveCount(25)->and($page->total())->toBe(26);
    expect($query->paginate(25, ['*'], 'page', 2))->toHaveCount(1);
    $sql = [];
    DB::listen(function ($event) use (&$sql) {
        $sql[] = $event->sql;
    });
    $this->reader->rows($page->getCollection()->take(1));
    $single = count($sql);
    $sql = [];
    $this->reader->rows($page->getCollection());
    expect(count($sql))->toBeLessThanOrEqual($single + 1);
    expect(implode(' ', $sql))->not->toContain('"input",', '"output",', '"error",', '"path",');
});

it('renders localized developer views and preserves gates as informational only', function (string $locale) {
    config(['customer_api.v2_enabled' => false, 'mcp.enabled' => false]);
    devApi($this->owner, devJob($this->owner));
    devConnection($this->owner);
    foreach (['jobs', 'api', 'keys', 'mcp', 'reservations', 'files'] as $section) {
        $response = $this->get(route('admin.operations', ['locale' => $locale, 'section' => $section]))->assertOk();
        expect($response->getContent())->not->toContain('admin_developer.', 'admin_p2.mcp', 'admin_shell.mcp', 'PRIVATE_');
        $response->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false);
    }
    expect(config('mcp.enabled'))->toBeFalse()->and(config('customer_api.v2_enabled'))->toBeFalse();
})->with(['en', 'ar', 'ku']);

it('requires fresh active admin read permission on requests', function () {
    $this->operator->update(['admin_capabilities' => []]);
    // Existing policy grants admin.read to active Admins; deeper capabilities are separate.
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'api']))->assertOk();
    $this->operator->update(['status' => 0]);
    $this->get(route('admin.operations', ['locale' => 'en', 'section' => 'mcp']))->assertForbidden();
});

it('keeps reservation amounts and review markers separate from processing state', function (string $state, int $held, int $released) {
    $api = devApi($this->owner, null, ['status' => 'failed']);
    ApiCreditReservation::create(['customer_id' => $this->owner->id, 'api_job_id' => $api->id, 'amount' => 80, 'status' => $state]);
    $row = $this->reader->rows($this->reader->query('reservations', ['apiJob' => $api->id])->get())->first();
    expect($row)->toMatchArray(['reservation_state' => $state, 'held_amount' => $held, 'released_amount' => $released, 'settled_amount' => 0, 'local_lifecycle' => 'not_recorded', 'api_status' => 'failed']);
    expect(in_array('retained_reservation', $row['problems']))->toBe($state === 'reserved');
})->with([['reserved', 80, 0], ['released', 0, 80]]);

it('finds result links without source metadata and counts only usable local file metadata', function () {
    $api = devApi($this->owner, devJob($this->owner, ['status' => 'done']));
    foreach (['active', 'expired', 'deleted'] as $state) {
        $file = CustomerFile::create(['customer_id' => $this->owner->id, 'disk' => 's3', 'path' => 'PRIVATE_PATH', 'purpose' => 'render', 'mime' => 'audio/wav', 'size_bytes' => 100, 'status' => 'active', 'expires_at' => $state === 'expired' ? now()->subDay() : now()->addDay(), 'deleted_at' => $state === 'deleted' ? now() : null]);
        ApiResultFile::create(['id' => (string) Str::uuid(), 'customer_id' => $this->owner->id, 'api_job_id' => $api->id, 'storage_file_id' => $file->id, 'result_kind' => 'audio']);
    }
    expect($this->reader->rows($this->reader->query('api', ['apiJob' => $api->id])->get())->first()['result_count'])->toBe(1);
    $files = $this->reader->rows($this->reader->query('files', ['apiJob' => $api->id])->get());
    expect($files)->toHaveCount(3);
    expect($files->pluck('api_job_id')->unique()->all())->toBe([$api->id]);
    expect($files->first()['storage_mode'])->toBe('temporary');
    expect($this->reader->query('files', ['job' => $api->ml_job_id])->count())->toBe(3);
    $app = devJob($this->owner);
    CustomerFile::create(['customer_id' => $this->owner->id, 'disk' => 's3', 'path' => 'PRIVATE_APP_PATH', 'purpose' => 'render', 'status' => 'active', 'source_type' => 'ml_job', 'source_id' => $app->id]);
    $appFiles = $this->reader->rows($this->reader->query('files', ['job' => $app->id])->get());
    expect($appFiles)->toHaveCount(1)->and($appFiles->first()['channel'])->toBe('app');
});

it('filters processing and effective revocation and safely presents public client identity', function () {
    $running = devJob($this->owner);
    $saving = devJob($this->owner, ['status' => 'saving']);
    devJob($this->owner, ['status' => 'done']);
    expect($this->reader->query('jobs', ['status' => 'processing'])->pluck('id')->all())->toEqualCanonicalizing([$running->id, $saving->id]);
    $connection = devConnection($this->owner);
    $connection->update(['client_id' => 'https://client.example.test/client.json', 'revoked_at' => now()]);
    expect($this->reader->query('mcp', ['status' => 'active'])->count())->toBe(0);
    $row = $this->reader->rows($this->reader->query('mcp', ['status' => 'revoked'])->get())->first();
    expect($row)->toMatchArray(['status' => 'revoked', 'client_identity' => 'https://client.example.test/client.json']);
    $connection->update(['client_id' => 'https://client.example.test/client.json?token=PRIVATE_TOKEN']);
    expect($this->reader->rows($this->reader->query('mcp', [])->get())->first()['client_identity'])->toBeNull();
});
