<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\MetKurd\Jobs\CloneOmniSubmissionService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Providers\RunPodProvider;
use App\Services\Storage\StorageFileDeletionService;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    Cache::flush();
    $this->seed();
    $this->seed(Database\Seeders\OmniToolSeeder::class);
    config()->set('metkurd_v2.enabled', true);
});

function cloneV2Customer(string $suffix): Customer
{
    $customer = Customer::create([
        'username' => "clone_v2_{$suffix}", 'email' => "clone-v2-{$suffix}@example.com", 'password' => 'Secret123!',
        'status' => 1, 'email_verify' => true, 'phone_verify' => true,
    ]);
    CreditWallet::query()->updateOrCreate(['customer_id' => $customer->id, 'wallet_type' => CreditWallet::TYPE_APP], ['balance_credits' => 1000, 'subscription_balance_credits' => 1000, 'addon_balance_credits' => 0]);

    return $customer->fresh();
}

function cloneV2Reference(Customer $customer, string $name, array $attributes = []): CustomerFile
{
    $path = "renders/customer-{$customer->id}/clone_xomni/reference/{$name}";
    Storage::disk('s3')->put($path, 'reference-audio');

    return CustomerFile::create(array_merge([
        'customer_id' => $customer->id, 'purpose' => 'reference', 'tool_code' => 'clone_xomni', 'disk' => 's3',
        'path' => $path, 'size_bytes' => 15, 'mime' => 'audio/wav', 'status' => 'active', 'counts_toward_quota' => true,
        'meta' => ['role' => 'speaker_reference', 'original_name' => $name],
    ], $attributes));
}

it('shows only reusable owned CTTS references and keeps render history in the CTTS family', function () {
    $customer = cloneV2Customer('history');
    $other = cloneV2Customer('other');
    $reference = cloneV2Reference($customer, 'my-voice.wav');
    cloneV2Reference($other, 'other-voice.wav');
    CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'render', 'tool_code' => 'clone_xomni', 'disk' => 's3',
        'path' => "renders/customer-{$customer->id}/clone_xomni/render/output.wav", 'size_bytes' => 10, 'mime' => 'audio/wav',
        'status' => 'active', 'counts_toward_quota' => true, 'meta' => ['original_name' => 'generated-output.wav'],
    ]);
    $cloneTool = Tool::query()->where('code', 'clone_xomni')->firstOrFail();
    $apolloTool = Tool::query()->where('code', 'xomni')->firstOrFail();
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $cloneTool->id, 'status' => 'failed', 'job_kind' => 'clone_xomni', 'input' => ['text' => 'CTTS-only history', 'reference_audio_name' => 'my-voice.wav']]);
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $apolloTool->id, 'status' => 'failed', 'job_kind' => 'xomni', 'input' => ['text' => 'Apollo must stay separate']]);

    $this->actingAs($customer, 'app');
    $workspace = Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'])
        ->assertSee('Reference voices')
        ->assertSee('my-voice.wav')
        ->assertSee('v2-ctts-preview-btn', false)
        ->assertDontSee('<audio controls', false)
        ->assertSee('wire:target="selectReference"', false)
        ->assertDontSee('other-voice.wav')
        ->assertDontSee('generated-output.wav')
        ->assertSee('CTTS-only history')
        ->assertDontSee('Apollo must stay separate')
        ->call('selectReference', $reference->id)
        ->assertSet('selectedReferenceId', $reference->id)
        ->assertSee('data-metkurd-waveform', false)
        ->assertSee('v2-ctts-selected-waveform-shell', false)
        ->assertSee('ctts-reference-'.$reference->id, false)
        ->assertSee('data-accent="danger"', false)
        ->call('useAnotherReference')
        ->assertSet('selectedReferenceId', null);

    $workspace->call('selectReference', 999999)
        ->assertSet('selectedReferenceId', null)
        ->assertSee('no longer available');
});

it('deduplicates a reused CTTS reference and defers object availability checks until use', function () {
    $customer = cloneV2Customer('dedupe');
    $first = cloneV2Reference($customer, 'same-voice.wav');
    CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'reference', 'tool_code' => 'clone_xomni', 'disk' => 's3', 'path' => $first->path,
        'size_bytes' => 15, 'mime' => 'audio/wav', 'status' => 'active', 'counts_toward_quota' => true,
        'meta' => ['role' => 'speaker_reference', 'original_name' => 'same-voice.wav'],
    ]);
    CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'reference', 'tool_code' => 'clone_xomni', 'disk' => 's3', 'path' => "renders/customer-{$customer->id}/clone_xomni/missing.wav",
        'size_bytes' => 15, 'mime' => 'audio/wav', 'status' => 'active', 'counts_toward_quota' => true,
        'meta' => ['role' => 'speaker_reference', 'original_name' => 'missing.wav'],
    ]);

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'])
        ->assertSee('same-voice.wav')
        ->assertSee('missing.wav');
});

it('streams a saved CTTS reference through an owned same-origin route', function () {
    $customer = cloneV2Customer('reference-stream');
    $other = cloneV2Customer('reference-stream-other');
    $reference = cloneV2Reference($customer, 'stream-me.wav');

    $this->actingAs($customer, 'app');
    $this->get(route('app.ctts-references.stream', ['locale' => 'en', 'file' => $reference->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav');

    $this->actingAs($other, 'app');
    $this->get(route('app.ctts-references.stream', ['locale' => 'en', 'file' => $reference->id]))->assertNotFound();
});

it('paginates the shared CTTS reference library in compact six-item pages', function () {
    $customer = cloneV2Customer('reference-pages');
    foreach (range(1, 7) as $index) {
        cloneV2Reference($customer, "reference-{$index}.wav", ['updated_at' => now()->subMinutes($index)]);
    }

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-2'])
        ->assertSee('1 / 2')
        ->assertSee('Reference voices pagination')
        ->assertSee('reference-1.wav')
        ->assertDontSee('reference-7.wav')
        ->call('nextReferencePage')
        ->assertSee('2 / 2')
        ->assertSee('reference-7.wav');
});

it('submits an owned saved reference without copying it and rejects another customers reference', function () {
    $customer = cloneV2Customer('submit');
    $other = cloneV2Customer('submit-other');
    $reference = cloneV2Reference($customer, 'reusable.wav');
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('omni')->once()->with('clone-text-to-speech', 'vector-1', Mockery::on(function (array $input) use ($reference): bool {
        return (string) data_get($input, 'audio_url') !== '' && (string) data_get($input, 'reference_audio_path') === (string) $reference->path;
    }))->andReturn(['id' => 'runpod-ctts-reuse']);
    app()->instance(RunPodV2Adapter::class, $adapter);

    $job = app(CloneOmniSubmissionService::class)->submit($customer, 'clone-text-to-speech', 'vector-1', 'ctts-reuse-key', [
        'text' => 'Clone this saved voice.', 'language' => 'ckb',
    ], null, $reference->id);

    expect((string) $job->status)->toBe('running')
        ->and((int) data_get($job->input, 'reference_file_id'))->toBe($reference->id)
        ->and((string) data_get($job->input, 'reference_audio_path'))->toBe((string) $reference->path)
        ->and(CustomerFile::query()->where('path', $reference->path)->count())->toBe(1);

    $rejected = app(CloneOmniSubmissionService::class)->submit($other, 'clone-text-to-speech', 'vector-1', 'ctts-foreign-key', [
        'text' => 'This must not use another customer reference.', 'language' => 'ckb',
    ], null, $reference->id);

    expect((string) $rejected->status)->toBe('failed')
        ->and((string) data_get($rejected->error, 'message'))->toContain('no longer available')
        ->and((string) $rejected->provider_job_id)->toBe('');
});

it('materializes a legacy owned reference on private S3 before submitting either Vector version', function () {
    $customer = cloneV2Customer('legacy-reference');
    Storage::fake('local');
    $path = "legacy/customer-{$customer->id}/voice.wav";
    Storage::disk('local')->put($path, 'legacy-reference-audio');
    $reference = CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'reference', 'tool_code' => 'clone_xomni', 'disk' => 'local',
        'path' => $path, 'size_bytes' => 22, 'mime' => 'audio/wav', 'status' => 'active', 'counts_toward_quota' => true,
        'meta' => ['role' => 'speaker_reference', 'original_name' => 'voice.wav'],
    ]);
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('omni')->once()->with('clone-text-to-speech', 'vector-2', Mockery::on(function (array $input): bool {
        return (string) data_get($input, 'reference_audio_disk') === 's3'
            && str_contains((string) data_get($input, 'reference_audio_path'), '/vector-v2/')
            && (string) data_get($input, 'audio_url') !== '';
    }))->andReturn(['id' => 'runpod-legacy-reference']);
    app()->instance(RunPodV2Adapter::class, $adapter);

    $job = app(CloneOmniSubmissionService::class)->submit($customer, 'clone-text-to-speech', 'vector-2', 'legacy-reference-key', [
        'text' => 'Use a secured legacy voice.', 'language' => 'ckb',
    ], null, $reference->id);

    $reference->refresh();
    expect((string) $job->status)->toBe('running')
        ->and((string) $reference->disk)->toBe('s3')
        ->and((string) $reference->path)->toContain('/vector-v2/');
    Storage::disk('s3')->assertExists($reference->path);
});

it('stores a new FilePond/Livewire reference as a reusable CTTS customer file', function () {
    $customer = cloneV2Customer('upload');
    $probe = Mockery::mock(\App\Services\Media\AudioProbeService::class);
    $probe->shouldReceive('probeUploadedFile')->once()->andReturn(['duration_sec' => 10]);
    app()->instance(\App\Services\Media\AudioProbeService::class, $probe);
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('omni')->once()->andReturn(['id' => 'runpod-ctts-upload']);
    app()->instance(RunPodV2Adapter::class, $adapter);
    $this->actingAs($customer, 'app');

    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'])
        ->set('text', 'A cloned speech from a newly uploaded voice.')
        ->set('referenceAudio', UploadedFile::fake()->create('fresh-reference.wav', 64, 'audio/wav'))
        ->call('submitClone')
        ->assertSet('currentJobId', fn (?string $id): bool => $id !== null)
        ->assertSee('Running');

    $file = CustomerFile::query()->where('customer_id', $customer->id)->where('purpose', 'reference')->firstOrFail();
    expect((string) $file->tool_code)->toBe('clone_xomni')
        ->and((string) data_get($file->meta, 'role'))->toBe('speaker_reference')
        ->and((string) data_get($file->meta, 'original_name'))->toBe('fresh-reference.wav');
    Storage::disk('s3')->assertExists($file->path);
});

it('keeps Vector 2.0 submission, billing, and reference storage separate from Vector 1.5', function () {
    $customer = cloneV2Customer('vector-two');
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('omni')->once()->with('clone-text-to-speech', 'vector-2', Mockery::on(fn (array $input): bool => (string) data_get($input, 'audio_url') !== ''))
        ->andReturn(['id' => 'runpod-vector-two']);
    app()->instance(RunPodV2Adapter::class, $adapter);

    $job = app(CloneOmniSubmissionService::class)->submit($customer, 'clone-text-to-speech', 'vector-2', 'vector-two-key', [
        'text' => 'Vector two keeps its own identity.', 'language' => 'ckb',
    ], UploadedFile::fake()->create('vector-two.wav', 64, 'audio/wav'));

    $action = $job->toolAction()->firstOrFail();
    $reference = CustomerFile::query()->where('customer_id', $customer->id)->where('purpose', 'reference')->firstOrFail();
    expect((string) $job->job_kind)->toBe('vector-v2')
        ->and((string) $job->tool->code)->toBe('vector-v2')
        ->and((string) $action->full_code)->toBe('vector-v2.generate')
        ->and((string) $reference->tool_code)->toBe('vector-v2')
        ->and((string) $reference->path)->toContain('/vector-v2/')
        ->and(data_get(CreditLedger::query()->where('reference_code', "ml-job:{$job->id}:charge")->firstOrFail()->meta, 'tool_action'))->toBe('vector-v2.generate');
});

it('writes and protects Vector 2.0 output through its own route and stored key', function () {
    $customer = cloneV2Customer('vector-two-output');
    config()->set('runpod.endpoints.omni_v2', 'vector-two-endpoint');
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $adapter->shouldReceive('omni')->once()->andReturn(['id' => 'runpod-vector-two-output']);
    app()->instance(RunPodV2Adapter::class, $adapter);
    $job = app(CloneOmniSubmissionService::class)->submit($customer, 'clone-text-to-speech', 'vector-2', 'vector-two-output-key', [
        'text' => 'A separately stored Vector two output.', 'language' => 'ckb',
    ], UploadedFile::fake()->create('vector-two-output.wav', 64, 'audio/wav'));
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->with('vector-two-endpoint', 'runpod-vector-two-output')->andReturn(['status' => 'COMPLETED', 'output' => [
        'audio_base64' => base64_encode('vector two output'), 'output_filename' => 'omnivoice_20260815_120001_2.wav', 'mime_type' => 'audio/wav',
    ]]);
    app()->instance(RunPodProvider::class, $provider);

    $tool = Tool::query()->where('code', 'vector-v2')->firstOrFail();
    app(XttsJobSyncService::class)->sync($job->fresh(), $tool);
    $job = $job->fresh();
    $path = (string) data_get($job->output, 'path');
    $output = CustomerFile::query()->where('customer_id', $customer->id)->where('purpose', 'render')->firstOrFail();
    expect($path)->toContain("/vector-v2/{$job->id}/")
        ->and($path)->not->toContain("/clone_xomni/{$job->id}/")
        ->and((string) $output->tool_code)->toBe('vector-v2');
    Storage::disk('s3')->assertExists($path);

    $this->actingAs($customer, 'app');
    $this->get(route('app.renders.vector-v2.download', ['locale' => 'en', 'jobId' => $job->id]))->assertOk()->assertDownload(basename($path));
    $this->get(route('app.renders.vector-v2.download', ['locale' => 'en', 'jobId' => (string) Str::uuid()]))->assertNotFound();

    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    app(StorageFileDeletionService::class)->delete($output);
    Storage::disk('s3')->assertMissing($path);
});

it('isolates versioned CTTS renders while intentionally sharing one customer reference cache', function () {
    $customer = cloneV2Customer('versioned-history');
    $vectorOne = Tool::query()->where('code', 'clone_xomni')->firstOrFail();
    $vectorTwo = Tool::query()->where('code', 'vector-v2')->firstOrFail();
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $vectorOne->id, 'status' => 'failed', 'job_kind' => 'clone_xomni', 'input' => ['text' => 'Vector one only']]);
    MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'tool_id' => $vectorTwo->id, 'status' => 'failed', 'job_kind' => 'vector-v2', 'input' => ['text' => 'Vector two only']]);
    cloneV2Reference($customer, 'vector-one-reference.wav');
    $v2ReferencePath = "renders/customer-{$customer->id}/vector-v2/reference/vector-two-reference.wav";
    Storage::disk('s3')->put($v2ReferencePath, 'reference-audio');
    CustomerFile::create(['customer_id' => $customer->id, 'purpose' => 'reference', 'tool_code' => 'vector-v2', 'disk' => 's3', 'path' => $v2ReferencePath, 'size_bytes' => 15, 'mime' => 'audio/wav', 'status' => 'active', 'counts_toward_quota' => true, 'meta' => ['role' => 'speaker_reference', 'original_name' => 'vector-two-reference.wav']]);

    $this->actingAs($customer, 'app');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-1'])
        ->assertSee('Vector one only')
        ->assertDontSee('Vector two only')
        ->assertSee('vector-one-reference.wav')
        ->assertSee('vector-two-reference.wav');
    Livewire::test('app::v2.pages.tools.app-tool', ['service' => 'clone-text-to-speech', 'tool' => 'vector-2'])
        ->assertSee('Vector two only')
        ->assertDontSee('Vector one only')
        ->assertSee('vector-one-reference.wav')
        ->assertSee('vector-two-reference.wav');

    expect(Cache::has("metkurd:v2:ctts:renders:customer:{$customer->id}:tool:clone_xomni:page:1:v1"))->toBeTrue()
        ->and(Cache::has("metkurd:v2:ctts:renders:customer:{$customer->id}:tool:vector-v2:page:1:v1"))->toBeTrue()
        ->and(Cache::has("metkurd:v2:ctts:references:customer:{$customer->id}"))->toBeTrue();
});

it('resolves V2 service themes from configured service color on service and tool routes', function () {
    $customer = cloneV2Customer('themes');
    $this->actingAs($customer, 'app');
    $this->get(route('app.v2.service', ['locale' => 'en', 'service' => 'text-to-speech']))->assertOk()->assertSee('metkurd-v2--primary');
    $this->get(route('app.v2.tool', ['locale' => 'en', 'service' => 'clone-text-to-speech', 'tool' => 'vector-2']))->assertOk()->assertSee('metkurd-v2--danger')->assertSee('v2-ctts-reference-pond');
    $this->get(route('app.v2.service', ['locale' => 'en', 'service' => 'speech-to-text']))->assertOk()->assertSee('metkurd-v2--success');
});
