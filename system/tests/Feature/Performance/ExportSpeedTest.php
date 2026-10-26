<?php

use App\Models\AgeBracket;
use App\Models\Branch;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\Rank;
use App\Models\Setting;
use App\Models\Visit;
use App\Services\HospitalSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/*
 * Step 7g: the faster Excel and PDF exports make the same files. The workbook
 * keeps its sheets, its 12 native charts and its merged title, note and
 * signature cells; Export right after Generate does not count the report
 * again; the PDF's small logo is made once per logo.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00', 'Asia/Manila'));
    $this->admin = userWithRole('admin');
    $encoder = userWithRole('encoder');

    $army = Branch::factory()->create(['name' => 'Army', 'sort_order' => 1]);
    $cpl = Rank::factory()->for($army)->create(['name' => 'CPL']);
    $hypertension = Diagnosis::factory()->create(['name' => 'Hypertension']);
    $asthma = Diagnosis::factory()->create(['name' => 'Asthma']);
    AgeBracket::create(['min_age' => 0, 'max_age' => 17]);
    AgeBracket::create(['min_age' => 18, 'max_age' => 59]);

    $visit = fn (string $when, string $category, Diagnosis $diagnosis, string $sex = 'Male') => Visit::factory()->create([
        'patient_id' => Patient::factory()->create(['sex' => $sex])->id,
        'visited_at' => Carbon::parse($when, 'Asia/Manila'),
        'category' => $category,
        'age' => 30,
        'branch_id' => $army->id,
        'rank_id' => $cpl->id,
        'diagnosis_id' => $diagnosis->id,
        'encoded_by' => $encoder->id,
    ]);

    // August (the "previous period" of September) and every category in September.
    $visit('2026-08-15 09:00', 'ER', $hypertension);
    $visit('2026-09-03 09:00', 'ER', $hypertension);
    $visit('2026-09-04 09:00', 'ER', $asthma, 'Female');
    $visit('2026-09-05 09:00', 'OPD', $asthma, 'Female');
    $visit('2026-09-06 09:00', 'Admission', $hypertension);
});

/** The Excel file of a response, read back with its charts. */
function speedWorkbook(string $content): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $content);

    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        return $reader->load($path);
    } finally {
        unlink($path);
    }
}

it('writes the same sheets, the 12 native charts and the merged title cells', function () {
    $book = speedWorkbook($this->actingAs($this->admin)->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk()->getContent());

    $charts = collect($book->getAllSheets())->mapWithKeys(fn ($sheet) => [$sheet->getTitle() => count($sheet->getChartCollection())])->all();

    expect($book->getSheetNames())->toBe(['Summary', 'OPD', 'ER', 'Admission', 'Chart data'])
        ->and($charts)->toBe(['Summary' => 6, 'OPD' => 2, 'ER' => 2, 'Admission' => 2, 'Chart data' => 0]);

    foreach (['Summary', 'OPD', 'ER', 'Admission'] as $title) {
        $sheet = $book->getSheetByName($title);
        $merges = array_values($sheet->getMergeCells());

        // Hospital, office, report title, period, status and "Generated": one merged line each.
        expect($merges)->toContain('A2:E2', 'A3:E3', 'A4:E4', 'A5:E5', 'A6:E6', 'A7:E7');

        // A merged range keeps its text in its first cell; the others are empty.
        foreach ($merges as $range) {
            $cells = $sheet->rangeToArray($range, null, false, false, false);
            $rest = array_slice(array_merge(...$cells), 1);

            expect(array_filter($rest, fn ($value) => $value !== null && $value !== ''))->toBe([]);
        }
    }
});

it('does not count the report again when Excel or PDF follows Generate', function () {
    $this->actingAs($this->admin)->get('/reports?type=monthly&month=2026-09')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/reports/export/excel?type=monthly&month=2026-09')->assertOk();
    $this->get('/reports/export/pdf?type=monthly&month=2026-09')->assertOk();
    DB::disableQueryLog();

    $counting = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'ROW_NUMBER') || str_contains($sql, 'COUNT('));

    expect($counting->all())->toBe([]);
});

it('makes the small PDF logo once, and again only for a new logo', function () {
    Storage::fake('local');

    $png = function (int $size, array $rgb): string {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };

    Storage::disk('local')->put('logo.png', $png(600, [10, 40, 90]));
    Setting::query()->updateOrCreate(['key' => 'logo_path'], ['value' => 'logo.png']);

    $settings = app(HospitalSettings::class);
    $first = $settings->imageDataUri('logo', 160);

    [$width, $height] = getimagesizefromstring(base64_decode(substr($first, strlen('data:image/png;base64,'))));

    expect([$width, $height])->toBe([160, 160]);

    // Remembered under the logo's time and size: asked again, nothing new is made.
    $keys = fn () => collect((fn () => array_keys($this->storage))->call(Cache::store('array')->getStore()))
        ->filter(fn (string $key) => str_starts_with($key, 'picture-small:'))
        ->values();

    expect($settings->imageDataUri('logo', 160))->toBe($first)
        ->and($keys())->toHaveCount(1);

    // A new logo (another file size) gets its own small copy.
    Storage::disk('local')->put('logo.png', $png(300, [200, 10, 10]));

    $second = $settings->imageDataUri('logo', 160);

    expect($second)->not->toBe($first)
        ->and($keys())->toHaveCount(2);
});
