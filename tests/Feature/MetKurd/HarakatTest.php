<?php

use App\Jobs\ReconcileMlJob;
use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerEntitlement;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Services\Harakat\HarakatJobSyncService;
use App\Services\MetKurd\Jobs\HarakatSubmissionService;
use App\Services\MetKurd\V2\HarakatInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    $this->seed(\Database\Seeders\OmniToolSeeder::class);
    Storage::fake('s3');
    Http::preventStrayRequests();
    config(['metkurd_v2.enabled' => true, 'runpod.base_url' => 'https://provider.test', 'runpod.api_key' => 'test-only', 'runpod.endpoints.tashkeel_v1' => 'test-harakat']);
    $this->customer = Customer::create(['username' => 'harakat-user', 'email' => 'harakat@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => 'app'], ['balance_credits' => 100000, 'subscription_balance_credits' => 100000, 'addon_balance_credits' => 0]);
    CreditWallet::updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => 'api'], ['balance_credits' => 777, 'subscription_balance_credits' => 777, 'addon_balance_credits' => 0]);
    $this->action = ToolAction::where('full_code', 'harakat.diacritize')->firstOrFail();
    // Fixture seeders run after registration migrations; copy source plan coverage.
    $source = ToolAction::where('full_code', 'xomni-v2.generate')->value('id');
    foreach (DB::table('plan_entitlements')->where('tool_action_id', $source)->get() as $row) {
        $copy = (array) $row;
        unset($copy['id']);
        $copy['tool_action_id'] = $this->action->id;
        DB::table('plan_entitlements')->insertOrIgnore($copy);
    }
    $this->actingAs($this->customer, 'app');
});

function harakatSubmit($test, string $key = 'test-key'): MlJob
{
    Http::fake(['https://provider.test/v2/test-harakat/run' => Http::response(['id' => 'provider-job'])]);

    return app(HarakatSubmissionService::class)->submit($test->customer, $key, '  مرحبا بكم  ');
}

function harakatOutput(MlJob $job): array
{
    return ['success' => true, 'job_id' => $job->id, 'source_mode' => 'text', 'text' => 'مَرْحَبًا بِكُمْ', 'characters' => 999, 'words' => 999, 'lines' => 999, 'chunks' => 1];
}

function harakatComplete($test): MlJob
{
    $job = harakatSubmit($test);
    Http::fake(['https://provider.test/v2/test-harakat/status/provider-job' => Http::response(['status' => 'COMPLETED', 'output' => harakatOutput($job)])]);
    (new ReconcileMlJob($job->id))->handle();

    return $job->fresh();
}

it('submits exactly the text contract once and bills server-counted accepted characters only', function () {
    $job = harakatSubmit($this);
    $again = app(HarakatSubmissionService::class)->submit($this->customer, 'test-key', 'مرحبا بكم');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://provider.test/v2/test-harakat/run'
        && $request->data() === ['input' => ['job_id' => $job->id, 'source_mode' => 'text', 'text' => 'مرحبا بكم']]);
    expect($again->id)->toBe($job->id)->and(MlJob::count())->toBe(1)->and($job->endpoint_key)->toBe('tashkeel_v1')
        ->and($job->input['characters'])->toBe(mb_strlen('مرحبا بكم'))
        ->and((int) $job->credits_charged)->toBe(app(HarakatSubmissionService::class)->quote($this->customer, mb_strlen('مرحبا بكم')))
        ->and(CreditLedger::where('direction', 'debit')->count())->toBe(1)
        ->and((int) CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->value('balance_credits'))->toBe(777);
    expect(fn () => app(HarakatSubmissionService::class)->submit($this->customer, 'test-key', 'نص آخر'))->toThrow(RuntimeException::class);
    expect(MlJob::count())->toBe(1);
    Http::assertSentCount(1);
});

it('validates text and configured limits before any debit', function (mixed $text) {
    config(['metkurd_v2.harakat.max_chars' => 10]);
    expect(fn () => app(HarakatSubmissionService::class)->submit($this->customer, 'bad', $text))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('direction', 'debit')->count())->toBe(0);
    Http::assertNothingSent();
})->with([null, '', " \n\t", [['file_url' => 'https://example.test']], str_repeat('ع', 11)]);

it('accepts Arabic mixed text and counts unicode characters after trimming', function () {
    $input = app(HarakatInput::class)->prepare("\u{00a0}مرحبا API 123 😀\u{00a0}");
    expect($input['text'])->toBe('مرحبا API 123 😀')->and($input['characters'])->toBe(mb_strlen('مرحبا API 123 😀'));
});

it('persists text and private TXT before completion and does not repoll terminal history', function () {
    $job = harakatComplete($this);
    expect($job->status)->toBe('done')->and($job->output['text'])->toBe('مَرْحَبًا بِكُمْ')
        ->and($job->output['characters'])->toBe(mb_strlen('مَرْحَبًا بِكُمْ'))->and($job->output['words'])->toBe(2)
        ->and($job->output['lines'])->toBe(1)->and($job->output)->not->toHaveKey('provider_output');
    $file = CustomerFile::where('source_id', $job->id)->firstOrFail();
    expect($file->tool_code)->toBe('harakat')->and($file->purpose)->toBe('render')->and($file->status)->toBe('active');
    Storage::disk('s3')->assertExists($job->output['path']);
    expect(Storage::disk('s3')->get($job->output['path']))->toBe($job->output['text']);
    $count = count(Http::recorded());
    app(HarakatJobSyncService::class)->sync($job);
    expect(count(Http::recorded()))->toBe($count)->and(CustomerFile::count())->toBe(1);
    $this->get(route('app.v2.harakat.txt', ['locale' => 'en', 'jobId' => $job->id]))->assertOk()->assertDownload('harakat.txt');
});

it('keeps persistence failures retryable without resubmission or refund', function () {
    $job = harakatSubmit($this);
    Http::fake(['https://provider.test/v2/test-harakat/status/provider-job' => Http::response(['status' => 'COMPLETED', 'output' => harakatOutput($job)])]);
    $storage = app(\App\Services\Storage\CustomerOutputStorage::class);
    $broken = Mockery::mock(\App\Services\Storage\CustomerOutputStorage::class)->makePartial();
    $broken->shouldReceive('saveTextToS3')->once()->andThrow(new RuntimeException('storage failure'));
    app()->instance(\App\Services\Storage\CustomerOutputStorage::class, $broken);
    app(HarakatJobSyncService::class)->sync($job);
    expect($job->fresh()->isActive())->toBeTrue()->and(CustomerFile::count())->toBe(0)->and($job->fresh()->refunded_at)->toBeNull();
    app()->instance(\App\Services\Storage\CustomerOutputStorage::class, $storage);
    $this->travel(61)->seconds();
    app(HarakatJobSyncService::class)->sync($job->fresh());
    expect($job->fresh()->status)->toBe('done')->and(CreditLedger::where('direction', 'debit')->count())->toBe(1);
});

it('sanitizes failed and malformed worker results and refunds once', function (string $case) {
    $job = harakatSubmit($this);
    $output = harakatOutput($job);
    $response = ['status' => 'COMPLETED', 'output' => $output];
    match ($case) {
        'false' => $response['output']['success'] = false,
        'failed' => $response['status'] = 'FAILED',
        'wrong_job' => $response['output']['job_id'] = 'another-customer',
        'missing_text' => $response['output']['text'] = '',
        'transport_error' => $response['error'] = 'https://secret.test/?signature=private',
        'output_error' => $response['output']['error'] = 'secret',
    };
    if (in_array($case, ['false', 'failed', 'output_error'], true)) {
        $response['output']['error'] = '/private/path customer secret';
    }
    Http::fake(['https://provider.test/v2/test-harakat/status/provider-job' => Http::response($response)]);
    (new ReconcileMlJob($job->id))->handle();
    (new ReconcileMlJob($job->id))->handle();
    expect($job->fresh()->status)->toBe('failed')->and($job->fresh()->output)->toBeNull()->and($job->fresh()->refunded_at)->not->toBeNull()
        ->and(json_encode($job->fresh()->error))->not->toContain('secret', '/private', 'signature')
        ->and(CustomerFile::count())->toBe(0)->and(CreditLedger::where('reference_code', "ml-job:{$job->id}:refund")->count())->toBe(1);
})->with(['false', 'failed', 'wrong_job', 'missing_text', 'transport_error', 'output_error']);

it('shows the newly submitted job immediately and preserves its identity after remount', function () {
    Http::fake(['https://provider.test/v2/test-harakat/run' => Http::response(['id' => 'provider-job'])]);
    $page = Livewire::test('app::v2.pages.tools.app-harakat')->set('text', 'مرحبا')->call('diacritize')->assertHasNoErrors();
    $job = MlJob::firstOrFail();
    $page->assertSet('currentJobId', $job->id)->assertSee('wire:poll.5s="pollHarakat"', false)->call('diacritize');
    Http::assertSentCount(1);
    Livewire::test('app::v2.pages.tools.app-harakat')->assertSet('submissionKey', $job->submission_key)
        ->call('clearText')->assertSet('currentJobId', $job->id)->assertSet('text', 'مرحبا');
});

it('enforces App concurrency while allowing an identical replay', function () {
    $job = harakatSubmit($this);
    $limits = Mockery::mock(\App\Services\Plans\PlanConcurrencyService::class);
    $limits->shouldReceive('allowedConcurrentJobsForCustomer')->andReturn(1);
    app()->instance(\App\Services\Plans\PlanConcurrencyService::class, $limits);
    expect(app(HarakatSubmissionService::class)->submit($this->customer, 'test-key', 'مرحبا بكم')->id)->toBe($job->id);
    expect(fn () => app(HarakatSubmissionService::class)->submit($this->customer, 'new-key', 'نص جديد'))->toThrow(RuntimeException::class);
    expect(MlJob::count())->toBe(1)->and(CreditLedger::where('direction', 'debit')->count())->toBe(1);
});

it('registers independent copies of all source prices and plan entitlements', function () {
    // This test operates only on the isolated SQLite fixture.
    DB::table('pricing_rules')->where('tool_action_id', $this->action->id)->delete();
    DB::table('plan_entitlements')->where('tool_action_id', $this->action->id)->delete();
    DB::table('tool_actions')->where('id', $this->action->id)->delete();
    DB::table('tools')->where('code', 'harakat')->delete();
    $source = ToolAction::where('full_code', 'xomni-v2.generate')->value('id');
    $migration = require database_path('migrations/2026_09_21_000001_register_harakat_tool.php');
    $migration->up();
    $target = ToolAction::where('full_code', 'harakat.diacritize')->value('id');
    foreach (['pricing_rules', 'plan_entitlements'] as $table) {
        $normalize = fn ($row) => collect((array) $row)->except(['id', 'tool_action_id', 'created_at', 'updated_at'])->all();
        expect(DB::table($table)->where('tool_action_id', $target)->orderBy('id')->get()->map($normalize)->all())
            ->toBe(DB::table($table)->where('tool_action_id', $source)->orderBy('id')->get()->map($normalize)->all());
    }
    $migration->down();
    expect(ToolAction::where('full_code', 'harakat.diacritize')->exists())->toBeTrue();
});

it('keeps uncertain submissions for review and never replays them', function () {
    Http::fake(['https://provider.test/*' => Http::response([], 503)]);
    $service = app(HarakatSubmissionService::class);
    $job = $service->submit($this->customer, 'unknown', 'مرحبا');
    $service->submit($this->customer, 'unknown', 'مرحبا');
    expect($job->failure_stage)->toBe('provider_submission_unknown')->and($job->refunded_at)->toBeNull()->and($job->provider_job_id)->toBeNull();
    Http::assertSentCount(1);
});

it('refunds known rejection or missing endpoint without legacy fallback', function (bool $configured) {
    config(['runpod.endpoints.tashkeel_v1' => $configured ? 'test-harakat' : null, 'runpod.endpoints.kocr_v2' => 'wrong-endpoint']);
    Http::fake(['https://provider.test/*' => Http::response([], 422)]);
    $job = app(HarakatSubmissionService::class)->submit($this->customer, 'rejected', 'مرحبا');
    expect($job->status)->toBe('failed')->and($job->refunded_at)->not->toBeNull();
    Http::assertSentCount($configured ? 1 : 0);
})->with([true, false]);

it('keeps accepted jobs active when endpoint configuration disappears', function () {
    $job = harakatSubmit($this);
    config(['runpod.endpoints.tashkeel_v1' => null]);
    app(HarakatJobSyncService::class)->sync($job);
    expect($job->fresh()->isActive())->toBeTrue()->and($job->fresh()->refunded_at)->toBeNull();
    Http::assertSentCount(1);
});

it('enforces independent access and rejects foreign unavailable and expired results', function () {
    $job = harakatComplete($this);
    $file = CustomerFile::firstOrFail();
    $url = route('app.v2.harakat.txt', ['locale' => 'en', 'jobId' => $job->id]);
    $file->update(['expires_at' => now()->subMinute()]);
    $this->get($url)->assertNotFound();
    $file->update(['expires_at' => null, 'status' => 'deleted']);
    $this->get($url)->assertNotFound();
    $file->update(['status' => 'active']);
    $foreign = Customer::create(['username' => 'other-harakat', 'email' => 'other@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($foreign, 'app')->get($url)->assertNotFound();
    $this->actingAs($this->customer, 'app');
    CustomerEntitlement::create(['customer_id' => $this->customer->id, 'tool_action_id' => $this->action->id, 'entitlement_channel' => 'app', 'allowed' => false]);
    $this->actingAs($this->customer->fresh(), 'app')->get(route('app.v2.harakat', ['locale' => 'en']))->assertForbidden();
    expect(fn () => app(HarakatSubmissionService::class)->submit($this->customer->fresh(), 'denied', 'مرحبا'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('renders text-only OCR-family workspace and history in each locale', function (string $locale) {
    app()->setLocale($locale);
    Lang::addJsonPath(resource_path('lang/app'));
    $job = harakatComplete($this);
    $this->get(route('app.v2.harakat', ['locale' => $locale]))->assertOk()->assertSee(__('Harakat 1.0'))->assertSee('metkurd-v2--info', false)
        ->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false)->assertSee('dir="auto"', false)
        ->assertDontSee('type="file"', false)->assertDontSee('v2-ocr-dropzone', false);
    $page = Livewire::test('app::v2.pages.tools.app-harakat')->assertSee(__('History'))
        ->call('openResult', $job->id)->assertSet('outputText', $job->output['text'])->assertSee(__('Download TXT'));
    $page->call('diacritize')->assertHasNoErrors();
    expect(MlJob::count())->toBe(1);
    $page->set('text', 'نص جديد')->assertSet('currentJobId', null)->assertSet('outputText', '');
    $this->get(route('app.v2.service', ['locale' => $locale, 'service' => 'ocr']))->assertOk()->assertSee(__('Harakat 1.0'))->assertSee(__('OCR Scanner 2.0'));
})->with(['en', 'ar', 'ku']);

it('delivers Harakat layout styles without nesting the outside page ancestor inside the component', function () {
    $page = Livewire::test('app::v2.pages.tools.app-harakat');
    expect($page->effects)->toHaveKey('globalStyleModule')->not->toHaveKey('styleModule');

    $url = str_replace('{component}', 'app---v2--pages--tools--app-harakat', \Livewire\Mechanisms\HandleRequests\EndpointResolver::componentGlobalCssPath());
    $css = $this->get($url)->assertOk()->getContent();
    expect($css)->toContain('.metkurd-v2 .v2-workspace.v2-harakat-workspace', 'grid-template-columns:repeat(2,minmax(0,1fr))', '.v2-harakat-editor:focus', '@media(max-width:767.98px)')
        ->not->toContain('[wire\\:name=');
});

it('preserves migration snapshots and classifies Harakat with its independent API scope', function () {
    $migration = require database_path('migrations/2026_09_21_000001_register_harakat_tool.php');
    DB::table('pricing_rules')->where('tool_action_id', $this->action->id)->update(['credits_per_unit' => 7]);
    $before = DB::table('pricing_rules')->where('tool_action_id', $this->action->id)->get()->toJson();
    $migration->up();
    expect(DB::table('pricing_rules')->where('tool_action_id', $this->action->id)->get()->toJson())->toBe($before)
        ->and($this->action->default_metric_code)->toBe('character')
        ->and(app(\App\Services\Admin\AdminV2Catalog::class)->classification(Tool::where('code', 'harakat')->first()))->toBe('current')
        ->and(app(\App\Services\CustomerApi\V2\ApiCatalog::class)->scopeForAction('harakat.diacritize'))->toBe('v2:harakat');
    $identity = app(\App\Support\CustomerStorageLibrary::class)->identity(new CustomerFile(['tool_code' => 'harakat']));
    expect($identity['service_key'])->toBe('ocr')->and($identity['key'])->toBe('harakat-1');
});
