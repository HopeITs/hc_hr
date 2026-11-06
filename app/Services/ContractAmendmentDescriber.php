<?php

namespace App\Services;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\Currency;
use App\Models\ContractAmendment;
use Carbon\Carbon;

/**
 * An amendment's changes in words, for the screens: the amendments card on a
 * contract and the employee timeline. The printed sheet words them through the
 * contract's own clause components instead.
 */
final class ContractAmendmentDescriber
{
    /**
     * What an amendment changed, one line per term a person would name:
     * [label, before, after]. Pay lines are left out for a role barred from
     * figures.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function lines(ContractAmendment $amendment, bool $withPay): array
    {
        $changed = $amendment->changedFields();
        $before = $amendment->terms_before ?? [];
        $after = $amendment->terms_after ?? [];
        $lines = [];

        $typeName = fn ($type) => filled($type) ? ContractTypeEnum::getLocalizedName((int) $type)['value'] : '—';
        $hours = fn (array $t) => filled($t['weekly_hours'] ?? null) ? trans_choice('contracts.amendment.weekly-hours', (int) $t['weekly_hours'], ['count' => (int) $t['weekly_hours']]) : '—';
        $time = fn ($v) => filled($v) ? substr((string) $v, 0, 5) : null;
        $shift = function (array $t) use ($time): string {
            $first = $time($t['work_hours_from'] ?? null) && $time($t['work_hours_to'] ?? null)
                ? $time($t['work_hours_from']).'–'.$time($t['work_hours_to'])
                : null;
            $second = $time($t['work_hours_2_from'] ?? null) && $time($t['work_hours_2_to'] ?? null)
                ? $time($t['work_hours_2_from']).'–'.$time($t['work_hours_2_to'])
                : null;

            return collect([$first, $second])->filter()->implode(' · ') ?: '—';
        };
        $days = function (array $t): string {
            if (! is_numeric($t['work_day_from'] ?? null) || ! is_numeric($t['work_day_to'] ?? null)) {
                return '—';
            }

            $name = fn (int $d) => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($d)->translatedFormat('l');

            return $name((int) $t['work_day_from']).' – '.$name((int) $t['work_day_to']);
        };

        if (in_array('type', $changed, true)) {
            $lines[] = [__('general.type'), $typeName($before['type'] ?? null), $typeName($after['type'] ?? null)];
        }

        if (in_array('weekly_hours', $changed, true)) {
            $lines[] = [__('general.weekly-hours'), $hours($before), $hours($after)];
        }

        if (array_intersect(['work_hours_from', 'work_hours_to', 'work_hours_2_from', 'work_hours_2_to'], $changed)) {
            $lines[] = [__('contracts.amendment.hours'), $shift($before), $shift($after)];
        }

        if (array_intersect(['work_day_from', 'work_day_to'], $changed)) {
            $lines[] = [__('general.work-days'), $days($before), $days($after)];
        }

        if ($withPay && array_intersect(['salary', 'currency', 'salary_per'], $changed)) {
            $money = fn ($amount, $currency) => filled($amount)
                ? number_format((float) $amount, 2).' '.(Currency::getLocalizedName((int) $currency)[app()->getLocale()] ?? '')
                : '—';

            $lines[] = [
                __('general.salary'),
                $money($amendment->salary_before, $before['currency'] ?? null),
                $money($amendment->salary_after, $after['currency'] ?? null),
            ];
        }

        if ($withPay && in_array('pay_tiers', $changed, true)) {
            $lines[] = [
                __('contracts.amendment.pay-tiers'),
                trans_choice('contracts.tiers-count', $amendment->payTiersBefore->count(), ['count' => $amendment->payTiersBefore->count()]),
                trans_choice('contracts.tiers-count', $amendment->payTiersAfter->count(), ['count' => $amendment->payTiersAfter->count()]),
            ];
        }

        $described = ['type', 'weekly_hours', 'work_hours_from', 'work_hours_to', 'work_hours_2_from', 'work_hours_2_to',
            'work_day_from', 'work_day_to', 'salary', 'currency', 'salary_per', 'pay_tiers'];
        $rest = array_diff($changed, $described, $withPay ? [] : ContractAmendment::PAY_FIELDS);

        if ($rest !== []) {
            $lines[] = [__('contracts.amendment.other-terms'), '', ''];
        }

        return $lines;
    }

    /**
     * The same, as one line for the timeline: "Weekly hours: 20 → 30 · …".
     * The timeline prints the new salary on a line of its own, so the salary
     * change is left out of this one ($withSalary false) to say it once.
     */
    public function summary(ContractAmendment $amendment, bool $withPay, bool $withSalary = true): string
    {
        $arrow = app()->getLocale() === 'ar' ? ' ← ' : ' → ';
        $salaryLabel = __('general.salary');

        return collect($this->lines($amendment, $withPay))
            ->reject(fn (array $line) => ! $withSalary && $line[0] === $salaryLabel)
            ->map(fn (array $line) => $line[1] === '' && $line[2] === '' ? $line[0] : $line[0].': '.$line[1].$arrow.$line[2])
            ->implode(' · ');
    }
}
