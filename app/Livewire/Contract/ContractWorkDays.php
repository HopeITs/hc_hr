<?php

namespace App\Livewire\Contract;

use App\Enums\ScheduleMode;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Contract;
use App\Models\ContractWorkPattern;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveRequestPricing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The working-days card on a contract's page: the days worked now, the moves
 * of the day off so far, and HR's "Change days off" from a date on — the
 * Study Zone volunteer who moves their day off every few months, without an
 * amendment (ContractWorkPatterns::change()). Any program may use it.
 */
class ContractWorkDays extends Component
{
    use GuardsModalClose;
    use WithFeedback;

    /** Saturday first, the way the working week is read here (Carbon numbers). */
    public const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

    #[Locked]
    public int $contractId;

    public bool $showChange = false;

    /** @var array<int, int> the working days being picked */
    public array $days = [];

    public ?string $effectiveFrom = null;

    public ?string $note = null;

    public function mount(Contract $contract): void
    {
        Gate::authorize('view', $contract);

        $this->contractId = $contract->id;
    }

    public function openChange(): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        $this->resetErrorBag();
        $this->days = $this->daysInForce($contract);
        $this->effectiveFrom = today()->toDateString();
        $this->note = null;
        $this->markClean('workDaysDialog');
        $this->showChange = true;
    }

    public function toggleDay(int $day): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        if ($day < 0 || $day > 6 || in_array($day, $this->placeWeekend($contract), true)) {
            return;
        }

        $this->days = in_array($day, $this->days, true)
            ? array_values(array_diff($this->days, [$day]))
            : [...$this->days, $day];
    }

    public function save(ContractWorkPatterns $patterns): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        $this->validate([
            'effectiveFrom' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'effectiveFrom' => __('contracts.work-days.effective-from'),
            'note' => __('contracts.work-days.note'),
        ]);

        try {
            $patterns->change($contract, $this->days, $this->effectiveFrom, $this->note, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'effective_from' ? 'effectiveFrom' : $field, $messages[0]);
            }

            return;
        }

        $this->close();
        $this->toast(__('contracts.work-days.saved'));
    }

    public function close(): void
    {
        $this->reset(['showChange', 'days', 'effectiveFrom', 'note']);
        $this->resetErrorBag();
    }

    public function render(ContractWorkPatterns $patterns): View
    {
        $contract = $this->contract();
        $user = auth()->user();
        $place = $this->placeWeekend($contract);
        $patternRows = $contract->workPatterns()->with(['author.profile', 'amendment:id,number'])->get();
        $canChange = $user?->can('changeWorkDays', $contract) ?? false;
        $fixed = (int) $contract->schedule_mode === ScheduleMode::Fixed;

        return view('livewire.contract.contract-work-days', [
            'contract' => $contract,
            // Shown for a fixed schedule that has days of its own, or may get them.
            'visible' => $fixed && ($patternRows->isNotEmpty() || $canChange),
            'canChange' => $canChange,
            'week' => $this->week($this->daysInForce($contract), $place),
            'history' => $patternRows->sortByDesc('effective_from')->values()
                ->map(fn (ContractWorkPattern $row) => [
                    'from' => $row->effective_from,
                    'days' => $row->days() === null ? null : $this->names($row->days()),
                    'by' => $row->author?->profile?->name ?? $row->author?->email,
                    // Named in the viewer's language, never stored as words.
                    'note' => $row->amendment
                        ? __('contracts.work-days.from-amendment', ['number' => $row->amendment->number])
                        : $row->note,
                ]),
            'picker' => $this->week($this->days, $place),
            'expected' => $patterns->daysPerWeek($contract),
            // Accepted leave on or after the date keeps the hours it was decided with.
            'affected' => $this->showChange && $this->effectiveFrom && strtotime($this->effectiveFrom)
                ? $patterns->acceptedLeavesFrom($contract, $this->effectiveFrom)
                : collect(),
        ]);
    }

    /** The dialog's id; it is shown by $showChange. */
    protected function guardedDialogs(): array
    {
        return ['workDaysDialog'];
    }

    protected function guardedDialogIsOpen(string $dialog): bool
    {
        return $this->showChange;
    }

    protected function guardedEntries(string $dialog): array
    {
        return ['days' => $this->days, 'effectiveFrom' => $this->effectiveFrom, 'note' => $this->note];
    }

    protected function closeGuardedDialog(string $dialog): void
    {
        $this->close();
    }

    /**
     * The days worked today: the work pattern in force, else the day range
     * without the place's day off. Empty when the contract states neither.
     *
     * @return array<int, int>
     */
    private function daysInForce(Contract $contract): array
    {
        $own = $contract->workWeekdaysOn(today());

        if ($own !== null) {
            return $own;
        }

        if ($contract->work_day_from === null || $contract->work_day_to === null || $contract->work_day_to < $contract->work_day_from) {
            return [];
        }

        return array_values(array_diff(range((int) $contract->work_day_from, (int) $contract->work_day_to), $this->placeWeekend($contract)));
    }

    /** @return array<int, int> */
    private function placeWeekend(Contract $contract): array
    {
        [$weekend, $source] = app(LeaveRequestPricing::class)->placeWeekendFor($contract);

        return $source === 'default' ? [] : $weekend;
    }

    /**
     * @param  array<int, int>  $working
     * @param  array<int, int>  $closed
     * @return list<array{day: int, name: string, working: bool, closed: bool}>
     */
    private function week(array $working, array $closed): array
    {
        return array_map(fn (int $day) => [
            'day' => $day,
            'name' => $this->dayName($day),
            'working' => in_array($day, $working, true) && ! in_array($day, $closed, true),
            'closed' => in_array($day, $closed, true),
        ], self::WEEK_ORDER);
    }

    /** @param  array<int, int>  $days */
    private function names(array $days): string
    {
        return collect(self::WEEK_ORDER)
            ->filter(fn (int $day) => in_array($day, $days, true))
            ->map(fn (int $day) => $this->dayName($day))
            ->implode(app()->getLocale() === 'ar' ? '، ' : ', ');
    }

    private function dayName(int $day): string
    {
        return now()->startOfWeek(0)->addDays($day)->translatedFormat('l');
    }

    private function contract(): Contract
    {
        return Contract::query()->with('position.center')->findOrFail($this->contractId);
    }
}
