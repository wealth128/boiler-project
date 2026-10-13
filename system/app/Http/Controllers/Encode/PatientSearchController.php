<?php

namespace App\Http\Controllers\Encode;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Find returning patient" on the Encode screen. The only JSON endpoint in
 * the app: GET /patients/search?q=
 *
 * Matches the start of the last name ("Santos", or "Santos Ma" for last and
 * first name), or the Patient ID ("P-000012", "12"). Patients in Trash are
 * never returned. At most 10 results, sorted by name.
 *
 * Each result carries the branch and rank of the patient's latest visit
 * (not in Trash), so the form can fill them in.
 */
class PatientSearchController extends Controller
{
    public const MIN_LENGTH = 2;

    public const LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return response()->json(['query' => $query, 'data' => []]);
        }

        $patients = Patient::query()
            ->where(fn (Builder $where) => $this->match($where, $query))
            ->with('latestVisit')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'query' => $query,
            'data' => $patients->map(fn (Patient $patient): array => [
                'id' => $patient->id,
                'patient_no' => $patient->patient_no,
                'last_name' => $patient->last_name,
                'first_name' => $patient->first_name,
                'middle_initial' => $patient->middle_initial,
                'sex' => $patient->sex->value,
                'birthdate' => $patient->birthdate->format('Y-m-d'),
                'full_name' => $patient->full_name,
                'last_visit' => $patient->latestVisit ? [
                    'branch_id' => $patient->latestVisit->branch_id,
                    'rank_id' => $patient->latestVisit->rank_id,
                    'rank_other' => $patient->latestVisit->rank_other,
                ] : null,
            ])->values(),
        ]);
    }

    /**
     * @param  Builder<Patient>  $where
     */
    private function match(Builder $where, string $query): void
    {
        // Patient ID: "P-000012", "p12", "000012" or "12".
        if (preg_match('/^(?:p-?)?(\d+)$/i', $query, $digits)) {
            $where->where('patient_no', 'like', '%'.$digits[1].'%');

            return;
        }

        $prefix = addcslashes(preg_replace('/\s+/', ' ', $query), '\\%_').'%';

        $where->where('last_name', 'like', $prefix)
            ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$prefix])
            ->orWhereRaw("CONCAT(last_name, ', ', first_name) LIKE ?", [$prefix]);
    }
}
