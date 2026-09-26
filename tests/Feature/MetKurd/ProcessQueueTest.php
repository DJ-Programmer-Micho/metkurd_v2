<?php

use App\Models\Customer;
use App\Models\CustomerEntitlement;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\ToolAction;
use App\Services\Plans\PlanConcurrencyService;
use App\Support\CustomerProcessQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    $this->seed();
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    config(['metkurd_v2.enabled' => true]);
    Http::preventStrayRequests();
    Storage::fake('s3');
    $this->customer = Customer::create(['username' => 'queue-user', 'email' => 'queue@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($this->customer, 'app');
    foreach (array_keys(app(CustomerProcessQueue::class)->definitions()) as $action) {
        CustomerEntitlement::create(['customer_id' => $this->customer->id, 'tool_action_id' => ToolAction::where('full_code', $action)->value('id'), 'entitlement_channel' => 'app', 'allowed' => true]);
    }
});

function processQueueJob(Customer $customer, string $action = 'ocr.standard', string $status = 'running', array $attributes = []): MlJob
{
    $definition = app(CustomerProcessQueue::class)->definitions()[$action];
    $toolAction = ToolAction::where('full_code', $action)->firstOrFail();
    $kind = match ($definition['kind']) {
        'qasr' => 'leo', 'kocr' => 'ocr', 'omni_clone' => $definition['legacy_tool'], default => $definition['kind']
    };

    return MlJob::forceCreate(array_replace([
        'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => \App\Models\Tool::where('code', $definition['legacy_tool'])->value('id'), 'tool_action_id' => $toolAction->id,
        'job_kind' => $kind, 'status' => $status, 'endpoint_key' => $definition['endpoint'] ?? match ($kind) {
            'leo', 'caption' => 'qasr_v2', 'ocr' => 'kocr_v2', 'stem' => 'stem', 'harakat' => 'tashkeel_v1', default => 'omni_v2'
        },
        'submission_key' => (string) Str::uuid(),
        'input' => ['v2' => true, 'workspace' => $kind === 'stem' ? 'stem_v2' : null, 'separation_mode' => $definition['stems'] ?? null, 'text' => 'Owned input', 'provider_status' => 'COMPLETED'],
        'output' => [], 'error' => ['message' => 'private-provider-error'], 'provider_job_id' => 'private-provider-id',
        'created_at' => now()->subHour(), 'updated_at' => now(), 'finished_at' => $status === 'done' ? now() : null,
        'lock_expires_at' => now()->subMinutes(10),
    ], $attributes));
}

it('reads all twelve V2 products in one bounded projection without provider storage or financial work', function () {
    foreach (array_keys(app(CustomerProcessQueue::class)->definitions()) as $action) {
        processQueueJob($this->customer, $action);
    }
    Storage::shouldReceive('disk')->never();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(CustomerProcessQueue::class)->read($this->customer);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
    $projection = Str::before(strtolower($queries[0]['query']), ' from ');
    expect($projection)->not->toContain('input', 'output', 'error', 'provider', '*');
    expect(strtolower($queries[0]['query']))->toContain('limit 13')->not->toContain('customer_files', 'pricing');
    expect($result['jobs'])->toHaveCount(12)->and($result['has_active'])->toBeTrue()->and($result['truncated'])->toBeFalse();
    $labels = array_column($result['jobs'], 'label');
    expect($labels)->toContain('Apollo 1.5v', 'Apollo 2.0v', 'Vector 1.5v', 'Vector 2.0v', 'Zeta 1.0v', 'Theta 1.0v', 'Leo', 'Caption', 'OCR Scanner 2.0', 'Harakat 1.0', 'STEM 2', 'STEM 4');
    expect(json_encode($result))->not->toContain('private-provider', 'COMPLETED', 'Owned input', 'runpod', 'omni_v2');
    foreach ($result['jobs'] as $job) {
        expect($job['url'])->toContain('/en/app-v2/', 'queue_job='.$job['id']);
    }
    Http::assertNothingSent();
});

it('keeps old unlocked jobs visible and only reads done as ready after local persistence', function () {
    $ocr = processQueueJob($this->customer, attributes: ['lock_expires_at' => null]);
    $apollo = processQueueJob($this->customer, 'xomni-v2.generate', 'queued');
    $stem = processQueueJob($this->customer, 'stem.sep4', 'saving');
    $reader = app(CustomerProcessQueue::class);
    expect(array_column($reader->read($this->customer)['jobs'], 'status'))->toContain('queued', 'running', 'saving');
    expect(array_filter(array_column($reader->read($this->customer)['jobs'], 'terminal_key')))->toBeEmpty();
    $ocr->update(['status' => 'done', 'finished_at' => now()]);
    $apollo->update(['status' => 'failed']);
    $jobs = collect($reader->read($this->customer)['jobs'])->keyBy('id');
    expect($jobs[$ocr->id]['status_label'])->toBe('Ready')->and($jobs[$ocr->id]['terminal_key'])->toContain(':done:')
        ->and($jobs[$apollo->id]['status_label'])->toBe('Failed')->and($jobs[$stem->id]['status_label'])->toBe('Saving');
    Http::assertNothingSent();
});

it('excludes other customers API jobs legacy jobs and old terminal history', function () {
    $other = Customer::create(['username' => 'other-queue', 'email' => 'other-queue@example.test', 'password' => 'Secret123!', 'status' => 1]);
    processQueueJob($other);
    processQueueJob($this->customer, attributes: ['input' => ['api_job_id' => 'api-private']]);
    processQueueJob($this->customer, attributes: ['input' => ['wallet_type' => 'api']]);
    processQueueJob($this->customer, attributes: ['endpoint_key' => 'kocr_v1', 'input' => []]);
    processQueueJob($this->customer, status: 'done', attributes: ['updated_at' => now()->subDays(2)]);
    $expected = processQueueJob($this->customer, status: 'failed');
    expect(array_column(app(CustomerProcessQueue::class)->read($this->customer)['jobs'], 'id'))->toBe([$expected->id]);
});

it('bounds history and prioritizes active work regardless of the displayed allowance', function () {
    for ($i = 0; $i < 20; $i++) {
        processQueueJob($this->customer, status: 'done');
    }
    $active = processQueueJob($this->customer, attributes: ['updated_at' => now()->subHours(2)]);
    $result = app(CustomerProcessQueue::class)->read($this->customer);
    expect($result['jobs'])->toHaveCount(12)->and($result['jobs'][0]['id'])->toBe($active->id)
        ->and($result['truncated'])->toBeTrue()->and($result['has_active'])->toBeTrue();
});

it('refreshes the shell renderlessly and keeps acknowledgement out of MlJob', function () {
    $job = processQueueJob($this->customer);
    $page = Livewire::test('app::v2.components.shared.process-queue')->assertSee('data-process-queue', false)
        ->assertDontSee('wire:poll', false)->assertSet('allowedSlots', app(PlanConcurrencyService::class)->allowedConcurrentJobsForCustomer($this->customer));
    $job->update(['status' => 'done', 'finished_at' => now()]);
    $before = $job->fresh()->getRawOriginal();
    Storage::shouldReceive('disk')->never();
    $expected = app(CustomerProcessQueue::class)->read($this->customer);
    $page->call('refreshQueue')->assertReturned($expected);
    expect($job->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

it('renders translated queue controls and localized links', function (string $locale) {
    app()->setLocale($locale);
    processQueueJob($this->customer, 'zeta.generate', 'done');
    processQueueJob($this->customer, 'theta.generate', 'failed');
    processQueueJob($this->customer, 'harakat.diacritize', 'queued');
    Livewire::test('app::v2.components.shared.process-queue')->assertSee(__('process_queue.title'))->assertDontSee('process_queue.');
    foreach (app(CustomerProcessQueue::class)->read($this->customer)['jobs'] as $job) {
        expect($job['url'])->toContain('/'.$locale.'/app-v2/');
    }
    $source = require resource_path('lang/en/process_queue.php');
    expect(array_keys(require resource_path('lang/'.$locale.'/process_queue.php')))->toBe(array_keys($source));
})->with(['en', 'ar', 'ku']);

it('opens the requested owned result in each existing workspace', function (string $action, string $component, array $parameters, string $property) {
    $job = processQueueJob($this->customer, $action, 'done', ['created_at' => now()->subHours(3), 'updated_at' => now()->subHours(2)]);
    // Newer history must not steal selection; also force audio pagination beyond page one.
    for ($i = 0; $i < 4; $i++) {
        processQueueJob($this->customer, $action, 'failed');
    }
    if ($job->job_kind === 'stem') {
        CustomerFile::create(['customer_id' => $this->customer->id, 'tool_code' => 'stem', 'purpose' => 'render', 'disk' => 's3', 'path' => 'fixture/'.$job->id, 'size_bytes' => 1, 'mime' => 'audio/wav', 'status' => 'active', 'source_type' => 'ml_job', 'source_id' => $job->id]);
    }
    $page = Livewire::withQueryParams(['queue_job' => $job->id])->test('app::v2.pages.tools.'.$component, $parameters)
        ->assertSet($property, $job->id);
    if (in_array($component, ['app-tool', 'multi-speaker'])) {
        expect(collect($page->get('recentRenders')->items())->pluck('id')->all())->toContain($job->id);
    }
    $url = collect(app(CustomerProcessQueue::class)->read($this->customer)['jobs'])->firstWhere('id', $job->id)['url'];
    $this->get($url)->assertOk()->assertSee('data-process-queue', false);
    Http::assertNothingSent();
})->with([
    ['xomni.generate', 'app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-1'], 'currentJobId'],
    ['xomni-v2.generate', 'app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2'], 'currentJobId'],
    ['clone_xomni.generate', 'app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'], 'currentJobId'],
    ['vector-v2.generate', 'app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-2'], 'currentJobId'],
    ['zeta.generate', 'multi-speaker', ['service' => 'text-to-speech', 'tool' => 'zeta-1'], 'currentJobId'],
    ['theta.generate', 'multi-speaker', ['service' => 'clone-text-to-speech', 'tool' => 'theta-1'], 'currentJobId'],
    ['leo.transcribe', 'app-leo', [], 'currentJobId'],
    ['caption.standard', 'app-caption', [], 'currentJobId'],
    ['ocr.standard', 'app-ocr', [], 'currentJobId'],
    ['harakat.diacritize', 'app-harakat', [], 'currentJobId'],
    ['stem.sep2', 'app-stem', ['mode' => '2'], 'selectedRenderId'],
    ['stem.sep4', 'app-stem', ['mode' => '4'], 'selectedRenderId'],
]);

it('ignores cross-customer cross-service and API queue result links', function (string $case) {
    $job = processQueueJob($this->customer, 'xomni-v2.generate', 'done');
    if ($case === 'owner') {
        $other = Customer::create(['username' => 'other-link', 'email' => 'other-link@example.test', 'password' => 'Secret123!', 'status' => 1]);
        $job->update(['customer_id' => $other->id]);
    } elseif ($case === 'api') {
        $job->update(['input' => ['wallet_type' => 'api']]);
    } else {
        $job->update(['tool_action_id' => ToolAction::where('full_code', 'zeta.generate')->value('id')]);
    }
    Livewire::withQueryParams(['queue_job' => $job->id])->test('app::v2.pages.tools.app-tool', ['service' => 'text-to-speech', 'tool' => 'apollo-2'])->assertSet('currentJobId', null);
    Http::assertNothingSent();
})->with(['owner', 'api', 'service']);
