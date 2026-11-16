<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user flagged `must_change_password` (first login of the seeded admin, or an
 * account created by an administrator) may only read their session, change
 * their password and log out. The Angular guard already sends them to the
 * password screen; this enforces it on the API too, so the initial password
 * cannot be used to work around that screen.
 */
class EnsurePasswordChanged
{
    /** Endpoints still reachable before the password is changed. */
    private const ALLOWED = ['api/login', 'api/user', 'api/logout', 'api/change-password'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::ALLOWED)) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'Vous devez changer votre mot de passe avant de continuer.',
                'must_change_password' => true,
            ], 403);
        }

        return $next($request);
    }
}
