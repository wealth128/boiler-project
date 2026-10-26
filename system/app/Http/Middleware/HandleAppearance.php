<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Light, Dark or System; anything else (a edited cookie) is Light, the default.
        $choice = $request->cookie('appearance');

        View::share('appearance', in_array($choice, ['light', 'dark', 'system'], true) ? $choice : 'light');

        return $next($request);
    }
}
