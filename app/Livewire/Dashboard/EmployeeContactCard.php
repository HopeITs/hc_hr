<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Employee;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One person's work contact (DashboardLayout::RECORDS "contact"), added from
 * the work contact section of their profile: name, photo, position, work
 * email and phones. The same rule as that section: EmployeePolicy::
 * viewDirectory, asked on every render; the photo only with viewAvatar.
 */
class EmployeeContactCard extends Component
{
    use HandlesLoadFailure;

    #[Locked]
    public int $employeeId;

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::recordKey('contact', (int) ($params['employeeId'] ?? 0));

        return view('livewire.dashboard.card-placeholder', ['title' => app(DashboardLayout::class)->label($key)]);
    }

    public function render()
    {
        $employee = Employee::query()->with(['account', 'media'])->find($this->employeeId);
        $allowed = $employee && Gate::allows('viewDirectory', $employee);

        return view('livewire.dashboard.employee-contact-card', [
            'employee' => $allowed ? $employee : null,
            'position' => $allowed ? $this->guardedLoad(fn () => $employee->latestActiveContract()?->position?->title) : null,
            'avatar' => $allowed && Gate::allows('viewAvatar', $employee) ? $employee->avatar_url : null,
            'email' => $allowed ? $employee->account?->email : null,
        ]);
    }
}
