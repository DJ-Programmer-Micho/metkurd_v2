<?php

use App\Http\Controllers\LawController;
use App\Support\AreaJsonTranslations;
use App\Support\Landing\LandingTranslationManager;
use App\Support\LandingContent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('s3');
    Storage::fake('public');
    AreaJsonTranslations::flush();
});

it('renders each canonical legal document from its localized catalog with a visible revision date', function (string $locale, string $document) {
    $response = $this->get('/'.$locale.'/'.$document)->assertOk();
    $html = $response->getContent();
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($dom);
    $prefix = $document.'_page';
    $cards = AreaJsonTranslations::group($prefix.'.cards', 'landing', $locale);
    expect($cards)->toHaveCount($document === 'privacy' ? 13 : 17);
    $renderedCards = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " policy-card ")]');
    expect($renderedCards->length)->toBe(count($cards));
    foreach ($cards as $i => $card) {
        expect(trim($xpath->query('.//h2', $renderedCards->item($i))->item(0)->textContent))->toBe($card['title'])
            ->and(trim($xpath->query('.//p', $renderedCards->item($i))->item(0)->textContent))->toBe($card['copy']);
    }
    $date = $xpath->query('//time[@datetime="2026-10-07"]');
    expect($date->length)->toBe(1)->and(trim($date->item(0)->textContent))->toBe('2026-10-07');
    $response->assertSee(AreaJsonTranslations::get($prefix.'.updated_label', 'landing', $locale));
    expect($dom->documentElement->getAttribute('lang'))->toBe($locale)
        ->and($dom->documentElement->getAttribute('dir'))->toBe($locale === 'en' ? 'ltr' : 'rtl');
    expect($xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'))->toBe('https://metkurd.ai/'.$locale.'/'.$document);
    foreach (['en', 'ar', 'ku', 'x-default'] as $language) {
        expect($xpath->query('//link[@hreflang="'.$language.'"]')->item(0)->getAttribute('href'))
            ->toBe('https://metkurd.ai/'.($language === 'x-default' ? 'en' : $language).'/'.$document);
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku'])->with(['privacy', 'terms']);

it('redirects legacy named legal routes to the current localized documents', function (string $locale, string $legacy, string $document, string $method) {
    $route = app('router')->getRoutes()->getByName('law.'.$document);
    expect($route->getActionName())->toBe(LawController::class.'@'.$method);
    $this->withSession(['applocale' => $locale])->get($legacy)
        ->assertStatus(302)->assertRedirect('/'.$locale.'/'.$document)
        ->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku'])->with([
    ['/law/privacy-policy', 'privacy', 'privacyPolicy'],
    ['/law/terms-conditions', 'terms', 'termsCondition'],
]);

it('uses an allowlisted default for legacy legal redirects', function () {
    config(['app.locale' => 'ar']);
    $this->get('/law/privacy-policy')->assertRedirect('/ar/privacy');
    $this->withSession(['applocale' => 'unsupported'])->get('/law/terms-conditions')->assertRedirect('/en/terms');
});

it('links signup to the canonical legal pages in the selected language', function (string $locale) {
    // Signup has no locale URL parameter or locale middleware; it uses the app locale.
    app()->setLocale($locale);
    $this->get(route('app.signup'))->assertOk()
        ->assertSee(route('landing.terms', ['locale' => $locale]), false)
        ->assertSee(route('landing.privacy', ['locale' => $locale]), false)
        ->assertDontSee('/law/privacy-policy')->assertDontSee('/law/terms-conditions');
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('uses the English legal catalog as the only raw defaults and keeps translation management complete', function () {
    $english = json_decode(file_get_contents(resource_path('lang/landing/en.json')), true, flags: JSON_THROW_ON_ERROR);
    $expected = array_filter($english, fn ($key) => str_starts_with($key, 'privacy_page.') || str_starts_with($key, 'terms_page.'), ARRAY_FILTER_USE_KEY);
    app()->setLocale('ku');
    expect(LandingContent::translationCatalog(['privacy_page', 'terms_page']))->toEqual($expected);
    $entries = app(LandingTranslationManager::class)->editableEntries();
    foreach ($expected as $key => $value) {
        expect(LandingContent::rawText($key))->toBe($value)
            ->and($entries[$key]['default'])->toBe($value);
        foreach (['en', 'ar', 'ku'] as $locale) {
            expect($entries[$key][$locale])->toBe(AreaJsonTranslations::get($key, 'landing', $locale))->not->toBe('');
        }
    }
    expect((new ReflectionClass(LandingContent::class))->getConstant('CONTENT'))
        ->not->toHaveKey('privacy_page')->not->toHaveKey('terms_page');
});

it('retains detailed privacy topics and restored terms provisions in the canonical source', function () {
    $privacy = LandingContent::rawSection('privacy_page.cards');
    expect(array_column($privacy, 'title'))->toBe([
        'Account and profile information', 'Submitted content and processing records',
        'Connected accounts and MCP access', 'Website, analytics and security data',
        'Support and operational notifications', 'Billing and payment records',
        'Who receives information', 'Temporary files and upload links',
        'Saved files, references and deletion', 'Retention of account and service records',
        'Backups and external copies', 'Your account and file controls', 'Privacy questions and requests',
    ]);
    $terms = implode(' ', array_column(LandingContent::rawSection('terms_page.cards'), 'copy'));
    foreach (['MET IRAQ Company', 'REST API V2', 'MCP', 'commercially', 'non-refundable',
        'last billing period', 'laws of Iraq', 'terminate', 'uninterrupted', 'backup',
        'Political manipulation', 'explicit consent', 'support@metkurd.ai'] as $provision) {
        expect($terms)->toContain($provision);
    }
    expect($terms)->not->toContain('AI Translation', 'only accessible by you');
});

it('keeps the original HTML as historical evidence outside public serving and runtime consumers', function () {
    foreach (['METKURDPrivacy.html', 'METKURDTermcondition.html'] as $file) {
        expect(is_file(base_path('docs/metkurd/legal-history/'.$file)))->toBeTrue()
            ->and(is_file(public_path('landing/law/'.$file)))->toBeFalse();
        foreach (['app', 'routes', 'resources/views', 'config', 'bootstrap'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)) as $source) {
                if (str_ends_with($source->getFilename(), '.php') && ! str_contains($source->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                    expect(file_get_contents($source->getPathname()))->not->toContain($file);
                }
            }
        }
    }
    foreach (['privacy-policy-one', 'terms-conditions-one'] as $view) {
        expect(is_file(resource_path('views/landing/law/'.$view.'.blade.php')))->toBeFalse();
    }
});
