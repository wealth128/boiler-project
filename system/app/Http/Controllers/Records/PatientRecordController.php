<?php

namespace App\Http\Controllers\Records;

use App\Enums\PatientDeleteReason;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Services\TablePaging;
use App\Services\TrashService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient Records, Patients tab (prototype/prototype.html, "Patient
 * Records"). Admin only (role:admin on the route).
 *
 * Search by name or Patient ID (?q=&page=), sorted by last name, 50 rows
 * per page. Patients in Trash never show. "Visits" counts only visits not
 * in Trash.
 *
 * Delete moves the patient to Trash through TrashService, only after all
 * their visits are in Trash (TrashPolicy). The audit entry
 * "patient.deleted" comes from AuditObserver.
 */
class PatientRecordController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        Gate::authorize(Permission::ViewRecords->value);

        $q = RecordSearch::query($request);

        $query = Patient::query()->withCount('visits');
        RecordSearch::patients($query, $q);

        // Ids first, then the rows of the page with their visit counts (TablePaging::paginate).
        $page = TablePaging::paginate($query->orderBy('last_name')->orderBy('first_name')->orderBy('id'), $request)
            ->withQueryString();

        if ($redirect = TablePaging::redirectPastLastPage($request, $page)) {
            return $redirect;
        }

        return Inertia::render('records/patients', [
            'filters' => ['q' => $q],
            'patients' => [
                'data' => collect($page->items())
                    ->map(fn (Patient $patient): array => [
                        'id' => $patient->id,
                        'patient_no' => $patient->patient_no,
                        'patient_name' => $patient->full_name,
                        'sex' => $patient->sex->value,
                        'birthdate' => $patient->birthdate->format('Y-m-d'),
                        'visit_count' => (int) $patient->visits_count,
                    ])
                    ->values(),
                'meta' => TablePaging::meta($page),
            ],
            'deleteReasons' => PatientDeleteReason::options(),
        ]);
    }

    /**
     * Move a patient to Trash with a reason from PatientDeleteReason. Refused while they still
     * have visits that are not in Trash; the message shows in the
     * "Move to Trash?" row, as in the prototype.
     */
    public function destroy(Request $request, Patient $patient, TrashService $trash): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $trash->moveToTrash($patient, $actor, $request->input('reason'), $request->input('reason_other'));
        } catch (AuthorizationException $e) {
            // A refusal with a reason ("Move this patient's visits to Trash
            // first.") shows in the row. Anything else is a plain 403.
            // Gate refusals always carry their Response (Response::authorize()).
            $message = $e->response()->message();

            if ($message === null) {
                throw $e;
            }

            throw ValidationException::withMessages(['item' => $message]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved {$patient->patient_no} to Trash"]);

        return RecordSearch::redirectBack('records.patients');
    }
}
