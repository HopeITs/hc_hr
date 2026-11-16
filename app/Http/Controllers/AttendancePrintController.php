<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Timesheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The printed Monthly TimeSheet (page 1) and Monthly Summary (page 2) of everyone the user may
 * see for a month, one pair of pages per person. A sheet with days still waiting for HR is
 * marked DRAFT on every page.
 */
class AttendancePrintController extends Controller
{
    public function __invoke(Request $request, Roster $roster, Timesheet $timesheet)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'city_id' => ['nullable', 'integer'],
            'employees' => ['nullable', 'array'],
            'employees.*' => ['integer'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']);

        $employees = $roster->employees($request->user(), $month, $data['city_id'] ?? null)
            ->when(! empty($data['employees']), fn ($all) => $all->whereIn('id', $data['employees']));

        abort_if($employees->isEmpty(), 404);

        $sheets = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'sheet' => $timesheet->forEmployee($employee, $month),
        ])->values();

        return view('attendance.print', [
            'sheets' => $sheets,
            'month' => $month,
            'draft' => $sheets->contains(fn (array $s) => $s['sheet']['totals']['to_decide'] > 0),
        ]);
    }
}
