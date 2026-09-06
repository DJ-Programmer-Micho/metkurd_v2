<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Providers\RunPodProvider;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    Storage::fake('s3');
    config()->set('runpod.endpoints.kocr_v2', 'ocr-test');
    $owner = Customer::create(['username' => 'ocr-security', 'email' => 'ocr-security@example.com', 'password' => 'Secret123!', 'status' => 1]);
    $this->job = MlJob::create(['id' => 'ocr-owned', 'customer_id' => $owner->id, 'job_kind' => 'ocr', 'status' => 'running', 'provider_job_id' => 'remote-ocr', 'input' => ['v2' => true, 'file_path' => 'renders/owner/ocr/ocr-owned/input.pdf', 'file_name' => 'source.pdf']]);
});

it('rejects worker paths outside the owned OCR namespace including traversal', function ($path) {
    Storage::disk('s3')->put($path, 'private text');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['uploaded_keys' => ['text' => $path], 'text' => 'inline text']]);
    app()->instance(RunPodProvider::class, $provider);
    app(OcrJobSyncService::class)->sync($this->job);
    expect($this->job->fresh()->status)->toBe('failed')->and(CustomerFile::count())->toBe(0);
})->with(['renders/another/ocr/text.txt', 'renders/owner/ocr/ocr-owned/../other.txt', '/renders/owner/ocr/ocr-owned/text.txt', 'renders/owner/ocr/ocr-owned/%2e%2e/other.txt']);

it('does not complete OCR when its inline result write fails', function ($throws) {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['text' => 'result text']]);
    app()->instance(RunPodProvider::class, $provider);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->andReturn(false);
    $write = $disk->shouldReceive('put')->once();
    $throws ? $write->andThrow(new RuntimeException('Storage unavailable')) : $write->andReturn(false);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    app(OcrJobSyncService::class)->sync($this->job);
    expect($this->job->fresh()->status)->toBe('running')->and(CustomerFile::count())->toBe(0);
})->with([true, false]);

it('keeps monitoring OCR when cancellation cannot be confirmed and retains its input', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('cancel')->once()->andThrow(new RuntimeException('Temporary connection failure'));
    app()->instance(RunPodProvider::class, $provider);
    expect(fn () => app(OcrJobSyncService::class)->cancel($this->job))->toThrow(RuntimeException::class);
    expect($this->job->fresh()->status)->toBe('running')->and(data_get($this->job->fresh()->input, 'file_path'))->not->toBeEmpty();
});

it('marks OCR cancelled only when the remote cancellation is acknowledged', function () {
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    $storage = app(App\Services\Storage\CustomerOutputStorage::class);
    $inputPath = data_get($this->job->input, 'file_path');
    $storage->saveTextToS3($this->job->customer_id, $inputPath, 'source', ['purpose' => 'input_document']);
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('cancel')->once()->with('ocr-test', 'remote-ocr')->andReturn(['status' => 'CANCELLED']);
    $provider->shouldNotReceive('status');
    app()->instance(RunPodProvider::class, $provider);
    $sync = app(OcrJobSyncService::class);
    expect($sync->cancel($this->job))->toBeTrue();
    $sync->sync($this->job->fresh());
    expect($this->job->fresh()->status)->toBe('cancelled');
    $storage->deleteOcrOutputs($this->job->fresh());
    $storage->deleteOcrOutputs($this->job->fresh());
    expect($this->job->fresh()->status)->toBe('deleted');
    Storage::disk('s3')->assertMissing($inputPath);
});
