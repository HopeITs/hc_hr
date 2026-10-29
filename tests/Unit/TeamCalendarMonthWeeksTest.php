<?php

namespace Tests\Unit;

use App\Livewire\Team\Calendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TeamCalendarMonthWeeksTest extends TestCase
{
    public function test_a_leave_across_days_is_one_bar_per_week(): void
    {
        // 2026-09-27 (Sunday) … two weeks; the leave runs Tuesday to the next Monday.
        $weeks = Calendar::monthWeeks($this->days('2026-09-27', 14, [
            $this->entry('a', 'Rami', '2026-09-29', '2026-10-05'),
        ]));

        $this->assertCount(2, $weeks);
        $this->assertCount(1, $weeks[0]['bars']);
        $this->assertSame(['col' => 3, 'span' => 5, 'lane' => 0, 'continues_before' => false, 'continues_after' => true],
            array_diff_key($weeks[0]['bars'][0], ['entry' => 0]));
        $this->assertSame(['col' => 1, 'span' => 2, 'lane' => 0, 'continues_before' => true, 'continues_after' => false],
            array_diff_key($weeks[1]['bars'][0], ['entry' => 0]));
    }

    public function test_overlapping_leaves_take_separate_lanes_and_the_overflow_is_counted(): void
    {
        $weeks = Calendar::monthWeeks($this->days('2026-09-27', 7, [
            $this->entry('a', 'Long', '2026-09-27', '2026-10-03'),
            $this->entry('b', 'Short', '2026-09-28', '2026-09-28'),
            $this->entry('c', 'Later', '2026-09-30', '2026-10-01'),
            $this->entry('d', 'Extra', '2026-09-30', '2026-09-30'),
        ]), lanes: 2);

        $lanes = array_column(array_map(fn (array $b) => ['k' => $b['entry']['key'], 'l' => $b['lane']], $weeks[0]['bars']), 'l', 'k');

        $this->assertSame(['a' => 0, 'b' => 1, 'c' => 1], $lanes);
        $this->assertSame(2, $weeks[0]['lanes']);
        $this->assertSame([0, 0, 0, 1, 0, 0, 0], $weeks[0]['hidden']);
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function days(string $from, int $count, array $entries): array
    {
        $days = [];

        for ($i = 0; $i < $count; $i++) {
            $date = CarbonImmutable::parse($from)->addDays($i)->toDateString();
            $days[$date] = ['date' => $date, 'members' => array_values(array_filter($entries, fn (array $e) => in_array($date, $e['dates'], true)))];
        }

        return $days;
    }

    private function entry(string $key, string $name, string $from, string $to): array
    {
        $dates = [];

        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return ['key' => $key, 'name' => $name, 'from' => $from, 'to' => $to, 'dates' => $dates];
    }
}
