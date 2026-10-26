<?php

use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Visit;
use App\Services\Charts\ChartPalette;
use App\Services\Charts\PdfSvg;
use App\Services\MonthService;
use App\Services\ReportBuilder;
use App\Services\ReportCache;
use App\Services\ReportCharts;
use App\Services\ReportSheetResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/*
 * Step 7d-3: the charts above the report tables, the same on screen, in
 * Print, in the PDF and in Excel, and the speed of building a report.
 *
 * The dataset is the September 2026 one of ReportContentTest (counted by
 * hand there): ER 3 patients and 4 visits, OPD 2 and 2, Admission 1 and 1,
 * 5 patients and 7 visits in all, August 2 and 2. "Today" is 15-Oct-2026.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));

    $this->admin = userWithRole('admin');
    $this->encoder = userWithRole('encoder');

    $this->army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $this->navy = Branch::factory()->create(['name' => 'Navy', 'sort_order' => 2]);
    $this->others = Branch::factory()->create(['name' => 'Others', 'is_afp' => false, 'sort_order' => 3]);

    $this->cpl = Rank::factory()->for($this->army)->create(['name' => 'CPL', 'sort_order' => 1]);
    $this->ens = Rank::factory()->for($this->navy)->create(['name' => 'ENS', 'sort_order' => 1]);
    $this->dependent = Rank::factory()->for($this->others)->create(['name' => 'Dependent', 'sort_order' => 1]);

    $this->hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $this->asthma = Diagnosis::factory()->create(['name' => 'Asthma']);
    $this->otherDiagnosis = Diagnosis::factory()->create(['name' => 'Other (specify)', 'is_other' => true]);

    AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);
});

/** @param  array<string, mixed>  $attributes */
function chartVisit(object $test, Patient $patient, string $when, string $category, array $attributes = []): Visit
{
    return Visit::factory()->create([
        'patient_id' => $patient->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'branch_id' => $test->army->id,
        'rank_id' => $test->cpl->id,
        'diagnosis_id' => $test->hypertension->id,
        'encoded_by' => $test->encoder->id,
        ...$attributes,
    ]);
}

function chartsSeed(object $test): void
{
    $a = Patient::factory()->create(['sex' => 'Male']);
    $b = Patient::factory()->create(['sex' => 'Female']);
    $c = Patient::factory()->create(['sex' => 'Female']);
    $d = Patient::factory()->create(['sex' => 'Male']);
    $e = Patient::factory()->create(['sex' => 'Male']);

    chartVisit($test, $a, '2026-08-15 09:00', 'ER');
    chartVisit($test, $c, '2026-08-20 09:00', 'OPD', ['branch_id' => $test->others->id, 'rank_id' => $test->dependent->id, 'age' => 45]);

    chartVisit($test, $a, '2026-09-03 09:00', 'ER');
    chartVisit($test, $a, '2026-09-10 09:00', 'ER', ['age' => 31, 'diagnosis_id' => $test->asthma->id]);
    chartVisit($test, $a, '2026-09-05 08:00', 'OPD');
    chartVisit($test, $b, '2026-09-04 09:00', 'ER', ['age' => 10, 'branch_id' => $test->navy->id, 'rank_id' => $test->ens->id, 'diagnosis_id' => $test->asthma->id]);
    chartVisit($test, $c, '2026-09-05 09:00', 'OPD', ['age' => 45, 'branch_id' => $test->others->id, 'rank_id' => $test->dependent->id]);
    chartVisit($test, $d, '2026-09-06 09:00', 'Admission', ['age' => 70]);
    chartVisit($test, $e, '2026-09-04 10:00', 'ER', ['age' => 25, 'diagnosis_id' => $test->otherDiagnosis->id, 'diagnosis_other' => 'Dengue']);
}

/** The whole resolved report of a query, as the screen, Print, the PDF and Excel get it. */
function chartsResolved(string $query = 'type=monthly&month=2026-09'): array
{
    parse_str($query, $input);

    return app(ReportSheetResolver::class)->resolve($input);
}

/** The table a chart sits above. */
function chartTableAbove(array $resolved, string $page, array $chart): array
{
    return collect($resolved['sections'][$page])->firstWhere('key', $chart['source']['table']);
}

/** Every chart of the resolved report: [page, chart]. */
function chartsAll(array $resolved): array
{
    $found = [];

    foreach ($resolved['charts'] as $page => $charts) {
        foreach ($charts as $chart) {
            $found[] = [$page, $chart];
        }
    }

    return $found;
}

/** The text of every <text> and the title of every bar, in an SVG. */
function chartSvgTexts(string $svg): array
{
    preg_match_all('/<text [^>]*>(.*?)<\/text>/s', $svg, $m);

    return array_map(fn (string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_XML1), $m[1]);
}

function chartLuminance(string $hex): float
{
    $channel = fn (int $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
}

// --- Which charts, from which table -----------------------------------------------------------

it('makes the six Summary charts and two for each category, each above its own table', function () {
    chartsSeed($this);
    $resolved = chartsResolved();

    expect(array_keys($resolved['charts']))->toBe(['summary', 'OPD', 'ER', 'Admission'])
        ->and(array_keys($resolved['charts']['summary']))->toBe(['sex', 'age_sex', 'diagnoses', 'branches', 'daily', 'comparison'])
        ->and(array_keys($resolved['charts']['ER']))->toBe(['diagnoses', 'daily']);

    foreach (chartsAll($resolved) as [$page, $chart]) {
        // A chart's key is the key of a table of the same page.
        expect(array_column($resolved['sections'][$page], 'key'))->toContain($chart['key'])
            ->and($chart['source']['table'])->toBe($chart['key']);
    }
});

it('skips the comparison chart when there is no previous period', function () {
    chartsSeed($this);

    // January 2026 has nothing before it.
    $resolved = chartsResolved('type=monthly&month=2026-01');

    expect($resolved['charts']['summary'])->not->toHaveKey('comparison');
});

it('draws every chart from the numbers of its table and nothing else', function () {
    chartsSeed($this);
    $resolved = chartsResolved();

    foreach (chartsAll($resolved) as [$page, $chart]) {
        $table = chartTableAbove($resolved, $page, $chart);
        ['first' => $first, 'last' => $last, 'label_col' => $labelCol, 'cols' => $cols] = $chart['source'];

        expect($chart['rows'])->toHaveCount($last - $first + 1);

        // The daily census: its table holds only the totals; the chart draws the report's day by day
        // figures, which add up to exactly those totals.
        if (isset($chart['source']['sheet'])) {
            $days = $resolved['report']['daily']['rows'];
            $sums = array_fill(0, count($chart['series']), 0);

            foreach ($chart['rows'] as $index => $row) {
                $names = array_column($chart['series'], 'label');

                expect($row['label'])->toBe($days[$index]['label'])
                    ->and($row['values'])->toBe(array_map(fn (string $name): int => $days[$index]['counts'][$name], $names));

                foreach ($row['values'] as $position => $value) {
                    $sums[$position] += $value;
                }
            }

            // The totals row of the table: the sums of the chart (and "Total visits" last on the Summary).
            $totalsRow = $table['rows'][array_key_last($table['rows'])]['cells'];

            expect(array_slice($totalsRow, 1, count($sums)))->toBe($sums)
                ->and(array_column($table['rows'], 'style'))->toBe(['total']);

            continue;
        }

        foreach ($chart['rows'] as $index => $row) {
            $cells = $table['rows'][$first + $index]['cells'];

            expect($row['label'])->toBe((string) $cells[$labelCol])
                ->and($row['values'])->toBe(array_map(fn (int $col): int => (int) $cells[$col], $cols));
        }
    }
});

it('charts the hand-counted September numbers', function () {
    chartsSeed($this);
    $charts = chartsResolved()['charts'];

    $values = fn (string $page, string $key): array => array_map(fn (array $row): array => [$row['label'], ...$row['values']], $charts[$page][$key]['rows']);

    expect($values('summary', 'sex'))->toBe([['All categories', 3, 2]])
        ->and($values('summary', 'age_sex'))->toBe([['0–17', 0, 1], ['18–59', 2, 1], ['No bracket', 1, 0]])
        ->and($values('summary', 'diagnoses'))->toBe([['Hypertension', 4], ['Asthma', 2], ['Dengue', 1]])
        ->and($values('summary', 'branches'))->toBe([['Army', 3], ['Navy', 1], ['Others', 1]])
        ->and($values('summary', 'comparison'))->toBe([['Patients', 5, 2], ['Visits', 7, 2]])
        ->and($values('ER', 'diagnoses'))->toBe([['Asthma', 2], ['Dengue', 1], ['Hypertension', 1]])
        ->and($charts['summary']['daily']['rows'])->toHaveCount(30)
        ->and($charts['summary']['daily']['rows'][3]['values'])->toBe([0, 2, 0])
        ->and($charts['ER']['daily']['rows'][3]['values'])->toBe([2]);
});

it('draws the top ten diagnoses only, highest first', function () {
    foreach (range(1, 12) as $n) {
        $diagnosis = Diagnosis::factory()->create(['name' => "Diagnosis {$n}"]);

        foreach (range(1, $n) as $visit) {
            chartVisit($this, Patient::factory()->create(), '2026-09-05 09:00', 'ER', ['diagnosis_id' => $diagnosis->id]);
        }
    }

    $chart = chartsResolved()['charts']['summary']['diagnoses'];

    expect($chart['rows'])->toHaveCount(10)
        ->and(array_column($chart['rows'], 'label')[0])->toBe('Diagnosis 12')
        ->and(array_column($chart['rows'], 'label')[9])->toBe('Diagnosis 3')
        ->and($chart['title'])->toBe('Top 10 diagnoses');
});

it('draws a daily census by month for a quarter and a year', function () {
    chartsSeed($this);

    $quarter = chartsResolved('type=quarterly&year=2026&quarter=3')['charts']['summary']['daily'];
    $year = chartsResolved('type=annual&year=2026')['charts']['summary']['daily'];

    expect($quarter['title'])->toBe('Monthly census')
        ->and(array_column($quarter['rows'], 'short'))->toBe(['Jul', 'Aug', 'Sep'])
        ->and(array_column($quarter['rows'], 'values')[2])->toBe([2, 4, 1])
        ->and($year['rows'])->toHaveCount(12)
        ->and($year['rows'][0]['short'])->toBe('Jan');
});

// --- Presentable rules -------------------------------------------------------------------------

it('gives every chart a title, a subtitle and a role="img" SVG with its summary as aria-label', function () {
    chartsSeed($this);

    foreach (chartsAll(chartsResolved()) as [, $chart]) {
        foreach (['svg', 'svg_narrow'] as $kind) {
            expect($chart[$kind])->toContain('role="img"')
                ->toContain('aria-label="'.htmlspecialchars($chart['aria'], ENT_QUOTES | ENT_XML1).'"');
        }

        expect($chart['title'])->not->toBe('')
            ->and($chart['subtitle'])->toContain('September 2026')
            ->and(chartSvgTexts($chart['svg']))->toContain($chart['title'])
            ->and($chart['subtitle'])->toMatch('/ · .*(patients|visits)/');
    }
});

it('says what a chart shows in its aria-label', function () {
    chartsSeed($this);
    $charts = chartsResolved()['charts'];

    expect($charts['summary']['daily']['aria'])->toBe('Daily census, September 2026. ER 4 visits, OPD 2, Admission 1.')
        ->and($charts['ER']['daily']['aria'])->toBe('Daily census, September 2026. ER 4 visits.')
        ->and($charts['summary']['sex']['aria'])->toBe('Male vs Female, September 2026. Male 3 patients (60.0%), Female 2 (40.0%).')
        ->and($charts['summary']['branches']['aria'])->toBe('Branch of service, September 2026. Army 3, Navy 1, Others 1 (patients).')
        ->and($charts['summary']['diagnoses']['aria'])->toBe('Top diagnoses, September 2026. Hypertension 4, Asthma 2, Dengue 1 (visits).')
        ->and($charts['summary']['age_sex']['aria'])->toContain('0–17 male 0, female 1; 18–59 male 2, female 1; No bracket male 1, female 0')
        ->and($charts['summary']['comparison']['aria'])->toContain('Patients 5 this period, 2 in August 2026, change +3 (+150.0%)');
});

it('writes every number beside its bar, and never leaves a chart empty-looking', function () {
    chartsSeed($this);
    $charts = chartsResolved()['charts'];

    // Counts are written as text in the drawing: the ER pyramid, bars and the 100% bar all show them.
    $bars = chartSvgTexts($charts['summary']['diagnoses']['svg']);
    $pyramid = chartSvgTexts($charts['summary']['age_sex']['svg']);
    $share = chartSvgTexts($charts['summary']['sex']['svg']);

    expect($bars)->toContain('4')->toContain('2')->toContain('1')
        ->and($bars)->toContain('Hypertension')->toContain('Asthma')->toContain('Dengue')
        ->and($pyramid)->toContain('2')->toContain('1')->toContain('0–17')
        ->and(implode(' ', $share))->toContain('Male 3 · 60.0%')->toContain('Female 2 · 40.0%')
        ->and(implode(' ', chartSvgTexts($charts['summary']['branches']['svg'])))->toContain('3 · 60.0%')->toContain('1 · 20.0%');
});

it('draws flat 2D charts: no gradient, shadow, filter, pattern, pie or script', function () {
    chartsSeed($this);

    foreach (chartsAll(chartsResolved()) as [, $chart]) {
        foreach (['svg', 'svg_narrow'] as $kind) {
            $svg = $chart[$kind];

            // A well-formed picture.
            expect(@simplexml_load_string($svg))->not->toBeFalse()
                ->and($svg)->not->toMatch('/gradient|<filter|shadow|<pattern|<circle|<ellipse|<script|onclick|onload|<image|xlink:href/i')
                ->and($svg)->toContain('fill="#FFFFFF"');
        }
    }
});

it('keeps every text at 12 units or more, which is at least 9 pt in the PDF and Print', function () {
    chartsSeed($this);

    // The PDF page holds 178 mm of width (A4 less 16 mm margins) = 504.6 pt for the 640 unit wide drawing.
    $pointsPerUnit = (178 / 25.4 * 72) / 640;

    foreach (chartsAll(chartsResolved()) as [, $chart]) {
        preg_match_all('/font-size="([\d.]+)"/', $chart['svg'], $wide);
        preg_match_all('/font-size="([\d.]+)"/', $chart['svg_narrow'], $narrow);

        expect(min($wide[1]))->toBeGreaterThanOrEqual(12)
            ->and(min($wide[1]) * $pointsPerUnit)->toBeGreaterThanOrEqual(9)
            ->and(min($narrow[1]))->toBeGreaterThanOrEqual(12);
    }
});

it('wraps a long diagnosis name over lines and never cuts it, with the full name as the tooltip', function () {
    $long = Diagnosis::factory()->create(['name' => 'Acute exacerbation of chronic obstructive pulmonary disease with respiratory failure']);
    chartVisit($this, Patient::factory()->create(), '2026-09-05 09:00', 'ER', ['diagnosis_id' => $long->id]);

    $chart = chartsResolved()['charts']['summary']['diagnoses'];

    foreach (['svg', 'svg_narrow'] as $kind) {
        $words = implode(' ', chartSvgTexts($chart[$kind]));

        // Every word of the name is on the picture, over more than one line.
        foreach (explode(' ', $long->name) as $word) {
            expect($words)->toContain($word);
        }

        expect(substr_count($chart[$kind], '<text '))->toBeGreaterThan(8)
            ->and($chart[$kind])->toContain('<title>'.$long->name.': 1 visit');
    }
});

it('escapes what people typed, so a diagnosis can never put markup into a chart', function () {
    chartVisit($this, Patient::factory()->create(), '2026-09-05 09:00', 'ER', [
        'diagnosis_id' => $this->otherDiagnosis->id,
        'diagnosis_other' => '<img src=x onerror=alert(1)> & "quote"',
    ]);

    $chart = chartsResolved()['charts']['summary']['diagnoses'];

    foreach (['svg', 'svg_narrow'] as $kind) {
        expect($chart[$kind])->not->toContain('<img')
            ->toContain('&lt;img')
            ->and(@simplexml_load_string($chart[$kind]))->not->toBeFalse();
    }
});

it('shows "No visits in this period" instead of an empty chart', function () {
    chartsSeed($this);

    // May 2026 has no visits at all.
    $charts = chartsResolved('type=monthly&month=2026-05')['charts'];

    foreach (chartsAll(['charts' => $charts]) as [$page, $chart]) {
        if ($chart['key'] === 'comparison') {
            continue;
        }

        expect($chart['empty'])->toBeTrue()
            ->and($chart['message'])->toBe('No visits in this period')
            ->and(chartSvgTexts($chart['svg']))->toContain('No visits in this period')
            ->and(chartSvgTexts($chart['svg_narrow']))->toContain('No visits in this period')
            // No bars, axes or legend: only the heading and the message.
            ->and($chart['svg'])->not->toContain('class="rc-bar"');
    }
});

it('keeps one fixed color for each category and each sex, in every chart and format', function () {
    chartsSeed($this);
    $resolved = chartsResolved();
    $charts = $resolved['charts'];

    $colors = ChartPalette::CATEGORIES;

    // OPD, ER and Admission are different, and far enough apart in brightness to tell apart in black and white.
    expect(array_unique($colors))->toHaveCount(3);

    $light = array_map(chartLuminance(...), $colors);
    $values = array_values($light);
    sort($values);

    expect($values[1] - $values[0])->toBeGreaterThan(0.09)
        ->and($values[2] - $values[1])->toBeGreaterThan(0.09)
        ->and(abs(chartLuminance(ChartPalette::MALE) - chartLuminance(ChartPalette::FEMALE)))->toBeGreaterThan(0.2);

    // Summary daily census: the three category colors, in order. A category page: its own color only.
    expect(array_column($charts['summary']['daily']['series'], 'color'))->toBe([$colors['OPD'], $colors['ER'], $colors['Admission']])
        ->and(array_column($charts['summary']['sex']['series'], 'color'))->toBe([ChartPalette::MALE, ChartPalette::FEMALE])
        ->and(array_column($charts['summary']['age_sex']['series'], 'color'))->toBe([ChartPalette::MALE, ChartPalette::FEMALE]);

    foreach (['OPD', 'ER', 'Admission'] as $category) {
        expect(array_column($charts[$category]['daily']['series'], 'color'))->toBe([$colors[$category]])
            ->and($charts[$category]['daily']['svg'])->toContain($colors[$category])
            ->and($charts[$category]['diagnoses']['svg'])->toContain($colors[$category]);

        // And none of the other categories' colors.
        foreach (array_diff_key($colors, [$category => 1]) as $other) {
            expect($charts[$category]['daily']['svg'])->not->toContain($other);
        }
    }

    foreach (['svg', 'svg_narrow'] as $kind) {
        foreach ($colors as $color) {
            expect($charts['summary']['daily'][$kind])->toContain($color);
        }
    }
});

// --- Screen, Print, PDF ---------------------------------------------------------------------------

it('gives the screen and Print the charts, the same as the resolved report', function () {
    chartsSeed($this);

    $screen = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09&category=summary')->assertOk()->inertiaProps();
    $print = $this->actingAs($this->admin)->get('/reports/print?type=monthly&month=2026-09&scope=all')->assertOk()->inertiaProps();
    $expected = json_decode(json_encode(chartsResolved()['charts']), true);

    // The screen gets the charts of the open tab only; Print the pages it prints.
    $oneTab = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09&category=ER')->assertOk()->inertiaProps();

    expect($screen['charts'])->toEqual(['summary' => $expected['summary']])
        ->and($oneTab['charts'])->toEqual(['ER' => $expected['ER']])
        ->and($print['charts'])->toEqual($expected)
        ->and($screen['charts']['summary']['sex']['rows'][0]['values'])->toBe([3, 2]);
});

/** The SVG pictures of a rendered PDF page, decoded, in order. */
function chartPdfPictures(string $html): array
{
    preg_match_all('/<img src="data:image\/svg\+xml;base64,([^"]+)"/', $html, $m);

    return array_map('base64_decode', $m[1]);
}

function chartPdfHtml(array $resolved, array $pages): string
{
    return view('reports.pdf', [
        ...$resolved,
        'generatedAt' => now(),
        'logoData' => null,
        'signatureData' => [],
        'pages' => $pages,
    ])->render();
}

it('puts the same SVG chart above its table in the PDF page, in one block', function () {
    chartsSeed($this);
    $resolved = chartsResolved();

    $html = chartPdfHtml($resolved, ['summary', 'ER']);
    $pictures = chartPdfPictures($html);
    // The PDF gets the same drawing, with the PDF's own font files named in its text (PdfSvg).
    $expected = array_map(
        PdfSvg::prepare(...),
        [...array_column($resolved['charts']['summary'], 'svg'), ...array_column($resolved['charts']['ER'], 'svg')],
    );

    // Every chart of the two pages, in the order of their tables, the very same drawing.
    expect($pictures)->toHaveCount(count($expected));

    foreach ($expected as $svg) {
        expect($pictures)->toContain($svg);
    }

    // The chart and its table are in one ".sec" block, which the PDF keeps on one page.
    expect($html)->toMatch('/<div class="sec">\s*<div class="chart"><img [^>]+>\s*<\/div>\s*<h4>Male vs Female|<div class="sec">\s*<div class="chart"><img [^>]+><\/div>\s*<h4>Sex<\/h4>/')
        ->and($html)->toContain('.sec { page-break-inside: avoid; }');

    // The number of every bar is in the picture the PDF draws.
    expect(implode(' ', chartSvgTexts($pictures[0])))->not->toBe('');
});

it('makes a PDF that contains the charts, drawn as vector, with the fixed colors', function () {
    chartsSeed($this);

    $pdf = $this->actingAs($this->admin)->get('/reports/export/pdf?type=monthly&month=2026-09&scope=all')->assertOk();
    $bytes = $pdf->getContent();

    expect($bytes)->toStartWith('%PDF');

    // Read the page drawing commands: the navy of ER and the blue of OPD are filled in as rectangles.
    $drawing = '';
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);

    foreach ($streams[1] as $stream) {
        $plain = @gzuncompress($stream);
        $drawing .= $plain === false ? '' : $plain;
    }

    $fill = function (string $hex): string {
        [$r, $g, $b] = array_map(fn (string $c): int => hexdec($c), str_split(ltrim($hex, '#'), 2));

        return sprintf('/%s %s %s rg/', preg_quote(rtrim(rtrim(number_format($r / 255, 3, '.', ''), '0'), '.'), '/').'\d*', preg_quote(rtrim(rtrim(number_format($g / 255, 3, '.', ''), '0'), '.'), '/').'\d*', preg_quote(rtrim(rtrim(number_format($b / 255, 3, '.', ''), '0'), '.'), '/').'\d*');
    };

    expect($drawing)->not->toBe('')
        ->and($drawing)->toMatch($fill(ChartPalette::CATEGORIES['ER']))
        ->and($drawing)->toMatch($fill(ChartPalette::CATEGORIES['OPD']))
        ->and($drawing)->toMatch($fill(ChartPalette::MALE))
        ->and($drawing)->toMatch($fill(ChartPalette::FEMALE));
});

// --- Excel ----------------------------------------------------------------------------------------

/**
 * The charts of an Excel file: for each, [sheet, title, series colors, references], from the file's XML.
 *
 * @return list<array{title: string, colors: list<string>, refs: list<string>, anchor: array{from: int, to: int}}>
 */
function chartExcelCharts(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($path);

    $charts = [];

    for ($n = 1; $zip->locateName("xl/charts/chart{$n}.xml") !== false; $n++) {
        $xml = $zip->getFromName("xl/charts/chart{$n}.xml");
        preg_match('/<a:t>(.*?)<\/a:t>/', $xml, $title);
        preg_match_all('/<c:f>(.*?)<\/c:f>/', $xml, $refs);
        preg_match_all('/<c:spPr><a:solidFill><a:srgbClr val="([0-9A-F]{6})"/', $xml, $colors);

        $charts[] = [
            'title' => html_entity_decode($title[1] ?? ''),
            'colors' => $colors[1],
            'refs' => array_map(fn (string $ref): string => html_entity_decode($ref), $refs[1]),
            'anchor' => ['from' => 0, 'to' => 0],
        ];
    }

    // Where each chart is drawn: the rows between its top left and bottom right corner (0-based in the file).
    $anchors = [];

    for ($n = 1; $zip->locateName("xl/drawings/drawing{$n}.xml") !== false; $n++) {
        preg_match_all('/<xdr:from>.*?<xdr:row>(\d+)<\/xdr:row>.*?<\/xdr:from><xdr:to>.*?<xdr:row>(\d+)<\/xdr:row>/s', $zip->getFromName("xl/drawings/drawing{$n}.xml"), $found, PREG_SET_ORDER);

        foreach ($found as $one) {
            $anchors[] = ['from' => (int) $one[1] + 1, 'to' => (int) $one[2] + 1];
        }
    }

    $zip->close();
    unlink($path);

    foreach ($charts as $index => $chart) {
        $charts[$index]['anchor'] = $anchors[$index] ?? ['from' => 0, 'to' => 0];
    }

    return $charts;
}

function chartBookOf(TestResponse $response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    try {
        return IOFactory::load($path);
    } finally {
        unlink($path);
    }
}

function chartExcelResponse(object $test, string $query = 'type=monthly&month=2026-09'): TestResponse
{
    return $test->actingAs($test->admin)->get('/reports/export/excel?'.$query)->assertOk();
}

/** The rows of a table in a sheet: [first data row, last data row, header row], found by its heading in column A. */
function chartTableRowsIn(Worksheet $sheet, array $table): array
{
    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        if ($sheet->getCell("A{$row}")->getValue() === $table['title'] && $sheet->getCell('A'.($row + 1))->getValue() === $table['columns'][0]['label']) {
            return [$row + 2, $row + 1 + count($table['rows']), $row + 1, $row];
        }
    }

    throw new RuntimeException('Table not found: '.$table['title']);
}

it('puts native Excel charts above their tables, with the same numbers, titles and colors', function () {
    chartsSeed($this);
    $resolved = chartsResolved();
    $response = chartExcelResponse($this);
    $book = chartBookOf($response);
    $charts = chartExcelCharts($response);

    // Expected, in the order the file holds them: each sheet's charts in the order of its tables.
    $expected = [];

    foreach (['summary' => 'Summary', 'OPD' => 'OPD', 'ER' => 'ER', 'Admission' => 'Admission'] as $page => $sheetName) {
        foreach ($resolved['sections'][$page] as $table) {
            if (isset($resolved['charts'][$page][$table['key']])) {
                $expected[] = [$sheetName, $page, $table, $resolved['charts'][$page][$table['key']]];
            }
        }
    }

    expect($charts)->toHaveCount(count($expected))->toHaveCount(12);

    foreach ($expected as $index => [$sheetName, $page, $table, $chart]) {
        $actual = $charts[$index];
        [$first, $last, $header, $heading] = chartTableRowsIn($book->getSheetByName($sheetName), $table);

        // The daily census chart reads the hidden sheet "Chart data" (header in row 1, one row for each day or month).
        $onDataSheet = isset($chart['source']['sheet']);
        $readSheet = $onDataSheet ? 'Chart data' : $sheetName;

        if ($onDataSheet) {
            [$first, $last, $header] = [2, 1 + count($chart['rows']), 1];
        }

        // Title and colors are the chart's own, with data labels on.
        expect($actual['title'])->toBe($chart['title'].' – '.$chart['subtitle'])
            ->and($actual['colors'])->toBe(array_map(fn (array $one): string => ChartPalette::argb($one['color']), $chart['series']))
            // Above the table: the chart ends before the table's heading.
            ->and($actual['anchor']['to'])->toBeLessThan($heading)
            ->and($actual['anchor']['from'])->toBeLessThan($actual['anchor']['to']);

        // The series point at this sheet, inside the table, and read the table's own numbers.
        $source = $chart['source'];
        $sheet = $book->getSheetByName($readSheet);
        $values = [];

        foreach ($actual['refs'] as $ref) {
            expect($ref)->toMatch("/^'{$readSheet}'!\\\$[A-E]\\\$\\d+(:\\\$[A-E]\\\$\\d+)?$/");
            preg_match('/\$([A-E])\$(\d+)(?::\$([A-E])\$(\d+))?$/', $ref, $m);
            [$col, $from, $to] = [$m[1], (int) $m[2], (int) ($m[4] ?? $m[2])];

            // Series names are the header cells; everything else is inside the table's rows.
            if ($from === $to && $from === $header) {
                continue;
            }

            expect($from)->toBeGreaterThanOrEqual($first)->and($to)->toBeLessThanOrEqual($last);

            $cells = [];

            for ($row = $from; $row <= $to; $row++) {
                $cells[] = $sheet->getCell("{$col}{$row}")->getValue();
            }

            $values[$col] = $cells;
        }

        // Labels in column A, then one column for each series: the chart's own rows.
        expect($values['A'])->toBe(array_map(fn (array $row): string => $row['label'], $chart['rows']));

        foreach ($source['cols'] as $position => $col) {
            expect($values[chr(65 + $col)])->toBe(array_map(fn (array $row): int => $row['values'][$position], $chart['rows']));
        }
    }
});

it('keeps every Excel chart inside its own table, or the hidden Chart data sheet for the daily census', function () {
    chartsSeed($this);
    $response = chartExcelResponse($this);

    foreach (chartExcelCharts($response) as $chart) {
        foreach ($chart['refs'] as $ref) {
            preg_match_all('/\$([A-Z])\$(\d+)/', $ref, $m);

            // Columns A to E only, and the sheet is one of the report's four or the hidden one.
            expect($m[1])->each->toBeIn(['A', 'B', 'C', 'D', 'E'])
                ->and($ref)->toMatch("/^'(Summary|OPD|ER|Admission|Chart data)'!/");
        }
    }
});

it('says there is nothing to chart in Excel when a period has no visits, instead of an empty chart', function () {
    chartsSeed($this);
    $response = chartExcelResponse($this, 'type=monthly&month=2026-05');
    $book = chartBookOf($response);
    $texts = [];

    foreach ($book->getSheetByName('Summary')->toArray() as $row) {
        $texts[] = (string) $row[0];
    }

    // Only the comparison chart is left (May has April before it, also empty, so it is empty too).
    expect(count(chartExcelCharts($response)))->toBe(0)
        ->and($texts)->toContain('Daily census: No visits in this period.')
        ->and($texts)->toContain('Top diagnoses: No visits in this period.');
});

it('writes the Excel styles by range, so the file has only a handful of cell formats', function () {
    chartsSeed($this);
    $response = chartExcelResponse($this);
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    preg_match('/<cellXfs count="(\d+)"/', $zip->getFromName('xl/styles.xml'), $m);
    $zip->close();
    unlink($path);

    // Cell by cell styling made hundreds; one style per look makes a few dozen at most.
    expect((int) $m[1])->toBeLessThan(80);
});

// --- Saved copies ---------------------------------------------------------------------------------

it('draws a closed month from its saved copy, so its charts never change', function () {
    chartsSeed($this);
    app(MonthService::class)->close('2026-09', $this->admin);

    $before = chartsResolved()['charts'];

    // A late change to the records (a visit added to September) and to a list does not touch the final report.
    chartVisit($this, Patient::factory()->create(['sex' => 'Female']), '2026-09-12 09:00', 'ER');
    $this->army->update(['name' => 'Army (renamed)']);

    $after = chartsResolved();

    expect($after['snapshot'])->not->toBeNull()
        ->and($after['charts'])->toEqual($before)
        ->and($after['charts']['summary']['branches']['rows'][0]['label'])->toBe('Army');
});

// --- Speed: how many queries, and the cache ------------------------------------------------------------

/** The number of queries a callback runs. */
function chartQueriesOf(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('builds a full monthly, quarterly and annual report in a bounded number of queries', function () {
    chartsSeed($this);

    $monthly = chartQueriesOf(fn () => chartsResolved('type=monthly&month=2026-09'));
    $quarterly = chartQueriesOf(fn () => chartsResolved('type=quarterly&year=2026&quarter=3'));
    $annual = chartQueriesOf(fn () => chartsResolved('type=annual&year=2026'));

    expect($monthly)->toBeLessThan(40)
        ->and($quarterly)->toBeLessThan(40)
        ->and($annual)->toBeLessThan(40);
});

it('does not run more queries for more data: the count is the same for 1 visit and for 300', function () {
    chartVisit($this, Patient::factory()->create(), '2026-09-02 09:00', 'ER');
    $few = chartQueriesOf(fn () => app(ReportBuilder::class)->build('2026-09'));

    foreach (range(1, 100) as $n) {
        $patient = Patient::factory()->create();

        foreach (['ER', 'OPD', 'Admission'] as $category) {
            chartVisit($this, $patient, '2026-09-'.str_pad((string) (($n % 28) + 1), 2, '0', STR_PAD_LEFT).' 09:00', $category, ['age' => $n % 90]);
        }
    }

    $many = chartQueriesOf(fn () => app(ReportBuilder::class)->build('2026-09'));

    // The number of lines read for typed ranks can differ by a query or two; nothing grows with the number of visits.
    expect($many)->toBe($few);
});

it('counts a report once and shows it again from memory while nothing has changed', function () {
    chartsSeed($this);

    $counting = fn (): int => count(array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER'),
    ));

    DB::flushQueryLog();
    DB::enableQueryLog();

    chartsResolved();
    $first = $counting();

    DB::flushQueryLog();
    // The same period again (Generate, then Export): not counted again.
    $again = chartsResolved();
    $second = $counting();

    expect($first)->toBe(2)
        ->and($second)->toBe(0)
        ->and($again['report']['totals'])->toBe(['patients' => 5, 'visits' => 7]);

    DB::disableQueryLog();
});

it('counts again as soon as a visit, a patient, a list or a month changes', function () {
    chartsSeed($this);
    $counting = fn (callable $callback): int => (function () use ($callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $found = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER')));
        DB::disableQueryLog();

        return $found;
    })();

    chartsResolved();

    // A new visit.
    chartVisit($this, Patient::factory()->create(['sex' => 'Female']), '2026-09-20 09:00', 'ER');
    $newVisit = [$counting(fn () => $report = chartsResolved()), chartsResolved()['report']['totals']];

    // A visit moved to Trash (soft delete).
    Visit::query()->latest('id')->first()->delete();
    $afterTrash = [$counting(fn () => chartsResolved()), chartsResolved()['report']['totals']];

    // A patient's sex corrected: changes the Male / Female chart.
    Patient::query()->where('sex', 'Male')->first()->update(['sex' => 'Female']);
    $afterSex = $counting(fn () => chartsResolved());

    // A list item renamed (the model raises the report data version).
    $this->navy->update(['name' => 'Philippine Navy']);
    $afterList = $counting(fn () => chartsResolved());
    $branchLabels = array_column(chartsResolved()['charts']['summary']['branches']['rows'], 'label');

    // And unchanged again: from memory.
    $steady = $counting(fn () => chartsResolved());

    expect($newVisit[0])->toBe(2)->and($newVisit[1])->toBe(['patients' => 6, 'visits' => 8])
        ->and($afterTrash[0])->toBe(2)->and($afterTrash[1])->toBe(['patients' => 5, 'visits' => 7])
        ->and($afterSex)->toBe(2)
        ->and($afterList)->toBe(2)
        ->and($branchLabels)->toContain('Philippine Navy')
        ->and($steady)->toBe(0);
});

it('does not remember a report across ReportBuilder versions, and gives every period its own copy', function () {
    chartsSeed($this);
    $cache = app(ReportCache::class);

    $sept = $cache->build(Carbon::parse('2026-09-01', 'Asia/Manila'), Carbon::parse('2026-10-01', 'Asia/Manila'));
    $aug = $cache->build(Carbon::parse('2026-08-01', 'Asia/Manila'), Carbon::parse('2026-09-01', 'Asia/Manila'));

    expect($sept['totals']['visits'])->toBe(7)
        ->and($aug['totals']['visits'])->toBe(2)
        ->and($cache->stamp())->toMatch('/^\d+$/');
});

it('reads a closed month from its saved copy without counting', function () {
    chartsSeed($this);
    app(MonthService::class)->close('2026-09', $this->admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolved = chartsResolved();
    $counted = count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'ROW_NUMBER')));
    DB::disableQueryLog();

    expect($counted)->toBe(0)
        ->and($resolved['snapshot'])->not->toBeNull()
        ->and($resolved['charts']['summary']['sex']['rows'][0]['values'])->toBe([3, 2]);
});

it('counts nothing for a chart: the ReportCharts service only reads the tables and the daily census of the report', function () {
    chartsSeed($this);
    $resolved = chartsResolved();

    // The same tables give the same charts: nothing is counted in ReportCharts.
    $again = app(ReportCharts::class)->pages($resolved['sections'], $resolved['filters']->label, $resolved['report']['daily']);

    expect($again)->toEqual($resolved['charts']);
});

it('puts the daily figures on a very hidden last sheet "Chart data", which has the report\'s own numbers and no formula', function () {
    chartsSeed($this);
    $resolved = chartsResolved();
    $book = chartBookOf(chartExcelResponse($this));

    $names = array_map(fn ($sheet): string => $sheet->getTitle(), $book->getAllSheets());
    $data = $book->getSheetByName('Chart data');
    $rows = $data->toArray(null, false, false, false);
    $days = $resolved['report']['daily']['rows'];

    expect($names)->toBe(['Summary', 'OPD', 'ER', 'Admission', 'Chart data'])
        ->and($data->getSheetState())->toBe(Worksheet::SHEETSTATE_VERYHIDDEN)
        // The report sheets stay visible, and the first one opens.
        ->and($book->getSheetByName('Summary')->getSheetState())->toBe(Worksheet::SHEETSTATE_VISIBLE)
        ->and($book->getActiveSheetIndex())->toBe(0)
        ->and($rows[0])->toBe(['Day', 'OPD', 'ER', 'Admission'])
        ->and($rows)->toHaveCount(1 + count($days))
        ->and($rows[4])->toBe(['04-Sep-2026 (Fri)', 0, 2, 0])
        ->and($rows[5])->toBe(['05-Sep-2026 (Sat)', 2, 0, 0]);

    foreach ($days as $index => $day) {
        expect($rows[$index + 1])->toBe([$day['label'], ...array_values($day['counts'])]);
    }

    // Nothing on it is a formula.
    foreach ($data->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            expect($cell->isFormula())->toBeFalse();
        }
    }
});

it('names the months on the Chart data sheet for a quarter, which keeps its by-month rows in the table', function () {
    chartsSeed($this);
    $book = chartBookOf(chartExcelResponse($this, 'type=quarterly&year=2026&quarter=3'));
    $rows = $book->getSheetByName('Chart data')->toArray(null, false, false, false);

    expect($rows[0][0])->toBe('Month')
        ->and(array_column($rows, 0))->toBe(['Month', 'July 2026', 'August 2026', 'September 2026'])
        ->and($rows[3])->toBe(['September 2026', 2, 4, 1]);

    $summary = $book->getSheetByName('Summary')->toArray(null, false, false, false);

    expect(array_column($summary, 0))->toContain('Monthly census (visits)')->toContain('July 2026')->toContain('September 2026');
});

it('shows no day by day rows in the daily census table on the screen, Print and PDF, only the totals and the busiest day', function () {
    chartsSeed($this);
    $resolved = chartsResolved();
    $html = chartPdfHtml($resolved, ['summary', 'ER']);

    expect($html)->toContain('Busiest day: 04-Sep-2026 (2 visits)')
        ->and($html)->not->toContain('03-Sep-2026')
        ->and($html)->not->toContain('(Fri)')
        ->and($html)->toContain('All days');

    $screen = $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09&category=summary')->assertOk()->inertiaProps();
    $table = collect($screen['sections']['summary'])->firstWhere('key', 'daily');

    expect(array_column($table['rows'], 'cells'))->toBe([['All days', 2, 4, 1, 7]]);
});

it('draws every chart on white paper and tags only its neutral parts, so a dark screen can recolor them and Print, PDF and Excel stay light (Step 7f)', function () {
    chartsSeed($this);

    foreach (chartsAll(chartsResolved()) as [$page, $chart]) {
        foreach (['svg', 'svg_narrow'] as $key) {
            $svg = $chart[$key];

            // The drawing carries its own white page and dark ink: that is what Print and the PDF show.
            expect($svg)->toContain('fill="#FFFFFF" class="rc-bg"')
                ->and($svg)->not->toContain('dark')
                ->and($svg)->not->toContain('var(--');

            // Every neutral text and line is tagged; a data color never is.
            preg_match_all('/<text [^>]*fill="(#[0-9A-Fa-f]{6})"([^>]*)>/', $svg, $texts, PREG_SET_ORDER);

            foreach ($texts as [, $color, $rest]) {
                if (in_array($color, [ChartPalette::INK, ChartPalette::MUTED], true) && ! str_contains($rest, 'class=')) {
                    // Only the share inside a colored bar is left untagged.
                    expect($color)->toBe(ChartPalette::INK);
                }
            }

            preg_match_all('/<line [^>]*stroke="(#[0-9A-Fa-f]{6})"[^>]*\/>/', $svg, $lines, PREG_SET_ORDER);

            foreach ($lines as [$tag, $color]) {
                if (in_array($color, [ChartPalette::GRID, ChartPalette::AXIS], true)) {
                    expect($tag)->toContain('class="rc-');
                }
            }
        }
    }
});
