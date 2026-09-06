<?php

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class AdminAccess
{
    public const CAPABILITIES = ['admin.read', 'admin.customers', 'admin.catalog', 'admin.pricing', 'admin.finance', 'admin.reconcile'];

    public static function register(): void
    {
        foreach (self::CAPABILITIES as $capability) {
            Gate::define($capability, function (User $user) use ($capability): bool {
                $fresh = User::query()->find($user->id);

                return $fresh && (int) $fresh->status === 1
                    && ($capability === 'admin.read' || in_array($capability, $fresh->admin_capabilities ?? [], true));
            });
        }
    }

    public static function authorize(string $capability): User
    {
        $user = auth('admin')->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }
        Gate::forUser($user)->authorize($capability);

        return $user;
    }
}
