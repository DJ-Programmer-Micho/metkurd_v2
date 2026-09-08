<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\Gate;

/** Request-local display hints only. Final mutations always reauthorize through P0. */
final class AdminUiAccess
{
    public static function can(string $capability): bool
    {
        $user = auth('admin')->user();
        if (! $user) {
            return false;
        }
        $key = 'admin-ui-capability.'.$user->getAuthIdentifier().'.'.$capability;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, Gate::forUser($user)->allows($capability));
        }

        return (bool) request()->attributes->get($key);
    }
}
