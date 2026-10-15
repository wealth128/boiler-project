<?php

namespace App\Exports\Reports;

/**
 * One category's sheet (OPD, ER or Admission): Patients and Visits, then
 * Sex, Age range, Diagnosis (per visit, the full list) and Branch of
 * service and rank, stacked under bold headings.
 */
class CategorySheet extends ReportSheetExport
{
    /** @param  array<string, mixed>  $data  one category of the report */
    public function __construct(string $name, array $data)
    {
        parent::__construct($name);

        $this->add([$name], bold: true);
        $this->add(['Patients', $data['patients']]);
        $this->add(['Visits', $data['visits']]);

        $this->table('Sex', $data['sex'], $data['patients']);
        $this->table('Age range', $data['age']);
        $this->table('Diagnosis (per visit)', $data['diagnoses'], $data['visits']);

        $this->add();
        $this->add(['Branch of service and rank'], bold: true);
        $this->add(['Branch / Rank', 'Patients'], bold: true);

        foreach ($data['branches'] as $branch) {
            $this->add([$branch['name'], $branch['total']], bold: true);

            foreach ($branch['ranks'] as $rank) {
                $this->add(['    '.$rank['label'], $rank['count']]);
            }
        }
    }

    /**
     * @param  list<array{label: string, count: int}>  $lines
     */
    private function table(string $heading, array $lines, ?int $total = null): void
    {
        $this->add();
        $this->add([$heading], bold: true);

        foreach ($lines as $line) {
            $this->add([$line['label'], $line['count']]);
        }

        if ($total !== null) {
            $this->add(['Total', $total], bold: true);
        }
    }
}
