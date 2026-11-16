<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\Attendance\Grouping;
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
    public function __invoke(Request $request, Roster $roster, Timesheet $timesheet, Grouping $grouping)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'city_id' => ['nullable', 'integer'],
            'employees' => ['nullable', 'array'],
            'employees.*' => ['integer'],
            'group_by' => ['nullable', 'in:'.Grouping::PROJECT.','.Grouping::DEPARTMENT],
            'group' => ['nullable', 'regex:/^(\d+|none)$/'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']);

        $employees = $roster->employees($request->user(), $month, $data['city_id'] ?? null)
            ->when(! empty($data['employees']), fn ($all) => $all->whereIn('id', $data['employees']));

        abort_if($employees->isEmpty(), 404);

        $sheets = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'sheet' => $timesheet->forEmployee($employee, $month),
        ])->values();

        $by = Grouping::normalize($data['group_by'] ?? null);

        // One project or department only ("none" = people with none set).
        if (isset($data['group'])) {
            $groupId = $data['group'] === 'none' ? null : (int) $data['group'];
            $sheets = $sheets->filter(fn (array $item) => Grouping::groupId($item, $by) === $groupId)->values();
        }

        abort_if($sheets->isEmpty(), 404);

        // Printed in the order the page shows them: city, then project or department, then name.
        $sheets = $grouping->group($sheets, $by)
            ->flatMap(fn (array $block) => $block['groups'])
            ->flatMap(fn (array $group) => $group['items'])
            ->values();

        return view('attendance.print', [
            'sheets' => $sheets,
            'month' => $month,
            'draft' => $sheets->contains(fn (array $s) => $s['sheet']['totals']['to_decide'] > 0),
        ]);
    }
}
