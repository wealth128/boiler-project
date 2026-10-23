<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\ReportSheetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The print view of a report: the page the browser's Print dialog opens on.
 * Admin and Viewer (CO): role:admin,viewer on the route plus the
 * view-reports Gate, the same as the screen.
 *
 * It holds the pages to print: only the chosen tab ("This category"), or the
 * summary and every category ("All categories", ?scope=all), each starting
 * on a new sheet with its own header and signature block. The report comes
 * from ReportSheetResolver, so it matches the screen and the PDF exactly.
 */
class ReportPrintController extends Controller
{
    public function show(Request $request, ReportSheetResolver $resolver): Response
    {
        Gate::authorize(Permission::ViewReports->value);

        $data = $resolver->resolve($request->query());
        $filters = $data['filters'];
        $snapshot = $data['snapshot'];

        return Inertia::render('reports/print', [
            'filters' => $filters->toArray(),
            'sheet' => $data['sheet'],
            'report' => $data['report'],
            'sections' => $data['sections'],
            // Only the pages that print.
            'charts' => array_intersect_key($data['charts'], array_flip($filters->pages())),
            'snapshot' => $snapshot === null ? null : [
                'month_label' => $snapshot['month_label'],
                'taken_at' => $snapshot['taken_at']->toIso8601String(),
            ],
            'pages' => $filters->pages(),
        ]);
    }
}
