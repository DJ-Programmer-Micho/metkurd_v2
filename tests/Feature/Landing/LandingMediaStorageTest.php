<?php

use App\Models\LandingToolPage;
use App\Models\SiteMetaSetting;
use App\Support\Landing\LandingMediaStorage;
use App\Support\Landing\LandingToolPageCatalog;
use App\Support\Landing\SiteMetaSettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed();

    config()->set('landing.media_disk', 's3');
    config()->set('landing.media_url_strategy', 'proxy');
    config()->set('filesystems.disks.s3.url', 'https://fsn1.your-objectstorage.com/metkurd-v1');
    config()->set('filesystems.disks.s3.endpoint', 'https://fsn1.your-objectstorage.com');
    config()->set('filesystems.disks.s3.bucket', 'metkurd-v1');
    Storage::fake('s3');
});

it('resolves landing tool image urls from the configured shared media disk', function () {
    LandingToolPage::query()->create([
        'slug' => 'landing-media-test',
        'is_active' => true,
        'sort_order' => 1,
        'square_image_path' => '/storage/landing/tools/square/test-square.png',
        'hero_image_path' => 'web-setting/tools/test-hero.png',
        'card_image_path' => 'https://cdn.example.com/tool-card.png',
        'content' => [
            'en' => [
                'title' => 'Landing Media Test',
                'hero_text' => 'Hero text',
                'summary' => 'Summary',
                'about_copy' => 'About',
            ],
        ],
    ]);

    Storage::disk('s3')->put('landing/tools/square/test-square.png', 'square');
    Storage::disk('s3')->put('web-setting/tools/test-hero.png', 'hero');

    $tool = app(LandingToolPageCatalog::class)->findForLocaleBySlug('landing-media-test', 'en');

    expect($tool)->not->toBeNull()
        ->and(data_get($tool, 'square_image_url'))->toBe(url('media/web/landing/tools/square/test-square.png'))
        ->and(data_get($tool, 'hero_image_url'))->toBe(url('media/web/web-setting/tools/test-hero.png'))
        ->and(data_get($tool, 'card_image_url'))->toBe('https://cdn.example.com/tool-card.png');
});

it('converts legacy absolute object storage urls into proxy urls', function () {
    $absolute = 'https://fsn1.your-objectstorage.com/metkurd-v1/web-setting/tools/legacy-hero.png';
    Storage::disk('s3')->put('web-setting/tools/legacy-hero.png', 'legacy');

    $url = app(LandingMediaStorage::class)->publicUrl($absolute);

    expect($url)->toBe(url('media/web/web-setting/tools/legacy-hero.png'));
});

it('stores site meta uploads on the configured shared media disk', function () {
    $repository = app(SiteMetaSettingsRepository::class);
    $upload = UploadedFile::fake()->image('favicon.png', 64, 64);

    $repository->save([
        'favicon_upload' => $upload,
    ]);

    $setting = SiteMetaSetting::query()->where('key', 'meta.favicon_path')->firstOrFail();
    $storedPath = trim((string) data_get($setting->value, 'value', ''));

    expect($storedPath)->toStartWith('web-setting/site-meta/favicon-')
        ->and(app(LandingMediaStorage::class)->publicUrl('/storage/' . $storedPath))
        ->toBe(url('media/web/' . $storedPath));

    Storage::disk('s3')->assertExists($storedPath);
});

it('serves public landing media through app route for private buckets', function () {
    $path = 'web-setting/site-meta/favicon-test.png';
    Storage::disk('s3')->put($path, 'favicon-content');

    $response = $this->get(url('media/web/' . $path));

    $response->assertOk()
        ->assertHeader('Cache-Control');

    expect($response->streamedContent())->toBe('favicon-content');
});

it('does not expose non-allowlisted keys through landing media proxy', function () {
    $privatePath = 'customers/private/avatar.png';
    Storage::disk('s3')->put($privatePath, 'secret');

    $this->get(url('media/web/' . $privatePath))->assertNotFound();
});
