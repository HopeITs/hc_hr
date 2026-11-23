<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** The leaving-reasons report as a workbook: one sheet per block. */
class LeavingReasonsExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(protected array $report) {}

    public function sheets(): array
    {
        $r = $this->report;
        $share = fn ($rows) => $rows->map(fn ($x) => [$x['label'], $x['count'], $x['percent'].'%'])->all();

        return [
            $this->sheet('Summary', ['Metric', 'Value'], [
                ['Leavers', $r['total']],
                ['With a recorded reason', $r['recorded']],
                ['Reason not recorded', $r['missing']],
                ['Average tenure (months)', $r['averageTenureMonths'] ?? '-'],
                ['Previous period', $r['previousTotal'] ?? '-'],
                ['Turnover rate', $r['turnoverPercent'] === null ? '-' : $r['turnoverPercent'].'%'],
            ]),
            $this->sheet('By category', ['Category', 'People', 'Share'], $share($r['byCategory'])),
            $this->sheet('By reason', ['Reason', 'People', 'Share'], $share($r['byReason'])),
            $this->sheet('By tenure', ['Time with us', 'People', 'Share'], $share($r['byTenure'])),
            $this->sheet('By city', ['City', 'People', 'Share'], $share($r['byCity'])),
            $this->sheet('By month', ['Month', 'People'], $r['monthly']->map(fn ($n, $m) => [$m, $n])->values()->all()),
            $this->sheet('No reason', ['Name', 'Left on', 'Note'], array_map(fn ($x) => [$x['name'], $x['left_on'], $x['note'] ?? ''], $r['withoutReason'])),
        ];
    }

    private function sheet(string $title, array $headings, array $rows): object
    {
        return new class($title, $headings, $rows) implements FromArray, ShouldAutoSize, WithTitle
        {
            public function __construct(private string $title, private array $headings, private array $rows) {}

            public function array(): array
            {
                return [$this->headings, ...$this->rows];
            }

            public function title(): string
            {
                return $this->title;
            }
        };
    }
}
