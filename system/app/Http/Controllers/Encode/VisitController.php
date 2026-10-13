<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Controller;
use App\Http\Requests\Encode\StoreVisitRequest;
use App\Http\Requests\Encode\UpdateVisitRequest;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Saves the encoding form (prototype/prototype.html, "Encode Patient").
 * Checks are in StoreVisitRequest / UpdateVisitRequest and VisitPolicy.
 * Audit entries (patient.created / .updated, visit.created / .updated)
 * come from AuditObserver.
 */
class VisitController extends Controller
{
    /**
     * "Save & New": create the patient if new, or update the returning
     * patient's name, M.I., sex and birthdate (which corrects all their
     * visits), then create the visit stamped with the server time.
     */
    public function store(StoreVisitRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$patient, $visit] = DB::transaction(function () use ($request, $user): array {
            $patientId = $request->validated('patient_id');

            if ($patientId !== null) {
                $patient = Patient::query()->lockForUpdate()->findOrFail($patientId);
                $patient->update($request->patientAttributes());
            } else {
                $patient = Patient::create($request->patientAttributes());
            }

            $visit = Visit::create([
                ...$request->visitAttributes(),
                'patient_id' => $patient->id,
                'visited_at' => now(),
                'encoded_by' => $user->id,
            ]);

            return [$patient, $visit];
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Saved {$patient->patient_no} · {$patient->full_name} · {$visit->category->value}",
        ]);

        return to_route('encode.index');
    }

    /**
     * "Save changes". The visit keeps its date, time and encoder; the
     * patient's details are corrected for all their visits.
     */
    public function update(UpdateVisitRequest $request, Visit $visit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $visit, $user): void {
            /** @var Patient $patient */
            $patient = $visit->patient;
            $patient->update($request->patientAttributes());

            $visit->fill($request->visitAttributes());

            if ($visit->isDirty()) {
                $visit->updated_by = $user->id;
                $visit->save();
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Changes saved to {$visit->visit_no}"]);

        // Opened from Patient Records: back to the same filters and page.
        $returnTo = ReturnToRecords::from($request->input('return_to'), $user);

        return $returnTo !== null ? redirect($returnTo) : to_route('encode.index');
    }
}
