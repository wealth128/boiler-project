<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (planning/security.md, section 1).
 *
 * - X-Frame-Options SAMEORIGIN: the system can't be shown inside another site's frame.
 * - X-Content-Type-Options nosniff: a browser never guesses a file's type.
 * - Referrer-Policy same-origin: no address of this system is sent to another site.
 * - Content-Security-Policy: the strictest policy that works with Inertia and the
 *   built Vite files. Scripts run only from this server and the one inline script in
 *   resources/views/app.blade.php, which carries a new random nonce on every response,
 *   so injected script text can't run. Styles allow 'unsafe-inline' because the
 *   interface sets style attributes at run time (this does not allow scripts).
 *   Everything loads from this server: no CDN, no external fonts (the app has no
 *   internet). img-src also allows data: and blob: for the logo cropper preview.
 *
 * - Strict-Transport-Security, only when the request came over HTTPS (a browser
 *   ignores it over plain HTTP, and the hospital network may stay on HTTP). It
 *   is ready if the hospital chooses HTTPS: one year, this host only.
 *
 * A response that already set its own Content-Security-Policy (the logo and
 * signature pictures send a stricter one) keeps it.
 *
 * While "npm run dev" runs (the public/hot file exists, development only), the
 * Vite server is added to the policy so the page still loads.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // A new nonce for this response, picked up by @vite and by the inline script in app.blade.php.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::policy($nonce, self::devServer()));
        }

        return $response;
    }

    /** The policy text for one response. $devServer is the Vite dev server address, or null. */
    public static function policy(string $nonce, ?string $devServer = null): string
    {
        $dev = $devServer === null ? '' : ' '.$devServer.' '.preg_replace('#^http#', 'ws', $devServer);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$dev,
            "style-src 'self' 'unsafe-inline'".$dev,
            "img-src 'self' data: blob:",
            "font-src 'self' data:".$dev,
            "connect-src 'self'".$dev,
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    /** Only in development: the address "npm run dev" writes to public/hot. Never in production. */
    private static function devServer(): ?string
    {
        if (app()->isProduction() || ! Vite::isRunningHot()) {
            return null;
        }

        $url = @file_get_contents(Vite::hotFile());

        if ($url === false || ! preg_match('#^(https?://[^/\s]+)#', trim($url), $match)) {
            return null;
        }

        return $match[1];
    }
}
