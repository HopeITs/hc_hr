<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceImporter;
use App\Services\Attendance\Roster;
use App\Services\Attendance\UnreadableReport;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Upload the attendance report exported from the device program. The file is read and shown first
 * (how many punches, which period, which device numbers are not an employee yet); nothing is
 * stored until HR confirms. A number that is not an employee's ID is tied to someone once, and
 * stays tied.
 */
class Upload extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;
    use WithFileUploads;

    public $file;

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public ?int $importedId = null;

    /** Employee ID typed beside each unmatched device number. @var array<string, string> */
    public array $employeeIds = [];

    public function mount(): void
    {
        $this->authorizeActivePermission('attendance.manage');
    }

    public function updatedFile(): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $this->preview = null;
        $this->importedId = null;
        $this->resetErrorBag('file');

        $this->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xls,xlsx,csv,txt']]);

        try {
            $summary = app(AttendanceImporter::class)->preview($this->file->getRealPath());
        } catch (UnreadableReport $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->preview = [
            'total' => $summary['total'],
            'invalid' => $summary['invalid'],
            'from' => $summary['from'],
            'to' => $summary['to'],
            'unmatched' => collect($summary['numbers'])->where('employee', null)->count(),
            'numbers' => collect($summary['numbers'])->map(fn (array $row) => [
                'device_no' => $row['device_no'],
                'punches' => $row['punches'],
                'name' => $row['employee']?->name,
            ])->all(),
            'name' => $this->file->getClientOriginalName(),
        ];
    }

    public function import(): void
    {
        $this->authorizeActivePermission('attendance.manage');

        abort_if($this->file === null || $this->preview === null, 422);

        try {
            $import = app(AttendanceImporter::class)->import($this->file->getRealPath(), $this->file->getClientOriginalName(), auth()->user());
        } catch (UnreadableReport $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->importedId = $import->id;
        $this->cancel();
        $this->toast(__('attendance.upload.imported', ['new' => $import->punches_new]));
    }

    public function cancel(): void
    {
        $this->file = null;
        $this->preview = null;
        $this->resetErrorBag();
    }

    /** Tie a device number to an employee, by the employee's ID, within the user's cities. */
    public function linkNumber(string $deviceNo): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $id = trim($this->employeeIds[$deviceNo] ?? '');

        if ($id === '' || ! ctype_digit($id)) {
            $this->addError("employeeIds.$deviceNo", __('attendance.upload.enter-id'));

            return;
        }

        $employee = Employee::query()->find((int) $id);

        if ($employee === null || ! app(Roster::class)->covers(auth()->user(), $employee)) {
            $this->addError("employeeIds.$deviceNo", __('attendance.upload.no-such-employee'));

            return;
        }

        app(AttendanceImporter::class)->link($deviceNo, $employee, auth()->user());

        unset($this->employeeIds[$deviceNo]);
        $this->resetErrorBag();
        $this->toast(__('attendance.upload.linked', ['name' => $employee->name]));
    }

    public function render()
    {
        $unlinked = AttendancePunch::query()
            ->whereNull('employee_id')
            ->selectRaw('device_no, count(*) as punches, min(punched_at) as first_at, max(punched_at) as last_at')
            ->groupBy('device_no')
            ->orderBy('device_no')
            ->limit(200)
            ->get();

        return view('livewire.attendance.upload', [
            'unlinked' => $unlinked,
            'imports' => AttendanceImport::query()->with('user.profile')->latest()->limit(8)->get(),
        ]);
    }
}
