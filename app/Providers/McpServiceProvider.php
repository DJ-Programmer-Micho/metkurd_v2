<?php

namespace App\Providers;

use App\Services\Mcp\OAuth\AccessToken;
use App\Services\Mcp\OAuth\ScopeRepository;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->register(\Laravel\Passport\PassportServiceProvider::class);
        $this->app->bind(\Laravel\Passport\Bridge\ScopeRepository::class, ScopeRepository::class);
        $this->app->bind(\Laravel\Passport\ClientRepository::class, \App\Services\Mcp\OAuth\ClientRepository::class);
    }

    public function boot(): void
    {
        Passport::$accessTokenEntity = AccessToken::class;
        Passport::tokensExpireIn(now()->addMinutes(max(1, min(30, config('mcp.token_minutes')))));
        Passport::refreshTokensExpireIn(now()->addDays(max(1, min(90, config('mcp.refresh_days')))));
        $scopes = [...app(\App\Services\CustomerApi\V2\ApiCatalog::class)->serviceScopes(), 'v2:jobs:read', 'v2:files:download', 'mcp:uploads'];
        Passport::tokensCan(array_combine($scopes, $scopes));
        Passport::authorizationView('app.v2.mcp.consent');
        $this->loadRoutesFrom(base_path('routes/mcp.php'));
        $this->commands([\App\Console\Commands\RegisterMcpClient::class, \App\Console\Commands\McpReadiness::class]);
    }
}
