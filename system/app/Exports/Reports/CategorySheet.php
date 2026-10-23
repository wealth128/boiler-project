<?php

namespace App\Exports\Reports;

/**
 * One category's sheet (OPD, ER or Admission): Patients and Visits, then
 * Sex, Age range, Diagnosis (per visit, the full list) and Branch of
 * service and rank, each table under its own heading.
 */
class CategorySheet extends ReportSheetExport
{
    /**
     * @param  array<string, mixed>  $data  one category of the report
     * @param  array<string, string>  $meta  see ReportSheetExport
     */
    public function __construct(string $name, array $data, string $reportTitle, array $meta, ?string $logoPath)
    {
        parent::__construct($name, $reportTitle.' – '.$name, $meta, $logoPath);

        $this->sectionRow('Patients and Visits');
        $this->headerRow([$name, 'Count']);
        $this->bodyRow(['Patients', $data['patients']]);
        $this->bodyRow(['Visits', $data['visits']]);

        $this->table('Sex', 'Sex', 'Patients', $data['sex'], $data['patients']);
        $this->table('Age range', 'Age range', 'Patients', $data['age']);
        $this->table('Diagnosis (per visit)', 'Diagnosis', 'Visits', $data['diagnoses'], $data['visits']);

        $this->sectionRow('Branch of service and rank');
        $this->headerRow(['Branch / Rank', 'Patients']);

        foreach ($data['branches'] as $branch) {
            $this->groupRow([$branch['name'], $branch['total']]);

            foreach ($branch['ranks'] as $rank) {
                $this->bodyRow(['    '.$rank['label'], $rank['count']]);
            }
        }

        $this->signatureRows();
    }

    /**
     * @param  list<array{label: string, count: int}>  $lines
     */
    private function table(string $heading, string $labelHeader, string $countHeader, array $lines, ?int $total = null): void
    {
        $this->sectionRow($heading);
        $this->headerRow([$labelHeader, $countHeader]);

        foreach ($lines as $line) {
            $this->bodyRow([$line['label'], $line['count']]);
        }

        if ($total !== null) {
            $this->bodyRow(['Total', $total], bold: true);
        }
    }
}
