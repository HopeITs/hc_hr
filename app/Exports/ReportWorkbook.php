<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Any report as a workbook: one sheet per block, each
 * ['title' => string, 'headings' => string[], 'rows' => array[]].
 */
class ReportWorkbook implements Export, WithMultipleSheets
{
    use Exportable;

    /** @param  array<int, array{title: string, headings: array<int, string>, rows: array<int, array<int, mixed>>}>  $sheets */
    public function __construct(protected array $sheets) {}

    public function sheets(): array
    {
        return array_map(fn (array $s) => new class($s['title'], $s['headings'], $s['rows']) implements FromArray, ShouldAutoSize, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $rows) {}

            public function array(): array
            {
                return [$this->headings, ...$this->rows];
            }

            public function title(): string
            {
                // Excel sheet names: 31 characters, none of []:*?/\
                return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->title), 0, 31);
            }
        }, $this->sheets);
    }
}
