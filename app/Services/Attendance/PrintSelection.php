<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Which timesheets a print, PDF or Excel request asks for: a month, optionally one city, one
 * project or department, or a few people, always within what the user may see (Roster), in the
 * order the page shows them.
 */
class PrintSelection
{
    public function __construct(
        private readonly Roster $roster,
        private readonly Timesheet $timesheet,
        private readonly Grouping $grouping,
    ) {}

    /**
     * @return array{sheets: Collection<int, array{employee: Employee, sheet: array<string, mixed>}>, month: CarbonImmutable, draft: bool}
     */
    public function fromRequest(Request $request): array
    {
        // A print, PDF or Excel link with no month opens the month the Timesheets page opens
        // on, instead of failing validation: on a GET that meant a silent redirect "back",
        // to whatever page the session last remembered.
        if (blank($request->query('month'))) {
            $request->merge(['month' => now()->subDays(10)->format('Y-m')]);
        }

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'city_id' => ['nullable', 'integer'],
            'employees' => ['nullable', 'array'],
            'employees.*' => ['integer'],
            'group_by' => ['nullable', 'in:'.Grouping::PROJECT.','.Grouping::DEPARTMENT],
            'group' => ['nullable', 'regex:/^(\d+|none)$/'],
        ]);

        $month = CarbonImmutable::createFromFormat('!Y-m', $data['month']);

        $employees = $this->roster->employees($request->user(), $month, $data['city_id'] ?? null)
            ->when(! empty($data['employees']), fn ($all) => $all->whereIn('id', $data['employees']));

        abort_if($employees->isEmpty(), 404);

        // One batch of reads for the whole selection, not a set per person.
        $all = $this->timesheet->forEmployees($employees, $month);

        $sheets = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'sheet' => $all[$employee->id],
        ])->values();

        $by = Grouping::normalize($data['group_by'] ?? null);

        // One project or department only ("none" = people with none set).
        if (isset($data['group'])) {
            $groupId = $data['group'] === 'none' ? null : (int) $data['group'];
            $sheets = $sheets->filter(fn (array $item) => Grouping::groupId($item, $by) === $groupId)->values();
        }

        abort_if($sheets->isEmpty(), 404);

        // The order of the page: city, then project or department, then name.
        $sheets = $this->grouping->group($sheets, $by)
            ->flatMap(fn (array $block) => $block['groups'])
            ->flatMap(fn (array $group) => $group['items'])
            ->values();

        return [
            'sheets' => $sheets,
            'month' => $month,
            'draft' => $sheets->contains(fn (array $s) => $s['sheet']['totals']['to_decide'] > 0),
        ];
    }
}
