<?php

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

class PatientRecordController extends Controller
{
    use StubResponses;

    /**
     * Patient list with filters.
     */
    public function index(): Response
    {
        return $this->placeholder('Patient Records: Patients', 'All patients with search.');
    }

    /**
     * Move a patient to Trash (reason required).
     */
    public function destroy(Patient $patient): RedirectResponse
    {
        return $this->notBuiltYet("Delete patient {$patient->patient_no}");
    }
}
