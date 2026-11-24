<?php

namespace App\Livewire\Contract;

use App\Enums\ScheduleMode;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Contract;
use App\Models\ContractWorkPattern;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveRequestPricing;
use Illuminate\Support\Carbon;
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

    /**
     * The days the picker was last filled with (today's, or the chosen
     * date's). While the picked days still equal them, a new date refills
     * the picker with that date's days; once HR picks, it is left alone.
     *
     * @var array<int, int>
     */
    public array $seeded = [];

    public ?string $note = null;

    /**
     * How the dialog states the week: 'range' (from–to, the contract's own
     * way for most programs) or 'days' (picked one by one, Study Zone's).
     * Either way it saves the days; only the picker differs.
     */
    public string $mode = 'days';

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
        $this->seeded = $this->days;
        $this->mode = $this->isRange($contract) ? 'range' : 'days';
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

    /** The picker sets the days in the browser (instantly); keep them to real, open days. */
    public function updatedDays(): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        $this->days = array_values(array_diff(Contract::parseWeekdays($this->days), $this->placeWeekend($contract)));
        // An error about the days no longer describes what is picked.
        $this->resetErrorBag('days');
    }

    /** A new date shows that date's days, unless HR has already picked others. */
    public function updatedEffectiveFrom(): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        $this->resetErrorBag('effectiveFrom');

        if (! $this->effectiveFrom || ! strtotime($this->effectiveFrom)) {
            return;
        }

        if (Contract::parseWeekdays($this->days) === Contract::parseWeekdays($this->seeded)) {
            $this->days = $this->daysInForce($contract, Carbon::parse($this->effectiveFrom)->toDateString());
            $this->seeded = $this->days;
        }
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
        $this->reset(['showChange', 'days', 'seeded', 'mode', 'effectiveFrom', 'note']);
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
            // A day-range contract reads as a range, and is changed as one.
            'isRange' => $isRange = $this->isRange($contract),
            'rangeLabel' => $isRange ? $this->rangeLabel($this->daysInForce($contract)) : null,
            // A different number of days is an amendment: where to make one.
            'amendUrl' => $user?->can('amend', $contract) ? route('contracts.amend', $contract) : null,
            'now' => $this->summary($this->daysInForce($contract), $place),
            'week' => $this->week($this->daysInForce($contract), $place),
            // The change the days shown come from (null: as signed, never moved).
            'since' => ($since = $patternRows->last(fn (ContractWorkPattern $row) => $row->effective_from->lte(today()) && $patternRows->first()->isNot($row)))
                ? ['from' => $since->effective_from, 'label' => $this->source($since)]
                : null,
            // A move already saved for a later date.
            'next' => ($next = $patternRows->first(fn (ContractWorkPattern $row) => $row->effective_from->gt(today())))
                ? ['from' => $next->effective_from, 'days' => $next->days() === null ? null : $this->names(array_diff(range(0, 6), $next->days(), $place))]
                : null,
            'history' => $patternRows->sortByDesc('effective_from')->values()
                ->map(fn (ContractWorkPattern $row) => [
                    'from' => $row->effective_from,
                    'days' => $row->days() === null ? null : $this->names($row->days()),
                    'by' => $row->author?->profile?->name ?? $row->author?->email,
                    'note' => $this->source($row),
                ]),
            // The picker's fixed facts; which days are picked lives in the browser.
            'pickerDays' => array_map(fn (array $d) => [
                'day' => $d['day'], 'name' => $d['name'], 'short' => $d['short'], 'closed' => $d['closed'],
            ], $this->week([], $place)),
            // "Day off: Thursday → Tuesday": the own days off before the date, then after.
            'move' => $this->showChange ? $this->move($contract, $place) : null,
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
     * The days worked on $on (today by default). Empty when the contract
     * states none (a flexible week).
     *
     * @return array<int, int>
     */
    private function daysInForce(Contract $contract, ?string $on = null): array
    {
        // Picked days, a moved day off, or the day range (Contract::workWeekdaysOn),
        // without a day the center is closed.
        return array_values(array_diff($contract->workWeekdaysOn($on ?? today()) ?? [], $this->placeWeekend($contract)));
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
     * @return list<array{day: int, name: string, short: string, working: bool, closed: bool}>
     */
    private function week(array $working, array $closed): array
    {
        return array_map(fn (int $day) => [
            'day' => $day,
            'name' => $this->dayName($day),
            'short' => now()->startOfWeek(0)->addDays($day)->isoFormat('ddd'),
            'working' => in_array($day, $working, true) && ! in_array($day, $closed, true),
            'closed' => in_array($day, $closed, true),
        ], self::WEEK_ORDER);
    }

    /**
     * One line for the card: the working days, then the days off — the
     * person's own first, the place's after.
     *
     * @param  array<int, int>  $working
     * @param  array<int, int>  $closed
     * @return array{working: string, own: string, center: string, count: int}
     */
    private function summary(array $working, array $closed): array
    {
        $working = array_values(array_diff($working, $closed));

        return [
            'working' => $this->names($working, ' · '),
            'own' => $working === [] ? '' : $this->names(array_diff(range(0, 6), $working, $closed)),
            'center' => $this->names($closed),
            'count' => count($working),
        ];
    }

    /**
     * The person's own days off in force on the chosen date, and with the
     * days being picked: what the dialog says will change.
     *
     * @param  array<int, int>  $closed
     * @return array{before: string, after: string, changed: bool}|null
     */
    private function move(Contract $contract, array $closed): ?array
    {
        if (! $this->effectiveFrom || ! strtotime($this->effectiveFrom) || $this->days === []) {
            return null;
        }

        // What is in force on the date itself: saving replaces it from then on
        // (a row already on that date included), so that is what changes.
        $on = Carbon::parse($this->effectiveFrom)->toDateString();
        $workBefore = $this->daysInForce($contract, $on);
        $workAfter = Contract::parseWeekdays($this->days);
        $off = fn (array $working) => array_values(array_diff(range(0, 6), $working, $closed));
        $before = $off($workBefore);
        $after = $off($workAfter);
        sort($before);
        sort($after);

        // A day range reads best as the days worked ("Monday – Tuesday →
        // Tuesday – Wednesday"); picked days as the day off that moves.
        $asRange = $this->mode === 'range' && $this->isRun($workBefore) && $this->isRun($workAfter);

        return [
            'label' => __($asRange ? 'contracts.work-days.working-days' : 'contracts.work-days.day-off'),
            'before' => $asRange ? $this->rangeLabel($workBefore) : ($before === [] ? '—' : $this->names($before)),
            'after' => $asRange ? $this->rangeLabel($workAfter) : ($after === [] ? '—' : $this->names($after)),
            'changed' => $before !== $after,
        ];
    }

    /**
     * A contract whose week is a day range: none picked one by one in its
     * terms, and the days in force today follow on from each other.
     */
    private function isRange(Contract $contract): bool
    {
        if ($contract->workWeekdays() !== null) {
            return false;
        }

        return $this->isRun($this->daysInForce($contract));
    }

    /** Days that follow on from each other (a from–to range). @param  array<int, int>  $days */
    private function isRun(array $days): bool
    {
        return $days !== [] && max($days) - min($days) + 1 === count($days);
    }

    /** "Monday – Tuesday", or one day's name. @param  array<int, int>  $days */
    private function rangeLabel(array $days): ?string
    {
        if ($days === []) {
            return null;
        }

        return min($days) === max($days)
            ? $this->dayName(min($days))
            : $this->dayName(min($days)).' – '.$this->dayName(max($days));
    }

    /** What set a row: its amendment, named in the viewer's language (never stored as words), else HR's note. */
    private function source(ContractWorkPattern $row): ?string
    {
        return $row->amendment
            ? __('contracts.work-days.from-amendment', ['number' => $row->amendment->number])
            : $row->note;
    }

    /** @param  array<int, int>  $days */
    private function names(array $days, ?string $glue = null): string
    {
        return collect(self::WEEK_ORDER)
            ->filter(fn (int $day) => in_array($day, $days, true))
            ->map(fn (int $day) => $this->dayName($day))
            ->implode($glue ?? (app()->getLocale() === 'ar' ? '، ' : ', '));
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
