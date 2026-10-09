<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Dropdown lists. {list} is one of: diagnoses, ranks, age-brackets.
 */
class ListController extends Controller
{
    use StubResponses;

    public function index(): Response
    {
        return $this->placeholder('Lists', 'Diagnoses, ranks per branch, "Others" types and age brackets.');
    }

    public function store(string $list): RedirectResponse
    {
        return $this->notBuiltYet("Add to {$list}");
    }

    public function update(string $list, int $id): RedirectResponse
    {
        return $this->notBuiltYet("Edit {$list} #{$id}");
    }

    public function destroy(string $list, int $id): RedirectResponse
    {
        return $this->notBuiltYet("Delete {$list} #{$id}");
    }
}
