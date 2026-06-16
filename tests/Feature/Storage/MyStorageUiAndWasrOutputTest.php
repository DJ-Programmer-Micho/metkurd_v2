<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\AsrJobSyncService;
use App\Services\Mobile\MobileJobOutputReferenceService;
use App\Services\Providers\RunPodProvider;
use App\Support\CustomerFolder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Storage::fake('s3');
    app()->setLocale('en');
    $this->seed();
});

function storageUiCustomer(?string $email = null): Customer
{
    $suffix = Str::lower(Str::random(10));

    return Customer::create([
        'username' => 'storage_ui_'.$suffix,
        'email' => $email ?? 'storage-ui-'.$suffix.'@example.com',
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh(['profile', 'usage']);
}

it('chunks my-storage folder list in batches of twenty and loads more on demand', function () {
    $customer = storageUiCustomer();
    $folder = CustomerFolder::make(
        (int) $customer->id,
        $customer->profile?->first_name,
        $customer->profile?->last_name,
        $customer->username
    );

    foreach (range(1, 45) as $index) {
        CustomerFile::create([
            'customer_id' => (int) $customer->id,
            'purpose' => 'transcription',
            'tool_code' => 'wasr',
            'disk' => 's3',
            'path' => "renders/{$folder}/wasr/job-".str_pad((string) $index, 3, '0', STR_PAD_LEFT).'/transcription.txt',
            'size_bytes' => 128 + $index,
            'mime' => 'text/plain; charset=UTF-8',
            'status' => 'active',
            'meta' => [
                'job_id' => 'job-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'tool' => 'wasr',
            ],
        ]);
    }

    $component = Livewire::actingAs($customer, 'app')
        ->test('app::pages.my-storage.app-storage')
        ->call('navigateTo', 'wasr');

    expect($component->instance()->visibleFolderCards()->count())->toBe(20)
        ->and($component->instance()->hasMoreFolderCards())->toBeTrue();

    $component->call('loadMoreFolders');

    expect($component->instance()->visibleFolderCards()->count())->toBe(40)
        ->and($component->instance()->hasMoreFolderCards())->toBeTrue();

    $component->call('loadMoreFolders');

    expect($component->instance()->visibleFolderCards()->count())->toBe(45)
        ->and($component->instance()->hasMoreFolderCards())->toBeFalse();
});

it('does not create customer-visible wasr json artifacts when finalizing a successful transcription', function () {
    config()->set('runpod.endpoints.wasr', 'endpoint-wasr-test');

    $customer = storageUiCustomer();
    $tool = Tool::query()->where('code', 'wasr')->first()
        ?: Tool::query()->where('code', 'asr')->firstOrFail();
    $jobId = (string) Str::uuid();

    $job = MlJob::create([
        'id' => $jobId,
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) $tool->id,
        'status' => 'running',
        'provider' => 'runpod',
        'provider_job_id' => 'rp-wasr-job-1',
        'job_kind' => 'wasr',
        'input' => [
            'audio_name' => 'sample.wav',
            'audio_path' => "renders/customer/wasr/{$jobId}/input.wav",
        ],
        'lock_expires_at' => now()->addMinute(),
    ]);

    $runpod = \Mockery::mock(RunPodProvider::class);
    $runpod->shouldReceive('status')
        ->once()
        ->with('endpoint-wasr-test', 'rp-wasr-job-1')
        ->andReturn([
            'status' => 'COMPLETED',
            'output' => [
                'text' => 'Hello from WASR transcription.',
                'chunks' => [
                    ['text' => 'Hello', 'start' => 0, 'end' => 1],
                ],
                'meta' => ['model' => 'asr-v1'],
            ],
        ]);
    app()->instance(RunPodProvider::class, $runpod);

    app(AsrJobSyncService::class)->sync($job->fresh(), $tool);

    $fresh = $job->fresh();

    expect((string) $fresh->status)->toBe('done')
        ->and((string) data_get($fresh->output, 'text'))->toBe('Hello from WASR transcription.')
        ->and((string) data_get($fresh->output, 'path'))->toContain('/transcription.txt')
        ->and(data_get($fresh->output, 'json_path'))->toBeNull();

    expect(Storage::disk('s3')->exists((string) data_get($fresh->output, 'path')))->toBeTrue();

    expect(CustomerFile::query()
        ->where('customer_id', (int) $customer->id)
        ->where('status', 'active')
        ->where('path', 'like', '%.json')
        ->exists())->toBeFalse();
});

it('keeps wasr results readable and exposes transcript output references after removing json artifacts', function () {
    config()->set('runpod.endpoints.wasr', 'endpoint-wasr-test');

    $customer = storageUiCustomer();
    $tool = Tool::query()->where('code', 'wasr')->first()
        ?: Tool::query()->where('code', 'asr')->firstOrFail();
    $jobId = (string) Str::uuid();

    $job = MlJob::create([
        'id' => $jobId,
        'customer_id' => (int) $customer->id,
        'tool_id' => (int) $tool->id,
        'status' => 'running',
        'provider' => 'runpod',
        'provider_job_id' => 'rp-wasr-job-2',
        'job_kind' => 'wasr',
        'input' => [
            'audio_name' => 'sample.wav',
            'audio_path' => "renders/customer/wasr/{$jobId}/input.wav",
        ],
        'lock_expires_at' => now()->addMinute(),
    ]);

    $runpod = \Mockery::mock(RunPodProvider::class);
    $runpod->shouldReceive('status')
        ->once()
        ->andReturn([
            'status' => 'COMPLETED',
            'output' => [
                'text' => 'Readable transcription body.',
                'segments' => [
                    ['text' => 'Readable', 'start' => 0, 'end' => 1.2],
                ],
            ],
        ]);
    app()->instance(RunPodProvider::class, $runpod);

    app(AsrJobSyncService::class)->sync($job->fresh(), $tool);

    $fresh = $job->fresh(['tool']);
    $references = app(MobileJobOutputReferenceService::class)->referencesForJob($fresh, 'asr');
    $roles = collect($references['outputs'])->pluck('role')->all();

    expect((string) data_get($fresh->output, 'text'))->toBe('Readable transcription body.')
        ->and((string) data_get($references, 'primary_output.role'))->toBe('transcript')
        ->and($roles)->not->toContain('transcript_json')
        ->and((int) count($references['outputs']))->toBeGreaterThan(0);
});
