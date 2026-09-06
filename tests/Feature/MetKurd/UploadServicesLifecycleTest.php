<?php

use App\Models\CreditLedger;
use App\Models\CreditWallet;
use App\Models\Customer;
use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OcrV2SubmissionService;
use App\Services\MetKurd\Jobs\StemV2SubmissionService;
use App\Services\MetKurd\V2\RunPodV2Adapter;
use App\Services\Providers\RunPodProvider;
use App\Services\Security\JobExecutionLockService;
use App\Services\STEM\StemJobSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('submits each upload service only once for the same durable identity', function ($kind) {
    $this->seed();
    Storage::fake('s3');
    config()->set('runpod.endpoints.stem', 'stem-test');
    $owner = Customer::create(['username' => 'upload-service', 'email' => 'upload-service@example.com', 'password' => 'Secret123!', 'status' => 1]);
    CreditWallet::updateOrCreate(['customer_id' => $owner->id, 'wallet_type' => 'app'], ['balance_credits' => 1000, 'subscription_balance_credits' => 1000, 'addon_balance_credits' => 0]);
    $customer = Mockery::mock(Customer::class)->makePartial();
    $customer->setRawAttributes($owner->getAttributes(), true);
    $customer->exists = true;
    $customer->setRelation('profile', $owner->profile);
    $customer->shouldReceive('priceCreditsFor')->andReturnUsing(function ($action, $context) use ($kind) {
        if ($kind === 'ocr') {
            expect($context['pages'])->toBe(4);
        }

        return 10;
    });
    $customer->shouldReceive('isAllowed')->andReturn(true);
    $locks = Mockery::mock(JobExecutionLockService::class);
    $locks->shouldReceive('acquireOcrLock')->andReturn(['ok' => true]);
    $locks->shouldReceive('acquireStemLock')->andReturn(['ok' => true]);
    app()->instance(JobExecutionLockService::class, $locks);
    $adapter = Mockery::mock(RunPodV2Adapter::class);
    $provider = Mockery::mock(RunPodProvider::class);
    if ($kind === 'ocr') {
        $adapter->shouldReceive('kocr')->once()->andReturnUsing(function () {
            expect(MlJob::first()->submission_attempted_at)->not->toBeNull();
            expect(CreditLedger::where('direction', 'debit')->count())->toBe(1);

            return ['id' => 'remote-ocr'];
        });
    } else {
        $provider->shouldReceive('run')->once()->andReturn(['id' => 'remote-stem']);
    }
    app()->instance(RunPodV2Adapter::class, $adapter);
    app()->instance(RunPodProvider::class, $provider);
    $sync = Mockery::mock(StemJobSyncService::class);
    $sync->shouldReceive('buildRunpodInput')->andReturn(['stems' => $kind === 'stem2' ? 2 : 4]);
    app()->instance(StemJobSyncService::class, $sync);
    $options = ['submission_key' => 'same-upload-intent', 'input_hash' => 'content-hash', 'file_name' => 'image.png', 'file_ext' => 'png', 'file_mime' => 'image/png', 'file_bytes' => 100,
        'estimated_pages' => 1, 'pages' => 'all', 'run_llm_corrector' => false, 'stems' => $kind === 'stem2' ? 2 : 4, 'duration_sec' => 20, 'billable_minutes' => 1];
    $service = app($kind === 'ocr' ? OcrV2SubmissionService::class : StemV2SubmissionService::class);
    Illuminate\Support\Facades\Process::fake(['*' => Illuminate\Support\Facades\Process::result(output: "Pages: 4\n", exitCode: 0)]);
    $file = UploadedFile::fake()->create($kind === 'ocr' ? 'document.pdf' : 'audio.wav', 1, $kind === 'ocr' ? 'application/pdf' : 'audio/wav');
    $first = $service->submit($customer, $file, $options);
    $second = $service->submit($customer, $file, $options);
    expect($first->status)->toBe('running')->and($second->id)->toBe($first->id)->and(MlJob::count())->toBe(1);
    expect(data_get($first->input, 'billing_action'))->toBe(match ($kind) {
        'ocr' => 'ocr.standard', 'stem2' => 'stem.sep2', default => 'stem.sep4'
    });
    expect(CreditLedger::where('customer_id', $owner->id)->where('direction', 'debit')->count())->toBe(1);
})->with(['ocr', 'stem2', 'stem4']);
