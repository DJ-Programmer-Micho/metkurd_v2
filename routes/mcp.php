<?php

use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\McpBoundary;
use Illuminate\Support\Facades\Route;

Route::middleware([McpBoundary::class, 'throttle:120,1'])->group(function () {
    Route::match(['GET', 'POST', 'DELETE', 'OPTIONS'], '/mcp', \App\Http\Controllers\Mcp\TransportController::class)->name('mcp.transport');
    Route::get('/mcp/files/{id}', [\App\Http\Controllers\Mcp\FileController::class, 'download'])->name('mcp.files.download');
    Route::get('/.well-known/oauth-protected-resource/mcp', fn () => response()->json(new \Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata(
        [config('mcp.issuer')], resource: config('mcp.public_url'), resourceName: 'MetKurd AI',
        metadataPaths: ['/.well-known/oauth-protected-resource/mcp'])));
    Route::get('/.well-known/oauth-authorization-server', fn () => response()->json([
        'issuer' => config('mcp.issuer'), 'authorization_endpoint' => config('mcp.issuer').'/oauth/authorize',
        'token_endpoint' => config('mcp.issuer').'/oauth/token', 'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_methods_supported' => ['none'],
        'code_challenge_methods_supported' => ['S256'],
        'client_id_metadata_document_supported' => true,
        'scopes_supported' => array_keys(\Laravel\Passport\Passport::$scopes),
    ]));
    Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:30,1')->name('passport.token');
    Route::middleware(['web', 'auth:app', 'app.active', 'app.verified'])->group(function () {
        Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->name('passport.authorizations.authorize');
        Route::post('/oauth/authorize', [OAuthController::class, 'approve'])->name('passport.authorizations.approve');
    });
});
