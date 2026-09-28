<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OcrV2SubmissionService;
use App\Services\MetKurd\V2\InputBoundary;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Providers\RunPodProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    $this->seed();
    Storage::fake('local');
    Storage::fake('s3')->buildTemporaryUrlsUsing(fn () => 'https://storage.example.test/input.pdf');
    config(['livewire.temporary_file_upload.disk' => 'local', 'runpod.endpoints.kocr_v2' => 'test-ocr',
        'runpod.v2_input_hosts' => ['storage.example.test'], 'metkurd_v2.enabled' => true]);
    Process::fake(['*' => Process::result(output: "Pages: 174\n", exitCode: 0)]);
    $this->customer = Customer::create(['username' => 'ocr-submit', 'email' => 'ocr-submit@example.test', 'password' => 'Secret123!',
        'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    CreditWallet::updateOrCreate(['customer_id' => $this->customer->id, 'wallet_type' => 'app'],
        ['balance_credits' => 1000000, 'subscription_balance_credits' => 1000000, 'addon_balance_credits' => 0]);
    $this->pdf = fn () => UploadedFile::fake()->create('174-pages.pdf', 1, 'application/pdf');
    $this->workspace = fn () => Livewire::actingAs($this->customer, 'app')->test('app::v2.pages.tools.app-ocr');
});

it('preserves all and compact custom expressions across the independently verified input boundary', function (string $range) {
    $options = app(InputBoundary::class)->document(($this->pdf)(), ['pages' => $range]);
    expect($options['pages'])->toBe($range)->and($options['estimated_pages'])->toBe(174);
})->with(['all', '1-174']);

it('submits all 174 pages once with visible processing exact billing and the normal queue event', function (string $locale) {
    app()->setLocale($locale);
    Lang::addJsonPath(resource_path('lang/app'));
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->with('test-ocr', Mockery::on(fn ($input) => $input['options']['pages'] === 'all'),
        ['executionTimeout' => 900000, 'ttl' => 1200000], Mockery::type('int'))->andReturn(['id' => 'remote-all-pages']);
    app()->instance(RunPodProvider::class, $provider);
    $apiBalance = CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->value('balance_credits');
    $cost = $this->customer->priceCreditsFor('ocr.standard', ['channel' => 'app', 'metric_code' => 'page', 'pages' => 174, 'page_count' => 174, 'files' => 1, 'file_count' => 1]);
    $component = ($this->workspace)()->set('documentFile', ($this->pdf)())
        ->assertSet('verifiedPageCount', 174)->assertSet('pageMode', 'all')->assertSet('pageRange', '')
        ->assertSet('exportTxt', true)->assertSet('exportDocx', true)->assertSee('wire:model="exportTxt"', false)
        ->assertSee('wire:click="submitOcr"', false)->assertSee('wire:loading.attr="disabled"', false)
        ->call('submitOcr')->assertHasNoErrors()->assertSet('submissionError', '')
        ->assertSet('showJobStatus', true)->assertDispatched('metkurd:job-submitted')
        ->assertSee(__('Scanning your document…'))->assertSee(__('Current job'));
    $job = MlJob::sole();
    expect($job->status)->toBe('running')->and($job->input['page_range'])->toBe('all')
        ->and($job->input['pages_estimated'])->toBe(174)->and((int) $job->credits_charged)->toBe((int) $cost);
    $component->call('submitOcr')->assertSee(__('An OCR job is already in progress.'));
    // A stale/concurrent replay also hits the existing durable submission identity.
    $options = app(InputBoundary::class)->document(($this->pdf)(), array_merge($job->input['exports'], [
        'pages' => 'all', 'submission_key' => $job->submission_key, 'run_llm_corrector' => true,
    ]));
    expect(app(OcrV2SubmissionService::class)->submit($this->customer, ($this->pdf)(), $options)->id)->toBe($job->id);
    expect(MlJob::count())->toBe(1)->and(CreditLedger::where('direction', 'debit')->count())->toBe(1);
    expect((int) CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'app')->value('balance_credits'))->toBe(1000000 - (int) $cost);
    expect(CreditWallet::where('customer_id', $this->customer->id)->where('wallet_type', 'api')->value('balance_credits'))->toBe($apiBalance);
})->with(['en', 'ar', 'ku']);

it('requires a valid custom range and clears stale errors when switching modes', function (string $range) {
    $submission = Mockery::mock(OcrV2SubmissionService::class);
    $submission->shouldNotReceive('submit');
    app()->instance(OcrV2SubmissionService::class, $submission);
    $component = ($this->workspace)()->set('documentFile', ($this->pdf)())->set('pageMode', 'custom')->set('pageRange', $range)
        ->call('submitOcr')->assertHasErrors('pageRange')->assertSee('role="alert"', false);
    $component->assertSee($component->instance()->getErrorBag()->first('pageRange'));
    $component->set('pageMode', 'all')->assertSet('pageRange', '')->assertHasNoErrors()->assertSet('submissionError', '')
        ->assertDontSee('role="alert"', false);
    expect(MlJob::count())->toBe(0)->and(CreditLedger::where('direction', 'debit')->count())->toBe(0);
})->with(['', 'bad', '0', '175', '5-1', '1,,3']);

it('submits validated custom ranges and bills unique selected pages', function (string $range, int $count, string $sent) {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->with('test-ocr', Mockery::on(fn ($input) => $input['options']['pages'] === $sent),
        Mockery::type('array'), Mockery::type('int'))->andReturn(['id' => 'remote-range']);
    app()->instance(RunPodProvider::class, $provider);
    ($this->workspace)()->set('documentFile', ($this->pdf)())->set('pageMode', 'custom')->set('pageRange', $range)
        ->call('submitOcr')->assertHasNoErrors()->assertSet('submissionError', '')->assertDispatched('metkurd:job-submitted');
    $job = MlJob::sole();
    $cost = $this->customer->priceCreditsFor('ocr.standard', ['channel' => 'app', 'metric_code' => 'page', 'pages' => $count, 'page_count' => $count, 'files' => 1, 'file_count' => 1]);
    expect($job->input['pages_estimated'])->toBe($count)->and((int) $job->credits_charged)->toBe((int) $cost);
})->with([['1', 1, '1'], ['1-5', 5, '1,2,3,4,5'], ['1-5,8,10-12', 9, '1,2,3,4,5,8,10,11,12'], ['1-5,3,5', 5, '1,2,3,4,5'], ['1-174', 174, implode(',', range(1, 174))]]);

it('shows validation and service blockers beside Scan Document in each locale', function (string $locale) {
    app()->setLocale($locale);
    Lang::addJsonPath(resource_path('lang/app'));
    $component = ($this->workspace)()->call('submitOcr')->assertHasErrors('documentFile')->assertSee('role="alert"', false);
    $component->assertSee($component->instance()->getErrorBag()->first('documentFile'));
    $submission = Mockery::mock(OcrV2SubmissionService::class);
    $submission->shouldReceive('submit')->once()->andThrow(new RuntimeException(__('Pricing is not configured for this service. Please contact support.')));
    app()->instance(OcrV2SubmissionService::class, $submission);
    $component->set('documentFile', ($this->pdf)())->call('submitOcr')
        ->assertSee(__('Pricing is not configured for this service. Please contact support.'))->assertNotDispatched('metkurd:job-submitted');
    $component->set('pageMode', 'custom')->assertSet('submissionError', '');
})->with(['en', 'ar', 'ku']);

it('persists selected TXT and all existing exports through the normal sync and owned downloads', function () {
    $provider = Mockery::mock(RunPodProvider::class);
    $provider->shouldReceive('runWithPolicy')->once()->andReturn(['id' => 'remote-exports']);
    $provider->shouldReceive('status')->once()->andReturn(['status' => 'COMPLETED', 'output' => ['text' => 'Persisted OCR text']]);
    app()->instance(RunPodProvider::class, $provider);
    $component = ($this->workspace)()->set('documentFile', ($this->pdf)())
        ->set('exportTxt', false)->set('exportTxt', true)->set('exportMarkdown', true)->set('exportHtml', true)->set('exportZip', true)
        ->call('submitOcr')->assertSet('submissionError', '');
    $job = MlJob::sole();
    app(OcrJobSyncService::class)->sync($job);
    $job->refresh();
    expect($job->status)->toBe('done');
    $component->call('pollOcr')->assertSee('Persisted OCR text');
    expect(array_keys($component->instance()->availableDownloads($job)))->toBe(['txt', 'docx', 'markdown', 'html', 'zip']);
    foreach (['txt', 'docx', 'markdown', 'html', 'zip'] as $format) {
        expect(data_get($job->input, 'exports.export_'.$format))->toBeTrue();
        $path = data_get($job->output, $format === 'txt' ? 'text.path' : 'artifacts.'.$format.'.path');
        Storage::disk('s3')->assertExists($path);
        $this->actingAs($this->customer, 'app')->get($component->instance()->downloadUrl($job, $format))->assertOk();
    }
    expect(Storage::disk('s3')->get(data_get($job->output, 'text.path')))->toBe('Persisted OCR text');
    $component->call('resetOcr')->assertSet('exportTxt', true)->assertSet('exportDocx', true)->assertSet('exportMarkdown', false);
});
