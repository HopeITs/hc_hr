<?php

namespace App\Services\Inbox;

use App\Models\OffboardingJourney;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exit steps waiting on HR (DEC-09 increment 2): a departing volunteer still
 * holds delivered equipment whose return nobody has requested yet (the city
 * had no default receiver for it, so ChecklistWorkflow skipped it). Listed
 * after deactivation too: the equipment still has to come back.
 *
 * Due on the last contract's end (termination date first), Late after it,
 * as OffboardingJourneys::steps() marks the equipment step.
 */
class ExitInboxSource extends JourneyInboxSource
{
    public function name(): string
    {
        return 'exit';
    }

    protected function includesFormerStaff(): bool
    {
        return true;
    }

    protected function pending(): Builder
    {
        $unrequested = fn (Builder $checklist) => $checklist
            ->where('delivered_status', 'delivered')
            ->whereNull('returned_status');

        return OffboardingJourney::query()
            ->whereNull('completed_at')
            ->whereHas('employee.checklists', $unrequested)
            ->with(['employee.city', 'contract'])
            ->orderBy('started_at')
            ->orderBy('id');
    }

    /** @param  OffboardingJourney  $journey */
    protected function steps(Model $journey): array
    {
        return [['key' => 'equipment', 'state' => self::STATE_TODO]];
    }

    /** @param  OffboardingJourney  $journey */
    protected function due(Model $journey): ?CarbonInterface
    {
        $contract = $journey->contract;

        return $contract?->terminated_at ?? $contract?->end_date;
    }

    /** @param  OffboardingJourney  $journey */
    protected function late(Model $journey): bool
    {
        $due = $this->due($journey);

        return $due !== null && $due->toDateString() < now(config('app.timezone'))->toDateString();
    }
}
