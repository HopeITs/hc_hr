<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AttendanceDayDecision;
use App\Models\Employee;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Timesheet;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * The month of a center or city at a glance: one square per working day for each person, the
 * overtime in hours and free days, and the days that wait for HR. Clicking a day that has no
 * record (or a single punch) opens the question: did they work, or were they absent?
 */
class Timesheets extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    public string $month = '';

    public ?int $cityId = null;

    public bool $onlyToDecide = false;

    public ?int $dayEmployeeId = null;

    public ?string $dayDate = null;

    public string $dayKind = AttendanceDayDecision::WORKED;

    public string $dayNote = '';

    public function mount(): void
    {
        $this->authorizeActivePermission('attendance.view');

        $this->month = now()->subDays(10)->format('Y-m');
    }

    public function openDay(int $employeeId, string $date): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $employee = $this->scopedEmployee($employeeId);
        $row = $this->rowFor($employee, $date);

        abort_unless($row !== null && $row['decidable'], 422);

        $this->dayEmployeeId = $employee->id;
        $this->dayDate = $date;
        $this->dayKind = $row['decision'] ?? AttendanceDayDecision::WORKED;
        $this->dayNote = (string) ($row['note'] ?? '');
        $this->resetErrorBag();
    }

    public function closeDay(): void
    {
        $this->dayEmployeeId = $this->dayDate = null;
        $this->dayNote = '';
        $this->resetErrorBag();
    }

    public function saveDecision(): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $this->validate([
            'dayKind' => ['required', 'in:'.AttendanceDayDecision::WORKED.','.AttendanceDayDecision::ABSENT],
            'dayNote' => ['nullable', 'string', 'max:200'],
        ]);

        $employee = $this->scopedEmployee((int) $this->dayEmployeeId);
        $row = $this->rowFor($employee, (string) $this->dayDate);

        // Only a day that is waiting, or already decided, can be answered; leave, holidays and
        // weekends are never overwritten from here.
        abort_unless($row !== null && $row['decidable'], 422);

        AttendanceDayDecision::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $this->dayDate],
            ['kind' => $this->dayKind, 'note' => $this->dayNote !== '' ? $this->dayNote : null, 'decided_by' => auth()->id()],
        );

        $this->closeDay();
        $this->toast(__('attendance.timesheets.saved'));
    }

    public function clearDecision(): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $employee = $this->scopedEmployee((int) $this->dayEmployeeId);

        AttendanceDayDecision::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', (string) $this->dayDate)
            ->delete();

        $this->closeDay();
        $this->toast(__('attendance.timesheets.cleared'));
    }

    public function render()
    {
        $month = $this->monthDate();
        $roster = app(Roster::class);
        $service = app(Timesheet::class);

        $sheets = $roster->employees(auth()->user(), $month, $this->cityId)
            ->map(fn (Employee $employee) => ['employee' => $employee, 'sheet' => $service->forEmployee($employee, $month)]);

        $toDecide = $sheets->sum(fn (array $s) => $s['sheet']['totals']['to_decide']);

        if ($this->onlyToDecide) {
            $sheets = $sheets->filter(fn (array $s) => $s['sheet']['totals']['to_decide'] > 0);
        }

        $open = null;

        if ($this->dayEmployeeId !== null && $this->dayDate !== null) {
            $employee = Employee::query()->find($this->dayEmployeeId);
            $open = $employee ? ['employee' => $employee, 'row' => $this->rowFor($employee, $this->dayDate)] : null;
        }

        return view('livewire.attendance.timesheets', [
            'sheets' => $sheets->values(),
            'toDecide' => $toDecide,
            'cities' => $roster->cities(auth()->user()),
            'monthDate' => $month,
            'open' => $open,
            'canManage' => auth()->user()->hasActivePermission('attendance.manage'),
        ]);
    }

    private function monthDate(): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return $parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::now()->startOfMonth();
    }

    private function scopedEmployee(int $id): Employee
    {
        $employee = Employee::query()->findOrFail($id);

        abort_unless(app(Roster::class)->covers(auth()->user(), $employee), 403);

        return $employee;
    }

    /** @return array<string, mixed>|null */
    private function rowFor(Employee $employee, string $date): ?array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if (! $day instanceof CarbonImmutable) {
            return null;
        }

        $sheet = app(Timesheet::class)->forEmployee($employee, $day->startOfMonth());

        return collect($sheet['rows'])->first(fn (array $row) => $row['date']->toDateString() === $date);
    }
}
