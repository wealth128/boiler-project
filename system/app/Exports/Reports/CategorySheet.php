<?php

namespace App\Exports\Reports;

/**
 * One category's sheet (OPD, ER or Admission): every table of the category's
 * page, as ReportSections gives them, then the signature lines.
 */
class CategorySheet extends ReportSheetExport
{
    /**
     * @param  list<array<string, mixed>>  $sections  the category's tables
     * @param  array<string, array<string, mixed>>  $charts  the category's charts by table key
     * @param  array<string, string>  $meta  see ReportSheetExport
     */
    public function __construct(string $name, array $sections, array $charts, string $reportTitle, array $meta, ?string $logoPath)
    {
        parent::__construct($name, $reportTitle.' – '.$name, $meta, $logoPath);

        $this->sectionTables($sections, $charts);
        $this->signatureRows();
    }
}
