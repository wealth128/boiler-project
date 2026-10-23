<?php

namespace App\Exports\Reports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The report as an Excel file: a "Summary" sheet, then one sheet for each
 * category included (OPD, ER, Admission), then the hidden sheet "Chart data" for the daily census charts. Built from the same resolved
 * report as the screen and the PDF; nothing is counted here.
 */
class ReportWorkbook implements Export, WithMultipleSheets
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, list<array<string, mixed>>>  $sections  the tables of each page, from ReportSheetResolver
     * @param  array<string, array<string, array<string, mixed>>>  $charts  the charts of each page, from ReportSheetResolver
     * @param  array<string, mixed>  $sheet
     * @param  array{month_label: string, taken_at: CarbonInterface}|null  $snapshot
     */
    public function __construct(
        private readonly array $report,
        private readonly array $sections,
        private readonly array $charts,
        private readonly array $sheet,
        private readonly ?array $snapshot,
        private readonly CarbonInterface $generatedAt,
        private readonly string $generatedBy,
        private readonly ?string $logoPath = null,
    ) {}

    public function sheets(): array
    {
        $meta = [
            'hospital_name' => $this->sheet['hospital_name'],
            'office' => $this->sheet['office'],
            'prepared_by' => $this->sheet['prepared_by'],
            'prepared_by_title' => $this->sheet['prepared_by_title'],
            'noted_by' => $this->sheet['noted_by'],
            'noted_by_title' => $this->sheet['noted_by_title'],
            'period_label' => $this->sheet['period_label'],
            // Exactly as on screen: "Final", or the reason it is not.
            'status' => $this->sheet['signature_note'] ?? 'Final',
            'generated' => 'Generated '.$this->generatedAt->format('d-M-Y H:i').' by '.$this->generatedBy,
        ];

        $sheets = [new SummarySheet($this->sections['summary'], $this->charts['summary'] ?? [], $this->sheet, $this->snapshot, $meta, $this->logoPath)];

        foreach ($this->report['categories'] as $name => $data) {
            $sheets[] = new CategorySheet($name, $this->sections[$name], $this->charts[$name] ?? [], $this->sheet['title'], $meta, $this->logoPath);
        }

        // The day by day figures of the daily census charts: a hidden sheet, last, so the report sheets come first.
        $sheets[] = new ChartDataSheet($this->report['daily']);

        return $sheets;
    }
}
