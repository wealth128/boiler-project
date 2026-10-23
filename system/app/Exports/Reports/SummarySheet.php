<?php

namespace App\Exports\Reports;

use Carbon\CarbonInterface;

/**
 * The "Summary" sheet: the saved-copy note of a closed month, every table of
 * the Summary page (all categories together), then the signature lines.
 */
class SummarySheet extends ReportSheetExport
{
    /**
     * @param  list<array<string, mixed>>  $sections  the Summary page's tables
     * @param  array<string, array<string, mixed>>  $charts  the Summary page's charts by table key
     * @param  array<string, mixed>  $sheet
     * @param  array{month_label: string, taken_at: CarbonInterface}|null  $snapshot
     * @param  array<string, string>  $meta  see ReportSheetExport
     */
    public function __construct(array $sections, array $charts, array $sheet, ?array $snapshot, array $meta, ?string $logoPath)
    {
        parent::__construct('Summary', $sheet['title'], $meta, $logoPath);

        if ($snapshot !== null) {
            $this->blank();
            $this->noteRow('Saved copy, taken when '.$snapshot['month_label'].' was closed on '.$snapshot['taken_at']->format('d-M-Y H:i').'. Later changes to lists or records do not affect it.');
        }

        $this->sectionTables($sections, $charts);
        $this->signatureRows();
    }
}
