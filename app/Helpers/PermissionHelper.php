<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Route;

class PermissionHelper
{
    /**
     * Resolve the URL a user should land on after logging in.
     *
     * Walks the permission → route map (config/permission_redirects.php) and
     * returns the first route the user is allowed to reach. If the user has no
     * matching module permission, we fall back to the dashboard — it is gated
     * by `auth` only (every authenticated user may view it), so it is always a
     * safe landing page.
     *
     * Previously this returned route('no-permission'), a route that is not
     * defined anywhere, which threw RouteNotFoundException on the login POST
     * (500 error) and left under-permissioned users unable to reach the app.
     */
    public static function firstAllowedRoute($user)
    {
        // No authenticated user → back to the login screen.
        if (!$user) {
            return route('login');
        }

        $map = config('permission_redirects', []);

        foreach ($map as $permission => $route) {
            // Skip any mapped route that no longer exists so a stale config
            // entry can never crash the login redirect.
            if ($user->can($permission) && Route::has($route)) {
                return route($route);
            }
        }

        // Fallback: the dashboard is reachable by every authenticated user.
        if (Route::has('dashboard.index')) {
            return route('dashboard.index');
        }

        // Last-resort safety net so login can never 500.
        return route('login');
    }
}
