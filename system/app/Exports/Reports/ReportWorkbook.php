<?php

namespace App\Exports\Reports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The report as an Excel file: a "Summary" sheet, then one sheet for each
 * category included (OPD, ER, Admission). Built from the same resolved
 * report as the screen and the PDF; nothing is counted here.
 */
class ReportWorkbook implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $sheet
     * @param  array{month_label: string, taken_at: CarbonInterface}|null  $snapshot
     */
    public function __construct(
        private readonly array $report,
        private readonly array $sheet,
        private readonly ?array $snapshot,
        private readonly CarbonInterface $generatedAt,
    ) {}

    public function sheets(): array
    {
        $sheets = [new SummarySheet($this->report, $this->sheet, $this->snapshot, $this->generatedAt)];

        foreach ($this->report['categories'] as $name => $data) {
            $sheets[] = new CategorySheet($name, $data);
        }

        return $sheets;
    }
}
