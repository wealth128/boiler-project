<?php

use App\Http\Middleware\EndSessionAtDailyCutoff;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // After Inertia, so its redirect to /login works on form submits.
            EndSessionAtDailyCutoff::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Friendly error pages (pages/errors/error.tsx) instead of Laravel's
        // plain ones. 500 and 503 keep Laravel's debug page while APP_DEBUG
        // is on, so developers still see the error. JSON requests (the
        // patient search) keep their JSON answer.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $friendly = [403, 404, 419, 500, 503];

            if (! in_array($status, $friendly, true) || $request->expectsJson()) {
                return $response;
            }

            if (in_array($status, [500, 503], true) && config('app.debug')) {
                return $response;
            }

            // Session expired (old CSRF token): back to the form with a toast.
            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'The page expired. Please try again.']);

                return back();
            }

            // Only a 403 reason from our own Policies is shown, e.g. "This
            // month is closed...". Laravel's generic denial text is dropped.
            // A 404 message can name tables and IDs, and a 500 message can
            // hold anything, so those are never shown.
            $message = $status === 403 ? $e->getMessage() : '';

            if ($message === 'This action is unauthorized.') {
                $message = '';
            }

            return Inertia::render('errors/error', [
                'status' => $status,
                'message' => $message !== '' ? $message : null,
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
