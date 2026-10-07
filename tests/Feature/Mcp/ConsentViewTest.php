<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->withoutVite();
});

it('renders readable localized consent with the unchanged approval form and complete permissions', function (string $locale, bool $metadata) {
    app()->setLocale($locale);
    $scopeIds = ['v2:speech', 'v2:voice-clone', 'v2:transcriptions', 'v2:captions', 'v2:ocr', 'v2:stem', 'v2:harakat', 'v2:jobs:read', 'v2:files:download', 'mcp:uploads'];
    $clientId = 'https://client.example.test/'.str_repeat('long-client-identity-', 8).'client.json';
    $html = view('app.v2.mcp.consent', [
        'client' => (object) ['name' => 'ChatGPT', 'id' => $clientId, 'mcp_metadata_hash' => $metadata ? 'fixture' : null],
        'scopes' => array_map(fn ($id) => (object) ['id' => $id], $scopeIds),
        'authToken' => 'synthetic-consent-token',
    ])->render();
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($dom);
    expect($dom->documentElement->getAttribute('dir'))->toBe($locale === 'en' ? 'ltr' : 'rtl')
        ->and($dom->documentElement->getAttribute('lang'))->toBe($locale)
        ->and($xpath->query('//ul[@class="consent-scopes"]/li')->length)->toBe(10);
    foreach ($scopeIds as $id) {
        expect($html)->toContain($id);
    }
    foreach (['connect', 'consent', 'permissions', 'requesting_app', 'client_identity', 'credits_title', 'credits', 'approve', 'deny'] as $key) {
        expect(__('mcp.'.$key))->not->toBe('mcp.'.$key);
    }
    expect($html)->toContain(__('mcp.credits'), __('mcp.requesting_app'))
        ->not->toContain('app/css/app.min.css', 'text-light', 'bg-dark');
    expect($xpath->query('//div[@class="consent-client-id"]/code')->length)->toBe($metadata ? 1 : 0);
    if ($metadata) {
        expect($xpath->query('//div[@class="consent-client-id"]/code')->item(0)->textContent)->toBe($clientId);
    }
    $form = $xpath->query('//form')->item(0);
    expect($form->getAttribute('method'))->toBe('post')
        ->and($form->getAttribute('action'))->toBe(route('passport.authorizations.approve'))
        ->and($xpath->query('//input[@name="_token"]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="auth_token"]')->item(0)->getAttribute('value'))->toBe('synthetic-consent-token');
    foreach (['approve', 'deny'] as $decision) {
        expect($xpath->query('//button[@type="submit"][@name="decision"][@value="'.$decision.'"]')->length)->toBe(1);
    }
    if ($metadata && getenv('MCP_CONSENT_PREVIEW') === '1') {
        $directory = storage_path('framework/testing/consent-preview');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        // Render-only preview, with synthetic form data and no usable authorization session.
        file_put_contents($directory.'/'.$locale.'.html', preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-csrf"', $html));
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku'])->with([true, false]);

it('escapes untrusted client identity on the consent page', function () {
    $html = view('app.v2.mcp.consent', [
        'client' => (object) ['name' => '<img src=x onerror=alert(1)>', 'id' => '<script>alert(1)</script>', 'mcp_metadata_hash' => 'fixture'],
        'scopes' => [], 'authToken' => 'synthetic-consent-token',
    ])->render();
    expect($html)->toContain('&lt;img', '&lt;script&gt;')
        ->not->toContain('<img src=x', '<script>alert(1)</script>');
});
