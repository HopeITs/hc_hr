<?php

namespace App\Http\Controllers;

use App\Services\Attendance\PrintSelection;
use App\Services\Attendance\TimesheetWorkbook;
use App\Services\ContractPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * The timesheets as a PDF and as an Excel file: the same selection, order and scope as the page
 * (PrintSelection), so a person can only get what they may see. The PDF goes through the same
 * headless Chrome as the contracts; a render that fails answers 503 with a short reason, never a
 * 0-byte "PDF".
 */
class AttendanceExportController extends Controller
{
    public function pdf(Request $request, PrintSelection $selection, ContractPdfRenderer $renderer)
    {
        $data = $selection->fromRequest($request);

        try {
            $pdf = $renderer->renderHtml(view('attendance.print', $data + ['pdf' => true])->render());
        } catch (Throwable $exception) {
            Log::warning('Attendance PDF failed', ['message' => $exception->getMessage()]);

            return $this->failed();
        }

        if (! str_starts_with($pdf, '%PDF-')) {
            return $this->failed();
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="timesheets-'.$data['month']->format('Y-m').'.pdf"',
        ]);
    }

    public function xlsx(Request $request, PrintSelection $selection, TimesheetWorkbook $workbook)
    {
        $data = $selection->fromRequest($request);
        $book = $workbook->build($data['sheets'], $data['month'], $data['draft']);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $workbook->fileName($data['month']), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function failed()
    {
        return response(__('attendance.export.failed'), 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
