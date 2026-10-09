<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route rule "role:admin,encoder": only the listed roles may open the route.
 * Everyone else gets the 403 page. Put it after "auth", which sends signed-out
 * users to the login page first.
 *
 * The role lists in routes/web.php follow planning/rbac.md and match the
 * Gates in App\Enums\Permission.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($roles === []) {
            throw new InvalidArgumentException('The role middleware needs at least one role, e.g. role:admin.');
        }

        $allowed = array_map(fn (string $role) => Role::from($role), $roles);

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || ! $user->hasRole(...$allowed)) {
            abort(403);
        }

        return $next($request);
    }
}
