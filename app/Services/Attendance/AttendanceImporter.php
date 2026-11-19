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

            $stored = $this->store($punches, $import);

            $import->update(['punches_new' => $stored['new'], 'punches_locked' => $stored['locked']]);

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
