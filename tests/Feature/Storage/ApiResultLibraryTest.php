<?php

use App\Models\ApiJob;
use App\Models\ApiResultFile;
use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\CustomerUsage;
use App\Models\MlJob;
use App\Services\CustomerApi\CustomerApiFileLinkService;
use App\Services\CustomerApi\V2\ApiCatalog;
use App\Services\CustomerApi\V2\ApiJobResult;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\CustomerStorageBulkDownloadService;
use App\Services\Storage\StorageFileDeletionService;
use App\Support\CustomerStorageLibrary;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Storage::fake('s3')->buildTemporaryUrlsUsing(fn () => 'https://storage.example.test/private');
    $this->seed();
    config()->set('metkurd_v2.enabled', true);
    $this->owner = Customer::create(['username' => 'api-library', 'email' => 'api-library@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
});

/** Persist through the real shared output boundary, without dispatching a provider. */
function apiLibraryFixture(Customer $customer, string $service, array $input, string $mode, string $suffix = 'one'): array
{
    [, , $action] = app(ApiCatalog::class)->definition($service, $input);
    $tool = explode('.', $action)[0];
    $expiry = $mode === 'temporary' ? now()->addDays(7)->startOfSecond() : null;
    $api = ApiJob::create(['id' => 'job_library_'.$suffix, 'customer_id' => $customer->id, 'status' => 'completed', 'tool_code' => $tool, 'tool_action' => $action,
        'storage_mode' => $mode, 'meta' => ['api_version' => 2, 'service' => $service, 'expires_at' => $expiry?->toIso8601String()]]);
    $job = MlJob::create(['id' => 'ml_library_'.$suffix, 'customer_id' => $customer->id, 'status' => 'done', 'job_kind' => $tool,
        'model_key' => ($input['model'] ?? '2.0') === '1.5' ? 'model_1' : 'model_2',
        'input' => ['text' => 'Library result '.$suffix, 'api_version' => 2, 'api_job_id' => $api->id, 'api_storage_mode' => $mode, 'api_expires_at' => $expiry?->toIso8601String()]]);
    $api->update(['ml_job_id' => $job->id]);
    $storage = app(CustomerOutputStorage::class);
    $base = $storage->renderBaseDir($job, $tool);
    if ($service === 'ocr') {
        $output = ['disk' => 's3', 'text' => ['path' => $base.'/text.txt'], 'json' => ['path' => $base.'/result.json']];
        foreach (['docx', 'markdown', 'html', 'zip'] as $format) {
            $output['artifacts'][$format] = ['path' => $base.'/result.'.$format];
        }
        foreach ([$output['text'], $output['json'], ...array_values($output['artifacts'])] as $artifact) {
            Storage::disk('s3')->put($artifact['path'], 'fixture result');
        }
        $storage->registerOcrArtifacts($job, $output);
    } elseif ($service === 'stem') {
        $output = ['disk' => 's3', 'original' => ['path' => $base.'/original.wav'], 'result_json' => ['path' => $base.'/result.json']];
        $roles = ($input['mode'] === 2) ? ['vocals', 'instrumental'] : ['vocals', 'drums', 'bass', 'other'];
        foreach ($roles as $role) {
            $output['stems'][$role] = ['path' => $base.'/'.$role.'.mp3'];
        }
        foreach ([$output['original'], $output['result_json'], ...array_values($output['stems'])] as $artifact) {
            Storage::disk('s3')->put($artifact['path'], 'fixture result');
        }
        $storage->registerStemArtifacts($job, $output);
    } elseif (in_array($service, ['captions', 'transcriptions', 'harakat'], true)) {
        $storage->saveTextToS3($customer->id, $base.'/text.txt', 'fixture result',
            $storage->apiOutputMeta($job, $tool, $service === 'harakat' ? 'render' : 'transcription', $service === 'harakat' ? 'diacritized_text' : null));
        if ($service === 'captions') {
            $storage->saveTextToS3($customer->id, $base.'/text.srt', 'subtitle result', $storage->apiOutputMeta($job, $tool, 'caption', 'srt'));
        }
    } else {
        $storage->saveWavB64ToS3($customer->id, $base.'/result.wav', base64_encode('wave result'), ['job_id' => $job->id, 'tool' => $tool, 'purpose' => 'render']);
    }
    app(CustomerApiFileLinkService::class)->attachArtifacts($api);

    return [$api, $job, CustomerFile::where('customer_id', $customer->id)->where('meta->job_id', $job->id)->get()];
}

it('lists all native API result roles under the correct product without changing retention or quota', function (string $service, array $input, string $product, array $roles, string $mode) {
    [$api, $job, $files] = apiLibraryFixture($this->owner, $service, $input, $mode);
    $payload = app(ApiJobResult::class)->persistedPayload($api);
    expect(array_column($payload['result']['files'], 'kind'))->toEqualCanonicalizing($roles);
    expect($files)->toHaveCount(count($roles));
    expect(ApiResultFile::where('api_job_id', $api->id)->count())->toBe(count($roles));
    $used = (int) CustomerUsage::where('customer_id', $this->owner->id)->value('storage_used_bytes');
    expect($used)->toBe($mode === 'temporary' ? 0 : $files->sum('size_bytes'));
    foreach ($files as $file) {
        expect($file->retention_mode)->toBe($mode)->and($file->counts_toward_quota)->toBe($mode === 'permanent')
            ->and($file->source_type)->toBe('api_job')->and($file->source_id)->toBe($api->id)
            ->and($file->expires_at?->toIso8601String())->toBe(data_get($api->meta, 'expires_at'));
        expect(app(CustomerStorageLibrary::class)->identity($file, $job)['key'])->toBe($product);
        Storage::disk('s3')->assertExists($file->path);
    }
    $page = Livewire::actingAs($this->owner, 'app')->test('app::v2.pages.storage.app-storage')
        ->set('product', $product)->assertSee('job:'.$job->id)
        ->assertSee($mode === 'temporary' ? 'Temporary' : 'Permanent')->assertSee('API')
        ->call('openFolder', 'job:'.$job->id);
    expect(collect($page->get('files')->items())->pluck('id')->all())->toEqualCanonicalizing($files->pluck('id')->all());
    expect((int) CustomerUsage::where('customer_id', $this->owner->id)->value('storage_used_bytes'))->toBe($used);
})->with([
    ['speech', ['model' => '1.5'], 'apollo-1', ['render']],
    ['speech', ['model' => '2.0'], 'apollo-2', ['render']],
    ['voice-clone', ['model' => '1.5'], 'vector-1', ['render']],
    ['voice-clone', ['model' => '2.0'], 'vector-2', ['render']],
    ['zeta', [], 'zeta-1', ['render']], ['theta', [], 'theta-1', ['render']],
    ['transcriptions', [], 'leo', ['transcription']], ['captions', [], 'caption', ['transcription', 'srt']],
    ['ocr', [], 'ocr', ['text', 'json', 'docx', 'markdown', 'html', 'zip']],
    ['harakat', [], 'harakat-1', ['diacritized_text']],
    ['stem', ['mode' => 2], 'stem', ['original', 'result_json', 'vocals', 'instrumental']],
    ['stem', ['mode' => 4], 'stem', ['original', 'result_json', 'vocals', 'drums', 'bass', 'other']],
])->with(['temporary', 'permanent']);

it('hides staging and expired or deleted files before cleanup and rejects stale App links', function () {
    $this->freezeTime();
    [$api, $job, $files] = apiLibraryFixture($this->owner, 'speech', ['model' => '2.0'], 'temporary');
    $file = $files->first();
    $storage = app(CustomerOutputStorage::class);
    $storage->saveTextToS3($this->owner->id, 'staging.txt', 'input', $storage->apiOutputMeta($job, 'ocr', 'input_document'));
    $query = app(CustomerStorageLibrary::class)->filesFor($this->owner->id);
    expect($query->pluck('id')->all())->toBe([$file->id]);
    $file->update(['expires_at' => now()]);
    expect(app(CustomerStorageLibrary::class)->filesFor($this->owner->id)->count())->toBe(0)
        ->and($storage->temporaryUrlForCustomerFile($file))->toBeNull();
    $this->actingAs($this->owner, 'app')->get(route('app.v2.storage.download', ['locale' => 'en', 'file' => $file->id]))->assertNotFound();
    expect(fn () => app(CustomerStorageBulkDownloadService::class)->createArchive($this->owner, [$file->id]))->toThrow(InvalidArgumentException::class);
    Livewire::actingAs($this->owner, 'app')->test('app::v2.pages.storage.app-storage')
        ->assertDontSee('job:'.$job->id)->call('selectFile', $file->id)->assertSet('selectedFileId', null);
    // Metadata is retained until the existing scheduled cleanup can delete safely.
    Storage::disk('s3')->assertExists($file->path);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    $this->artisan('api:cleanup-expired-files')->assertSuccessful();
    expect($file->fresh()->status)->toBe('deleted')->and($file->fresh()->delete_reason)->toBe('expired');
    expect(ApiResultFile::where('storage_file_id', $file->id)->whereNull('deleted_at')->count())->toBe(0);
    Storage::disk('s3')->assertMissing($file->path);
});

it('caps preview URL expiry and keeps owned deletion idempotent without touching permanent quota', function () {
    $this->freezeTime();
    [$api, $job, $files] = apiLibraryFixture($this->owner, 'speech', [], 'temporary');
    $file = $files->first();
    $file->update(['expires_at' => now()->addMinute()]);
    Storage::disk('s3')->buildTemporaryUrlsUsing(function ($path, $expiry) use ($file) {
        expect($expiry->getTimestamp())->toBe($file->expires_at->getTimestamp());

        return 'https://storage.example.test/private';
    });
    expect(app(CustomerOutputStorage::class)->temporaryUrlForCustomerFile($file))->not->toBeNull();
    CustomerUsage::updateOrCreate(['customer_id' => $this->owner->id], ['storage_used_bytes' => 1234]);
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    app(StorageFileDeletionService::class)->delete($file);
    app(StorageFileDeletionService::class)->delete($file->fresh());
    expect((int) CustomerUsage::where('customer_id', $this->owner->id)->value('storage_used_bytes'))->toBe(1234)
        ->and(app(CustomerStorageLibrary::class)->filesFor($this->owner->id)->count())->toBe(0);
    expect(app(ApiJobResult::class)->persistedPayload($api)['result']['files'])->toBe([]);
});

it('never lists or downloads another customers API result', function () {
    [, $job, $files] = apiLibraryFixture($this->owner, 'speech', [], 'temporary');
    $other = Customer::create(['username' => 'api-other', 'email' => 'api-other@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    Livewire::actingAs($other, 'app')->test('app::v2.pages.storage.app-storage')
        ->assertDontSee('job:'.$job->id)->call('selectFile', $files->first()->id)->assertSet('selectedFileId', null);
    $this->actingAs($other, 'app')->get(route('app.v2.storage.download', ['locale' => 'en', 'file' => $files->first()->id]))->assertNotFound();
});

it('paginates temporary audio results and keeps saved references and web files visible', function () {
    $this->freezeTime();
    for ($i = 1; $i <= 13; $i++) {
        $this->travel(1)->seconds();
        apiLibraryFixture($this->owner, 'speech', ['model' => '2.0'], 'temporary', (string) $i);
    }
    $storage = app(CustomerOutputStorage::class);
    $storage->saveWavB64ToS3($this->owner->id, 'saved-reference.wav', base64_encode('reference'), ['tool' => 'theta', 'purpose' => 'reference', 'source_type' => 'upload']);
    $storage->saveWavB64ToS3($this->owner->id, 'web-result.wav', base64_encode('web result'), ['tool' => 'xomni-v2']);
    expect(app(CustomerStorageLibrary::class)->filesFor($this->owner->id)->count())->toBe(15);
    $page = Livewire::actingAs($this->owner, 'app')->test('app::v2.pages.storage.app-storage')
        ->set('product', 'apollo-2')->set('type', 'audio')->set('sort', 'newest');
    expect($page->get('files')->total())->toBe(14);
    $page->assertSee('job:ml_library_13')->assertDontSee('job:ml_library_1"')
        ->call('gotoPage', 2, 'storagePage')->assertSee('job:ml_library_1');
    $page->set('product', 'theta-1');
    expect($page->get('files')->total())->toBe(1);
    $reference = CustomerFile::where('customer_id', $this->owner->id)->where('purpose', 'reference')->firstOrFail();
    $page->call('openFolder', 'file:'.$reference->id)->assertSee('saved-reference.wav');
    expect((int) CustomerUsage::where('customer_id', $this->owner->id)->value('storage_used_bytes'))->toBe(strlen('reference') + strlen('web result'));
});

it('keeps inconsistent deleted metadata hidden even if the status is still active', function () {
    [$api, $job, $files] = apiLibraryFixture($this->owner, 'speech', [], 'temporary');
    $file = $files->first();
    $file->update(['deleted_at' => now()]);
    expect(app(CustomerStorageLibrary::class)->filesFor($this->owner->id)->count())->toBe(0)
        ->and(app(ApiJobResult::class)->persistedPayload($api)['result']['files'])->toBe([]);
});

it('renders retention and expiry in each customer locale', function (string $locale, string $temporary) {
    [, $job, $files] = apiLibraryFixture($this->owner, 'speech', [], 'temporary');
    $this->actingAs($this->owner, 'app')->get(route('app.v2.storage', ['locale' => $locale]))
        ->assertOk()->assertSee($temporary)->assertSee($files->first()->expires_at->toIso8601String())
        ->assertSee('<bdi>API</bdi>', false)->assertSee('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', false);
})->with([['en', 'Temporary'], ['ar', 'مؤقت'], ['ku', 'کاتی']]);
