<?php

namespace App\Exports\Import;

use App\Services\Import\ImportColumns;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * The downtime template (Records > Import > "Download template"): the file
 * the offices fill in while the system is down. Four sheets:
 * "How to fill", "Visits" (what the import reads), "Example" and "Lists".
 * The lists are the ones in the system right now.
 */
class DowntimeTemplate implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(private readonly TemplateLists $lists, private readonly CarbonInterface $downloadedAt) {}

    public function sheets(): array
    {
        return [
            new HowToFillSheet($this->downloadedAt),
            new VisitsSheet('Visits', $this->lists, [], ImportColumns::MAX_ROWS + 1),
            new VisitsSheet('Example', $this->lists, $this->exampleRows(), 10),
            new ListsSheet($this->lists),
        ];
    }

    /**
     * Four made-up visits, using names that exist in the lists right now so
     * that they would pass the import's checks.
     *
     * @return list<list<string|float|null>>
     */
    private function exampleRows(): array
    {
        $first = fn (array $items, int $n = 0): ?string => $items[$n] ?? null;
        $other = 'Other (specify)';

        $branches = $this->lists->branches;
        $rankOf = fn (?string $branch, int $n = 0): ?string => $branch === null ? null : $first($this->lists->ranksByBranch[$branch] ?? [], $n);
        $listed = array_values(array_filter($this->lists->diagnoses, fn (string $name): bool => $name !== $other));
        $others = $branches !== [] ? $branches[count($branches) - 1] : null;
        $day = fn (int $daysAgo): float => Date::PHPToExcel($this->downloadedAt->startOfDay()->subDays($daysAgo)->toDateTime());

        return [
            [$day(2), '08:15', null, 'DELA CRUZ', 'JUAN', 'R', 'Male', Date::PHPToExcel('1988-05-14'), null, $first($branches), $rankOf($first($branches)), null, $first($listed), null, 'ER', 'BP 160/100 on arrival', 'Nurse A. Ramos'],
            [$day(2), '09:40', 'P-000012', 'SANTOS', 'MARIA', 'L', 'Female', Date::PHPToExcel('1975-02-02'), null, $others, $rankOf($others), null, $other, 'Migraine', 'ER', null, 'Nurse A. Ramos'],
            [$day(2), '14:05', null, 'REYES', 'PEDRO', null, 'Male', Date::PHPToExcel('1960-11-30'), null, $others, $other, 'Retired PNP', $first($listed, 1), null, 'Admission', 'From ER, admitted to ward', 'Nurse B. Cruz'],
            [$day(1), '02:30', null, 'GARCIA', 'ANA', 'M', 'Female', Date::PHPToExcel('2019-07-21'), null, $others, $rankOf($others), null, $first($listed, 2), null, 'ER', 'Child of a Navy officer', 'Nurse B. Cruz'],
        ];
    }
}
