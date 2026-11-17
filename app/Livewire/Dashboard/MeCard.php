<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Livewire\Concerns\IsDashboardTile;
use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * "Me": the viewer's own record at a glance (name, photo, position, centre,
 * the current contract's type and dates, department, direct manager, work
 * phone) and a link to the profile. Only the viewer's own employee record is
 * read; no pay, no ID numbers, no personal contact details.
 */
class MeCard extends Component
{
    use HandlesLoadFailure;
    use IsDashboardTile;

    public function render()
    {
        return view('livewire.dashboard.me-card', [
            'me' => $this->guardedLoad(fn () => $this->me()),
        ]);
    }

    /** @return array<string, mixed>|null */
    protected function me(): ?array
    {
        $user = auth()->user();
        $employee = $user?->profile;

        if (! $employee instanceof Employee) {
            return null;
        }

        $contract = $employee->latestActiveContract()?->loadMissing(['contractType', 'position.center', 'position.department', 'position.directManager']);
        $position = $contract?->position;
        $manager = $position?->directManager?->activeContracts()->with('employee')->latest('start_date')->first()?->employee;

        return [
            'name' => (string) $employee->name,
            'avatar' => Gate::forUser($user)->allows('viewAvatar', $employee) ? $employee->avatar_url : null,
            'position' => $position?->title,
            'center' => $position?->center?->name,
            'type' => $contract?->contractType?->name,
            'start' => $contract?->start_date,
            'end' => $contract?->end_date,
            'department' => $position?->department?->name,
            'manager' => $manager?->is_active ? (string) $manager->name : null,
            'phone' => $employee->work_phone ?: null,
        ];
    }
}
