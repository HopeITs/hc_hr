<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/** The Activity report as a workbook: one sheet per block. */
class ActivityReportExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(protected array $report) {}

    public function sheets(): array
    {
        $r = $this->report;

        return [
            $this->sheet('Summary', ['Metric', 'Value'], [
                ['Period (days)', $r['days']],
                ['Total actions', $r['total']],
                ['Active users', $r['activeUsers']],
            ]),
            $this->sheet('Top users', ['User', 'Actions', 'Last action'],
                $r['topUsers']->map(fn ($u) => [$u['name'], $u['actions'], (string) $u['last']])->all()),
            $this->sheet('Daily', ['Date', 'Actions'], $r['daily']->map(fn ($d) => [$d['date'], $d['actions']])->all()),
            $this->sheet('Hours', ['Hour', 'Actions'], $r['hours']->map(fn ($h) => [sprintf('%02d:00', $h['hour']), $h['actions']])->all()),
            $this->sheet('Cities', ['City', 'Actions', 'Users'], $r['cities']->map(fn ($c) => [$c['name'], $c['actions'], $c['users']])->all()),
            $this->sheet('No activity', ['User', 'Last seen'],
                $r['inactive']->map(fn ($u) => [$u['name'], $u['last_seen'] ? (string) $u['last_seen'] : '-'])->all()),
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
