<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDeviceLink;
use App\Models\AttendanceImport;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a device report into stored punches. A punch is kept once whatever the number of times
 * it is uploaded (device number + minute is unique), and a device number is tied to an employee
 * either by HR (AttendanceDeviceLink) or because it is the employee's ID.
 */
class AttendanceImporter
{
    public function __construct(
        private readonly DeviceReportReader $reader,
        private readonly MonthWorkflow $workflow,
    ) {}

    /**
     * What an upload holds, before anything is stored.
     *
     * @return array{total: int, invalid: int, from: ?string, to: ?string, numbers: list<array{device_no: string, punches: int, employee: ?Employee}>}
     */
    public function preview(string $path): array
    {
        $report = $this->reader->read($path);
        $perNumber = [];
        $times = [];

        foreach ($report['punches'] as $punch) {
            $perNumber[$punch['device_no']] = ($perNumber[$punch['device_no']] ?? 0) + 1;
            $times[] = $punch['at'];
        }

        $employees = $this->employeesFor(array_keys($perNumber));

        $numbers = collect($perNumber)
            ->map(fn (int $punches, string $no) => ['device_no' => $no, 'punches' => $punches, 'employee' => $employees[$no] ?? null])
            ->sortBy(fn (array $row) => [$row['employee'] === null ? 0 : 1, (int) $row['device_no']])
            ->values()
            ->all();

        return [
            'total' => count($report['punches']),
            'invalid' => $report['invalid'],
            'from' => $times === [] ? null : min($times)->toDateString(),
            'to' => $times === [] ? null : max($times)->toDateString(),
            'numbers' => $numbers,
        ];
    }

    public function import(string $path, string $fileName, ?User $by): AttendanceImport
    {
        $report = $this->reader->read($path);
        $punches = $report['punches'];

        return DB::transaction(function () use ($punches, $fileName, $by) {
            $times = array_column($punches, 'at');

            $import = AttendanceImport::create([
                'user_id' => $by?->id,
                'file_name' => $fileName,
                'period_start' => $times === [] ? null : min($times)->toDateString(),
                'period_end' => $times === [] ? null : max($times)->toDateString(),
                'punches_total' => count($punches),
                'device_numbers' => count(array_unique(array_column($punches, 'device_no'))),
            ]);

            // HR uploads the report month by month, and a file is the whole truth for the days it
            // covers: what was stored before for the same device numbers on those days goes, and
            // the file's punches take its place. A closed month is never touched.
            $replaced = $this->clearPeriod($punches);

            $stored = $this->store($punches, $import);

            // Punches kept from before may have been stored when their number matched no one
            // (or someone else): tie every punch of the file's days to whom its number is now.
            $this->relink($punches);

            $import->update(['punches_new' => $stored['new'], 'punches_locked' => $stored['locked']]);

            // Not a column: only the message after the upload needs it.
            $import->setAttribute('punches_replaced', $replaced);

            return $import;
        });
    }

    /**
     * Keep punches once, whichever way they arrived (a file or a device pushing by itself): tie
     * each device number to its employee, skip the ones that fall in a locked month, ignore the
     * ones already stored, and withdraw the approval of any month that gained a new punch.
     *
     * @param  list<array{device_no: string, at: CarbonImmutable}>  $punches
     * @return array{new: int, locked: int}
     */
    public function store(array $punches, AttendanceImport $import, ?int $deviceId = null): array
    {
        $employees = $this->employeesFor(array_values(array_unique(array_column($punches, 'device_no'))));

        // A locked month does not change: its punches are not taken in.
        $locked = AttendanceMonth::query()->whereNotNull('locked_at')->get()
            ->mapWithKeys(fn (AttendanceMonth $m) => [$m->employee_id.'|'.$m->month->format('Y-m') => true]);

        $now = now();
        $rows = [];
        $skipped = 0;

        foreach ($punches as $p) {
            $employeeId = ($employees[$p['device_no']] ?? null)?->id;

            if ($employeeId !== null && isset($locked[$employeeId.'|'.$p['at']->format('Y-m')])) {
                $skipped++;

                continue;
            }

            $rows[] = [
                'import_id' => $import->id,
                'device_id' => $deviceId,
                'device_no' => $p['device_no'],
                'employee_id' => $employeeId,
                'punched_at' => $p['at']->format('Y-m-d H:i:s'),
                'created_at' => $now,
            ];
        }

        $new = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $new += AttendancePunch::query()->insertOrIgnore($chunk);
        }

        // New punches in an approved month: what was approved is no longer what the month holds.
        if ($new > 0) {
            $this->withdrawApprovals(AttendancePunch::query()->where('import_id', $import->id)->whereNotNull('employee_id')->get(['employee_id', 'punched_at']));
        }

        return ['new' => $new, 'locked' => $skipped];
    }

    /** Tie a device number to an employee and give the punches already stored for it their owner. */
    public function link(string $deviceNo, Employee $employee, ?User $by): void
    {
        DB::transaction(function () use ($deviceNo, $employee, $by) {
            AttendanceDeviceLink::query()->updateOrCreate(
                ['device_no' => $deviceNo],
                ['employee_id' => $employee->id, 'created_by' => $by?->id],
            );

            AttendancePunch::query()->where('device_no', $deviceNo)->update(['employee_id' => $employee->id]);

            $this->withdrawApprovals(AttendancePunch::query()->where('device_no', $deviceNo)->get(['employee_id', 'punched_at']));
        });
    }

    /**
     * Who each device number is: a hand-made link first, then the employee whose ID it is.
     *
     * @param  list<string>  $numbers
     * @return array<string, Employee>
     */
    public function employeesFor(array $numbers): array
    {
        $found = [];

        $links = AttendanceDeviceLink::query()->with('employee')->whereIn('device_no', $numbers)->get();

        foreach ($links as $link) {
            if ($link->employee) {
                $found[$link->device_no] = $link->employee;
            }
        }

        if (config('attendance.device_number_is_employee_id')) {
            $ids = collect($numbers)->reject(fn (string $no) => isset($found[$no]) || ! ctype_digit($no))->map(fn (string $no) => (int) $no)->all();

            foreach (Employee::query()->whereIn('id', $ids)->get() as $employee) {
                $found[(string) $employee->id] ??= $employee;
            }
        }

        return $found;
    }

    /** @param  Collection<int, AttendancePunch>  $punches */
    /**
     * For the file's days and device numbers, set each stored punch's employee to whom the number
     * stands for now. A closed month is left as it is.
     *
     * @param  list<array{device_no: string, at: CarbonImmutable}>  $punches
     */
    private function relink(array $punches): void
    {
        if ($punches === []) {
            return;
        }

        $times = array_column($punches, 'at');
        $from = min($times)->startOfDay()->format('Y-m-d H:i:s');
        $to = max($times)->endOfDay()->format('Y-m-d H:i:s');
        $locked = AttendanceMonth::query()->whereNotNull('locked_at')->get()
            ->mapWithKeys(fn (AttendanceMonth $m) => [$m->employee_id.'|'.$m->month->format('Y-m') => true]);

        foreach ($this->employeesFor(array_values(array_unique(array_column($punches, 'device_no')))) as $number => $employee) {
            $stale = AttendancePunch::query()
                ->where('device_no', (string) $number)
                ->whereBetween('punched_at', [$from, $to])
                ->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employee->id))
                ->get(['id', 'punched_at'])
                ->reject(fn (AttendancePunch $p) => isset($locked[$employee->id.'|'.$p->punched_at->format('Y-m')]));

            foreach ($stale->pluck('id')->chunk(500) as $ids) {
                AttendancePunch::query()->whereIn('id', $ids->all())->update(['employee_id' => $employee->id]);
            }
        }
    }

    /**
     * Remove the punches already stored for the file's device numbers on the days the file covers
     * (from its first day to its last), except in a closed month. Returns how many went.
     *
     * @param  list<array{device_no: string, at: CarbonImmutable}>  $punches
     */
    private function clearPeriod(array $punches): int
    {
        if ($punches === []) {
            return 0;
        }

        $times = array_column($punches, 'at');
        $numbers = array_values(array_unique(array_column($punches, 'device_no')));
        $inFile = [];
        foreach ($punches as $p) {
            $inFile[$p['device_no'].'|'.$p['at']->format('Y-m-d H:i')] = true;
        }

        $locked = AttendanceMonth::query()->whereNotNull('locked_at')->get()
            ->mapWithKeys(fn (AttendanceMonth $m) => [$m->employee_id.'|'.$m->month->format('Y-m') => true]);

        $old = AttendancePunch::query()
            ->whereIn('device_no', $numbers)
            ->whereBetween('punched_at', [min($times)->startOfDay()->format('Y-m-d H:i:s'), max($times)->endOfDay()->format('Y-m-d H:i:s')])
            ->get(['id', 'device_no', 'employee_id', 'punched_at'])
            ->reject(fn (AttendancePunch $p) => $p->employee_id !== null && isset($locked[$p->employee_id.'|'.$p->punched_at->format('Y-m')]))
            // What the file holds again stays as it is: the same file twice changes nothing.
            ->reject(fn (AttendancePunch $p) => isset($inFile[$p->device_no.'|'.$p->punched_at->format('Y-m-d H:i')]));

        foreach ($old->pluck('id')->chunk(500) as $ids) {
            AttendancePunch::query()->whereIn('id', $ids->all())->delete();
        }

        // A month that lost punches is no longer what was approved.
        $this->withdrawApprovals($old->whereNotNull('employee_id'));

        return $old->count();
    }

    private function withdrawApprovals(Collection $punches): void
    {
        $punches
            ->map(fn (AttendancePunch $p) => $p->employee_id.'|'.$p->punched_at->format('Y-m'))
            ->unique()
            ->each(function (string $key) {
                [$employeeId, $month] = explode('|', $key);
                $this->workflow->withdrawApproval((int) $employeeId, CarbonImmutable::createFromFormat('!Y-m', $month));
            });
    }
}
