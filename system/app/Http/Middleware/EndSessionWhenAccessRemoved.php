<?php

namespace App\Http\Middleware;

use App\Actions\Fortify\AuthenticateUser;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removing access works right away (planning/rbac.md). On every request,
 * if the signed-in account has been deactivated, locked or moved to Trash
 * since login, the session ends and the user goes to the login page with
 * the same message the login form would show. Audit: "user.session_ended"
 * with the reason.
 *
 * A user in Trash is no longer loaded by the auth guard (SoftDeletes), so
 * the account is looked up from the id still stored in the session. For
 * that to work, this runs before the "auth" middleware (bootstrap/app.php,
 * priority list), which would otherwise send them to login with no message.
 * It also runs before Inertia's middleware, so it answers form submits with
 * a 303 redirect itself.
 */
class EndSessionWhenAccessRemoved
{
    public const REMOVED_MESSAGE = 'This account was removed. Ask the Admin or System Admin.';

    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        /** @var User|null $user */
        $user = $guard->user();
        $id = $guard->id(); // falls back to the id in the session when no user loads

        if ($id === null) {
            return $next($request);
        }

        $user ??= User::withTrashed()->find($id);

        [$reason, $message] = match (true) {
            $user === null => ['deleted', self::REMOVED_MESSAGE], // deleted permanently
            $user->trashed() => ['trashed', self::REMOVED_MESSAGE],
            $user->locked_at !== null => ['locked', AuthenticateUser::lockedMessage()],
            ! $user->is_active => ['deactivated', AuthenticateUser::DEACTIVATED_MESSAGE],
            default => [null, null],
        };

        if ($reason === null) {
            return $next($request);
        }

        if ($user !== null) {
            $this->audit->log('user.session_ended', $user, ['reason' => $reason], by: $user);
        }

        // Not logout(): that would also write "user.logout", which means the
        // user clicked Log out.
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        // Shown in red under the login form, like a refused login. 303 so a
        // PUT or DELETE form submit is followed with a GET.
        return redirect()->route('login', status: 303)->withErrors(['username' => $message]);
    }
}
