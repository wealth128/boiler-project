<?php

namespace App\Http\Controllers\Reports;

use App\Enums\Permission;
use App\Exports\Reports\ReportWorkbook;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HospitalSettings;
use App\Services\ReportSheetResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF and Excel downloads of the report on screen (planning/mvp-scope.md
 * section 4). Admin and Viewer (CO): role:admin,viewer on the route plus the
 * view-reports Gate, the same as the screen.
 *
 * The report comes from ReportSheetResolver, so a file always matches the
 * screen for the same URL values (bad values fall back to the same defaults),
 * and a closed month exports its saved copy. Files are built in memory and
 * streamed; nothing is kept in public/ or storage. Each download is written
 * to the audit log as "report.exported".
 */
class ReportExportController extends Controller
{
    public function pdf(Request $request, ReportSheetResolver $resolver, AuditLogger $audit, HospitalSettings $hospital): Response
    {
        Gate::authorize(Permission::ViewReports->value);

        $data = $resolver->resolve($request->query());
        $filters = $data['filters'];
        $generatedAt = now();

        $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false])
            ->setPaper('a4', 'portrait')
            ->loadView('reports.pdf', [
                ...$data,
                'generatedAt' => $generatedAt,
                'logoData' => $hospital->imageDataUri('logo'),
                // Signature pictures only on a final report (every month closed);
                // a closed month uses the copies frozen when it was closed.
                'signatureData' => $resolver->signatureData($data),
                // This tab only, or the summary and every category, each on its own page.
                'pages' => $filters->pages(),
            ]);

        $this->addFooter($pdf, 'Generated '.$generatedAt->format('d-M-Y H:i'));

        $this->logExport($audit, $request, 'pdf', $filters);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filters->fileStem().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function excel(Request $request, ReportSheetResolver $resolver, AuditLogger $audit, HospitalSettings $hospital): Response
    {
        Gate::authorize(Permission::ViewReports->value);

        $data = $resolver->resolve($request->query());
        $filters = $data['filters'];

        // Always every category: a Summary sheet and one sheet per category.
        // The logo is not frozen when a month is closed, so it is always the current one.
        $logo = $hospital->imageFile('logo');

        $workbook = new ReportWorkbook(
            $data['report'],
            $data['sections'],
            [...$data['sheet'], 'category' => null],
            $data['snapshot'],
            now(),
            $request->user()->name,
            $logo === null ? null : Storage::disk('local')->path($logo),
        );

        $this->logExport($audit, $request, 'excel', $filters, 'All');

        return response(Excel::raw($workbook, ExcelWriter::XLSX), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filters->fileStem(everything: true).'.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * "Page X of Y · Generated ..." at the bottom of every page. The page
     * count is only known after the layout is done, so it is drawn after.
     */
    private function addFooter(\Barryvdh\DomPDF\PDF $pdf, string $text): void
    {
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $size = 8;
        $width = $canvas->get_width();
        $height = $canvas->get_height();

        $label = "Page {PAGE_NUM} of {PAGE_COUNT} · {$text}";
        // Centered: the widest case is about as wide as a two-digit page count.
        $textWidth = $dompdf->getFontMetrics()->getTextWidth(str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], ['00', '00'], $label), $font, $size);

        $canvas->page_text(($width - $textWidth) / 2, $height - 32, $label, $font, $size, [0, 0, 0]);
    }

    private function logExport(AuditLogger $audit, Request $request, string $format, ReportFilters $filters, ?string $category = null): void
    {
        /** @var User $user */
        $user = $request->user();

        // The subject is the person who took the report: a report is not a record.
        $audit->log('report.exported', $user, [
            'format' => $format,
            'period' => $filters->periodCode(),
            'category' => $category ?? $filters->categoryName(),
        ]);
    }
}
