<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Controller;
use App\Http\Requests\Encode\StoreVisitRequest;
use App\Http\Requests\Encode\UpdateVisitRequest;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        $patientId = $request->validated('patient_id');

        // A new patient the encoder did not confirm as a different person
        // ("save as new") goes through the duplicate guard. The guard in
        // StoreVisitRequest runs before the save, so two encoders saving the
        // same new patient at the same moment would both pass it. Saving runs
        // under a lock for that name and birthdate and checks again inside,
        // so the second one gets the same duplicate message.
        $guarded = $patientId === null && ! $request->boolean('confirm_new_patient');
        $lock = $guarded ? $this->lockNewPatient($request) : null;

        try {
            [$patient, $visit] = DB::transaction(function () use ($request, $user, $patientId, $guarded): array {
                if ($patientId !== null) {
                    $patient = Patient::query()->lockForUpdate()->findOrFail($patientId);
                    $patient->update($request->patientAttributes());
                } else {
                    if ($guarded && Patient::identityExists(
                        (string) $request->validated('last_name'),
                        (string) $request->validated('first_name'),
                        (string) $request->validated('birthdate'),
                    )) {
                        throw ValidationException::withMessages(['duplicate_patient' => StoreVisitRequest::DUPLICATE_MESSAGE]);
                    }

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
        } finally {
            $lock?->release();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Saved {$patient->patient_no} · {$patient->full_name} · {$visit->category->value}",
        ]);

        return to_route('encode.index');
    }

    /**
     * The lock key for a new patient. The name is compared the way MySQL
     * compares it in the duplicate check (utf8mb4_unicode_ci: case and accents
     * ignored), so "Peña", "PEÑA" and "Pena" share one lock; extra spaces are
     * collapsed. Different spellings that MySQL treats as equal must never get
     * different locks, or two saves at the same moment could both pass.
     */
    public static function newPatientLockKey(string $lastName, string $firstName, string $birthdate): string
    {
        $part = fn (string $value): string => Str::of($value)->ascii()->lower()->squish()->value();

        return 'new-patient:'.sha1($part($lastName).'|'.$part($firstName).'|'.trim($birthdate));
    }

    /**
     * Take the lock for a new patient's name and birthdate (case, accents and extra
     * spaces ignored), waiting a few seconds for another save of the same
     * person. If it is still busy, the duplicate message is shown: the other
     * save is very likely the same person.
     */
    private function lockNewPatient(StoreVisitRequest $request): Lock
    {
        $key = self::newPatientLockKey((string) $request->validated('last_name'), (string) $request->validated('first_name'), (string) $request->validated('birthdate'));
        $lock = Cache::lock($key, 30);

        try {
            $lock->block((int) config('census.lock_wait_seconds'));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['duplicate_patient' => StoreVisitRequest::DUPLICATE_MESSAGE]);
        }

        return $lock;
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
