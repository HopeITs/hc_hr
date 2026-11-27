<?php

namespace App\Console\Commands;

use App\Enums\ScheduleMode;
use App\Models\Contract;
use App\Services\ActivityLogger;
use App\Services\ContractWorkPatterns;
use App\Services\LeaveRequestPricing;
use App\Support\Dashboard\WorkdayClock;
use App\Support\StatisticsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Excel-imported contracts state their working days only as text (work_days_en/ar, "From Monday Till Friday").
 * This reads that text once and writes the days the rest of the system counts: a day range when it does not wrap,
 * the days themselves when it does ("From Saturday Till Thursday" is 0,1,2,3,4,6), since a wrapping range is not a
 * range anywhere else (Contract::termsDays(), the printed day count, ContractForm's gte rule).
 *
 * Only active fixed contracts with no days at all are read. The English text decides; an Arabic text that names
 * other days leaves the contract as it is. Written through the query, not the model: a contract save runs the
 * observer (auto-renew, statistics), which a data fix must not set off. The print toggles stay as they are, so a
 * contract that did not print its days still does not.
 */
class FillContractWorkDays extends Command
{
    /** Arabic day names as the import wrote them (hamza and "ال" dropped), to day-of-week numbers (0 = Sunday). */
    private const AR_DAYS = ['احد' => 0, 'اثنين' => 1, 'ثلاثاء' => 2, 'اربعاء' => 3, 'خميس' => 4, 'جمعه' => 5, 'سبت' => 6];

    protected $signature = 'contracts:fill-work-days
        {--dry-run : List what would be written, and the contracts whose text and center weekend disagree}';

    protected $description = 'Turn the working-days text of imported contracts into set days (once).';

    public function handle(LeaveRequestPricing $pricing, ContractWorkPatterns $patterns, ActivityLogger $log): int
    {
        $contracts = Contract::query()
            ->with('position.center', 'employee')
            ->where('is_active', true)
            ->where('schedule_mode', ScheduleMode::Fixed)
            ->whereNull('work_day_from')->whereNull('work_day_to')->whereNull('work_weekdays')
            ->whereNotNull('work_days_en')
            ->orderBy('id')
            ->get();

        $plans = [];
        $skipped = [];
        $differs = [];

        foreach ($contracts as $contract) {
            $range = WorkdayClock::dayRangeFromText($contract->work_days_en);
            $rangeAr = self::dayRangeFromArabic($contract->work_days_ar);

            if ($range === null) {
                $skipped[] = [$contract->id, $contract->employee?->name, $contract->work_days_en, 'English text not read'];

                continue;
            }

            if ($rangeAr !== null && $rangeAr !== $range) {
                $skipped[] = [$contract->id, $contract->employee?->name, $contract->work_days_en.' / '.$contract->work_days_ar, 'Arabic names other days'];

                continue;
            }

            [$from, $to] = $range;
            $days = $from <= $to ? range($from, $to) : [...range($from, 6), ...range(0, $to)];
            $fields = $from <= $to
                ? ['work_day_from' => $from, 'work_day_to' => $to]
                : ['work_weekdays' => implode(',', Contract::parseWeekdays($days))];

            [$weekend] = $pricing->placeWeekendFor($contract);
            $off = array_values(array_diff(range(0, 6), $days));
            sort($weekend);

            if ($off !== $weekend) {
                $differs[] = [$contract->id, $contract->employee?->name, $contract->work_days_en, self::names($off), self::names($weekend)];
            }

            $plans[] = [$contract, $fields];
        }

        $this->info(count($plans).' contract(s) to fill, '.count($skipped).' left as they are.');

        if ($skipped) {
            $this->table(['Contract', 'Employee', 'Text', 'Why left'], $skipped);
        }

        if ($differs) {
            $this->warn(count($differs).' of them state days off other than their center\'s weekend; from now on their own days count:');
            $this->table(['Contract', 'Employee', 'Text', 'Off by the text', 'Center weekend'], $differs);
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        foreach ($plans as [$contract, $fields]) {
            DB::transaction(function () use ($contract, $fields, $patterns, $log): void {
                Contract::query()->whereKey($contract->id)->update($fields + ['updated_at' => now()]);
                $contract->forceFill($fields);

                // Picked days are the contract's work pattern from its start (as the composer saves them).
                if (isset($fields['work_weekdays']) && ! $contract->hasAppliedAmendments()) {
                    $patterns->syncInitial($contract);
                }

                $log->log('contract.work_days_filled', $contract, properties: ['text' => $contract->work_days_en] + $fields, logName: 'contracts');
            });
        }

        StatisticsCache::flush();
        $this->info('Filled '.count($plans).' contract(s).');

        return self::SUCCESS;
    }

    /**
     * "من الاثنين إلى الجمعة" → [1, 5]; null when it is not two day names this reads.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dayRangeFromArabic(?string $text): ?array
    {
        if (! is_string($text)) {
            return null;
        }

        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه']);

        if (! preg_match('/^\s*(?:من\s+)?(?:يوم\s+)?(?:ال)?(\S+)\s+(?:الى|حتى|لغايه)\s+(?:يوم\s+)?(?:ال)?(\S+)\s*$/u', $text, $m)) {
            return null;
        }

        $from = self::AR_DAYS[$m[1]] ?? null;
        $to = self::AR_DAYS[$m[2]] ?? null;

        return $from === null || $to === null ? null : [$from, $to];
    }

    /** @param list<int> $days */
    private static function names(array $days): string
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return $days === [] ? '—' : implode(', ', array_map(fn (int $d) => $names[$d], $days));
    }
}
