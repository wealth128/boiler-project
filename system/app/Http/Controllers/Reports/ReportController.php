<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\StubResponses;
use App\Http\Controllers\Controller;
use Inertia\Response;

class ReportController extends Controller
{
    use StubResponses;

    /**
     * Report on screen (monthly, quarterly, annual, custom range).
     */
    public function index(): Response
    {
        return $this->placeholder('Reports', 'Monthly, quarterly, annual and custom-range reports per category.');
    }
}
