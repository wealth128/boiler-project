<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers the report ReportBuilder counted for a period, so showing the
 * screen and then taking the PDF, the Excel file or the print view of the
 * same period does not count it again from zero.
 *
 * A remembered report is only used while nothing it is made from has
 * changed. The key holds the period, ReportBuilder::VERSION (a new report
 * shape never reads an old copy) and the "report data version", a number
 * that goes up on any change to a visit, a patient, a branch, rank,
 * diagnosis or age range, a month closed or reopened, a saved copy or an
 * import (see ReportDataVersion). The next request then counts again. A
 * change made by hand in the database, outside the application, is not seen
 * until the hour is over.
 *
 * Closed months do not come here: they are shown from their saved copy
 * (ReportSheetResolver). Nothing in the cache is shared between periods or
 * people: the report holds counts, never names.
 */
class ReportCache
{
    /** How long an unchanged report is kept: one hour, then it is counted again anyway. */
    private const SECONDS = 3600;

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
     * Changes whenever any data the report is made from changes: the "report
     * data version" (ReportDataVersion). One small query, no table is read.
     */
    public function stamp(): string
    {
        return ReportDataVersion::current();
    }
}
