<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;

class VisitController extends Controller
{
    use StubResponses;

    /**
     * Save a visit (and the patient, if new).
     */
    public function store(): RedirectResponse
    {
        return $this->notBuiltYet('Save visit');
    }

    /**
     * Edit a visit (own same-day for encoders, any for Admin).
     */
    public function update(Visit $visit): RedirectResponse
    {
        return $this->notBuiltYet("Edit visit {$visit->visit_no}");
    }
}
