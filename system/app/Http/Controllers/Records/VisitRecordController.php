<?php

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class VisitRecordController extends Controller
{
    use StubResponses;

    /**
     * Visit list with filters.
     */
    public function index(): Response
    {
        return $this->placeholder('Patient Records: Visits', 'All visits with filters for date range, category, branch and search.');
    }

    /**
     * Move a visit to Trash (reason required).
     */
    public function destroy(Visit $visit): RedirectResponse
    {
        Gate::authorize('delete', $visit);

        return $this->notBuiltYet("Delete visit {$visit->visit_no}");
    }
}
