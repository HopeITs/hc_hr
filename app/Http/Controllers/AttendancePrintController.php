<?php

namespace App\Http\Controllers;

use App\Services\Attendance\PrintSelection;
use Illuminate\Http\Request;

/**
 * The printed Monthly TimeSheet (page 1) and Monthly Summary (page 2) of everyone the user may
 * see for a month, one pair of pages per person, to print from the browser. A sheet with days
 * still waiting for an answer is marked DRAFT on every page. The same pages as a PDF and as an
 * Excel file come from AttendanceExportController.
 */
class AttendancePrintController extends Controller
{
    public function __invoke(Request $request, PrintSelection $selection)
    {
        return view('attendance.print', $selection->fromRequest($request) + ['pdf' => false]);
    }
}
