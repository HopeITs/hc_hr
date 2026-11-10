<?php

namespace App\Livewire\Dashboard;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\Contract;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentRequestListQuery;
use App\Support\Dashboard\HubGroups;
use App\Support\Dashboard\WaitingOnYou;
use Livewire\Component;
use Throwable;

/**
 * The dashboard's six groups (HubGroups). A group's figures are read only when
 * its card first opens, and each keeps its page's own scope and permission:
 * Contract::visibleTo(), RecruitmentRequestListQuery, the viewer's own record.
 */
class Hub extends Component
{
    /** Figures of the cards opened so far: group id => rows, null if the load failed. */
    public array $figures = [];

    public function load(string $id): void
    {
        $user = auth()->user();

        // Client input: only a group this person actually has.
        if (! in_array($id, array_column(HubGroups::for($user), 'id'), true) || array_key_exists($id, $this->figures)) {
            return;
        }

        try {
            $this->figures[$id] = $this->figuresFor($id, $user);
        } catch (Throwable $e) {
            report($e);
            $this->figures[$id] = null;
        }
    }

    public function render()
    {
        return view('livewire.dashboard.hub', ['groups' => HubGroups::for(auth()->user())]);
    }

    /** @return list<array{value: int, label: string}> */
    private function figuresFor(string $id, User $user): array
    {
        return match ($id) {
            'people', 'reports' => $this->contracts($user),
            'leaves' => $this->leaves($user),
            'recruitment' => $user->can('viewAny', RecruitmentRequest::class)
                ? [['value' => app(RecruitmentRequestListQuery::class)->forUser($user)->count(), 'label' => 'requests']]
                : [],
            'structure' => $this->structure($user),
            default => [],
        };
    }

    /** @return list<array{value: int, label: string}> */
    private function contracts(User $user): array
    {
        if (! $user->hasActivePermission('contracts.view')) {
            return [];
        }

        return [
            ['value' => Contract::query()->visibleTo($user)->activeNow()->count(), 'label' => 'running-contracts'],
            ['value' => Contract::query()->visibleTo($user)->inView('ending-soon', 30)->count(), 'label' => 'ending-soon'],
            ['value' => Contract::query()->visibleTo($user)->inView('not-renewed', 30)->count(), 'label' => 'not-renewed'],
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private function leaves(User $user): array
    {
        $figures = [];

        if ($user->employee_id !== null) {
            $annual = $user->profile?->latestActiveContract()?->leaveBalances()->where('type_id', LeaveTypeCases::Annual)->with('contract')->first();

            if ($annual) {
                $figures[] = ['value' => $annual->int_available, 'label' => 'available-days'];
            }

            $figures[] = [
                'value' => LeaveRequest::query()
                    ->where('status', LeaveRequestStatus::Pending)
                    ->whereHas('leaveBalance.contract', fn ($contract) => $contract->where('employee_id', $user->employee_id))
                    ->count(),
                'label' => 'my-pending',
            ];
        }

        $toDecide = app(WaitingOnYou::class)->inbox($user)['counts']['decide'];

        if ($toDecide > 0) {
            $figures[] = ['value' => $toDecide, 'label' => 'to-decide'];
        }

        return $figures;
    }

    /** @return list<array{value: int, label: string}> */
    private function structure(User $user): array
    {
        if (! $user->hasActivePermission('positions.view')) {
            return [];
        }

        $positions = Position::query()->visible()->count();
        $filled = Position::query()->visible()->whereHas('activeContracts')->count();

        return [
            ['value' => $positions, 'label' => 'positions'],
            ['value' => $filled, 'label' => 'filled'],
            ['value' => $positions - $filled, 'label' => 'vacant'],
        ];
    }
}
