<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Inertia\Response;

class ReportExportController extends Controller
{
    use StubResponses;

    /**
     * Download the report as PDF.
     */
    public function pdf(): Response
    {
        return $this->placeholder('Export PDF', 'PDF download of the report.');
    }

    /**
     * Download the report as Excel.
     */
    public function excel(): Response
    {
        return $this->placeholder('Export Excel', 'Excel download of the report.');
    }
}
