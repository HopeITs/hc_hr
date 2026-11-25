<?php

namespace App\Livewire\Contract;

use App\Enums\ContractAmendmentStatus;
use App\Enums\ScheduleMode;
use App\Livewire\Concerns\GuardsModalClose;
use App\Livewire\Concerns\WithFeedback;
use App\Models\Contract;
use App\Models\ContractWorkPattern;
use App\Models\Program;
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

    /** 'card' on the contract page, 'profile' on the employee's profile. */
    #[Locked]
    public string $variant = 'card';

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

    /**
     * An amendment set the days on the chosen date (save() refuses it): kept
     * here so the browser can hold Save back. A hint only; save() decides.
     */
    public bool $dateTaken = false;

    public function mount(Contract $contract, string $variant = 'card'): void
    {
        // The contract's readers, the employee, and their direct / technical manager.
        Gate::authorize('viewWorkDays', $contract);

        $this->contractId = $contract->id;
        $this->variant = $variant === 'profile' ? 'profile' : 'card';
    }

    public function openChange(): void
    {
        $contract = $this->contract();
        Gate::authorize('changeWorkDays', $contract);

        $this->resetErrorBag();
        $this->days = $this->daysInForce($contract);
        $this->seeded = $this->days;
        // Its own way; with no days stated yet, a range — Study Zone picks them.
        $this->mode = $this->isRange($contract)
            || ($this->days === [] && (int) $contract->position?->program_id !== Program::STUDY_ZONE_ID)
            ? 'range' : 'days';
        // Today, unless an amendment fixed the days on it: then the next free day.
        $this->effectiveFrom = $this->freeDateFrom($contract, today()->toDateString());
        $this->dateTaken = false;
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
        $this->dateTaken = $this->amendmentOn($contract, $this->effectiveFrom) !== null;

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
        $this->reset(['showChange', 'days', 'seeded', 'mode', 'dateTaken', 'effectiveFrom', 'note']);
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
            // A fixed schedule whose days are stated (a range or picked days),
            // changed since, or that this viewer may set.
            'visible' => $fixed && ($patternRows->isNotEmpty() || $canChange || $this->daysInForce($contract) !== []),
            'canChange' => $canChange,
            'hours' => $this->hoursLabel($contract),
            'contractLabel' => $contract->position?->title,
            // A day-range contract reads as a range, and is changed as one.
            'isRange' => $isRange = $this->isRange($contract),
            'rangeLabel' => $isRange ? $this->rangeLabel($this->daysInForce($contract)) : null,
            // A different number of days is an amendment: where to make one,
            // the one already waiting, or who to ask.
            'amendWay' => $this->showChange ? $this->amendWay($contract) : null,
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
            // An amendment's date is the signed terms: said before Save, with the next free day.
            'amendmentOnDate' => $amendmentOnDate = $this->showChange ? $this->amendmentOn($contract, $this->effectiveFrom) : null,
            'freeDate' => $amendmentOnDate ? $this->freeDateFrom($contract, $this->effectiveFrom) : null,
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
     * The number of the amendment whose days start on $date, if one does: a
     * change on that date would replace the signed terms, so it is refused.
     */
    private function amendmentOn(Contract $contract, ?string $date): ?int
    {
        if (! $date || ! strtotime($date)) {
            return null;
        }

        $row = $contract->workPatterns()
            ->with('amendment:id,number')
            ->whereNotNull('contract_amendment_id')
            ->whereDate('effective_from', Carbon::parse($date)->toDateString())
            ->first();

        return $row?->amendment?->number;
    }

    /** $date, or the first day after it on which no amendment set the days. */
    private function freeDateFrom(Contract $contract, string $date): string
    {
        $taken = $contract->workPatterns()
            ->whereNotNull('contract_amendment_id')
            ->whereDate('effective_from', '>=', Carbon::parse($date)->toDateString())
            ->pluck('effective_from')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $day = Carbon::parse($date);

        while (in_array($day->toDateString(), $taken, true)) {
            $day = $day->addDay();
        }

        return $day->toDateString();
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

    /**
     * "09:00–17:00 · 40 h a week": the shifts of a working day, then the week.
     * Each range isolated left-to-right, or Arabic lays it out backwards.
     */
    private function hoursLabel(Contract $contract): ?string
    {
        $time = fn ($v) => filled($v) ? substr((string) $v, 0, 5) : null;
        $shift = fn ($from, $to) => $time($from) && $time($to) ? "\u{2066}".$time($from).'–'.$time($to)."\u{2069}" : null;

        $parts = array_filter([
            $shift($contract->work_hours_from, $contract->work_hours_to),
            $shift($contract->work_hours_2_from, $contract->work_hours_2_to),
            $contract->weekly_hours ? trans_choice('contracts.work-days.weekly-hours', (int) $contract->weekly_hours, ['count' => (int) $contract->weekly_hours]) : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The way to an amendment from the dialog, for whoever is looking: make
     * one; open the one already waiting (one at a time); or, for a role that
     * makes none (a manager) or a contract not in force, what to do instead.
     *
     * @return array{href: ?string, label: string}
     */
    private function amendWay(Contract $contract): array
    {
        $user = auth()->user();

        if ($user?->can('amend', $contract)) {
            return ['href' => route('contracts.amend', $contract), 'label' => __('contracts.work-days.make-amendment')];
        }

        $waiting = $contract->amendments()
            ->whereIn('status', [ContractAmendmentStatus::Draft, ContractAmendmentStatus::Approved])
            ->exists();

        if ($waiting && $user?->can('view', $contract)) {
            return ['href' => route('contracts.show', $contract).'#amendments', 'label' => __('contracts.work-days.open-waiting-amendment')];
        }

        return ['href' => null, 'label' => __($user?->activeRoleIs('HR') ? 'contracts.work-days.amend-not-now' : 'contracts.work-days.amend-ask-hr')];
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
