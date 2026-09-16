<?php

use App\Models\LandingSocialLink;
use App\Models\LandingToolPage;
use App\Models\User;
use App\Support\AreaJsonTranslations;
use App\Support\Landing\LandingToolPageCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('local');
    Storage::fake('s3');
    Storage::fake('public');
    config(['landing.media_disk' => 'local']);
    AreaJsonTranslations::flush();
    $this->cmsAdmin = User::forceCreate(['name' => 'CMS Fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.catalog']]);
    $this->cmsAdmin->profile()->create(['first_name' => 'CMS', 'last_name' => 'Fixture']);
    $this->actingAs($this->cmsAdmin, 'admin');
});

it('saves contact and social configuration into public localized Landing output', function () {
    $cms = Livewire::test('admin::pages.landing.adm-landing-contact')
        ->set('adminChangeReason', 'Isolated Landing consumer verification')->set('supportEmail', 'cms@example.test')
        ->set('companyLinesText', "Connected Company\nConnected Location")->call('saveContactSettings')->assertHasNoErrors();
    $cms->call('openSocialCreateModal')->set('socialPlatform', 'Example')->set('socialUrl', 'https://example.test/cms')
        ->call('saveSocialLink')->assertHasNoErrors();
    foreach (['en', 'ar', 'ku'] as $locale) {
        $this->get(route('landing.contact', ['locale' => $locale]))->assertOk()->assertSee('cms@example.test')->assertSee('Connected Company')->assertSee('https://example.test/cms');
    }
    $cms->call('toggleSocialStatus', LandingSocialLink::where('platform', 'Example')->value('id'));
    $this->get(route('landing.contact', ['locale' => 'en']))->assertDontSee('https://example.test/cms');
    Http::assertNothingSent();
});

it('persists tool page edits and activation in the public tool catalog and localized detail pages', function () {
    $cms = Livewire::test('admin::pages.landing.adm-landing-tools')->call('openToolCreateModal')
        ->set('adminChangeReason', 'Isolated Landing tool verification')->set('slug', 'consumer-fixture');
    foreach (['en' => 'Connected Tool', 'ar' => 'أداة متصلة', 'ku' => 'ئامرازی پەیوەست'] as $locale => $title) {
        $cms->set('title.'.$locale, $title)->set('heroText.'.$locale, $title.' example')
            ->set('summary.'.$locale, $title.' summary')->set('aboutCopy.'.$locale, $title.' description');
    }
    $cms->call('saveToolPage')->assertHasNoErrors();
    $page = LandingToolPage::where('slug', 'consumer-fixture')->firstOrFail();
    foreach (['en' => 'Connected Tool', 'ar' => 'أداة متصلة', 'ku' => 'ئامرازی پەیوەست'] as $locale => $title) {
        $this->get(route('landing.tools.show', ['locale' => $locale, 'slug' => $page->slug]))->assertOk()->assertSee($title);
    }
    $cms->call('openToolEditModal', $page->id)->set('title.en', 'Updated Connected Tool')->call('saveToolPage')->assertHasNoErrors();
    $this->get(route('landing.tools.show', ['locale' => 'en', 'slug' => $page->slug]))->assertSee('Updated Connected Tool');
    $cms->call('toggleToolPageStatus', $page->id);
    expect(app(LandingToolPageCatalog::class)->findForLocaleBySlug($page->slug, 'en'))->toBeNull();
    $this->get(route('landing.tools.show', ['locale' => 'en', 'slug' => $page->slug]))->assertNotFound();
    Http::assertNothingSent();
});

it('refreshes saved global social metadata in the actual Landing layout', function () {
    Livewire::test('admin::pages.landing.adm-landing-meta-settings')->set('adminChangeReason', 'Isolated meta consumer verification')
        ->set('defaultOgTitle', 'Connected Global OG Title')->set('defaultTwitterTitle', 'Connected Global Twitter Title')
        ->set('faviconUpload', \Illuminate\Http\UploadedFile::fake()->image('fixture.png', 64, 64))
        ->set('appleTouchIconUpload', \Illuminate\Http\UploadedFile::fake()->image('apple.png', 180, 180))
        ->call('saveMetaSettings')->assertHasNoErrors();
    $repo = app(\App\Support\Landing\SiteMetaSettingsRepository::class);
    expect($repo->defaultOgTitle())->toBe('Connected Global OG Title');
    foreach (['en', 'ar', 'ku'] as $locale) {
        app()->setLocale($locale);
        $fallback = view('landing::layouts.app', ['slot' => 'fixture'])->render();
        expect($fallback)->toContain('Connected Global OG Title', 'Connected Global Twitter Title');
        $response = $this->get(route('landing.home', ['locale' => $locale]))->assertOk();
        $response->assertSee($repo->publicUrl($repo->faviconPath()), false)->assertSee($repo->publicUrl($repo->appleTouchIconPath()), false);
        expect(view('landing::law.clean', ['slot' => 'fixture'])->render())->toContain($repo->publicUrl($repo->faviconPath()), $repo->publicUrl($repo->appleTouchIconPath()));
    }
});

it('writes translation edits to isolated locale files and refreshes the public Landing consumer', function () {
    $original = base_path();
    $isolated = Storage::disk('local')->path('cms-sandbox');
    File::ensureDirectoryExists($isolated.'/resources/lang/landing');
    foreach (['en', 'ar', 'ku'] as $locale) {
        File::copy(resource_path('lang/landing/'.$locale.'.json'), $isolated.'/resources/lang/landing/'.$locale.'.json');
        AreaJsonTranslations::get('site.name', 'landing', $locale); // Warm the original cache.
    }
    $this->withoutVite();
    app()->setBasePath($isolated);
    try {
        Livewire::test('admin::pages.landing.adm-landing-translations')
            ->set('adminChangeReason', 'Isolated translation consumer verification')
            ->set('translations', [['key' => 'site.name', 'en' => 'Connected EN', 'ar' => 'متصل AR', 'ku' => 'پەیوەست KU']])
            ->call('saveTranslations')->assertHasNoErrors();
        foreach (['en' => 'Connected EN', 'ar' => 'متصل AR', 'ku' => 'پەیوەست KU'] as $locale => $expected) {
            expect(AreaJsonTranslations::get('site.name', 'landing', $locale))->toBe($expected);
            $this->get(route('landing.home', ['locale' => $locale]))->assertOk()->assertSee($expected);
        }
    } finally {
        app()->setBasePath($original);
        AreaJsonTranslations::flush();
    }
});

it('keeps CMS readable but rejects direct saves after catalog capability is revoked', function (string $page, string $method) {
    $component = Livewire::test('admin::pages.landing.'.$page);
    $this->cmsAdmin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $component->call('$refresh')->assertSee('admin.catalog')->call($method)->assertForbidden();
})->with([
    ['adm-landing-contact', 'saveContactSettings'], ['adm-landing-tools', 'saveToolPage'],
    ['adm-landing-meta-settings', 'saveMetaSettings'], ['adm-landing-translations', 'saveTranslations'],
]);
