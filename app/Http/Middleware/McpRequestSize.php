<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Bound machine requests before Laravel's global input normalization parses JSON. */
class McpRequestSize
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('mcp', 'oauth/token')) {
            $limit = (int) config('mcp.max_body_bytes');
            if ((int) $request->header('Content-Length', 0) > $limit || strlen($request->getContent()) > $limit) {
                return response()->json(['error' => 'request_too_large'], 413)->header('Cache-Control', 'no-store');
            }
        }

        return $next($request);
    }
}
