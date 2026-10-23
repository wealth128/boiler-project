<?php

namespace App\Exports\Reports;

use Carbon\CarbonInterface;

/**
 * The "Summary" sheet: title block, grand totals, Patients and Visits for
 * each category, then the signature lines.
 */
class SummarySheet extends ReportSheetExport
{
    /**
     * @param  array<string, mixed>  $report  from ReportSheetResolver
     * @param  array<string, mixed>  $sheet
     * @param  array{month_label: string, taken_at: CarbonInterface}|null  $snapshot
     * @param  array<string, string>  $meta  see ReportSheetExport
     */
    public function __construct(array $report, array $sheet, ?array $snapshot, array $meta, ?string $logoPath)
    {
        parent::__construct('Summary', $sheet['title'], $meta, $logoPath);

        if ($snapshot !== null) {
            $this->blank();
            $this->noteRow('Saved copy, taken when '.$snapshot['month_label'].' was closed on '.$snapshot['taken_at']->format('d-M-Y H:i').'. Later changes to lists or records do not affect it.');
        }

        $this->sectionRow('Grand totals (all categories)');
        $this->headerRow(['Summary', 'Patients', 'Visits']);
        $this->bodyRow(['Total', $report['totals']['patients'], $report['totals']['visits']], bold: true);

        $this->sectionRow('By category');
        $this->headerRow(['Category', 'Patients', 'Visits']);

        foreach ($report['categories'] as $name => $data) {
            $this->bodyRow([$name, $data['patients'], $data['visits']]);
        }

        $this->signatureRows();
    }
}
