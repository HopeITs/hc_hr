<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AttendanceDayDecision;
use App\Models\Employee;
use App\Services\Attendance\Grouping;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Timesheet;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The month at a glance: for each city, the people grouped by project or by department, one
 * square per day, the overtime in hours and free days, and the days that wait for HR. Clicking a
 * day that has no record (or a single punch) opens the question: did they work, or were they
 * absent?
 */
class Timesheets extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    #[Url(as: 'month', history: false)]
    public string $month = '';

    #[Url(as: 'city', history: false)]
    public ?int $cityId = null;

    #[Url(as: 'by', history: false)]
    public string $groupBy = Grouping::PROJECT;

    public string $search = '';

    public bool $onlyToDecide = false;

    /** Group keys the viewer folded away ("<city>:<group>"). @var array<string, bool> */
    public array $collapsed = [];

    public ?int $dayEmployeeId = null;

    public ?string $dayDate = null;

    public string $dayKind = AttendanceDayDecision::WORKED;

    public string $dayNote = '';

    public function mount(): void
    {
        $this->authorizeActivePermission('attendance.view-own');

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->subDays(10)->format('Y-m');
        }

        $this->groupBy = Grouping::normalize($this->groupBy);
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->monthDate()->addMonths($delta > 0 ? 1 : -1)->format('Y-m');
    }

    public function toggleGroup(string $key): void
    {
        $this->collapsed[$key] = ! ($this->collapsed[$key] ?? false);
    }

    public function openDay(int $employeeId, string $date): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $employee = $this->scopedEmployee($employeeId, $date);
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

        $employee = $this->scopedEmployee((int) $this->dayEmployeeId, $this->dayDate);
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

        $employee = $this->scopedEmployee((int) $this->dayEmployeeId, $this->dayDate);

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
        $by = Grouping::normalize($this->groupBy);
        $roster = app(Roster::class);
        $service = app(Timesheet::class);

        $items = $roster->employees(auth()->user(), $month, $this->cityId)
            ->when(trim($this->search) !== '', fn ($all) => $all->filter(fn (Employee $e) => $this->matchesSearch($e)))
            ->map(fn (Employee $employee) => ['employee' => $employee, 'sheet' => $service->forEmployee($employee, $month)])
            ->values();

        // The figures above the groups describe the whole selection, not just what is unfolded.
        $summary = [
            'people' => $items->count(),
            'to_decide' => (int) $items->sum(fn (array $i) => $i['sheet']['totals']['to_decide']),
            'overtime_hours' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_hours']), 2),
            'overtime_days' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_days']), 2),
        ];

        if ($this->onlyToDecide) {
            $items = $items->filter(fn (array $i) => $i['sheet']['totals']['to_decide'] > 0)->values();
        }

        $open = null;

        if ($this->dayEmployeeId !== null && $this->dayDate !== null) {
            $employee = Employee::query()->find($this->dayEmployeeId);
            $open = $employee ? ['employee' => $employee, 'row' => $this->rowFor($employee, $this->dayDate)] : null;
        }

        $days = collect(range(1, $month->daysInMonth))->map(fn (int $d) => [
            'n' => $d,
            'w' => $month->day($d)->locale(app()->getLocale())->isoFormat('dd'),
        ]);

        return view('livewire.attendance.timesheets', [
            'blocks' => app(Grouping::class)->group($items, $by),
            'summary' => $summary,
            'by' => $by,
            'days' => $days,
            'cities' => $roster->cities(auth()->user()),
            'mode' => $roster->mode(auth()->user()),
            'monthDate' => $month,
            'open' => $open,
            'canManage' => auth()->user()->hasActivePermission('attendance.manage'),
        ]);
    }

    private function matchesSearch(Employee $employee): bool
    {
        $needle = mb_strtolower(trim($this->search));

        foreach (['en', 'ar'] as $locale) {
            if (str_contains(mb_strtolower((string) $employee->nameForLocale($locale)), $needle)) {
                return true;
            }
        }

        return $needle === (string) $employee->id;
    }

    private function monthDate(): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m', $this->month);

        return $parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::now()->startOfMonth();
    }

    private function scopedEmployee(int $id, ?string $date = null): Employee
    {
        $employee = Employee::query()->findOrFail($id);
        $day = $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;
        $month = $day instanceof CarbonImmutable ? $day->startOfMonth() : $this->monthDate();

        abort_unless(app(Roster::class)->covers(auth()->user(), $employee, $month), 403);

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
