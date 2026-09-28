<?php

use App\Models\LandingToolPage;
use App\Models\PlanEntitlement;
use App\Models\ServicePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Support\Landing\LandingToolPageCatalog;
use App\Support\Landing\PublicDemoCatalog;
use App\Support\Landing\PublicProductCatalog;
use App\Support\Landing\PublicWebsiteContent;
use App\Support\LandingPricingCatalog;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('s3');
    Storage::fake('public');
    Cache::flush();
    config(['customer_api.v2_enabled' => false, 'mcp.enabled' => false]);
    foreach (app(MetKurdV2ToolCatalog::class)->services() as $family) {
        foreach ($family['tools'] as $definition) {
            if (! isset($definition['legacy_action'])) {
                continue;
            }
            Tool::firstOrCreate(['code' => $definition['legacy_tool']], ['name' => $definition['name'], 'is_active' => true]);
            ToolAction::firstOrCreate(['full_code' => $definition['legacy_action']], [
                'tool_code' => $definition['legacy_tool'], 'action_code' => explode('.', $definition['legacy_action'])[1],
                'name' => $definition['name'], 'is_active' => true,
            ]);
        }
    }
});

it('projects only active V2 products and removes disabled tools and actions', function () {
    LandingToolPage::create(['slug' => 'translation', 'is_active' => true, 'content' => ['en' => ['title' => 'TRANS-CKB']]]);
    $catalog = app(PublicProductCatalog::class);
    expect($catalog->names())->toContain('Apollo 1.5', 'Apollo 2.0', 'Zeta 1.0', 'Theta 1.0', 'Harakat 1.0', 'STEM 4');
    ToolAction::where('full_code', 'xomni-v2.generate')->first()->update(['is_active' => false]);
    Tool::where('code', 'theta')->first()->update(['is_active' => false]);
    expect($catalog->names())->not->toContain('Apollo 2.0', 'Theta 1.0');
    expect(array_column(app(LandingToolPageCatalog::class)->listForLocale('en'), 'slug'))->toBe(['tts', 'ctts', 'asr', 'ocr', 'stem']);
    $this->get('/en/tools/translation')->assertNotFound();
    $this->get('/en/tools/unknown')->assertNotFound();
});

it('renders current localized content and metadata without obsolete claims', function (string $locale, string $path) {
    $response = $this->get('/'.$locale.$path)->assertOk();
    $response->assertDontSee('TRANS-CKB')->assertDontSee('TTS Delta')->assertDontSee('NEO')
        ->assertDontSee('xomni-v2.generate')->assertDontSee('RunPod')->assertDontSee('/tools/translation');
    $html = $response->getContent();
    expect($html)->toContain('dir="'.($locale === 'en' ? 'ltr' : 'rtl').'"', 'rel="canonical"', 'hreflang="x-default"', 'property="og:image"', 'name="twitter:card"');
    foreach (['en', 'ar', 'ku'] as $sibling) {
        expect($html)->toContain('hreflang="'.$sibling.'"');
    }
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);
    expect($scripts[1])->not->toBeEmpty();
    foreach ($scripts[1] as $json) {
        expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR))->toHaveKey('@context');
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku'])->with(['', '/tools', '/tools/tts', '/tools/ctts', '/tools/asr', '/tools/ocr', '/tools/stem', '/pricing', '/metkurd-ai-overview']);

it('uses identical visible FAQs and FAQ schema in all locales', function (string $locale, string $path, string $section) {
    $response = $this->get('/'.$locale.$path)->assertOk();
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $scripts);
    $schema = collect($scripts[1])->map(fn ($json) => json_decode($json, true))->firstWhere('@type', 'FAQPage');
    $faqs = app(PublicWebsiteContent::class)->faqs($section);
    expect($schema['mainEntity'])->toHaveCount(count($faqs));
    foreach ($faqs as $i => $faq) {
        expect($schema['mainEntity'][$i]['acceptedAnswer']['text'])->toBe($faq['copy']);
        $response->assertSee($faq['copy'])->assertSee($faq['title']);
    }
})->with(['en', 'ar', 'ku'])->with([['', 'home'], ['/pricing', 'pricing']]);

it('removes an inactive family from every discovery surface', function () {
    LandingToolPage::create(['slug' => 'tts', 'is_active' => false]);
    foreach (['/en', '/en/tools', '/en/pricing', '/sitemap.xml', '/llms.txt'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('Apollo')->assertDontSee('Zeta')->assertDontSee('/tools/tts');
    }
    $this->get('/en/tools/tts')->assertNotFound();
});

it('gates the localized MCP FAQ and its matching visible and structured answer', function (string $locale, string $path, string $section) {
    $copy = app(PublicWebsiteContent::class);
    $question = $copy->text($section.'_faq.mcp.title', [], $locale);
    $answer = $copy->text($section.'_faq.mcp.copy', [], $locale);
    foreach ([false, true] as $enabled) {
        config(['mcp.enabled' => $enabled]);
        $html = $this->get('/'.$locale.$path)->assertOk()->getContent();
        $dom = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $xpath = new DOMXPath($dom);
        $visibleAnswers = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " faq-item ")]') as $card) {
            $heading = $xpath->query('.//h3 | .//h4', $card)->item(0);
            if ($heading && trim($heading->textContent) === $question) {
                $visibleAnswers[] = trim($xpath->query('.//p', $card)->item(0)->textContent);
            }
        }
        $schemaAnswers = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $schema = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
            if (($schema['@type'] ?? null) === 'FAQPage') {
                foreach ($schema['mainEntity'] as $entry) {
                    if ($entry['name'] === $question) {
                        $schemaAnswers[] = $entry['acceptedAnswer']['text'];
                    }
                }
            }
        }
        expect($visibleAnswers)->toBe($enabled ? [$answer] : [])
            ->and($schemaAnswers)->toBe($visibleAnswers)
            ->and($dom->documentElement->getAttribute('dir'))->toBe($locale === 'en' ? 'ltr' : 'rtl');
        if (! $enabled) {
            expect($html)->not->toContain($question, $answer);
            $this->get('/llms.txt')->assertOk()->assertDontSee('MCP');
        }
        $sitemap = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        foreach ($sitemap->url as $node) {
            expect(parse_url((string) $node->loc, PHP_URL_PATH))->not->toMatch('#/(?:api|mcp|oauth)(?:/|$)#');
        }
    }
    foreach (['ChatGPT', 'Claude', 'Codex', 'Model Context Protocol'] as $term) {
        expect($answer)->toContain($locale === 'en' ? $term : "\u{2066}".$term."\u{2069}");
    }
    expect($copy->text('home_faq.mcp.copy', [], $locale))->toBe($copy->text('pricing_faq.mcp.copy', [], $locale));
})->with(['en', 'ar', 'ku'])->with([['', 'home'], ['/pricing', 'pricing']]);

it('gates API and MCP copy independently at request time', function () {
    $this->get('/llms.txt')->assertSee('API access is not currently available')->assertDontSee('MCP');
    config(['customer_api.v2_enabled' => true]);
    $this->get('/llms.txt')->assertSee('API access on eligible configured paid plans')->assertDontSee('MCP');
    config(['mcp.enabled' => true]);
    $this->get('/llms.txt')->assertSee('MCP connects compatible AI clients');
    $this->get('/en')->assertOk()->assertSee('ChatGPT')->assertSee('Claude');
});

it('serves a sitemap containing only real localized public pages', function () {
    $response = $this->get('/sitemap.xml')->assertOk();
    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    expect($xml->url)->toHaveCount(45);
    foreach ($xml->url as $node) {
        $path = parse_url((string) $node->loc, PHP_URL_PATH);
        expect($path)->not->toContain('app-v2', '/api', '/mcp', 'translation');
        $this->get($path)->assertOk();
    }
});

it('keeps prices authoritative and derives public features from channel-specific access', function () {
    $plan = ServicePlan::create(['code' => 'student', 'name' => 'Student', 'is_active' => true, 'is_free' => false,
        'price_iqd_monthly' => 12345, 'price_iqd_yearly' => 135790, 'app_monthly_credits' => 123,
        'api_monthly_credits' => 456, 'api_enabled' => true, 'api_requests_per_minute' => 30,
        'api_allowed_tools' => ['v2:speech'], 'ui_features' => ['features' => ['Translation: 500 seconds', 'All tools included']]]);
    $action = ToolAction::where('full_code', 'xomni-v2.generate')->firstOrFail();
    PlanEntitlement::create(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'all', 'allowed' => true]);
    PlanEntitlement::create(['service_plan_id' => $plan->id, 'tool_action_id' => $action->id, 'entitlement_channel' => 'api', 'allowed' => false]);
    config(['customer_api.v2_enabled' => true, 'mcp.enabled' => true]);
    $result = app(LandingPricingCatalog::class)->servicePlans()[0];
    expect($result['price_iqd_monthly'])->toBe(12345)->and($result['price_iqd_yearly'])->toBe(135790);
    expect(implode(' ', $result['features']))->toContain('Apollo 2.0')->not->toContain('Translation', 'All tools', 'API access', 'MCP connections');
    PlanEntitlement::where('entitlement_channel', 'api')->first()->update(['allowed' => true]);
    expect(implode(' ', app(LandingPricingCatalog::class)->servicePlans()[0]['features']))->toContain('API access', 'MCP connections', '456');
    expect($plan->fresh()->price_iqd_monthly)->toBe('12345');
});

it('filters demos by recorded active product without fabricating or relabeling samples', function () {
    $demo = app(PublicDemoCatalog::class)->filter('tts', ['groups' => [
        ['engine' => 'xtts', 'samples' => [['audio' => 'landing/demos/old.wav', 'label' => 'Old']]],
        ['engine' => 'xomni', 'samples' => [['audio' => 'landing/demos/real.wav', 'label' => 'Recorded sample']]],
        ['product' => 'apollo-2', 'samples' => [
            ['audio' => 'landing/demos/current.wav', 'label' => 'Second', 'sort_order' => 2],
            ['audio' => 'landing/demos/first.wav', 'label' => 'First', 'sort_order' => 1],
            ['audio' => 'landing/demos/hidden.wav', 'label' => 'Hidden', 'is_active' => false],
        ]],
    ]]);
    expect($demo['items'])->toHaveCount(3);
    expect($demo['groups'][0]['label'])->toBe('Apollo 1.5');
    expect($demo['groups'][1]['samples'][0]['label'])->toBe('First');
    ToolAction::where('full_code', 'xomni-v2.generate')->first()->update(['is_active' => false]);
    expect(app(PublicDemoCatalog::class)->filter('tts', $demo)['items'])->toHaveCount(1);
});

it('supports multiple OCR examples and STEM 2 instrumental output', function () {
    $ocr = app(PublicDemoCatalog::class)->filter('ocr', ['groups' => [['product' => 'scanner', 'samples' => [
        ['image' => 'landing/demos/one.png', 'extracted_text' => 'one'],
        ['image' => 'landing/demos/two.png', 'extracted_text' => 'two'],
    ]]]]);
    expect($ocr['items'])->toHaveCount(2);
    expect(app(PublicDemoCatalog::class)->filter('ocr', $ocr)['items'])->toEqual($ocr['items']);
    $stem = app(PublicDemoCatalog::class)->filter('stem', ['items' => [['product' => '2-stem', 'stems' => ['vocals' => 'landing/demos/v.wav', 'instrumental' => 'landing/demos/i.wav']]]]);
    expect($stem['items'][0]['stems'])->toHaveKeys(['vocals', 'instrumental']);
});

it('renders multiple configured examples under the correct current product label', function (string $family, string $product, array $samples, string $label) {
    LandingToolPage::create(['slug' => $family, 'is_active' => true, 'demo_config' => [
        'groups' => [['product' => $product, 'samples' => $samples]],
    ]]);
    $this->get('/en/tools/'.$family)->assertOk()->assertSee($label)->assertSee('Sample One')->assertSee('Sample Two')
        ->assertDontSee('Apollo 1.0')->assertDontSee('Vector 1.0')->assertDontSee('Delta');
})->with([
    ['tts', 'apollo-2', [['label' => 'Sample One', 'audio' => 'landing/demos/one.wav'], ['label' => 'Sample Two', 'audio' => 'landing/demos/two.wav']], 'Apollo 2.0'],
    ['ctts', 'theta-1', [['title' => 'Sample One', 'source_audio' => 'landing/demos/source.wav', 'cloned_audio' => 'landing/demos/one.wav'], ['title' => 'Sample Two', 'cloned_audio' => 'landing/demos/two.wav']], 'Theta 1.0'],
    ['ocr', 'scanner', [['title' => 'Sample One', 'image' => 'landing/demos/one.png', 'extracted_text' => 'one'], ['title' => 'Sample Two', 'extracted_text' => 'two']], 'OCR Scanner 2.0'],
]);

it('uses the primary localized home canonical for the home alias', function () {
    $this->get('/ar/home')->assertOk()->assertSee('<link rel="canonical" href="'.route('landing.home', ['locale' => 'ar']).'">', false)
        ->assertSee('hreflang="en" href="'.route('landing.home', ['locale' => 'en']).'"', false);
});

it('does not promote OCR when only Arabic Harakat is active', function () {
    ToolAction::where('full_code', 'ocr.standard')->first()->update(['is_active' => false]);
    $this->get('/en/tools/ocr')->assertOk()->assertSee('Arabic diacritization')->assertDontSee('OCR Scanner');
    expect(app(PublicWebsiteContent::class)->entity('en'))->not->toContain('Kurdish OCR');
});

it('preserves configured localized app download information for active families', function () {
    LandingToolPage::create(['slug' => 'tts', 'is_active' => true, 'content' => ['ar' => [
        'app_download' => ['enabled' => true, 'title' => 'تطبيق الهاتف',
            'android' => ['enabled' => true, 'url' => 'https://example.com/authorized-app']],
    ]]]);
    $tool = app(LandingToolPageCatalog::class)->findForLocaleBySlug('tts', 'ar');
    expect($tool['app_download']['enabled'])->toBeTrue()
        ->and($tool['app_download']['title'])->toBe('تطبيق الهاتف')
        ->and($tool['app_download']['android']['url'])->toBe('https://example.com/authorized-app');
});

it('omits a disabled Harakat product from human and machine discovery', function () {
    ToolAction::where('full_code', 'harakat.diacritize')->firstOrFail()->update(['is_active' => false]);
    foreach (['/en', '/en/tools', '/en/tools/ocr', '/llms.txt'] as $url) {
        $this->get($url)->assertOk()->assertDontSee('Harakat');
    }
});

it('applies private indexing controls without blocking public rendering assets', function () {
    $this->get(route('app.signin'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get('/en')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    $robots = file_get_contents(public_path('robots.txt'));
    expect($robots)->not->toContain("Disallow: /app/\n", "Disallow: /livewire/\n");
});

it('keeps repeated catalog reads bounded and excludes demo payloads from index reads', function () {
    LandingToolPage::create(['slug' => 'tts', 'is_active' => true, 'demo_config' => ['secret_fixture' => 'not loaded by the index']]);
    app(PublicProductCatalog::class)->forget();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    app(PublicProductCatalog::class)->products();
    $initial = count(\Illuminate\Support\Facades\DB::getQueryLog());
    foreach (range(1, 10) as $i) {
        app(PublicProductCatalog::class)->products();
    }
    expect(count(\Illuminate\Support\Facades\DB::getQueryLog()))->toBe($initial)->and($initial)->toBeLessThanOrEqual(2);
    app(LandingToolPageCatalog::class)->listForLocale('en');
    foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $query) {
        expect(strtolower($query['query']))->not->toContain('demo_config', 'ml_jobs');
    }
    \Illuminate\Support\Facades\DB::disableQueryLog();
});

it('invalidates shared public cache entries when an Admin-managed action changes', function () {
    Cache::put(PublicProductCatalog::CACHE_KEY, ['stale'], 300);
    Cache::put('landing:public-pages:v2:en', ['stale'], 300);
    ToolAction::where('full_code', 'zeta.generate')->first()->update(['is_active' => false]);
    expect(Cache::has(PublicProductCatalog::CACHE_KEY))->toBeFalse()
        ->and(Cache::has('landing:public-pages:v2:en'))->toBeFalse();
    expect(app(PublicProductCatalog::class)->has('zeta-1'))->toBeFalse();
});

it('does not expose private legacy or deactivated voice previews', function () {
    $voice = \App\Models\Voice::create(['code' => 'public-fixture', 'name' => 'Fixture', 'is_active' => true,
        'is_public' => false, 'meta' => ['engine' => 'xomni', 'preview_audio' => 'metkurd_audio_data/xtts/fixture.mp3']]);
    Storage::disk('s3')->put('metkurd_audio_data/xtts/fixture.mp3', 'fixture bytes');
    $url = '/en/tools/demos/voices/public-fixture/preview?proxy=1';
    $this->get($url)->assertNotFound();
    $voice->update(['is_public' => true]);
    $this->get($url)->assertOk();
    ToolAction::where('full_code', 'xomni.generate')->first()->update(['is_active' => false]);
    $this->get($url)->assertNotFound();
    Http::assertNothingSent();
});

it('rejects conflicting demo identities and legacy content types', function () {
    $service = app(PublicDemoCatalog::class);
    expect($service->filter('tts', ['type' => 'translation', 'items' => [['product' => 'apollo-2', 'source_text' => 'old']]])['items'])->toBe([]);
    expect($service->filter('tts', ['items' => [['product' => 'apollo-2', 'action' => 'xomni.generate', 'audio' => 'landing/demos/wrong.wav']]])['items'])->toBe([]);
});

it('publishes distinct localized page titles and descriptions and complete translated public copy', function (string $locale) {
    $titles = $descriptions = [];
    foreach (['', '/tools', '/tools/tts', '/tools/ctts', '/tools/asr', '/tools/ocr', '/tools/stem', '/pricing'] as $path) {
        $html = $this->get('/'.$locale.$path)->assertOk()->getContent();
        preg_match('#<title>(.*?)</title>#s', $html, $title);
        preg_match('#<meta name="description" content="([^"]*)"#', $html, $description);
        $titles[] = $title[1];
        $descriptions[] = $description[1];
    }
    expect(array_unique($titles))->toHaveCount(8)->and(array_unique($descriptions))->toHaveCount(8);
    $english = \App\Support\AreaJsonTranslations::all('landing', 'en');
    $localized = \App\Support\AreaJsonTranslations::all('landing', $locale);
    foreach ($english as $key => $value) {
        if (str_starts_with($key, 'public.') && ! str_starts_with($value, ':')) {
            expect($localized)->toHaveKey($key);
            if ($locale !== 'en') {
                expect($localized[$key])->not->toBe($value);
            }
        }
    }
})->with(['en', 'ar', 'ku']);
