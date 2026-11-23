<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AttendanceDayDecision;
use App\Models\AttendanceMonth;
use App\Models\Employee;
use App\Models\MailList;
use App\Services\Attendance\Grouping;
use App\Services\Attendance\MonthWorkflow;
use App\Services\Attendance\Roster;
use App\Services\Attendance\Timesheet;
use App\Services\Attendance\WorkflowRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The month at a glance: for each city, the people grouped by project or by department, one
 * square per day, the overtime in hours and free days, and the days that wait for an answer.
 * Clicking a day that has no record (or a single punch) opens the question: did they work, or
 * were they absent? Clicking a person opens their month: confirm, approve, lock, the signed
 * paper. Who may do what follows MonthWorkflow::abilities().
 */
class Timesheets extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;
    use WithFileUploads;

    #[Url(as: 'month', history: false)]
    public string $month = '';

    #[Url(as: 'city', history: false)]
    public ?int $cityId = null;

    #[Url(as: 'by', history: false)]
    public string $groupBy = Grouping::PROJECT;

    public ?int $dayEmployeeId = null;

    public ?string $dayDate = null;

    public string $dayKind = AttendanceDayDecision::WORKED;

    public string $dayNote = '';

    /** The person whose month is open in the side panel. */
    public ?int $monthEmployeeId = null;

    public $signedFile;

    public function mount(): void
    {
        $this->authorizeActivePermission('attendance.view-own');

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->subDays(10)->format('Y-m');
        }

        $this->groupBy = Grouping::normalize($this->groupBy);

        // Who loads what by default: an employee, a manager and the HR of a city are already
        // limited to their own people or place. Someone who sees every city starts on one (their
        // own, else the first) rather than on the whole organisation: the page is quick and useful
        // at once, and "All cities" is one choice away. An explicit ?city= in the link wins.
        if ($this->cityId === null && ! request()->has('city')) {
            $this->cityId = app(Roster::class)->defaultCityId(auth()->user());
        }
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->monthDate()->addMonths($delta > 0 ? 1 : -1)->format('Y-m');
        // An open person stays open: their next or previous month. Without a contract then, the list shows.
        $this->closeDay();
        $this->signedFile = null;
    }

    // ---- Deciding a day -------------------------------------------------------------------

    public function openDay(int $employeeId, string $date): void
    {
        $employee = $this->scopedEmployee($employeeId, $date);
        $row = $this->rowFor($employee, $date);

        abort_unless($row !== null && $row['decidable'] && $this->mayDecide($employee, $date), 403);

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
        $this->validate([
            'dayKind' => ['required', 'in:'.AttendanceDayDecision::WORKED.','.AttendanceDayDecision::ABSENT],
            'dayNote' => ['nullable', 'string', 'max:200'],
        ]);

        $employee = $this->scopedEmployee((int) $this->dayEmployeeId, $this->dayDate);
        $row = $this->rowFor($employee, (string) $this->dayDate);

        // Only a day that is waiting, or already decided, can be answered, by someone who
        // supervises this person; leave, holidays and weekends are never overwritten from here.
        abort_unless($row !== null && $row['decidable'] && $this->mayDecide($employee, (string) $this->dayDate), 403);

        AttendanceDayDecision::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'work_date' => $this->dayDate],
            ['kind' => $this->dayKind, 'note' => $this->dayNote !== '' ? $this->dayNote : null, 'decided_by' => auth()->id()],
        );

        app(MonthWorkflow::class)->withdrawApproval($employee, CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->dayDate));

        $this->closeDay();
        $this->toast(__('attendance.timesheets.saved'));
    }

    /**
     * The person's calendar answers a day with one of two buttons. The answer is saved through the
     * same checks as the dialog, then the next day that still waits opens by itself.
     */
    public function answerDay(string $kind): void
    {
        $employeeId = $this->dayEmployeeId;
        $this->dayKind = $kind;
        $this->dayNote = '';
        $this->saveDecision();

        if ($employeeId === null || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $employee = Employee::query()->find($employeeId);
        $next = $employee ? collect(app(Timesheet::class)->forEmployee($employee, $this->monthDate())['rows'])
            ->first(fn (array $row) => in_array($row['kind'], [Timesheet::ONE_PUNCH, Timesheet::NO_RECORD], true) && $row['decidable']) : null;

        if ($next !== null && $this->mayDecide($employee, $next['date']->toDateString())) {
            $this->dayEmployeeId = $employee->id;
            $this->dayDate = $next['date']->toDateString();
            $this->dayKind = AttendanceDayDecision::WORKED;
        }
    }

    public function clearDecision(): void
    {
        $employee = $this->scopedEmployee((int) $this->dayEmployeeId, $this->dayDate);

        abort_unless($this->mayDecide($employee, (string) $this->dayDate), 403);

        AttendanceDayDecision::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', (string) $this->dayDate)
            ->delete();

        app(MonthWorkflow::class)->withdrawApproval($employee, CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->dayDate));

        $this->closeDay();
        $this->toast(__('attendance.timesheets.cleared'));
    }

    // ---- The month's steps ----------------------------------------------------------------

    public function openMonth(int $employeeId): void
    {
        $this->scopedEmployee($employeeId);

        $this->monthEmployeeId = $employeeId;
        $this->signedFile = null;
        $this->resetErrorBag();
    }

    public function closeMonth(): void
    {
        $this->monthEmployeeId = null;
        $this->signedFile = null;
        $this->closeDay();
        $this->resetErrorBag();
    }

    public function confirmMonth(int $employeeId): void
    {
        $this->step('confirm', $employeeId);
    }

    public function approveMonth(int $employeeId): void
    {
        $this->step('approve', $employeeId);
    }

    public function lockMonth(int $employeeId): void
    {
        $this->step('lock', $employeeId);
    }

    public function reopenMonth(int $employeeId): void
    {
        $this->step('reopen', $employeeId);
    }

    /** Approve every month on this page that is ready and that the viewer may approve. */
    public function approveAllReady(): void
    {
        $this->bulk('approve');
    }

    /** Lock every approved month on this page that the viewer may lock. */
    public function lockAllApproved(): void
    {
        $this->bulk('lock');
    }

    public function saveSigned(): void
    {
        $this->validate(['signedFile' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png']]);

        $employee = $this->scopedEmployee((int) $this->monthEmployeeId);
        $month = $this->monthDate();
        $record = AttendanceMonth::for($employee, $month);

        abort_unless($this->can($employee, $record)['signed'], 403);

        $record->exists || $record->save();
        $record->addMedia($this->signedFile->getRealPath())
            ->usingFileName('timesheet-'.$employee->id.'-'.$month->format('Y-m').'.'.$this->signedFile->getClientOriginalExtension())
            ->preservingOriginal()
            ->toMediaCollection('signed');

        $this->signedFile = null;
        $this->toast(__('attendance.workflow.signed-saved'));
    }

    public function removeSigned(): void
    {
        $employee = $this->scopedEmployee((int) $this->monthEmployeeId);
        $record = AttendanceMonth::for($employee, $this->monthDate());

        abort_unless($this->can($employee, $record)['signed'], 403);

        $record->clearMediaCollection('signed');
        $this->toast(__('attendance.workflow.signed-removed'));
    }

    // ---- The page -------------------------------------------------------------------------

    public function render()
    {
        $month = $this->monthDate();
        $by = Grouping::normalize($this->groupBy);
        $roster = app(Roster::class);

        // Read once per request: the page, the summary and the open panel all use this.
        $items = $this->items($month);

        // The figures above the groups describe the whole selection (the month and city), not
        // what the browser's search or filter happens to hide.
        $summary = [
            'people' => $items->count(),
            'to_decide' => (int) $items->sum(fn (array $i) => $i['sheet']['totals']['to_decide']),
            'overtime_hours' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_hours']), 2),
            'overtime_days' => round($items->sum(fn (array $i) => $i['sheet']['totals']['overtime_days']), 2),
            'ready_to_approve' => $items->filter(fn (array $i) => $i['can']['approve'])->count(),
            'ready_to_lock' => $items->filter(fn (array $i) => $i['can']['lock'])->count(),
        ];

        $open = null;

        if ($this->dayEmployeeId !== null && $this->dayDate !== null) {
            $employee = Employee::query()->find($this->dayEmployeeId);
            $open = $employee ? ['employee' => $employee, 'row' => $this->rowFor($employee, $this->dayDate)] : null;
        }

        $panel = null;

        if ($this->monthEmployeeId !== null) {
            $panel = $items->first(fn (array $i) => $i['employee']->id === $this->monthEmployeeId);
        }

        // An employee sees only their own month: it opens straight away, with no list before it.
        if ($panel === null && $roster->mode(auth()->user()) === 'own') {
            $panel = $items->first();
        }

        $days = collect(range(1, $month->daysInMonth))->map(fn (int $d) => [
            'n' => $d,
            'w' => $month->day($d)->locale(app()->getLocale())->isoFormat('dd'),
        ]);

        return view('livewire.attendance.timesheets', [
            'blocks' => app(Grouping::class)->group($items, $by),
            'items' => $items,
            'summary' => $summary,
            // The mailing lists that stand for a city, project or department: "Email this group".
            'mailLists' => MailList::customRuled(),
            'by' => $by,
            'days' => $days,
            'cities' => $roster->cities(auth()->user()),
            'cityName' => $this->cityId ? $roster->cities(auth()->user())->firstWhere('id', $this->cityId)?->name : null,
            'mode' => $roster->mode(auth()->user()),
            'monthDate' => $month,
            'open' => $open,
            'panel' => $panel,
            'canManage' => auth()->user()->hasActivePermission('attendance.manage'),
        ]);
    }

    /**
     * Everyone the viewer may see this month, with the month's record and what the viewer may do
     * with it. The roster is already scoped to the viewer, so every person is covered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function items(CarbonImmutable $month): Collection
    {
        $workflow = app(MonthWorkflow::class);
        $employees = app(Roster::class)->employees(auth()->user(), $month, $this->cityId);
        $sheets = app(Timesheet::class)->forEmployees($employees, $month);

        $records = AttendanceMonth::query()
            ->with('media')
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('month', $month->toDateString())
            ->get()
            ->keyBy('employee_id');

        return $employees->map(function (Employee $employee) use ($sheets, $workflow, $records, $month) {
            $sheet = $sheets[$employee->id];
            // No record yet is a blank one, not a query per person.
            $record = $records[$employee->id] ?? new AttendanceMonth(['employee_id' => $employee->id, 'month' => $month->toDateString()]);

            return [
                'employee' => $employee,
                'sheet' => $sheet,
                'record' => $record,
                'state' => $record->state(),
                'can' => $workflow->abilities(auth()->user(), $employee, $month, $record, $sheet['totals']['to_decide'], true),
            ];
        })->values();
    }

    private function step(string $step, int $employeeId): void
    {
        $employee = $this->scopedEmployee($employeeId);

        try {
            app(MonthWorkflow::class)->{$step}(auth()->user(), $employee, $this->monthDate());
            $this->toast(__('attendance.workflow.done.'.$step));
        } catch (WorkflowRefused $e) {
            $this->toast(__($e->getMessage()), 'error');
        }
    }

    private function bulk(string $step): void
    {
        $workflow = app(MonthWorkflow::class);
        $done = 0;

        foreach ($this->items($this->monthDate()) as $item) {
            if (! $item['can'][$step]) {
                continue;
            }

            try {
                $workflow->{$step}(auth()->user(), $item['employee'], $this->monthDate());
                $done++;
            } catch (WorkflowRefused) {
                // One that cannot go through now does not stop the others.
            }
        }

        $this->toast(trans_choice('attendance.workflow.bulk-'.$step, $done, ['count' => $done]));
    }

    /** @return array{decide: bool, confirm: bool, approve: bool, lock: bool, reopen: bool, signed: bool} */
    private function can(Employee $employee, AttendanceMonth $record): array
    {
        $month = $this->monthDate();
        $toDecide = app(Timesheet::class)->forEmployee($employee, $month)['totals']['to_decide'];

        return app(MonthWorkflow::class)->abilities(auth()->user(), $employee, $month, $record, $toDecide);
    }

    private function mayDecide(Employee $employee, string $date): bool
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $month = $day instanceof CarbonImmutable ? $day->startOfMonth() : $this->monthDate();

        return app(MonthWorkflow::class)
            ->abilities(auth()->user(), $employee, $month, AttendanceMonth::for($employee, $month), 1)['decide'];
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
