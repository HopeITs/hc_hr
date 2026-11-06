<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Enums\ScheduleMode;
use App\Enums\SessionDuration;
use App\Models\ContractAmendment;
use Carbon\Carbon;

/**
 * An amendment's changes in words, for the screens: the amendments card on a
 * contract, the amendment page's notice and the employee timeline. The printed
 * sheet words them through the contract's own clause components instead.
 *
 * Only what a person chose is named. The print toggles (whether the sheet
 * states "N hours a week") are presentation, not terms, and are left out; a
 * fixed-mode time is not named once the schedule is flexible, nor a flexible
 * box once it is fixed; and a term that had no value before has no "before".
 */
final class ContractAmendmentDescriber
{
    /**
     * One line per term that changed: [label, before, after]. `before` is ''
     * when the term was not set before. Pay lines are left out for a role
     * barred from figures.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function lines(ContractAmendment $amendment, bool $withPay): array
    {
        return collect($this->terms($amendment, $withPay))
            ->filter(fn (array $term) => $term['changed'])
            ->map(fn (array $term) => [$term['label'], $term['before'], $term['after']])
            ->values()
            ->all();
    }

    /**
     * Every term the contract runs on after this amendment, changed or not —
     * what the card shows, so an amendment reads on its own: type, schedule,
     * weekly hours, working hours and days (or the flexible terms), pay.
     * A term with no value is left out; `before` is filled only when changed.
     *
     * @return list<array{label: string, after: string, before: string, changed: bool}>
     */
    public function terms(ContractAmendment $amendment, bool $withPay): array
    {
        $changed = $amendment->changedFields();
        $has = fn (string ...$fields) => array_intersect($fields, $changed) !== [];
        $before = $amendment->terms_before ?? [];
        $after = $amendment->terms_after ?? [];
        $terms = [];

        $add = function (string $label, string $from, string $to, bool $moved) use (&$terms): void {
            if ($to === '') {
                return;
            }

            $moved = $moved && $from !== $to;
            $terms[] = ['label' => $label, 'after' => $to, 'before' => $moved ? $from : '', 'changed' => $moved];
        };

        $add(__('general.type'), $this->type($before), $this->type($after), $has('type'));
        $add(__('general.composer.schedule-mode'), $this->scheduleMode($before), $this->scheduleMode($after), $has('schedule_mode'));
        $add(__('general.weekly-hours'), $this->weeklyHours($before), $this->weeklyHours($after), $has('weekly_hours'));

        $modeMoved = $has('schedule_mode');

        if ($this->isFlexible($after)) {
            $add(__('contracts.amendment.flexible-terms'), $this->isFlexible($before) ? $this->flexible($before) : '', $this->flexible($after),
                $modeMoved || $has('flex_days_per_week', 'flex_hours_per_week', 'flex_hours_per_month', 'flex_varies_by_requirements',
                    'flex_coordinated_with_manager', 'flex_custom_en', 'flex_custom_ar'));
        } else {
            $wasFixed = ! $this->isFlexible($before);

            $add(__('contracts.amendment.hours'), $wasFixed ? $this->shifts($before) : '', $this->shifts($after),
                $modeMoved || $has('work_hours_from', 'work_hours_to', 'work_hours_2_from', 'work_hours_2_to'));
            $add(__('general.work-days'), $wasFixed ? $this->days($before) : '', $this->days($after),
                $modeMoved || $has('work_day_from', 'work_day_to'));
        }

        if ($withPay) {
            if ($has('pay_mode')) {
                $add(__('contracts.amendment.pay-mode'), $this->payMode($before), $this->payMode($after), true);
            }

            if ($this->isFlexiblePay($after)) {
                $add(__('contracts.amendment.pay-tiers'),
                    $this->isFlexiblePay($before) ? $this->tiers($amendment->payTiersBefore()->get(), $before) : '',
                    $this->tiers($amendment->payTiersAfter()->get(), $after),
                    $has('pay_tiers', 'currency', 'pay_mode'));
            } else {
                $add(__('general.salary'),
                    $this->isFlexiblePay($before) ? '' : $this->money($amendment->salary_before, $before),
                    $this->money($amendment->salary_after, $after),
                    $has('salary', 'currency', 'salary_per', 'session_duration', 'pay_mode'));
            }
        }

        return $terms;
    }

    /**
     * The shortest telling, for the employee timeline: the new value of the two
     * changes a person looks for first (work type, weekly hours, work days,
     * working hours, in that order), then "+N" for the rest. No "was", and no
     * salary: the timeline prints the new salary on a line of its own. Empty
     * when only pay changed. The amendment's card has the full detail.
     */
    public function brief(ContractAmendment $amendment, bool $withPay): string
    {
        ['shown' => $shown, 'more' => $more] = $this->briefParts($amendment, $withPay);

        return collect($shown)->when($more !== [], fn ($s) => $s->push('+'.count($more)))->implode(' · ');
    }

    /**
     * brief() in its two halves, for a timeline that opens the rest on demand:
     * `shown` the new values on the line, `more` the remaining changes as
     * [label, new value].
     *
     * @return array{shown: list<string>, more: list<array{0: string, 1: string}>}
     */
    public function briefParts(ContractAmendment $amendment, bool $withPay): array
    {
        $priority = [
            __('general.type'),
            __('general.weekly-hours'),
            __('general.work-days'),
            __('contracts.amendment.hours'),
            __('contracts.amendment.flexible-terms'),
            __('general.composer.schedule-mode'),
        ];

        $lines = collect($this->lines($amendment, $withPay))
            ->reject(fn (array $line) => in_array($line[0], [__('general.salary'), __('contracts.amendment.pay-tiers'), __('contracts.amendment.pay-mode')], true))
            ->sortBy(fn (array $line) => ($i = array_search($line[0], $priority, true)) === false ? 99 : $i)
            ->values();

        return [
            'shown' => $lines->take(2)->map(fn (array $line) => $line[2])->values()->all(),
            'more' => $lines->slice(2)->map(fn (array $line) => [$line[0], $line[2]])->values()->all(),
        ];
    }

    /** The labels alone, for a folded line: "Weekly hours · Work days · Salary". */
    public function labels(ContractAmendment $amendment, bool $withPay): string
    {
        return collect($this->lines($amendment, $withPay))->pluck(0)->implode(' · ');
    }

    /**
     * One line for the timeline and the amendment page: "Weekly hours: 24
     * hours a week (was 40 hours a week) · …". The timeline prints the new
     * salary on a line of its own, so it passes $withSalary false.
     */
    public function summary(ContractAmendment $amendment, bool $withPay, bool $withSalary = true): string
    {
        $salaryLabel = __('general.salary');

        return collect($this->lines($amendment, $withPay))
            ->reject(fn (array $line) => ! $withSalary && $line[0] === $salaryLabel)
            ->map(fn (array $line) => $line[0].': '.$line[2].($line[1] !== '' ? ' ('.__('contracts.amendment.was', ['value' => $line[1]]).')' : ''))
            ->implode(' · ');
    }

    // ── terms in words ──────────────────────────────────────────────

    private function type(array $t): string
    {
        return filled($t['type'] ?? null) && (int) $t['type'] !== 0 ? ContractTypeEnum::getLocalizedName((int) $t['type'])['value'] : '';
    }

    private function scheduleMode(array $t): string
    {
        return ScheduleMode::getLocalizedName($this->isFlexible($t) ? ScheduleMode::Flexible : ScheduleMode::Fixed)['value'];
    }

    private function isFlexible(array $t): bool
    {
        return (int) ($t['schedule_mode'] ?? ScheduleMode::Fixed) === ScheduleMode::Flexible;
    }

    private function weeklyHours(array $t): string
    {
        $hours = (int) ($t['weekly_hours'] ?? 0);

        return $hours > 0 ? trans_choice('contracts.amendment.weekly-hours', $hours, ['count' => $hours]) : '';
    }

    private function shifts(array $t): string
    {
        $time = fn ($v) => filled($v) ? substr((string) $v, 0, 5) : null;
        // Isolated left-to-right (U+2066 … U+2069): in an Arabic line a bare
        // "09:00–15:00" is laid out backwards, as "15:00–09:00".
        $shift = fn (string $from, string $to) => $time($t[$from] ?? null) && $time($t[$to] ?? null)
            ? "\u{2066}".$time($t[$from]).'–'.$time($t[$to])."\u{2069}"
            : null;

        return collect([$shift('work_hours_from', 'work_hours_to'), $shift('work_hours_2_from', 'work_hours_2_to')])
            ->filter()->implode(' · ');
    }

    private function days(array $t): string
    {
        if (! is_numeric($t['work_day_from'] ?? null) || ! is_numeric($t['work_day_to'] ?? null)) {
            return '';
        }

        $name = fn (int $d) => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($d)->translatedFormat('l');

        return (int) $t['work_day_from'] === (int) $t['work_day_to']
            ? $name((int) $t['work_day_from'])
            : $name((int) $t['work_day_from']).' – '.$name((int) $t['work_day_to']);
    }

    private function flexible(array $t): string
    {
        $locale = app()->getLocale();

        return collect([
            filled($t['flex_hours_per_week'] ?? null) ? trans_choice('contracts.amendment.weekly-hours', (int) $t['flex_hours_per_week'], ['count' => (int) $t['flex_hours_per_week']]) : null,
            filled($t['flex_days_per_week'] ?? null) ? trans_choice('contracts.amendment.days-per-week', (int) $t['flex_days_per_week'], ['count' => (int) $t['flex_days_per_week']]) : null,
            filled($t['flex_hours_per_month'] ?? null) ? trans_choice('contracts.amendment.monthly-hours', (int) $t['flex_hours_per_month'], ['count' => (int) $t['flex_hours_per_month']]) : null,
            ! empty($t['flex_varies_by_requirements']) ? __('contracts.amendment.flex-varies') : null,
            ! empty($t['flex_coordinated_with_manager']) ? __('contracts.amendment.flex-coordinated') : null,
            filled($t["flex_custom_{$locale}"] ?? null) ? trim((string) $t["flex_custom_{$locale}"]) : null,
        ])->filter()->implode(' · ');
    }

    private function payMode(array $t): string
    {
        return __($this->isFlexiblePay($t) ? 'contracts.amendment.pay-flexible' : 'contracts.amendment.pay-fixed');
    }

    private function isFlexiblePay(array $t): bool
    {
        return (int) ($t['pay_mode'] ?? PayMode::Fixed) === PayMode::Flexible;
    }

    private function money(mixed $amount, array $t): string
    {
        if (blank($amount)) {
            return '';
        }

        $per = match ((int) ($t['salary_per'] ?? SalaryPer::Month)) {
            SalaryPer::Hour => __('general.composer.per-hour'),
            SalaryPer::Session => __('general.composer.per-session'),
            default => __('general.composer.per-month'),
        };

        $session = (int) ($t['salary_per'] ?? 0) === SalaryPer::Session && filled($t['session_duration'] ?? null)
            ? ' ('.SessionDuration::getLocalizedName((int) $t['session_duration'])['value'].')'
            : '';

        return $this->amount($amount).' '.$this->currency($t).' '.$per.$session;
    }

    private function tiers($tiers, array $t): string
    {
        $locale = app()->getLocale();

        return $tiers->map(fn ($tier) => trim((string) $tier->{"condition_{$locale}"}).': '.$this->amount($tier->amount).' '.$this->currency($t))
            ->implode(' · ');
    }

    private function amount(mixed $amount): string
    {
        $value = (float) $amount;

        // "150", not "150.00"; a figure with a fraction keeps it.
        return number_format($value, floor($value) === $value ? 0 : 2);
    }

    private function currency(array $t): string
    {
        return Currency::getLocalizedName((int) ($t['currency'] ?? 0))[app()->getLocale()] ?? '';
    }
}
