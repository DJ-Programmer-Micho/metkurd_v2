<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCustomerCanAccessTool
{
    public function handle(Request $request, Closure $next, string ...$guards)
    {
        $customer = $request->user('app');

        abort_unless($customer, 403, 'You do not have access to this service.');

        $mode = 'all';

        if (! empty($guards) && in_array(strtolower($guards[0]), ['any', 'all'], true)) {
            $mode = strtolower(array_shift($guards));
        }

        $guards = collect($guards)
            ->map(fn ($guard) => strtolower(trim((string) $guard)))
            ->filter()
            ->values();

        abort_if($guards->isEmpty(), 403, 'You do not have access to this service.');

        $allowed = $mode === 'any'
            ? $guards->contains(fn (string $guard) => $this->passesGuard($customer, $guard))
            : $guards->every(fn (string $guard) => $this->passesGuard($customer, $guard));

        abort_unless($allowed, 403, 'You do not have access to this service.');

        return $next($request);
    }

    protected function passesGuard($customer, string $guard): bool
    {
        return str_contains($guard, '.')
            ? $customer->isAllowed($guard)
            : $customer->canAccessTool($guard);
    }
}
