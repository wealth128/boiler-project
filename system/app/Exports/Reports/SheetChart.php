<?php

namespace App\Exports\Reports;

use App\Services\Charts\ChartPalette;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Layout;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

/**
 * One report chart as a native Excel chart (no picture), made from the
 * chart ReportCharts describes. Its series point at the cells of the table
 * the chart sits above, and only at those: the labels in the table's first
 * column, one series for each value column (named by the column header),
 * over the rows the chart uses. Nothing is copied or counted again.
 * The one exception is the daily census chart: its table holds only the
 * totals, so it reads the day by day figures from the hidden sheet "Chart data"
 * (ChartDataSheet), which are the report's own daily census.
 *
 * Same colors as the screen. Title and data labels are on; zero is not
 * written on the labels. The Age by sex chart is drawn as side by side bars
 * (Excel cannot mirror one series without helper cells outside the table).
 */
final class SheetChart
{
    /** Rows of the sheet kept free for each kind of chart (15 points each). */
    public const ROWS = ['columns' => 16, 'share' => 9, 'pyramid' => 17, 'bars' => 17, 'paired' => 11];

    /**
     * @param  array<string, mixed>  $chart  from ReportCharts
     * @param  int  $headerRow  the sheet row of the table's header
     * @param  int  $top  first sheet row of the chart
     * @param  int  $bottom  last sheet row of the chart
     */
    public static function make(array $chart, string $sheetTitle, int $headerRow, int $top, int $bottom, string $lastColumn): Chart
    {
        ['first' => $first, 'last' => $last, 'label_col' => $labelCol, 'cols' => $cols] = $chart['source'];

        // The daily chart reads the hidden sheet that holds its day by day figures; every other chart reads its own table.
        if (isset($chart['source']['sheet'])) {
            $sheetTitle = $chart['source']['sheet'];
            $headerRow = $chart['source']['header_row'];
        }

        $sheet = "'".str_replace("'", "''", $sheetTitle)."'!";
        $firstRow = $headerRow + 1 + $first;
        $lastRow = $headerRow + 1 + $last;
        $count = $lastRow - $firstRow + 1;
        $letter = fn (int $index): string => chr(65 + $index);

        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $sheet.'$'.$letter($labelCol).'$'.$firstRow.':$'.$letter($labelCol).'$'.$lastRow, null, $count)];
        $names = [];
        $values = [];

        foreach ($cols as $position => $col) {
            $names[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $sheet.'$'.$letter($col).'$'.$headerRow, null, 1);

            $series = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $sheet.'$'.$letter($col).'$'.$firstRow.':$'.$letter($col).'$'.$lastRow, '#,##0', $count);
            $series->setFillColor(ChartPalette::argb($chart['series'][$position]['color']));
            $values[] = $series;
        }

        $horizontal = in_array($chart['kind'], ['share', 'pyramid', 'bars', 'paired'], true);
        $grouping = match (true) {
            $chart['kind'] === 'share' => DataSeries::GROUPING_PERCENT_STACKED,
            $chart['kind'] === 'columns' && count($cols) > 1 => DataSeries::GROUPING_STACKED,
            default => DataSeries::GROUPING_CLUSTERED,
        };

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            $grouping,
            range(0, count($cols) - 1),
            $names,
            $categories,
            $values,
            $horizontal ? DataSeries::DIRECTION_BAR : DataSeries::DIRECTION_COL,
        );

        // The number on every bar; a zero is left out.
        $labels = new Layout;
        $labels->setShowVal(true);
        $labels->setShowLegendKey(false);
        $labels->setShowCatName(false);
        $labels->setShowSerName(false);
        $labels->setShowPercent(false);
        $labels->setShowBubbleSize(false);
        $labels->setNumFmtCode('#,##0;-#,##0;');
        $labels->setNumFmtLinked(false);

        // Bars that read top down (highest first, first age range at the top of a list, Patients over Visits).
        $categoryAxis = new Axis;

        if (in_array($chart['kind'], ['bars', 'paired'], true)) {
            $categoryAxis->setAxisOrientation(Axis::ORIENTATION_REVERSED);
        }

        $drawing = new Chart(
            'chart_'.$chart['key'],
            new Title($chart['title'].' – '.$chart['subtitle']),
            count($cols) > 1 ? new Legend(Legend::POSITION_BOTTOM, null, false) : null,
            new PlotArea($labels, [$series]),
            // The daily chart's figures are on a hidden sheet, so hidden cells must still be drawn.
            false,
            DataSeries::EMPTY_AS_GAP,
            null,
            null,
            $categoryAxis,
        );

        $drawing->setTopLeftPosition('A'.$top);
        $drawing->setBottomRightPosition($lastColumn.$bottom);

        return $drawing;
    }
}
