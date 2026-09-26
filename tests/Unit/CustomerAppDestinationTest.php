<?php

use App\Support\CustomerAppDestination as Destination;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['app.url' => 'http://localhost', 'customer_app.v1_enabled' => true, 'metkurd_v2.enabled' => true]);
    Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
});

it('rejects unsafe or non customer intended destinations', function ($url) {
    expect(Destination::intended($url))->toBeNull();
})->with([
    'https://evil.test/en/app-v2', '//evil.test/en/app-v2', 'http://localhost.evil.test/en/app-v2',
    'http://localhost@evil.test/en/app-v2', 'http://evil.test@localhost/en/app-v2',
    'http://localhost:81/en/app-v2', 'https://localhost/en/app-v2', 'javascript:alert(1)',
    '/\\evil.test', '/en/app-v2/../../app/signin', '/%2f%2fevil.test', '/en//app-v2',
    "/en/app-v2\r\nLocation: https://evil.test", '/app/signin', '/app/logout',
    '/forgot-password', '/app/phone', '/up', '/en/unknown', '/api/v2/voices',
]);

it('preserves local customer deep links and OAuth authorization queries', function ($path) {
    expect(Destination::intended($path))->toBe($path)
        ->and(Destination::intended('http://localhost'.$path))->toBe('http://localhost'.$path);
})->with(['/ku/app-v2/ocr/scanner?view=history', '/ar/app/profile', '/oauth/authorize?client_id=local&resource=https%3A%2F%2Flocalhost%2Fmcp']);

it('maps disabled legacy pages explicitly and leaves unknown products at home', function ($legacy, $target) {
    config(['customer_app.v1_enabled' => false]);
    expect(Destination::intended('/ku/app/'.$legacy))->toBe('http://localhost/ku/app-v2'.$target);
})->with([
    ['home', ''], ['profile', '/profile'], ['my-storage', '/storage'], ['my-billing', '/my-billing'],
    ['api', '/api'], ['subscription-plans', '/subscription-plans'], ['storage-plans', '/storage-plans'],
    ['addon-credits', '/addon-credits'], ['payments/fib/123', '/payments/fib/123'],
    ['xomni', '/text-to-speech/apollo-1'], ['clone-xomni', '/clone-text-to-speech/vector-1'],
    ['caption', '/speech-to-text/caption'], ['ocr', '/ocr/scanner'], ['stem', '/stem'], ['tran', ''], ['xtts', ''],
]);

it('falls back from a disabled V2 intended page without changing API or MCP flags', function ($v1, $expected) {
    config(['customer_app.v1_enabled' => $v1, 'metkurd_v2.enabled' => false, 'customer_api.v2_enabled' => true, 'mcp.enabled' => true]);
    expect(Destination::intended('/ar/app-v2/ocr/scanner'))->toBe('http://localhost/ar'.$expected)
        ->and(config('customer_api.v2_enabled'))->toBeTrue()->and(config('mcp.enabled'))->toBeTrue();
})->with([[true, '/app/home'], [false, '']]);
