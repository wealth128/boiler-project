<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessions end in two ways only (decided 2026-10-09):
 * 1. The user clicks "Log out".
 * 2. Every day at the cutoff time (config census.session_cutoff, default
 *    20:00 Asia/Manila). Anyone who logged in before the latest cutoff is
 *    signed out on their next click and must log in again.
 *
 * There is no idle timeout: SESSION_LIFETIME is set longer than a day, so
 * the cutoff always comes first.
 *
 * The login time is saved in the session when the user logs in
 * (AppServiceProvider, Login event).
 */
class EndSessionAtDailyCutoff
{
    public const SESSION_KEY = 'auth.logged_in_at';

    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $cutoff = self::latestCutoff();

        if ($user === null || $cutoff === null) {
            return $next($request);
        }

        $loggedInAt = $request->session()->get(self::SESSION_KEY);

        // A session from before this rule existed: start counting from now.
        if (! is_int($loggedInAt)) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());

            return $next($request);
        }

        if ($loggedInAt >= $cutoff->getTimestamp()) {
            return $next($request);
        }

        $this->audit->log('user.session_ended', $user, ['cutoff' => $cutoff->format('H:i')], by: $user);

        // Not logout(): that would also write "user.logout", which means the
        // user clicked Log out.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your session ended at '.$cutoff->format('g:i A').'. Please log in again.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }

    /**
     * The most recent cutoff that has already passed, e.g. at 9:15 AM on
     * 10 Oct that is 8:00 PM on 9 Oct. Null when the cutoff is turned off.
     */
    public static function latestCutoff(): ?CarbonImmutable
    {
        $time = config('census.session_cutoff');

        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        $now = CarbonImmutable::now();
        $today = $now->setTimeFromTimeString($time);

        return $today->greaterThan($now) ? $today->subDay() : $today;
    }
}
