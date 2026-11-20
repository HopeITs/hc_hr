<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The printed Monthly TimeSheet and Monthly Summary as an Excel file, in the form HR signs:
 * right to left, the same columns, the same words. For more than one person the first sheet is an
 * overview with one row each, followed by a timesheet and a summary sheet per person. The figures
 * are values (the system has already worked them out), not the template's formulas.
 */
class TimesheetWorkbook
{
    /**
     * @param  Collection<int, array{employee: \App\Models\Employee, sheet: array<string, mixed>}>  $sheets
     */
    public function build(Collection $sheets, CarbonImmutable $month, bool $draft): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        if ($sheets->count() > 1) {
            $this->overview($book->createSheet(), $sheets, $month, $draft);
        }

        foreach ($sheets as $item) {
            $this->timesheet($book->createSheet(), $item, $month, $draft);
            $this->summary($book->createSheet(), $item, $month, $draft);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function fileName(CarbonImmutable $month): string
    {
        return 'timesheets-'.$month->format('Y-m').'.xlsx';
    }

    private function t(string $key, array $replace = []): string
    {
        return __('attendance.form.'.$key, $replace, 'ar');
    }

    private function fmt(float|int|null $n): string|float|int
    {
        return $n === null ? '' : (float) $n;
    }

    /** @param  Collection<int, array<string, mixed>>  $sheets */
    private function overview(Worksheet $ws, Collection $sheets, CarbonImmutable $month, bool $draft): void
    {
        $ws->setTitle('Overview');
        $ws->setRightToLeft(true);
        $ws->setCellValue('A1', $this->t('title').' · '.$month->format('Y-m').($draft ? ' · '.$this->t('draft') : ''));
        $ws->mergeCells('A1:H1');

        $head = [$this->t('employee-id'), $this->t('volunteer'), $this->t('department'), $this->t('working-days'), $this->t('overtime'), $this->t('free-days'), $this->t('total-leave-hours'), '?'];
        $ws->fromArray($head, null, 'A3');

        $row = 4;

        foreach ($sheets as $item) {
            $totals = $item['sheet']['totals'];
            $placement = $item['sheet']['placement'];
            $ws->fromArray([
                $item['employee']->id,
                $item['employee']->name,
                $placement['program'] ?? $placement['department'] ?? '',
                $totals['working_days'],
                $totals['overtime_hours'],
                $totals['overtime_days'],
                $totals['leave_hours'],
                $totals['to_decide'],
            ], null, 'A'.$row++);
        }

        $this->frame($ws, 'A3:H3', 'A3:H'.($row - 1));
        $this->widths($ws, ['A' => 12, 'B' => 28, 'C' => 26, 'D' => 14, 'E' => 14, 'F' => 18, 'G' => 18, 'H' => 8]);
        $this->page($ws);
    }

    /** @param  array{employee: \App\Models\Employee, sheet: array<string, mixed>}  $item */
    private function timesheet(Worksheet $ws, array $item, CarbonImmutable $month, bool $draft): void
    {
        $employee = $item['employee'];
        $sheet = $item['sheet'];
        $totals = $sheet['totals'];
        $position = $employee->latestActiveContract()?->position;

        $ws->setTitle($this->sheetName('TS', $employee->id));
        $ws->setRightToLeft(true);

        $ws->setCellValue('A1', $this->t('title').($draft ? ' · '.$this->t('draft') : ''));
        $ws->mergeCells('A1:G1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $ws->setCellValue('A3', $this->t('volunteer'));
        $ws->setCellValue('B3', $employee->name);
        $ws->setCellValue('C3', $this->t('employee-id'));
        $ws->setCellValue('D3', $employee->id);
        $ws->setCellValue('F3', $this->t('month-year'));
        $ws->setCellValue('G3', $month->locale('ar')->translatedFormat('F').' '.$month->year);
        $ws->setCellValue('A4', $this->t('position'));
        $ws->setCellValue('B4', $position?->title_ar ?: $position?->title_en);
        $ws->setCellValue('C4', $this->t('department'));
        $ws->setCellValue('D4', $position?->program?->name);
        $ws->setCellValue('F4', $this->t('daily-hours'));
        $ws->setCellValue('G4', $sheet['daily_hours']);

        $ws->fromArray([
            $this->t('day'), $this->t('date'), $this->t('leave-type'), $this->t('leave-hours'),
            $this->t('overtime'), $this->t('working-days'), $this->t('notes'),
        ], null, 'A6');

        $row = 7;

        foreach (FormRows::for($sheet) as $r) {
            $ws->fromArray([
                $r['day'], $r['date'], $r['type'], $r['leave_hours'], $r['overtime'],
                $r['working_days'] === null ? '?' : $r['working_days'], $r['note'],
            ], null, 'A'.$row);

            if ($r['off']) {
                $ws->getStyle('A'.$row.':G'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F3F4');
            }

            $row++;
        }

        $ws->setCellValue('A'.$row, $this->t('monthly-total'));
        $ws->mergeCells('A'.$row.':B'.$row);
        $ws->setCellValue('C'.$row, $this->t('total-leave-hours'));
        $ws->setCellValue('D'.$row, $totals['leave_hours']);
        $ws->setCellValue('E'.$row, $totals['overtime_hours']);
        $ws->setCellValue('F'.$row, $totals['working_days']);
        $ws->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
        $ws->getStyle('A'.$row.':G'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6E7E8');

        $this->frame($ws, 'A6:G6', 'A6:G'.$row);

        $sign = $row + 3;
        $ws->setCellValue('A'.$sign, $this->t('sign-volunteer'));
        $ws->mergeCells('A'.$sign.':B'.$sign);
        $ws->setCellValue('C'.$sign, $this->t('sign-supervisor'));
        $ws->mergeCells('C'.$sign.':D'.$sign);
        $ws->setCellValue('F'.$sign, $this->t('sign-hr'));
        $ws->mergeCells('F'.$sign.':G'.$sign);

        $this->widths($ws, ['A' => 12, 'B' => 14, 'C' => 28, 'D' => 16, 'E' => 14, 'F' => 16, 'G' => 32]);
        $this->page($ws);
    }

    /** @param  array{employee: \App\Models\Employee, sheet: array<string, mixed>}  $item */
    private function summary(Worksheet $ws, array $item, CarbonImmutable $month, bool $draft): void
    {
        $employee = $item['employee'];
        $sheet = $item['sheet'];
        $totals = $sheet['totals'];

        $ws->setTitle($this->sheetName('SUM', $employee->id));
        $ws->setRightToLeft(true);

        $ws->setCellValue('A1', $this->t('summary-title').($draft ? ' · '.$this->t('draft') : ''));
        $ws->mergeCells('A1:F1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $ws->setCellValue('A3', $this->t('volunteer'));
        $ws->setCellValue('B3', $employee->name);
        $ws->setCellValue('C3', $this->t('employee-id'));
        $ws->setCellValue('D3', $employee->id);
        $ws->setCellValue('E3', $this->t('month-year'));
        $ws->setCellValue('F3', $month->locale('ar')->translatedFormat('F').' '.$month->year);

        $ws->fromArray([$this->t('total-working-days'), $this->t('total-overtime'), $this->t('free-days'), $this->t('leave-consumed')], null, 'A5');
        $ws->fromArray([$totals['working_days'], $totals['overtime_hours'], $totals['overtime_days'], $totals['leave_hours']], null, 'A6');
        $this->frame($ws, 'A5:D5', 'A5:D6');

        if ($totals['overtime_hours'] > 0) {
            $ws->setCellValue('A8', $this->t('overtime-note', [
                'hours' => (float) $totals['overtime_hours'], 'daily' => (float) $sheet['daily_hours'], 'days' => (float) $totals['overtime_days'],
            ]));
            $ws->mergeCells('A8:F8');
        }

        $ws->fromArray([
            $this->t('balance-type'), $this->t('balance-entitled'), $this->t('balance-month-hours'),
            $this->t('balance-month-days'), $this->t('balance-remaining'), $this->t('balance-status'),
        ], null, 'A10');

        $row = 11;

        foreach ($sheet['balances'] as $balance) {
            $ws->fromArray([
                $balance['type']?->name_ar ?: $balance['type']?->name_en,
                $balance['entitled_days'], $balance['month_hours'], $balance['month_days'],
                $balance['exceeded'] ? '—' : $this->t('days-and-hours', ['days' => $balance['remaining_days'], 'hours' => $balance['remaining_rest_hours']]),
                $balance['exceeded'] ? $this->t('exceeded') : $this->t('within'),
            ], null, 'A'.$row++);
        }

        $this->frame($ws, 'A10:F10', 'A10:F'.max(11, $row - 1));
        $ws->setCellValue('A'.($row + 1), $this->t('balances-note'));
        $ws->mergeCells('A'.($row + 1).':F'.($row + 1));

        $this->widths($ws, ['A' => 26, 'B' => 18, 'C' => 20, 'D' => 20, 'E' => 24, 'F' => 24]);
        $this->page($ws);
    }

    private function frame(Worksheet $ws, string $header, string $whole): void
    {
        $ws->getStyle($whole)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $ws->getStyle($whole)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getStyle($header)->getFont()->setBold(true);
        $ws->getStyle($header)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6E7E8');
        $ws->getStyle($header)->getAlignment()->setWrapText(true);
    }

    /** @param  array<string, int>  $widths */
    private function widths(Worksheet $ws, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $ws->getColumnDimension($column)->setWidth($width);
        }
    }

    private function page(Worksheet $ws): void
    {
        $ws->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(1);
    }

    /** Excel sheet names: at most 31 characters, none of []:*?/\ . */
    private function sheetName(string $prefix, int $id): string
    {
        return $prefix.' '.$id;
    }
}
