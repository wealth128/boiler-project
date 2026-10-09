<?php

namespace App\Http\Controllers\Trash;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Trash. {type} is one of: visits, patients, users, diagnoses, ranks,
 * age-brackets, months.
 */
class TrashController extends Controller
{
    use StubResponses;

    public function index(): Response
    {
        return $this->placeholder('Trash', 'Restore, delete permanently (type the ID to confirm), or empty Trash.');
    }

    public function restore(string $type, int $id): RedirectResponse
    {
        return $this->notBuiltYet("Restore {$type} #{$id}");
    }

    /**
     * Delete one item permanently.
     */
    public function destroy(string $type, int $id): RedirectResponse
    {
        return $this->notBuiltYet("Delete {$type} #{$id} permanently");
    }

    /**
     * Empty Trash (items that are still in use stay).
     */
    public function empty(): RedirectResponse
    {
        return $this->notBuiltYet('Empty Trash');
    }
}
