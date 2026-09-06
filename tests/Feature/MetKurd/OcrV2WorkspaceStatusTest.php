<?php

use App\Models\Customer;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OcrV2SubmissionService;
use App\Services\OCR\OcrJobSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
    Storage::fake('s3');
    $this->customer = Customer::create(['username' => 'ocr-status', 'email' => 'ocr-status@example.test', 'password' => 'Secret123!', 'status' => 1]);
    $this->old = MlJob::create(['id' => 'previous-ocr', 'customer_id' => $this->customer->id, 'job_kind' => 'ocr', 'status' => 'done',
        'input' => ['v2' => true, 'file_name' => 'previous.pdf'], 'output' => ['text' => ['inline' => 'Previous extracted text']], 'finished_at' => now()->subMinute()]);
});

it('labels a previous result and clears its status when a new document is uploaded', function (string $locale) {
    app()->setLocale($locale);
    Illuminate\Support\Facades\Lang::addJsonPath(resource_path('lang/app'));
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->assertSee(__('Previous result'))->assertSee('Previous extracted text')
        ->set('documentFile', UploadedFile::fake()->create('new.png', 1, 'image/png'))
        ->assertHasNoErrors()->assertSet('currentJobId', null)
        ->assertDontSee('Previous extracted text')->assertDontSee(__('OCR scan completed.'));
})->with(['en', 'ar', 'ku']);

it('prioritizes an active scan over a more recently updated old result on page load', function () {
    $active = MlJob::create(['id' => 'active-ocr', 'customer_id' => $this->customer->id, 'job_kind' => 'ocr', 'status' => 'running',
        'input' => ['v2' => true, 'file_name' => 'active.pdf'], 'updated_at' => now()->subHour()]);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->assertSet('currentJobId', $active->id)->assertSee('Scanning pages and extracting text.')
        ->assertDontSee('Previous extracted text');
});

it('renders the new job immediately after submission even when the old result was cached', function () {
    $submission = Mockery::mock(OcrV2SubmissionService::class);
    $submission->shouldReceive('submit')->once()->andReturnUsing(fn () => MlJob::create([
        'id' => 'new-ocr', 'customer_id' => $this->customer->id, 'job_kind' => 'ocr', 'status' => 'running',
        'input' => ['v2' => true, 'file_name' => 'new.png'],
    ]));
    app()->instance(OcrV2SubmissionService::class, $submission);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->set('documentFile', UploadedFile::fake()->create('new.png', 1, 'image/png'))
        // Reusing an upload after completion leaves a terminal job selected.
        ->set('currentJobId', $this->old->id)
        ->call('submitOcr')->assertHasNoErrors()->assertSet('currentJobId', 'new-ocr')
        ->assertSee('Current job')->assertSee('Scanning pages and extracting text.')
        ->assertSee('wire:poll.5s="pollOcr"', false)
        ->assertDontSee('Previous extracted text')->assertDontSee('OCR scan completed.');
});

it('keeps an active job in focus while preparing another file and blocks a second submission', function () {
    $this->old->update(['status' => 'running', 'output' => null]);
    $submission = Mockery::mock(OcrV2SubmissionService::class);
    $submission->shouldNotReceive('submit');
    app()->instance(OcrV2SubmissionService::class, $submission);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->set('documentFile', UploadedFile::fake()->create('new.png', 1, 'image/png'))
        ->assertSet('currentJobId', $this->old->id)
        ->call('submitOcr')->assertSee('An OCR job is already in progress.')
        ->assertSee('wire:poll.5s="pollOcr"', false);
});

it('shows completed text immediately and stops polling the completed job', function () {
    $this->old->update(['status' => 'running', 'output' => null]);
    $sync = Mockery::mock(OcrJobSyncService::class);
    $sync->shouldReceive('sync')->once()->andReturnUsing(function (MlJob $job) {
        $job->update(['status' => 'done', 'output' => ['text' => ['inline' => 'Newly completed text']]]);

        return ['status' => 'done'];
    });
    app()->instance(OcrJobSyncService::class, $sync);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->call('pollOcr')->assertSee('Newly completed text')->assertSee('OCR scan completed.')
        ->assertDontSee('wire:poll.5s="pollOcr"', false)
        ->call('pollOcr');
});

it('does not display another customers result or another service through a changed job ID', function () {
    $other = Customer::create(['username' => 'other-ocr', 'email' => 'other-ocr@example.test', 'password' => 'Secret123!', 'status' => 1]);
    $this->old->update(['customer_id' => $other->id]);
    $unrelated = MlJob::create(['id' => 'not-ocr', 'customer_id' => $this->customer->id, 'job_kind' => 'stem', 'status' => 'done', 'output' => ['text' => ['inline' => 'Other service result']]]);
    Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->set('currentJobId', $this->old->id)->assertDontSee('Previous extracted text')
        ->set('currentJobId', $unrelated->id)->assertDontSee('Other service result');
});
