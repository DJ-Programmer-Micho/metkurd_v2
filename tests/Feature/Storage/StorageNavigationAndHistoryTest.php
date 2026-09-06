<?php

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Services\MetKurd\V2\CaptionWorkspaceCache;
use App\Services\Storage\CustomerOutputStorage;
use App\Services\Storage\StorageFileDeletionService;
use App\Support\AreaJsonTranslations;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed();
    Storage::fake('s3');
    config()->set('metkurd_v2.enabled', true);
    $this->owner = Customer::create(['username' => 'navigation', 'email' => 'navigation@example.com', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->job = MlJob::create(['id' => 'owned-caption', 'customer_id' => $this->owner->id, 'job_kind' => 'caption', 'status' => 'done', 'input' => ['audio_name' => 'Meeting notes.wav']]);
    $storage = app(CustomerOutputStorage::class);
    foreach (['text.txt', 'captions.srt'] as $name) {
        $storage->saveTextToS3($this->owner->id, 'owned/'.$name, 'caption text', $storage->apiOutputMeta($this->job, 'caption', $name === 'text.txt' ? 'transcription' : 'caption'));
    }
    $this->job->update(['output' => ['path' => 'owned/text.txt', 'srt_path' => 'owned/captions.srt']]);
});

it('shows readable folders and returns to root preserving useful filters without the folder parameter', function () {
    $component = Livewire::actingAs($this->owner, 'app')->test('app::v2.pages.storage.app-storage')
        ->assertSee('Meeting notes.wav')->set('search', 'caption')->set('product', 'caption')->set('type', 'text')->set('sort', 'oldest')
        ->call('openFolder', 'job:owned-caption')->assertSee('Back to Storage')->assertSee('Meeting notes.wav');
    $root = $component->get('rootUrl');
    parse_str(parse_url($root, PHP_URL_QUERY), $query);
    expect($query)->toBe(['q' => 'caption', 'product' => 'caption', 'type' => 'text', 'sort' => 'oldest']);
    $component->call('closeFolder')->assertSet('folder', '')->assertRedirect($root);
});

it('renders Storage and SweetAlert in each locale with appropriate direction', function ($locale, $direction) {
    $this->actingAs($this->owner, 'app')->get(route('app.v2.storage', ['locale' => $locale, 'folder' => 'job:owned-caption']))
        ->assertOk()->assertSee('dir="'.$direction.'"', false)
        ->assertSee(AreaJsonTranslations::get('Back to Storage', 'app', $locale))
        ->assertSee('Meeting notes.wav')->assertSee('window.metkurdV2Confirm', false)->assertSee('dir="auto"', false);
})->with([['en', 'ltr'], ['ar', 'rtl'], ['ku', 'rtl']]);

it('does not expose another customers folder label or files', function () {
    $other = Customer::create(['username' => 'other-nav', 'email' => 'other-nav@example.com', 'password' => 'Secret123!', 'status' => 1]);
    Livewire::actingAs($other, 'app')->test('app::v2.pages.storage.app-storage')
        ->call('openFolder', 'job:owned-caption')->assertDontSee('Meeting notes.wav')->assertDontSee('captions.srt')->assertSee('This folder is empty');
});

it('updates partial history and invalidates Caption cache while retaining the audit record', function () {
    config()->set('filesystems.customer_outputs.allow_destructive_operations', true);
    $cache = app(CaptionWorkspaceCache::class);
    $resolve = fn () => new Illuminate\Pagination\LengthAwarePaginator([$this->job->fresh()->status], 1, 10);
    expect($cache->recentCaptions($this->owner->id, 1, false, $resolve)->items())->toBe(['done']);
    $deletion = app(StorageFileDeletionService::class);
    $deletion->delete(CustomerFile::where('path', 'owned/text.txt')->firstOrFail());
    expect($this->job->fresh()->status)->toBe('done')->and(data_get($this->job->fresh()->output, 'path'))->toBeNull()->and(data_get($this->job->fresh()->output, 'srt_path'))->toBe('owned/captions.srt');
    $deletion->delete(CustomerFile::where('path', 'owned/captions.srt')->firstOrFail());
    expect($this->job->fresh()->status)->toBe('deleted');
    expect($cache->recentCaptions($this->owner->id, 1, false, $resolve)->items())->toBe(['deleted']);
    expect(MlJob::whereKey($this->job->id)->exists())->toBeTrue();
});
