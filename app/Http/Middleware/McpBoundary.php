<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class McpBoundary
{
    public function renderException(Request $request, \Throwable $exception)
    {
        $browser = $request->is('oauth/authorize', '*/app-v2/mcp/uploads/*');
        if ($browser && ($exception instanceof \Illuminate\Auth\AuthenticationException || $exception instanceof \Illuminate\Validation\ValidationException)) {
            return null; // Preserve the existing browser login and validation redirect lifecycle.
        }
        $status = $exception instanceof \App\Services\CustomerApi\V2\ApiProblem ? $exception->status
            : ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getStatusCode() : 503);
        $code = $exception instanceof \App\Services\CustomerApi\V2\ApiProblem ? $exception->errorCode : 'service_unavailable';
        if ($browser && ! $request->expectsJson()) {
            $key = in_array($code, ['paid_plan_required', 'api_access_unavailable'], true)
                ? ($code === 'paid_plan_required' ? 'paid_only' : 'configuration_unavailable') : 'authorization_error';

            return response()->view('app.v2.mcp.error', ['message' => __('mcp.'.$key)], $status)->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['error' => $code], $status)->header('Cache-Control', 'private, no-store');
    }

    public function handle(Request $request, Closure $next)
    {
        if (! config('mcp.enabled')) {
            return response()->json(['error' => 'service_unavailable'], 503);
        }
        $public = parse_url(config('mcp.public_url'));
        $authority = 'https://'.($public['host'] ?? '').(isset($public['port']) ? ':'.$public['port'] : '');
        if (config('mcp.public_url') !== $authority.'/mcp' || config('mcp.issuer') !== $authority
            || (app()->environment('production') && ! in_array(config('cache.stores.'.config('mcp.session_store').'.driver'), ['redis', 'database', 'memcached'], true))) {
            return response()->json(['error' => 'service_unavailable'], 503);
        }
        $origin = $request->header('Origin');
        if (($public['scheme'] ?? '') !== 'https' || $request->getSchemeAndHttpHost() !== 'https://'.($public['host'] ?? '').(isset($public['port']) ? ':'.$public['port'] : '')
            || ($origin !== null && ! in_array($origin, config('mcp.origins'), true))) {
            return response()->json(['error' => 'invalid_origin'], 403);
        }
        if ((int) $request->header('Content-Length', 0) > config('mcp.max_body_bytes') && ! $request->is('*/app-v2/mcp/uploads/*')) {
            return response()->json(['error' => 'request_too_large'], 413);
        }
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        } catch (\App\Services\CustomerApi\V2\ApiProblem $e) {
            return response()->json(['error' => $e->errorCode], $e->status);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['error' => 'access_unavailable'], $e->getStatusCode());
        } catch (\Throwable $e) {
            // Neither arguments, OAuth credentials nor underlying exception bodies enter logs/responses.
            return response()->json(['error' => 'service_unavailable'], 503);
        }
    }
}
