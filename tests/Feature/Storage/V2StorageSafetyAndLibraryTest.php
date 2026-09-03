<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\Storage\CustomerStorageBulkDownloadService;
use App\Services\Storage\StorageDestructiveOperationBlocked;
use App\Services\Storage\StorageFileDeletionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    $this->seed();
    config()->set('metkurd_v2.enabled', true);
});

function v2StorageCustomer(string $suffix): Customer
{
    return Customer::create([
        'username' => "v2_storage_{$suffix}",
        'email' => "v2-storage-{$suffix}@example.com",
        'password' => 'Secret123!',
        'status' => 1,
        'email_verify' => true,
        'phone_verify' => true,
    ])->fresh();
}

function v2StorageFile(Customer $customer, string $name, int $bytes = 128, array $attributes = []): CustomerFile
{
    $path = "renders/customer-{$customer->id}/xomni/job-{$name}/{$name}.wav";
    Storage::disk('s3')->put($path, str_repeat('a', $bytes));

    return CustomerFile::create(array_merge([
        'customer_id' => $customer->id,
        'purpose' => 'render',
        'tool_code' => 'xomni',
        'disk' => 's3',
        'path' => $path,
        'size_bytes' => $bytes,
        'mime' => 'audio/wav',
        'status' => 'active',
        'counts_toward_quota' => true,
        'meta' => [],
    ], $attributes));
}

it('renders the V2 storage library from owned canonical files and keeps Apollo models distinct', function () {
    $customer = v2StorageCustomer('library');
    $apolloOne = v2StorageFile($customer, 'apollo-one', 128, ['meta' => ['job_id' => 'job-apollo-one']]);
    $apolloTwo = v2StorageFile($customer, 'apollo-two', 256, [
        'tool_code' => 'xomni-v2',
        'path' => "renders/customer-{$customer->id}/xomni-v2/job-apollo-two/apollo-two.wav",
        'meta' => ['job_id' => 'job-apollo-two'],
    ]);
    \App\Models\MlJob::create(['id' => 'job-apollo-one', 'customer_id' => $customer->id, 'model_key' => 'model_1', 'status' => 'done', 'input' => ['text' => 'First Apollo output']]);
    \App\Models\MlJob::create(['id' => 'job-apollo-two', 'customer_id' => $customer->id, 'model_key' => 'model_2', 'status' => 'done', 'input' => ['text' => 'Second Apollo output']]);
    CustomerUsage::query()->updateOrCreate(['customer_id' => $customer->id], ['storage_used_bytes' => 384]);

    Livewire::actingAs($customer, 'app')
        ->test('app::v2.pages.storage.app-storage')
        ->assertSee('My Storage')
        ->assertSee('Storage Status')
        ->assertSee('All Assets')
        ->assertSee('Apollo 1.5v')
        ->assertSee('Apollo 2.0v')
        ->assertSee('job-apollo-one')
        ->assertSee('job-apollo-two')
        ->assertSee('384 B')
        ->set('product', 'apollo-2')
        ->assertSee('Apollo 2.0v')
        ->assertSee('job-apollo-two')
        ->assertDontSee('job-apollo-one')
        ->call('openFolder', 'job:job-apollo-two')
        ->assertSee('Second Apollo output')
        ->assertDontSee('First Apollo output')
        ->call('selectFile', $apolloTwo->id)
        ->assertSee('File Preview')
        ->assertSee('apollo-two.wav')
        ->call('closeFolder')
        ->set('product', 'all')
        ->set('search', 'Second Apollo output')
        ->assertSee('job-apollo-two')
        ->assertDontSee('job-apollo-one');
});

it('plays the saved ASR source audio when a transcription result is selected', function () {
    $customer = v2StorageCustomer('asr-preview');
    $jobId = 'job-asr-preview';
    $sourcePath = "uploads/customer-{$customer->id}/wasr/{$jobId}/source.wav";
    $resultPath = "renders/customer-{$customer->id}/wasr/{$jobId}/transcript.txt";
    Storage::disk('s3')->put($sourcePath, str_repeat('a', 256));
    Storage::disk('s3')->put($resultPath, 'A completed transcription.');

    CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'input_audio', 'tool_code' => 'wasr', 'disk' => 's3',
        'path' => $sourcePath, 'size_bytes' => 256, 'mime' => 'audio/wav', 'status' => 'active',
        'counts_toward_quota' => true, 'meta' => ['job_id' => $jobId],
    ]);
    $transcript = CustomerFile::create([
        'customer_id' => $customer->id, 'purpose' => 'render', 'tool_code' => 'wasr', 'disk' => 's3',
        'path' => $resultPath, 'size_bytes' => 26, 'mime' => 'text/plain', 'status' => 'active',
        'counts_toward_quota' => true, 'meta' => ['job_id' => $jobId],
    ]);

    Livewire::actingAs($customer, 'app')
        ->test('app::v2.pages.storage.app-storage')
        ->call('selectFile', $transcript->id)
        ->assertSet('selectedPreviewKind', 'source')
        ->assertSet('selectedPreviewName', 'source.wav')
        ->assertSee('Source audio')
        ->assertSee('Listen to the audio that was transcribed by this job.');
});

it('keeps every supported service visible when its storage filter changes', function () {
    $customer = v2StorageCustomer('service-filters');
    $fixtures = [
        ['xomni', 'tts-job', 'apollo-1'], ['wasr', 'asr-job', 'wasr'], ['caption', 'caption-job', 'caption'],
        ['ocr', 'ocr-job', 'ocr'], ['stem', 'stem-job', 'stem'], ['tran', 'translation-job', 'translation'],
    ];

    foreach ($fixtures as [$tool, $job, $product]) {
        v2StorageFile($customer, $job, 1024, [
            'tool_code' => $tool,
            'path' => "renders/customer-{$customer->id}/{$tool}/{$job}/result.txt",
            'mime' => 'text/plain',
            'meta' => ['job_id' => $job],
        ]);
    }

    $storage = Livewire::actingAs($customer, 'app')->test('app::v2.pages.storage.app-storage')
        ->assertSee('tts-job')->assertSee('asr-job')->assertSee('caption-job')->assertSee('ocr-job')->assertSee('stem-job')->assertSee('translation-job');

    foreach ([['apollo-1', 'tts-job'], ['wasr', 'asr-job'], ['caption', 'caption-job'], ['ocr', 'ocr-job'], ['stem', 'stem-job'], ['translation', 'translation-job']] as [$product, $job]) {
        $storage->set('product', $product)->assertSee($job);
    }
});

it('enforces the destructive storage guard before any remote or database deletion', function () {
    $customer = v2StorageCustomer('guard');
    $file = v2StorageFile($customer, 'guard', 128);
    CustomerUsage::query()->updateOrCreate(['customer_id' => $customer->id], ['storage_used_bytes' => 128]);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', false);

    expect(fn () => app(StorageFileDeletionService::class)->delete($file))->toThrow(StorageDestructiveOperationBlocked::class)
        ->and(CustomerFile::query()->findOrFail($file->id)->status)->toBe('active')
        ->and(Storage::disk('s3')->exists($file->path))->toBeTrue()
        ->and((int) CustomerUsage::query()->where('customer_id', $customer->id)->value('storage_used_bytes'))->toBe(128);
});

it('cleans an owned missing object exactly once when destructive operations are explicitly enabled', function () {
    $customer = v2StorageCustomer('missing');
    $file = v2StorageFile($customer, 'missing', 128);
    CustomerUsage::query()->updateOrCreate(['customer_id' => $customer->id], ['storage_used_bytes' => 128]);
    Storage::disk('s3')->delete($file->path);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);

    $result = app(StorageFileDeletionService::class)->deleteWithOutcome($file);
    app(StorageFileDeletionService::class)->delete($file->fresh());

    expect($result['remote_missing'])->toBeTrue()
        ->and((string) $file->fresh()->status)->toBe('deleted')
        ->and((int) CustomerUsage::query()->where('customer_id', $customer->id)->value('storage_used_bytes'))->toBe(0);
});

it('keeps the canonical record and quota intact when remote deletion reports failure', function () {
    $customer = v2StorageCustomer('remote-failure');
    $file = v2StorageFile($customer, 'remote-failure', 128);
    CustomerUsage::query()->updateOrCreate(['customer_id' => $customer->id], ['storage_used_bytes' => 128]);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);

    $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('exists')->once()->with($file->path)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($file->path)->andReturnFalse();
    Storage::shouldReceive('disk')->twice()->with('s3')->andReturn($disk);

    expect(fn () => app(StorageFileDeletionService::class)->delete($file))->toThrow(\RuntimeException::class)
        ->and((string) $file->fresh()->status)->toBe('active')
        ->and((int) CustomerUsage::query()->where('customer_id', $customer->id)->value('storage_used_bytes'))->toBe(128);
});

it('does not allow one customer to download another customers V2 storage record', function () {
    $owner = v2StorageCustomer('owner');
    $other = v2StorageCustomer('other');
    $file = v2StorageFile($owner, 'private');

    $this->actingAs($other, 'app')
        ->get(route('app.v2.storage.download', ['locale' => 'en', 'file' => $file->id]))
        ->assertNotFound();
});

it('creates a bounded ZIP from selected owned files without reading them into PHP memory', function () {
    $customer = v2StorageCustomer('zip');
    $first = v2StorageFile($customer, 'first', 128);
    $second = v2StorageFile($customer, 'second', 256);
    config()->set('filesystems.customer_outputs.bulk_download_max_files', 2);
    config()->set('filesystems.customer_outputs.bulk_download_max_bytes', 1024);

    $archive = app(CustomerStorageBulkDownloadService::class)->createArchive($customer, [$first->id, $second->id]);
    $zip = new ZipArchive;
    $zip->open($archive['path']);

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getNameIndex(0))->toContain((string) $first->id)
        ->and($zip->getNameIndex(1))->toContain((string) $second->id);

    $zip->close();
    File::deleteDirectory(dirname($archive['path']));
});

it('reports mixed bulk deletion accurately and keeps failed records for retry', function () {
    $customer = v2StorageCustomer('bulk');
    $present = v2StorageFile($customer, 'present', 128);
    $missing = v2StorageFile($customer, 'missing', 256);
    CustomerUsage::query()->updateOrCreate(['customer_id' => $customer->id], ['storage_used_bytes' => 384]);
    Storage::disk('s3')->delete($missing->path);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);

    $result = app(StorageFileDeletionService::class)->deleteOwned($customer, [$present->id, $missing->id, 999999]);

    expect($result)->toMatchArray(['deleted' => 2, 'missing' => 1, 'failed' => 0, 'skipped' => 1])
        ->and((int) CustomerUsage::query()->where('customer_id', $customer->id)->value('storage_used_bytes'))->toBe(0)
        ->and(CustomerFile::query()->whereIn('id', [$present->id, $missing->id])->where('status', 'deleted')->count())->toBe(2);
});

it('removes a completed STEM render from workspace history when its final stored artifact is deleted', function () {
    $customer = v2StorageCustomer('stem-history');
    $job = MlJob::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'customer_id' => $customer->id,
        'job_kind' => 'stem',
        'status' => 'done',
        'storage_out_bytes' => 256,
        'input' => ['workspace' => 'stem_v2', 'separation_mode' => 4],
        'output' => ['stems' => ['vocals' => ['path' => 'vocals.mp3'], 'drums' => ['path' => 'drums.mp3']]],
    ]);
    $vocals = v2StorageFile($customer, 'stem-vocals', 128, [
        'tool_code' => 'stem', 'source_type' => 'ml_job', 'source_id' => $job->id,
        'meta' => ['job_id' => $job->id],
    ]);
    $drums = v2StorageFile($customer, 'stem-drums', 128, [
        'tool_code' => 'stem', 'source_type' => 'ml_job', 'source_id' => $job->id,
        'meta' => ['job_id' => $job->id],
    ]);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);

    app(StorageFileDeletionService::class)->delete($vocals);
    expect((string) $job->fresh()->status)->toBe('done');

    app(StorageFileDeletionService::class)->delete($drums);
    expect((string) $job->fresh()->status)->toBe('deleted')
        ->and($job->fresh()->output)->toBeNull()
        ->and((int) $job->fresh()->storage_out_bytes)->toBe(0);
});
