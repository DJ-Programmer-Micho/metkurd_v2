<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureCustomerVerificationIsComplete;
use App\Http\Middleware\EnsureCustomerCanAccessTool;
use App\Http\Middleware\EnsureUserAppIsActive;
use App\Http\Middleware\LocalizationMainMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '**'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->alias([
            'auth' => Authenticate::class,
            'localization.main' => LocalizationMainMiddleware::class,
            'app.active' => EnsureUserAppIsActive::class,
            'app.verified' => EnsureCustomerVerificationIsComplete::class,
            'app.tool.access' => EnsureCustomerCanAccessTool::class,
        ]);

        // $middleware->redirectGuestsTo(function ($request) {
        //     if ($request->is('admin-met-kurd/*') || $request->routeIs('admin-met-kurd.*')) {
        //         return route('admin.signin');
        //     }
        //     return route('app.signin');
        // });
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('app/*') || $request->routeIs('app.*')) {
                return route('app.signin');
            }
            return route('app.signin');
        });

        $middleware->redirectUsersTo(function ($request) {
            if (auth('admin')->check()) {
                return route('admin.home',['locale' => app()->getLocale()]);
            }
            if (auth('app')->check()) {
                return route('app.home',['locale' => app()->getLocale()]);
            }
            return route('app.home',['locale' => app()->getLocale()]);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
