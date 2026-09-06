<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\ASR\QasrJobSyncService;
use App\Services\Providers\RunPodProvider;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    Storage::fake('s3');
    $this->owner = Customer::create(['username' => 'storage-hardening', 'email' => 'storage-hardening@example.com', 'password' => 'Secret123!', 'status' => 1]);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
});

it('does not register or count failed writes', function (string $kind, bool $throws) {
    $disk = Mockery::mock();
    $expectation = $disk->shouldReceive('put')->once();
    $throws ? $expectation->andThrow(new RuntimeException('Storage unreachable')) : $expectation->andReturn(false);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    $storage = app(CustomerOutputStorage::class);
    $write = fn () => match ($kind) {
        'wav' => $storage->saveWavB64ToS3($this->owner->id, 'result.wav', base64_encode('audio')),
        'text' => $storage->saveTextToS3($this->owner->id, 'result.txt', 'result'),
        'upload' => $storage->saveUploadedFileToS3($this->owner->id, UploadedFile::fake()->create('source.wav', 1, 'audio/wav'), 'source.wav'),
    };
    expect($write)->toThrow(RuntimeException::class);
    expect(CustomerFile::where('customer_id', $this->owner->id)->count())->toBe(0);
    expect((int) CustomerUsage::where('customer_id', $this->owner->id)->value('storage_used_bytes'))->toBe(0);
})->with(['wav', 'text', 'upload'])->with([false, true]);

it('accounts one object once across persistence retries and size changes', function () {
    $storage = app(CustomerOutputStorage::class);
    $storage->saveTextToS3($this->owner->id, 'result.txt', 'hello');
    $storage->saveTextToS3($this->owner->id, 'result.txt', 'hello');
    $storage->saveTextToS3($this->owner->id, 'result.txt', 'hello world');
    expect(CustomerFile::where('customer_id', $this->owner->id)->count())->toBe(1);
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(11);
});

it('registers worker objects idempotently and never revives a deleted artifact', function () {
    $storage = app(CustomerOutputStorage::class);
    Storage::disk('s3')->put('worker.txt', 'hello');
    $storage->registerExistingObject($this->owner->id, 's3', 'worker.txt');
    $storage->registerExistingObject($this->owner->id, 's3', 'worker.txt');
    Storage::disk('s3')->put('worker.txt', 'hello world');
    $storage->registerExistingObject($this->owner->id, 's3', 'worker.txt');
    expect(CustomerFile::count())->toBe(1)->and((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(11);
    $storage->deleteFromS3AndUncount($this->owner->id, 'worker.txt');
    Storage::disk('s3')->put('worker.txt', 'late write');
    expect(fn () => $storage->registerExistingObject($this->owner->id, 's3', 'worker.txt'))->toThrow(RuntimeException::class);
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(0);
});

it('does not subtract the first artifact again after partial job deletion fails', function () {
    $storage = app(CustomerOutputStorage::class);
    foreach (['a.txt' => 10, 'b.srt' => 20, 'unrelated.txt' => 100] as $path => $bytes) {
        $storage->saveTextToS3($this->owner->id, $path, str_repeat('x', $bytes));
    }
    $job = MlJob::create(['id' => 'partial-delete', 'customer_id' => $this->owner->id, 'status' => 'done', 'output' => ['path' => 'a.txt', 'bytes' => 10, 'srt_path' => 'b.srt', 'srt_bytes' => 20]]);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->with('a.txt')->once()->andReturn(true);
    $disk->shouldReceive('exists')->with('b.srt')->twice()->andReturn(true);
    $disk->shouldReceive('delete')->with('a.txt')->once()->andReturn(true);
    $disk->shouldReceive('delete')->with('b.srt')->once()->andThrow(new RuntimeException('Transient deletion failure'));
    $disk->shouldReceive('delete')->with('b.srt')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    $sync = app(QasrJobSyncService::class);
    expect(fn () => $sync->deleteFinishedTranscription($job))->toThrow(RuntimeException::class);
    expect($job->fresh()->status)->toBe('delete_failed');
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(120);
    $sync->deleteFinishedTranscription($job->fresh());
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(100);
    $storage->deleteFromS3AndUncount($this->owner->id, 'a.txt', 10);
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(100);
});

it('keeps a job incomplete when final result persistence fails and finalizes safely on retry', function () {
    $tool = Tool::firstOrCreate(['code' => 'xomni'], ['name' => 'Apollo']);
    $job = MlJob::create(['id' => 'write-failed-job', 'customer_id' => $this->owner->id, 'tool_id' => $tool->id, 'status' => 'running', 'provider_job_id' => 'provider-test', 'endpoint_key' => 'omni_v2']);
    config()->set('runpod.endpoints.omni_v2', 'test-endpoint');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->twice()->andReturn(['status' => 'COMPLETED', 'output' => ['audio_base64' => base64_encode('audio')]]);
    app()->instance(RunPodProvider::class, $provider);
    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('put')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    $sync = app(XttsJobSyncService::class);
    $sync->sync($job, $tool);
    expect($job->fresh()->status)->toBe('running');
    expect(CustomerFile::count())->toBe(0);
    $this->travel(11)->seconds();
    $sync->sync($job->fresh(), $tool);
    $sync->sync($job->fresh(), $tool);
    expect($job->fresh()->status)->toBe('done');
    expect(CustomerFile::count())->toBe(1);
    expect((int) $this->owner->usage()->value('storage_used_bytes'))->toBe(5);
});
