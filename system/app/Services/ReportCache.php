<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remembers the report ReportBuilder counted for a period, so showing the
 * screen and then taking the PDF, the Excel file or the print view of the
 * same period does not count it again from zero.
 *
 * A remembered report is only used while nothing it is made from has
 * changed. The key holds the period, ReportBuilder::VERSION (a new report
 * shape never reads an old copy) and a "data changed" stamp: a checksum of
 * every table the figures come from. Any change to a visit, a patient, a
 * branch, rank, diagnosis or age range, a month closed or reopened, or a
 * saved copy, whether made by the application or by hand in the database,
 * gives a new stamp, so the next request counts again.
 *
 * Closed months do not come here: they are shown from their saved copy
 * (ReportSheetResolver). Nothing in the cache is shared between periods or
 * people: the report holds counts, never names.
 */
class ReportCache
{
    /** How long an unchanged report is kept: one hour, then it is counted again anyway. */
    private const SECONDS = 3600;

    /** The tables the figures of a report come from. */
    private const TABLES = ['visits', 'patients', 'branches', 'ranks', 'diagnoses', 'age_brackets', 'month_closures', 'report_snapshots'];

    private ?string $stamp = null;

    public function __construct(private readonly ReportBuilder $builder) {}

    /**
     * The report for the time from $from up to (not including) $to,
     * counted now or remembered from the last time with the same data.
     *
     * @return array<string, mixed>
     */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = sprintf(
            'report:v%d:%s:%s:%s',
            ReportBuilder::VERSION,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $this->stamp(),
        );

        return Cache::remember($key, self::SECONDS, fn (): array => $this->builder->build($from, $to));
    }

    /**
     * Changes whenever any table the report is made from changes. One query,
     * read once per request.
     */
    public function stamp(): string
    {
        if ($this->stamp !== null) {
            return $this->stamp;
        }

        $sums = [];

        // Table names are fixed above; CHECKSUM TABLE takes names, not bindings.
        foreach (DB::select('CHECKSUM TABLE '.implode(', ', self::TABLES)) as $row) {
            $sums[] = $row->Table.'='.($row->Checksum ?? 'x');
        }

        return $this->stamp = md5(implode('|', $sums));
    }
}
