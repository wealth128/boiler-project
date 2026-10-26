<?php

namespace App\Http\Controllers\Records;

use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Search box and "back to the same page" shared by both Records tabs.
 * (Paging: App\Services\TablePaging.)
 *
 * Search:
 *
 * - "P-000012", "p12": Patient ID contains these digits
 * - "V-000045", "v45": Visit ID contains these digits (Visits tab only)
 * - "12": Patient ID or Visit ID contains these digits
 * - anything else: the name contains the text, as "Last First",
 *   "Last, First" or "First Last" ("santos", "santos ma", "maria santos")
 */
final class RecordSearch
{
    public const MAX_LENGTH = 60;

    /**
     * The search text from the URL: trimmed, spaces collapsed, at most
     * MAX_LENGTH characters.
     */
    public static function query(Request $request): string
    {
        $q = preg_replace('/\s+/u', ' ', trim(self::param($request, 'q'))) ?? '';

        return mb_substr($q, 0, self::MAX_LENGTH);
    }

    /**
     * One value from the URL as text. A missing value, or a list such as
     * ?q[]=x, gives "".
     */
    public static function param(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /**
     * After a delete: back to the Records page the user came from, with its
     * filters and page. Only that page on this site is accepted
     * (planning/security.md, section 2, rule 5). Anything else goes to the
     * plain page.
     */
    public static function redirectBack(string $route): RedirectResponse
    {
        $plain = route($route);
        $previous = url()->previous();
        $from = parse_url($previous);
        $to = parse_url($plain);

        $same = is_array($from) && is_array($to)
            && ($from['scheme'] ?? null) === ($to['scheme'] ?? null)
            && ($from['host'] ?? null) === ($to['host'] ?? null)
            && ($from['port'] ?? null) === ($to['port'] ?? null)
            && ($from['path'] ?? null) === ($to['path'] ?? null)
            && ! isset($from['user'], $from['fragment']);

        return redirect($same ? $previous : $plain);
    }

    /**
     * @param  Builder<Visit>  $visits
     */
    public static function visits(Builder $visits, string $q): void
    {
        if ($q === '') {
            return;
        }

        [$type, $digits] = self::idSearch($q);

        // A name: the matching patients are found first, in one query over the patients
        // table, and the visits are then read through the patient index. Asked as one
        // query, MySQL would rather check the name again for every visit of the year.
        // Patients in Trash never match (the Patient query leaves them out, as whereHas did).
        $visits->where(function (Builder $where) use ($q, $type, $digits): void {
            if ($digits === null) {
                $where->whereIn('patient_id', Patient::query()->where(fn (Builder $patient) => self::nameMatches($patient, $q))->pluck('id')->all());

                return;
            }

            if ($type !== 'p') {
                $where->orWhere('visit_no', 'like', self::contains($digits));
            }

            if ($type !== 'v') {
                $where->orWhereIn('patient_id', Patient::query()->select('id')->where('patient_no', 'like', self::contains($digits)));
            }
        });
    }

    /**
     * @param  Builder<Patient>  $patients
     */
    public static function patients(Builder $patients, string $q): void
    {
        if ($q === '') {
            return;
        }

        [$type, $digits] = self::idSearch($q);

        if ($digits === null) {
            $patients->where(fn (Builder $where) => self::nameMatches($where, $q));

            return;
        }

        // A Visit ID never matches a patient.
        $type === 'v'
            ? $patients->whereRaw('1 = 0')
            : $patients->where('patient_no', 'like', self::contains($digits));
    }

    /**
     * "P-000012" gives ['p', '000012'], "45" gives [null, '45'], a name
     * gives [null, null].
     *
     * @return array{0: 'p'|'v'|null, 1: string|null}
     */
    private static function idSearch(string $q): array
    {
        if (preg_match('/^([pv])-?\s*(\d+)$/i', $q, $match)) {
            return [strtolower($match[1]) === 'p' ? 'p' : 'v', $match[2]];
        }

        if (preg_match('/^\d+$/', $q)) {
            return [null, $q];
        }

        return [null, null];
    }

    /**
     * @param  Builder<Patient>  $where
     */
    private static function nameMatches(Builder $where, string $q): void
    {
        $like = self::contains($q);

        $where->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$like])
            ->orWhereRaw("CONCAT(last_name, ', ', first_name) LIKE ?", [$like])
            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
    }

    private static function contains(string $text): string
    {
        return '%'.addcslashes($text, '\\%_').'%';
    }
}
