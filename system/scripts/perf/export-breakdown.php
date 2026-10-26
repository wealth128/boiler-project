<?php

/**
 * Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
 *
 * Times every phase of the report Excel and PDF exports with the real classes:
 *
 *   php scripts/perf/export-breakdown.php <system dir> <monthly|quarterly|annual> <2026-09|2026-Q3|2026> [--readonly] [--dbcache] [--logo=<png>] [--runs=N]
 *
 * --readonly  the MySQL session is READ ONLY, so nothing can be written (use it on patient_census)
 * --dbcache   use the configured (database) cache instead of an in-memory one
 */

use App\Exports\Reports\ChartDataSheet;
use App\Exports\Reports\ReportWorkbook;
use App\Services\HospitalSettings;
use App\Services\ReportBuilder;
use App\Services\ReportSheetResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Sheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

$base = $argv[1];
$type = $argv[2];
$value = $argv[3];
$readonly = in_array('--readonly', $argv, true);
$logo = null;
$runs = 3;

foreach ($argv as $arg) {
    str_starts_with($arg, '--logo=') && $logo = substr($arg, 7);
    str_starts_with($arg, '--runs=') && $runs = (int) substr($arg, 7);
}

if (! in_array('--dbcache', $argv, true)) {
    putenv('CACHE_STORE=array');
    $_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';
}

$t0 = microtime(true);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$boot = (microtime(true) - $t0) * 1000;

if ($readonly) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$queries = 0;
DB::listen(function () use (&$queries): void {
    $queries++;
});

$query = match ($type) {
    'quarterly' => ['type' => 'quarterly', 'year' => explode('-Q', $value)[0], 'quarter' => explode('-Q', $value)[1]],
    'annual' => ['type' => 'annual', 'year' => $value],
    default => ['type' => 'monthly', 'month' => $value],
};

$query['scope'] = 'all'; // PDF of every page, as the measured HTTP requests.
$ms = fn (float $start): float => round((microtime(true) - $start) * 1000, 1);
$out = ['db' => DB::connection()->getDatabaseName(), 'env' => app()->environment(), 'debug' => config('app.debug'), 'boot_ms' => round($boot, 1), 'period' => $query, 'logo' => $logo !== null];
$resolver = app(ReportSheetResolver::class);

// The report: counted (cache empty), then from the cache.
Cache::flush();
$t = microtime(true);
$q0 = $queries;
$data = $resolver->resolve($query);
$out['report_counted_ms'] = $ms($t);
$out['report_counted_queries'] = $queries - $q0;
$t = microtime(true);
app(ReportBuilder::class)->build($data['filters']->from, $data['filters']->to);
$out['counting_only_ms'] = $ms($t);
$t = microtime(true);
$q0 = $queries;
$data = $resolver->resolve($query);
$out['report_remembered_ms'] = $ms($t);
$out['report_remembered_queries'] = $queries - $q0;

$workbook = fn () => new ReportWorkbook($data['report'], $data['sections'], $data['charts'], [...$data['sheet'], 'category' => null], $data['snapshot'], now(), 'Perf Tester', $logo);

// Excel, phase by phase, the way maatwebsite builds it.
$out['excel'] = [];
for ($i = 0; $i < $runs; $i++) {
    $r = ['rows' => 0.0, 'cells' => 0.0, 'charts' => 0.0, 'logo' => 0.0, 'widths' => 0.0, 'styling' => 0.0, 'chart_data_sheet' => 0.0];
    $t = microtime(true);
    $sheets = $workbook()->sheets();
    $r['rows'] = $ms($t);

    $spreadsheet = new Spreadsheet;
    $spreadsheet->disconnectWorksheets();

    foreach ($sheets as $export) {
        $start = microtime(true);
        $worksheet = $spreadsheet->createSheet();
        $sheet = app()->make(Sheet::class, ['worksheet' => $worksheet]);
        $sheet->open($export);
        $t = microtime(true);
        $sheet->fromArray($export);
        $cells = $ms($t);
        $t = microtime(true);
        $export instanceof WithCharts && $sheet->addCharts($export->charts());
        $charts = $ms($t);
        $t = microtime(true);
        $export instanceof WithDrawings && $sheet->addDrawings($export->drawings());
        $drawings = $ms($t);
        $t = microtime(true);
        if ($export instanceof WithColumnWidths) {
            foreach ($export->columnWidths() as $column => $width) {
                $worksheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
            }
        }
        $widths = $ms($t);
        $t = microtime(true);
        $export instanceof WithStyles && $export->styles($worksheet);
        $styling = $ms($t);

        if ($export instanceof WithEvents) {
            foreach ($export->registerEvents() as $event => $listener) {
                $event === AfterSheet::class && $listener(new AfterSheet($sheet, $export));
            }
        }

        if ($export instanceof ChartDataSheet) {
            $r['chart_data_sheet'] += $ms($start);
        } else {
            $r['cells'] += $cells;
            $r['charts'] += $charts;
            $r['logo'] += $drawings;
            $r['widths'] += $widths;
            $r['styling'] += $styling;
        }
    }

    $spreadsheet->setActiveSheetIndex(0);
    $file = (string) tempnam(sys_get_temp_dir(), 'xl');
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->setIncludeCharts(true);
    $writer->setPreCalculateFormulas(false);
    $t = microtime(true);
    $writer->save($file);
    $r['write_file'] = $ms($t);
    $t = microtime(true);
    $bytes = (string) file_get_contents($file);
    $r['read_back'] = $ms($t);
    @unlink($file);
    $r['file_kb'] = round(strlen($bytes) / 1024, 1);
    $r['charts_in_file'] = array_sum(array_map(fn ($ws) => count($ws->getChartCollection()), $spreadsheet->getAllSheets()));

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->setIncludeCharts(false);
    $file = (string) tempnam(sys_get_temp_dir(), 'xl');
    $t = microtime(true);
    $writer->save($file);
    $r['write_file_without_charts'] = $ms($t);
    @unlink($file);
    $spreadsheet->disconnectWorksheets();

    // The real path of the controller, end to end (report already resolved).
    $t = microtime(true);
    $raw = Excel::raw($workbook(), ExcelWriter::XLSX);
    $r['excel_total'] = $ms($t);
    $out['excel'][] = $r;
}

// PDF.
$out['pdf'] = [];
for ($i = 0; $i < $runs; $i++) {
    $r = [];
    $t = microtime(true);
    $logoData = $logo === null ? app(HospitalSettings::class)->imageDataUri('logo', 160) : 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo));
    $r['logo'] = $ms($t);
    $t = microtime(true);
    $html = view('reports.pdf', [...$data, 'generatedAt' => now(), 'logoData' => $logoData, 'signatureData' => $resolver->signatureData($data), 'pages' => $data['filters']->pages()])->render();
    $r['html'] = $ms($t);
    $t = microtime(true);
    $pdf = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false])->setPaper('a4', 'portrait')->loadHTML($html);
    $r['load'] = $ms($t);
    $t = microtime(true);
    $pdf->render();
    $r['layout'] = $ms($t);
    $t = microtime(true);
    $output = $pdf->output();
    $r['write'] = $ms($t);
    $r['pdf_kb'] = round(strlen($output) / 1024, 1);
    $r['pages'] = $pdf->getDomPDF()->getCanvas()->get_page_count();
    $out['pdf'][] = $r;
}

$out['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
