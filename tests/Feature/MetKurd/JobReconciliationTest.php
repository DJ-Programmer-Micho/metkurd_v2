<?php

use App\Jobs\ReconcileMlJob;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Models\Tool;
use App\Services\Providers\RunPodProvider;
use App\Services\XTTS\XttsJobSyncService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();
    Storage::fake('s3');
    config()->set('runpod.endpoints.omni_v2', 'test-endpoint');
    $owner = Customer::create(['username' => 'reconcile', 'email' => 'reconcile@example.com', 'password' => 'Secret123!', 'status' => 1]);
    $this->tool = Tool::firstOrCreate(['code' => 'xomni'], ['name' => 'Apollo']);
    $this->job = MlJob::create(['id' => 'reconciliation-job', 'customer_id' => $owner->id, 'tool_id' => $this->tool->id, 'provider' => 'runpod', 'provider_job_id' => 'remote', 'endpoint_key' => 'omni_v2', 'status' => 'running']);
});

it('keeps accepted V2 jobs retryable when their endpoint is unconfigured and never falls back to another worker', function (string $code, string $endpoint, array $input) {
    $tool = Tool::firstOrCreate(['code' => $code], ['name' => $code]);
    $tool->update(['meta' => ['runpod_endpoint_id' => 'obsolete-worker']]);
    $this->job->update(['tool_id' => $tool->id, 'job_kind' => $code, 'endpoint_key' => $endpoint, 'input' => $input]);
    config()->set("runpod.endpoints.{$endpoint}", '');
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldNotReceive('run');
    $provider->shouldReceive('status')->once()->with('restored-worker', 'remote')->andReturn(['status' => 'IN_PROGRESS']);
    app()->instance(RunPodProvider::class, $provider);

    (new ReconcileMlJob($this->job->id))->handle();
    expect($this->job->fresh()->status)->toBe('running')
        ->and($this->job->fresh()->refunded_at)->toBeNull()
        ->and($this->job->fresh()->provider_job_id)->toBe('remote');
    config()->set("runpod.endpoints.{$endpoint}", 'restored-worker');
    $this->travel(11)->seconds();
    (new ReconcileMlJob($this->job->id))->handle();
    expect($this->job->fresh()->status)->toBe('running');
})->with([
    'Apollo 1.5' => ['xomni', 'omni_v2', []],
    'Apollo 2' => ['xomni-v2', 'omni_v2', []],
    'Vector 1.5' => ['clone_xomni', 'omni_v2', []],
    'Vector 2' => ['vector-v2', 'omni_v2', []],
    'Leo' => ['leo', 'qasr_v2', []],
    'Caption' => ['caption', 'qasr_v2', []],
    'OCR' => ['ocr', 'kocr_v2', ['v2' => true]],
    'STEM 2' => ['stem', 'stem', ['workspace' => 'stem_v2', 'separation_mode' => 2]],
    'STEM 4' => ['stem', 'stem', ['workspace' => 'stem_v2', 'separation_mode' => 4]],
]);

it('finishes without a browser and never queries a completed job even with stale browser state', function () {
    $this->job->update(['started_at' => now()->subMinutes(10)]);
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('No stale ML jobs matched the current filters.')->assertSuccessful();
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['audio_base64' => base64_encode('result')]]);
    app()->instance(RunPodProvider::class, $provider);
    $stale = $this->job->fresh();
    $this->artisan('ml-jobs:reconcile')->assertSuccessful();
    expect($this->job->fresh()->status)->toBe('done')->and(CustomerFile::count())->toBe(1);
    app(XttsJobSyncService::class)->sync($stale, $this->tool);
    (new ReconcileMlJob($this->job->id))->handle();
    $this->travel(2)->hours();
    $this->artisan('ml-jobs:mark-stale-failed')->expectsOutput('No stale ML jobs matched the current filters.')->assertSuccessful();
    expect($this->job->fresh()->status)->toBe('done')->and(CustomerFile::count())->toBe(1);
});

it('shares a poll interval across browser sessions and server checks', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->twice()->andReturn(['status' => 'IN_PROGRESS', 'output' => ['audio_base64' => base64_encode('partial')]]);
    app()->instance(RunPodProvider::class, $provider);
    $sync = app(XttsJobSyncService::class);
    $sync->sync($this->job, $this->tool);
    $sync->sync($this->job->fresh(), $this->tool);
    (new ReconcileMlJob($this->job->id))->handle();
    expect($this->job->fresh()->status)->toBe('running')->and(CustomerFile::count())->toBe(0);
    $this->travel(11)->seconds();
    $sync->sync($this->job->fresh(), $this->tool);
});

it('survives temporary network failure and retries after the interval', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('status')->once()->andThrow(new RuntimeException('Network unavailable'));
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['audio_base64' => base64_encode('result')]]);
    app()->instance(RunPodProvider::class, $provider);
    (new ReconcileMlJob($this->job->id))->handle();
    expect($this->job->fresh()->status)->toBe('running')->and($this->job->fresh()->poll_token)->toBeNull();
    $this->travel(11)->seconds();
    (new ReconcileMlJob($this->job->id))->handle();
    expect($this->job->fresh()->status)->toBe('done');
});

it('does not poll terminal or leased jobs', function ($status) {
    $this->job->forceFill(['status' => $status, 'poll_locked_until' => $status === 'running' ? now()->addMinute() : null])->save();
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldNotReceive('status');
    app()->instance(RunPodProvider::class, $provider);
    app(XttsJobSyncService::class)->sync($this->job, $this->tool);
    expect($this->job->fresh()->status)->toBe($status);
})->with(['done', 'failed', 'deleted', 'deleting', 'delete_failed', 'cancelled', 'running']);

it('times out acknowledged jobs but preserves ambiguous remote submissions', function () {
    $this->job->update(['started_at' => now()->subHours(2)]);
    $this->artisan('ml-jobs:mark-stale-failed')->assertSuccessful();
    expect($this->job->fresh()->status)->toBe('failed');
    $this->job->refresh()->update(['status' => 'running', 'provider_job_id' => null, 'submission_attempted_at' => now()->subHours(2), 'failure_stage' => 'provider_submission_unknown']);
    $this->artisan('ml-jobs:mark-stale-failed')->assertSuccessful();
    expect($this->job->fresh()->status)->toBe('running');
});

it('rechecks dispatch state after stale candidate selection', function () {
    $this->job->update(['provider_job_id' => null, 'started_at' => now()->subHours(2)]);
    $candidate = $this->job->fresh();
    $this->job->update(['submission_attempted_at' => now()]);
    $command = new class extends App\Console\Commands\MarkStaleMlJobsFailed
    {
        public function attempt(MlJob $candidate): bool
        {
            return $this->markFailed($candidate, ['running' => 1]);
        }
    };
    expect($command->attempt($candidate))->toBeFalse()->and($this->job->fresh()->status)->toBe('running');
});
