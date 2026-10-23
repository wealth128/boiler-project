<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet of the report workbook: a title block, then tables stacked
 * top to bottom, then the Prepared by / Noted by lines. Only the look is
 * decided here; the rows are the report's numbers and labels as given.
 * A count of 0 is written as 0, not left empty (WithStrictNullComparison).
 *
 * Every text value is stored as plain text. A name or diagnosis that starts
 * with =, +, - or @ can therefore never run as a formula (planning/security.md
 * section 2, rule 7). Counts are integers, so they stay numbers.
 *
 * Signature pictures are never put in Excel (decided in 6h); the logo is.
 */
abstract class ReportSheetExport extends DefaultValueBinder implements FromArray, WithColumnWidths, WithCustomValueBinder, WithDrawings, WithStrictNullComparison, WithStyles, WithTitle
{
    private const NAVY = '1F3864';

    private const ZEBRA = 'F2F5FA';

    private const LINE = '9AA5B8';

    /** The last column used: tables have up to five. */
    private const LAST_COLUMN = 'E';

    /** Column widths (characters) that still fit an A4 portrait page; a wider sheet prints landscape. */
    private const PORTRAIT_WIDTH = 100;

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
     * Column A fits its longest label (a table row, not a heading that may
     * spill over the empty cells beside it); the others fit their header and
     * figures. Wrapping takes care of anything longer than the limit.
     *
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
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

        return $widths;
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->setShowGridLines(false);

        foreach ($this->kinds as $row => $look) {
            match ($look['kind']) {
                'hospital' => $this->styleTitleLine($sheet, $row, 16, bold: true),
                'office' => $this->styleTitleLine($sheet, $row, 11),
                'report' => $this->styleTitleLine($sheet, $row, 13, bold: true),
                'period', 'status', 'generated' => $this->styleTitleLine($sheet, $row, 10),
                'note' => $this->styleNote($sheet, $row),
                'section' => $this->styleSection($sheet, $row),
                'header' => $this->styleHeader($sheet, $row),
                'sign' => $this->styleSignature($sheet, $row),
                'logo' => $sheet->getRowDimension($row)->setRowHeight(42),
                'blank' => null,
                default => $this->styleTableRow($sheet, $row, $look['zebra']),
            };
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
     * Every table of a page, as ReportSections gives them: heading, header row, rows, note.
     * A figure that is not there is written as a dash.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    protected function sectionTables(array $sections): void
    {
        foreach ($sections as $section) {
            $this->sectionRow($section['title']);
            $this->headerRow(
                array_column($section['columns'], 'label'),
                array_column($section['columns'], 'type'),
            );

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

    private function styleTitleLine(Worksheet $sheet, int $row, int $size, bool $bold = false): void
    {
        $sheet->mergeCells("A{$row}:".self::LAST_COLUMN.$row);
        $sheet->getStyle("A{$row}")->getFont()->setSize($size)->setBold($bold);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight($size + 8);
    }

    private function styleNote(Worksheet $sheet, int $row): void
    {
        $sheet->mergeCells("A{$row}:".self::LAST_COLUMN.$row);
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP)->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(10);

        $width = array_sum($this->columnWidths());
        $lines = max(1, (int) ceil(mb_strlen((string) $this->rows[$row - 1][0]) / max(1, $width - 4)));
        $sheet->getRowDimension($row)->setRowHeight(15 * $lines);
    }

    private function styleSection(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle("A{$row}")->getFont()->applyFromArray(['bold' => true, 'size' => 12, 'color' => ['rgb' => self::NAVY]]);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_BOTTOM);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    private function styleHeader(Worksheet $sheet, int $row): void
    {
        $range = $this->tableRange($row);
        $style = $sheet->getStyle($range);
        $style->getFont()->applyFromArray(['bold' => true, 'color' => ['rgb' => 'FFFFFF']]);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LINE);
        $this->alignCells($sheet, $row);
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    private function styleTableRow(Worksheet $sheet, int $row, bool $zebra): void
    {
        $style = $sheet->getStyle($this->tableRange($row));

        if ($zebra) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ZEBRA);
        }

        if (in_array($this->kinds[$row]['kind'], ['total', 'group'], true)) {
            $style->getFont()->setBold(true);
        }

        $style->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::LINE);
        $this->alignCells($sheet, $row);
    }

    /** Only the cells the row really uses, so a 2-column table has no empty third column. */
    private function tableRange(int $row): string
    {
        return "A{$row}:".chr(64 + max(1, count($this->rows[$row - 1]))).$row;
    }

    /**
     * Numbers right-aligned in their own format (thousands separators,
     * percentages, signed changes), text left-aligned and wrapped. A dash for
     * a missing figure is aligned like the figures of its column.
     */
    private function alignCells(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);

        $types = $this->kinds[$row]['types'];
        $header = $this->kinds[$row]['kind'] === 'header';

        foreach (array_slice($this->rows[$row - 1], 1, null, true) as $index => $value) {
            $cell = $sheet->getStyle(chr(65 + $index).$row);
            $type = $types[$index] ?? (is_string($value) ? 'text' : 'int');

            if ($header) {
                $cell->getAlignment()->setHorizontal($type === 'text' ? Alignment::HORIZONTAL_LEFT : Alignment::HORIZONTAL_RIGHT)->setWrapText(true);
            } elseif ($type === 'text') {
                $cell->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
            } else {
                $cell->getNumberFormat()->setFormatCode(self::FORMATS[$type] ?? self::FORMATS['int']);
                $cell->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
    }

    private function styleSignature(Worksheet $sheet, int $row): void
    {
        $sheet->getRowDimension($row)->setRowHeight(32);
        $sheet->getStyle("A{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->mergeCells("B{$row}:C{$row}");
        $name = $sheet->getStyle("B{$row}:C{$row}");
        $name->getFont()->setBold(true);
        $name->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        // The signature line, above the name.
        $name->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
    }

    private function setUpPage(Worksheet $sheet): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        // Fit to one page wide shrinks a sheet that is too wide, so one that is wider than a portrait page prints landscape.
        $setup->setOrientation(array_sum($this->columnWidths()) > self::PORTRAIT_WIDTH ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
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
    }
}
