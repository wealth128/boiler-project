<?php

namespace App\Exports\Reports;

use Carbon\CarbonInterface;

/**
 * The "Summary" sheet: header lines, period, grand totals, and Patients and
 * Visits for each category, then the signature lines.
 */
class SummarySheet extends ReportSheetExport
{
    /**
     * @param  array<string, mixed>  $report  from ReportSheetResolver
     * @param  array<string, mixed>  $sheet
     * @param  array{month_label: string, taken_at: CarbonInterface}|null  $snapshot
     */
    public function __construct(array $report, array $sheet, ?array $snapshot, CarbonInterface $generatedAt)
    {
        parent::__construct('Summary');

        $this->add([$sheet['hospital_name'].' · '.$sheet['office']], bold: true);
        $this->add([$sheet['title']], bold: true);
        $this->add([$sheet['period_label'].($sheet['category'] ? ' · '.$sheet['category'].' only' : '')]);

        if ($snapshot !== null) {
            $this->add(['Saved copy, taken when '.$snapshot['month_label'].' was closed on '.$snapshot['taken_at']->format('d-M-Y H:i').'. Later changes to lists or records do not affect it.']);
        }

        $this->add();
        $this->add([$sheet['category'] ? 'Totals' : 'Grand totals (all categories)', 'Patients', 'Visits'], bold: true);
        $this->add(['Total', $report['totals']['patients'], $report['totals']['visits']]);

        $this->add();
        $this->add(['Category', 'Patients', 'Visits'], bold: true);

        foreach ($report['categories'] as $name => $data) {
            $this->add([$name, $data['patients'], $data['visits']]);
        }

        $this->add();
        $this->add(['Prepared by · '.$sheet['prepared_by_title'], $sheet['prepared_by']]);
        $this->add(['Noted by · '.$sheet['noted_by_title'], $sheet['noted_by']]);
        $this->add();
        $this->add(['Generated '.$generatedAt->format('d-M-Y H:i')]);
    }
}
