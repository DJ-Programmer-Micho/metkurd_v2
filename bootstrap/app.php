<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateCustomerApiKey;
use App\Http\Middleware\CheckCustomerApiConcurrency;
use App\Http\Middleware\CheckCustomerApiRateLimit;
use App\Http\Middleware\EnsureCustomerApiAccess;
use App\Http\Middleware\EnsureCustomerCanAccessTool;
use App\Http\Middleware\EnsureCustomerVerificationIsComplete;
use App\Http\Middleware\EnsureUserAppIsActive;
use App\Http\Middleware\EnsureV2DashboardEnabled;
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
            'customer.api' => AuthenticateCustomerApiKey::class,
            'customer.api.access' => EnsureCustomerApiAccess::class,
            'customer.api.rate_limit' => CheckCustomerApiRateLimit::class,
            'customer.api.concurrency' => CheckCustomerApiConcurrency::class,
            'localization.main' => LocalizationMainMiddleware::class,
            'app.active' => EnsureUserAppIsActive::class,
            'app.verified' => EnsureCustomerVerificationIsComplete::class,
            'app.tool.access' => EnsureCustomerCanAccessTool::class,
            'app.v2.enabled' => EnsureV2DashboardEnabled::class,
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
                return route('admin.home', ['locale' => app()->getLocale()]);
            }
            if (auth('app')->check()) {
                return route('app.home', ['locale' => app()->getLocale()]);
            }

            return route('app.home', ['locale' => app()->getLocale()]);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, Request $request) {
            $adminSurface = $request->routeIs('admin.*') || ($request->is('livewire/*') && auth('admin')->check());
            if ($adminSurface && ! $exception instanceof \Illuminate\Validation\ValidationException
                && ! $exception instanceof \Illuminate\Auth\AuthenticationException) {
                $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 500;
                if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    $status = 403;
                }
                $message = __('admin_p0.request_failed');

                return $request->expectsJson() || $request->is('livewire/*')
                    ? response()->json(['message' => $message], $status)->header('Cache-Control', 'private, no-store')
                    : response($message, $status)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'private, no-store');
            }
            if ($request->is('api/v2', 'api/v2/*')) {
                return app(\App\Http\Middleware\ApiV2Boundary::class)->renderException($exception);
            }
            $customerSurface = $request->is('*/app-v2', '*/app-v2/*', '*/app/*')
                || ($request->is('livewire/*') && auth('app')->check());
            if (! $customerSurface || $exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $status = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                ? $exception->getStatusCode() : 500;
            if ($status < 500 && stripos($exception->getMessage(), 'runpod') === false) {
                return null;
            }
            $message = \App\Support\CustomerFacingError::message($exception->getMessage());

            return $request->expectsJson() || $request->is('livewire/*')
                ? response()->json(['message' => $message], $status)
                : response($message, $status)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'private, no-store');
        });
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
