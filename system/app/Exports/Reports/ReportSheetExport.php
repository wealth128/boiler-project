<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCharts;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet of the report workbook: a title block, then tables stacked
 * top to bottom (each with its chart above it, when it has one), then the
 * Prepared by / Noted by lines. Only the look is decided here; the rows are
 * the report's numbers and labels as given.
 * A count of 0 is written as 0, not left empty (WithStrictNullComparison).
 *
 * Every text value is stored as plain text. A name or diagnosis that starts
 * with =, +, - or @ can therefore never run as a formula (planning/security.md
 * section 2, rule 7). Counts are integers, so they stay numbers.
 *
 * Signature pictures are never put in Excel (decided in 6h); the logo is.
 *
 * Speed: styles are not set cell by cell. Every row and cell is first put in
 * a group of the same look (header, zebra row, number column ...), and each
 * group gets its style once, over all its ranges. One style call for a whole
 * table column instead of one for each cell is what keeps a big workbook fast.
 */
abstract class ReportSheetExport extends DefaultValueBinder implements FromArray, WithCharts, WithColumnWidths, WithCustomValueBinder, WithDrawings, WithStrictNullComparison, WithStyles, WithTitle
{
    private const NAVY = '1F3864';

    private const ZEBRA = 'F2F5FA';

    private const LINE = '9AA5B8';

    /** The last column used: tables have up to five. */
    private const LAST_COLUMN = 'E';

    /** Column widths (characters) that still fit an A4 portrait page; a wider sheet prints landscape. */
    private const PORTRAIT_WIDTH = 100;

    /** Row height of Excel, in points, for a row that was not given one. */
    private const ROW = 15;

    /** How a column of each type is shown. */
    private const FORMATS = [
        'int' => '#,##0',
        'pct' => '0.0"%"',
        'delta' => '+#,##0;-#,##0;0',
        'deltapct' => '+0.0"%";-0.0"%";0.0"%"',
    ];

    /** @var list<list<string|int|float>> */
    private array $rows = [];

    /** @var array<int, array{kind: string, zebra: bool, types: list<string>}> Row number (1-based) => how it looks. */
    private array $kinds = [];

    private int $tableRows = 0;

    private int $titleBlockEnd = 0;

    private int $reportTitleRow = 0;

    private int $periodRow = 0;

    /** @var list<string> The column types of the table being built. */
    private array $types = [];

    /** @var array<string, int> Table key => the sheet row of its header. */
    private array $headerRows = [];

    /** @var list<array{chart: array<string, mixed>, table: string, top: int, bottom: int}> The charts and the rows kept free for them. */
    private array $placed = [];

    /** @var array<string, array<string, list<array{0: int, 1: int}>>> Look => column span "A:C" => runs of rows (first, last). */
    private array $groups = [];

    /** @var list<string> */
    private array $merges = [];

    /** @var array<int, float> Row number => height in points (only the rows that have one). */
    private array $heights = [];

    /** @var array<string, int>|null */
    private ?array $widths = null;

    /**
     * @param  array{hospital_name: string, office: string, prepared_by: string, prepared_by_title: string, noted_by: string, noted_by_title: string, period_label: string, status: string, generated: string}  $meta
     */
    public function __construct(
        private readonly string $sheetTitle,
        private readonly string $reportTitle,
        private readonly array $meta,
        private readonly ?string $logoPath,
    ) {
        $this->buildTitleBlock();
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    /** @return list<list<string|int|float>> */
    public function array(): array
    {
        return $this->rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** The hospital logo at the top left, when one is set. */
    public function drawings(): array
    {
        if ($this->logoPath === null) {
            return [];
        }

        $logo = new Drawing;
        $logo->setName('Hospital logo');
        $logo->setPath($this->logoPath);
        $logo->setResizeProportional(true);
        $logo->setHeight(48);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(4);
        $logo->setOffsetY(3);

        return [$logo];
    }

    /**
     * The native charts, each over the rows kept free above its table.
     *
     * @return list<Chart>
     */
    public function charts(): array
    {
        return array_map(
            fn (array $placed): Chart => SheetChart::make($placed['chart'], $this->sheetTitle, $this->headerRows[$placed['table']], $placed['top'], $placed['bottom'], self::LAST_COLUMN),
            $this->placed,
        );
    }

    /**
     * Column A fits its longest label (a table row, not a heading that may
     * spill over the empty cells beside it); the others fit their header and
     * figures. Wrapping takes care of anything longer than the limit.
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        if ($this->widths !== null) {
            return $this->widths;
        }

        $longest = array_fill(0, 5, 0);

        foreach ($this->kinds as $row => $look) {
            if (! in_array($look['kind'], ['header', 'body', 'total', 'group'], true)) {
                continue;
            }

            foreach ($this->rows[$row - 1] as $index => $cell) {
                $text = is_int($cell) ? number_format($cell) : (string) $cell;
                // A header may wrap onto two lines, so it counts for half.
                $length = $look['kind'] === 'header' && $index > 0 ? (int) ceil(mb_strlen($text) / 2) : mb_strlen($text);
                $longest[$index] = max($longest[$index], $length + ($look['kind'] === 'header' ? 2 : 0));
            }
        }

        $widths = ['A' => min(60, max(26, $longest[0] + 4))];

        foreach (['B', 'C', 'D', 'E'] as $index => $column) {
            $widths[$column] = min(24, max(12, $longest[$index + 1] + 4));
        }

        return $this->widths = $widths;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->setShowGridLines(false);

        foreach ($this->kinds as $row => $look) {
            match ($look['kind']) {
                'hospital' => $this->titleLine($row, 'hospital', 16),
                'office' => $this->titleLine($row, 'office', 11),
                'report' => $this->titleLine($row, 'report', 13),
                'period', 'status', 'generated' => $this->titleLine($row, 'small', 10),
                'note' => $this->noteLine($row),
                'section' => $this->sectionLine($row),
                'header' => $this->headerLine($row),
                'sign' => $this->signatureLine($row),
                'logo' => $this->heights[$row] = 42,
                'blank', 'chart' => null,
                default => $this->tableLine($row, $look['zebra'], $look['kind']),
            };
        }

        // One style call for each look, over all the ranges that share it.
        foreach ($this->groups as $look => $spans) {
            $ranges = [];

            foreach ($spans as $span => $runs) {
                [$from, $to] = explode(':', $span);

                foreach ($runs as [$first, $last]) {
                    $ranges[] = $from.$first.':'.$to.$last;
                }
            }

            $sheet->getStyle(implode(',', $ranges))->applyFromArray($this->style($look));
        }

        // The other cells of a merged range are always empty here (a title, note or name
        // sits in its first cell), so there is nothing to blank out: "hide" skips that walk
        // over the range and writes the same file.
        foreach ($this->merges as $range) {
            $sheet->mergeCells($range, Worksheet::MERGE_CELL_CONTENT_HIDE);
        }

        foreach ($this->heights as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        $this->setUpPage($sheet);

        return [];
    }

    // --- Building rows ---------------------------------------------------------------

    /** A heading over a table. Starts a new table. */
    protected function sectionRow(string $heading): void
    {
        $this->blank();
        $this->push([$heading], 'section');
        $this->tableRows = 0;
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $types  the type of each column (text, int, pct, delta, deltapct)
     */
    protected function headerRow(array $cells, array $types = []): void
    {
        $this->types = $types;
        $this->push($cells, 'header');
        $this->tableRows = 0;
    }

    /** @param  list<string|int|float>  $cells */
    protected function bodyRow(array $cells, bool $bold = false): void
    {
        $this->push($cells, $bold ? 'total' : 'body', $this->tableRows % 2 === 1);
        $this->tableRows++;
    }

    /**
     * Every table of a page, as ReportSections gives them: the chart above it
     * when it has one, heading, header row, rows, note. A figure that is not
     * there is written as a dash.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, array<string, mixed>>  $charts  the page's charts by table key (ReportCharts)
     */
    protected function sectionTables(array $sections, array $charts = []): void
    {
        foreach ($sections as $section) {
            if (isset($charts[$section['key']])) {
                $this->chartBlock($charts[$section['key']]);
            }

            $this->sectionRow($section['title']);
            $this->headerRow(
                array_column($section['columns'], 'label'),
                array_column($section['columns'], 'type'),
            );
            $this->headerRows[$section['key']] = count($this->rows);

            foreach ($section['rows'] as $row) {
                $cells = array_map(fn ($cell) => $cell ?? '–', $row['cells']);

                match ($row['style']) {
                    'group' => $this->groupRow($cells),
                    'child' => $this->bodyRow(['    '.$cells[0], ...array_slice($cells, 1)]),
                    'total' => $this->bodyRow($cells, bold: true),
                    default => $this->bodyRow($cells),
                };
            }

            if ($section['note'] !== null) {
                $this->noteRow($section['note']);
            }
        }
    }

    /**
     * The rows kept free for a chart, which is drawn over them when the file
     * is written; or a line saying there is nothing to chart.
     *
     * @param  array<string, mixed>  $chart
     */
    private function chartBlock(array $chart): void
    {
        $this->blank();

        if ($chart['empty']) {
            $this->noteRow($chart['title'].': '.$chart['message'].'.');

            return;
        }

        $top = count($this->rows) + 1;

        for ($row = 0; $row < SheetChart::ROWS[$chart['kind']]; $row++) {
            $this->push([], 'chart');
        }

        $this->placed[] = ['chart' => $chart, 'table' => $chart['source']['table'], 'top' => $top, 'bottom' => count($this->rows)];
    }

    /**
     * A bold line inside a table, like a branch above its ranks.
     *
     * @param  list<string|int|float>  $cells
     */
    protected function groupRow(array $cells): void
    {
        $this->push($cells, 'group', $this->tableRows % 2 === 1);
        $this->tableRows++;
    }

    /** An explanatory line that wraps over the table width. */
    protected function noteRow(string $text): void
    {
        $this->push([$text], 'note');
    }

    protected function blank(): void
    {
        $this->push([], 'blank');
    }

    /** The Prepared by and Noted by lines, text only. */
    protected function signatureRows(): void
    {
        $this->blank();
        $this->push(['Prepared by · '.$this->meta['prepared_by_title'], $this->meta['prepared_by']], 'sign');
        $this->blank();
        $this->push(['Noted by · '.$this->meta['noted_by_title'], $this->meta['noted_by']], 'sign');
    }

    /** @param  list<string|int|float>  $cells */
    private function push(array $cells, string $kind, bool $zebra = false): void
    {
        // A row with no cell at all is dropped by the writer, which would shift every row below it.
        $this->rows[] = $cells === [] ? [''] : $cells;
        $this->kinds[count($this->rows)] = ['kind' => $kind, 'zebra' => $zebra, 'types' => $this->types];
    }

    private function buildTitleBlock(): void
    {
        $meta = $this->meta;

        // The first row is kept for the logo, whether or not one is set.
        $this->push([], 'logo');
        $this->push([$meta['hospital_name']], 'hospital');
        $this->push([$meta['office']], 'office');
        $this->push([$this->reportTitle], 'report');
        $this->reportTitleRow = count($this->rows);
        $this->push([$meta['period_label']], 'period');
        $this->periodRow = count($this->rows);
        $this->push([$meta['status']], 'status');
        $this->push([$meta['generated']], 'generated');

        $this->titleBlockEnd = count($this->rows);
    }

    // --- Styling ---------------------------------------------------------------------

    /** Puts the cells of a row (columns $from to $to) in a look. Rows that follow each other join into one range. */
    private function mark(string $look, string $from, string $to, int $row): void
    {
        $runs = &$this->groups[$look]["{$from}:{$to}"];
        $last = $runs === null ? null : array_key_last($runs);

        if ($last !== null && $runs[$last][1] === $row - 1) {
            $runs[$last][1] = $row;
        } else {
            $runs[] = [$row, $row];
        }
    }

    private function titleLine(int $row, string $look, int $size): void
    {
        $this->merges[] = "A{$row}:".self::LAST_COLUMN.$row;
        $this->mark($look, 'A', 'A', $row);
        $this->heights[$row] = $size + 8;
    }

    private function noteLine(int $row): void
    {
        $this->merges[] = "A{$row}:".self::LAST_COLUMN.$row;
        $this->mark('note', 'A', 'A', $row);

        $width = array_sum($this->columnWidths());
        $lines = max(1, (int) ceil(mb_strlen((string) $this->rows[$row - 1][0]) / max(1, $width - 4)));
        $this->heights[$row] = 15 * $lines;
    }

    private function sectionLine(int $row): void
    {
        $this->mark('section', 'A', 'A', $row);
        $this->heights[$row] = 22;
    }

    private function headerLine(int $row): void
    {
        $last = $this->lastColumn($row);
        $this->mark('header', 'A', $last, $row);
        $this->alignCells($row, header: true);
        $this->heights[$row] = 20;
    }

    private function tableLine(int $row, bool $zebra, string $kind): void
    {
        $last = $this->lastColumn($row);
        $this->mark('table', 'A', $last, $row);

        if ($zebra) {
            $this->mark('zebra', 'A', $last, $row);
        }

        if (in_array($kind, ['total', 'group'], true)) {
            $this->mark('bold', 'A', $last, $row);
        }

        $this->alignCells($row, header: false);
    }

    /** Only the cells the row really uses, so a 2-column table has no empty third column. */
    private function lastColumn(int $row): string
    {
        return chr(64 + max(1, count($this->rows[$row - 1])));
    }

    /**
     * Numbers right-aligned in their own format (thousands separators,
     * percentages, signed changes), text left-aligned and wrapped. A dash for
     * a missing figure is aligned like the figures of its column.
     */
    private function alignCells(int $row, bool $header): void
    {
        $this->mark('left', 'A', 'A', $row);

        $types = $this->kinds[$row]['types'];

        foreach (array_slice($this->rows[$row - 1], 1, null, true) as $index => $value) {
            $column = chr(65 + $index);
            $type = $types[$index] ?? (is_string($value) ? 'text' : 'int');

            if ($type === 'text') {
                $this->mark('left', $column, $column, $row);
            } elseif ($header) {
                $this->mark('right-wrap', $column, $column, $row);
            } else {
                $this->mark('number:'.(isset(self::FORMATS[$type]) ? $type : 'int'), $column, $column, $row);
            }
        }
    }

    private function signatureLine(int $row): void
    {
        $this->heights[$row] = 32;
        $this->mark('sign-label', 'A', 'A', $row);
        $this->merges[] = "B{$row}:C{$row}";
        $this->mark('sign-name', 'B', 'C', $row);
    }

    /**
     * The style of a look, as the array PhpSpreadsheet takes.
     *
     * @return array<string, mixed>
     */
    private function style(string $look): array
    {
        $left = ['horizontal' => 'left', 'vertical' => 'center'];

        return match (true) {
            $look === 'hospital' => ['font' => ['size' => 16, 'bold' => true], 'alignment' => $left],
            $look === 'office' => ['font' => ['size' => 11], 'alignment' => $left],
            $look === 'report' => ['font' => ['size' => 13, 'bold' => true], 'alignment' => $left],
            $look === 'small' => ['font' => ['size' => 10], 'alignment' => $left],
            $look === 'note' => ['font' => ['italic' => true, 'size' => 10], 'alignment' => ['horizontal' => 'left', 'vertical' => 'top', 'wrapText' => true]],
            $look === 'section' => ['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::NAVY]], 'alignment' => ['horizontal' => 'left', 'vertical' => 'bottom']],
            $look === 'header' => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::NAVY]],
                'alignment' => ['vertical' => 'center'],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => self::LINE]]],
            ],
            $look === 'table' => [
                'alignment' => ['vertical' => 'top'],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => self::LINE]]],
            ],
            $look === 'zebra' => ['fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::ZEBRA]]],
            $look === 'bold' => ['font' => ['bold' => true]],
            $look === 'left' => ['alignment' => ['horizontal' => 'left', 'wrapText' => true]],
            $look === 'right-wrap' => ['alignment' => ['horizontal' => 'right', 'wrapText' => true]],
            str_starts_with($look, 'number:') => [
                'numberFormat' => ['formatCode' => self::FORMATS[substr($look, 7)]],
                'alignment' => ['horizontal' => 'right'],
            ],
            $look === 'sign-label' => ['alignment' => ['vertical' => 'top', 'horizontal' => 'left']],
            $look === 'sign-name' => [
                'font' => ['bold' => true],
                'alignment' => ['vertical' => 'top', 'horizontal' => 'center', 'wrapText' => true],
                // The signature line, above the name.
                'borders' => ['top' => ['borderStyle' => 'thin']],
            ],
            default => [],
        };
    }

    private function setUpPage(Worksheet $sheet): void
    {
        $landscape = array_sum($this->columnWidths()) > self::PORTRAIT_WIDTH;

        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        // Fit to one page wide shrinks a sheet that is too wide, so one that is wider than a portrait page prints landscape.
        $setup->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setFitToPage(true);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        // Excel allows one repeating range per sheet, so the report title and period repeat.
        $setup->setRowsToRepeatAtTopByStartAndEnd($this->reportTitleRow, $this->periodRow);

        $margins = $sheet->getPageMargins();
        $margins->setLeft(1.5 / 2.54)->setRight(1.5 / 2.54)->setTop(1.5 / 2.54)->setBottom(1.5 / 2.54);

        $hospital = str_replace('&', '&&', $this->meta['hospital_name']);
        $sheet->getHeaderFooter()->setOddFooter("&L{$hospital}&RPage &P of &N");

        // Frozen below the title block so it stays in view.
        $sheet->freezePane('A'.($this->titleBlockEnd + 1));

        $this->keepChartsOnOnePage($sheet, $landscape);
    }

    /**
     * A chart is never cut by a page break: when it, its table heading and
     * the first rows of its table would not fit in what is left of a printed
     * page, a page break goes above it. The page is worked out from the
     * paper size and how far "fit to one page wide" shrinks the sheet, with
     * a margin, so it is a close estimate, not Excel's own count.
     */
    private function keepChartsOnOnePage(Worksheet $sheet, bool $landscape): void
    {
        if ($this->placed === []) {
            return;
        }

        // Excel's column width in characters to points: 7 pixels a character and 5 of padding, 0.75 points a pixel.
        $sheetWidth = array_sum(array_map(fn (int $width): float => ($width * 7 + 5) * 0.75, $this->columnWidths()));
        $paperWidth = ($landscape ? 29.7 : 21.0) - 3.0;
        $paperHeight = ($landscape ? 21.0 : 29.7) - 3.0;
        $scale = min(1.0, $paperWidth / 2.54 * 72 / $sheetWidth);
        $capacity = $paperHeight / 2.54 * 72 / $scale * 0.9;

        $height = fn (int $row): float => (float) ($this->heights[$row] ?? self::ROW);
        $repeated = $height($this->reportTitleRow) + $height($this->periodRow);

        $starts = [];

        foreach ($this->placed as $placed) {
            // From the blank row above the chart to the first rows of its table.
            $starts[$placed['top'] - 1] = $placed['bottom'] + 4;
        }

        $used = 0.0;

        foreach (array_keys($this->rows) as $index) {
            $row = $index + 1;

            if (isset($starts[$row])) {
                $block = 0.0;

                for ($inside = $row; $inside <= min($starts[$row], count($this->rows)); $inside++) {
                    $block += $height($inside);
                }

                if ($used > 0 && $used + $block > $capacity) {
                    $sheet->setBreak('A'.($row - 1), Worksheet::BREAK_ROW);
                    $used = $repeated;
                }
            }

            $used += $height($row);

            if ($used > $capacity) {
                // A page ended inside a table: the next one starts with the repeated title.
                $used = $repeated + $height($row);
            }
        }
    }
}
