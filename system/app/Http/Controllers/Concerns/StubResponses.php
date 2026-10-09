<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TEMPORARY (Step 1): placeholder responses for routes whose feature is not
 * built yet. Remove this trait once every controller has real code.
 */
trait StubResponses
{
    /**
     * Show the shared placeholder page for a screen that is not built yet.
     */
    protected function placeholder(string $title, string $description): Response
    {
        return Inertia::render('placeholder', [
            'title' => $title,
            'description' => $description,
        ]);
    }

    /**
     * Answer a form action that is not built yet with a toast.
     */
    protected function notBuiltYet(string $action): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'info',
            'message' => "{$action}: not built yet.",
        ]);

        return back();
    }
}
