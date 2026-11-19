<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Holiday;
use App\Notifications\BirthdayChoiceNeeded;
use App\Services\BirthdayHoliday;
use App\Services\BirthdayHolidayNotifier;
use App\Services\NotificationDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Meeting 19/11, track 3: a volunteer whose birthday falls on a weekend or a
 * holiday in the next config('birthday-holiday.notify_days_ahead') days, and
 * who has not asked for another day yet, is told to pick a working day in
 * the same week. Once per (employee, year, holiday): the ledger claim is keyed
 * to the holiday and 1 January of the birthday's year. Service agreements get
 * no birthday holiday, so BirthdayHoliday gives them no occurrence at all.
 */
class NotifyBirthdayConflicts extends Command
{
    public const LEDGER_KIND = 'birthday-choice-needed';

    protected $signature = 'birthday:notify-conflicts';

    protected $description = 'Ask volunteers whose birthday falls on a weekend or holiday to pick another day in the same week';

    public function handle(NotificationDeliveryLedger $ledger, BirthdayHoliday $birthdays, BirthdayHolidayNotifier $notifier): int
    {
        $from = CarbonImmutable::instance($ledger->today())->startOfDay();
        $to = $from->addDays(max(0, (int) config('birthday-holiday.notify_days_ahead', 14)));
        $sent = 0;

        if (! Holiday::query()->where('kind', Holiday::KIND_BIRTHDAY)->occurringBetween($from, $to)->exists()) {
            $this->info('Sent 0 birthday reminder(s).');

            return self::SUCCESS;
        }

        $monthDays = $this->monthDays($from, $to);

        Employee::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('actual_dob')->orWhereNotNull('dob'))
            ->select(['id', 'city_id', 'dob', 'actual_dob', 'is_active'])
            ->lazyById()
            ->filter(fn (Employee $e) => isset($monthDays[CarbonImmutable::parse($e->actual_dob ?? $e->dob)->format('m-d')]))
            ->each(function (Employee $employee) use ($ledger, $birthdays, $notifier, $from, $to, &$sent): void {
                $contract = $employee->latestActiveContract();

                if (! $contract) {
                    return;
                }

                foreach ($birthdays->occurrencesFor($contract, $from, $to) as $occurrence) {
                    if (! $occurrence['conflict'] || $occurrence['choice'] !== null) {
                        continue;
                    }

                    $holiday = $occurrence['holiday'];
                    $day = CarbonImmutable::create($occurrence['year'], 1, 1);

                    foreach ($notifier->accountsOf($employee->id) as $user) {
                        if (! $ledger->claim(self::LEDGER_KIND, $user->id, [$holiday->id], $day)) {
                            continue;
                        }

                        try {
                            $user->notify(new BirthdayChoiceNeeded($holiday, $occurrence['year'], $occurrence['date']));
                            $ledger->markDelivered(self::LEDGER_KIND, $user->id, [$holiday->id], $day);
                            $sent++;
                        } catch (Throwable $e) {
                            $ledger->release(self::LEDGER_KIND, $user->id, [$holiday->id], $day);
                            report($e);
                        }
                    }
                }
            });

        $this->info("Sent {$sent} birthday reminder(s).");

        return self::SUCCESS;
    }

    /**
     * The month-days whose birthday lands in [$from, $to]; 29 February is
     * kept on 28 February in other years, as BirthdayHoliday does.
     *
     * @return array<string, true>
     */
    private function monthDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[$day->format('m-d')] = true;

            if ($day->format('m-d') === '02-28' && ! $day->isLeapYear()) {
                $days['02-29'] = true;
            }
        }

        return $days;
    }
}
