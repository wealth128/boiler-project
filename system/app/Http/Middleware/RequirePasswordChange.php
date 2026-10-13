<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary passwords (planning/rbac.md): a user whose password was set by
 * Admin, System Admin or IT (users.must_change_password) can only open
 * "Choose your new password" and Log out until they set their own.
 * Every other page sends them back to that page.
 */
class RequirePasswordChange
{
    /** Routes open while the password is temporary. */
    public const ALLOWED_ROUTES = ['password.choose', 'password.choose.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Choose your new password first.'], 403);
        }

        return redirect()->route('password.choose');
    }
}
