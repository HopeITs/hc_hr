<?php

namespace Tests\Feature;

use App\Console\Commands\FillContractWorkDays;
use App\Enums\ScheduleMode;
use App\Models\City;
use App\Models\Contract;
use App\Support\Dashboard\WorkdayClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** R2-4b: the working-days text of imported contracts becomes days the system counts. */
class FillContractWorkDaysTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('SYR', '000');
    }

    public function test_a_plain_range_becomes_the_day_range_and_a_wrapping_one_the_days_themselves(): void
    {
        $plain = $this->imported('From Monday Till Friday', 'من الاثنين إلى الجمعة');
        $wraps = $this->imported('From Saturday Till Thursday', 'من السبت إلى الخيمس'); // the import's own misspelling
        $bare = $this->imported('Sunday Till Thursday', 'الاحد إلى الخميس');

        $this->artisan('contracts:fill-work-days')->assertSuccessful();

        $plain->refresh();
        $this->assertSame([1, 5], [$plain->work_day_from, $plain->work_day_to]);
        $this->assertNull($plain->work_weekdays);
        $this->assertSame([1, 2, 3, 4, 5], Contract::termsDays($plain->getAttributes()));

        $wraps->refresh();
        $this->assertNull($wraps->work_day_from);
        $this->assertSame('0,1,2,3,4,6', $wraps->work_weekdays);
        $this->assertSame([0, 1, 2, 3, 4, 6], $wraps->workWeekdaysOn('2026-06-01'));

        $this->assertSame([0, 4], [$bare->fresh()->work_day_from, $bare->fresh()->work_day_to]);

        // Recorded, and the printed contract is left as it was.
        $this->assertSame(3, Activity::query()->where('description', 'contract.work_days_filled')->count());
        $this->assertFalse((bool) $plain->show_days_per_week);
        $this->assertFalse((bool) $plain->show_day_range);
    }

    public function test_it_leaves_alone_what_it_cannot_read_or_must_not_touch(): void
    {
        $conflict = $this->imported('monday till friday', 'الخميس الى الجمعة');
        $prose = $this->imported('Monday, Wednesday', 'الاثنين والأربعاء');
        $flexible = $this->imported('From Monday Till Friday', 'من الاثنين إلى الجمعة', ['schedule_mode' => ScheduleMode::Flexible]);
        $set = $this->imported('From Monday Till Friday', 'من الاثنين إلى الجمعة', ['work_day_from' => 0, 'work_day_to' => 4]);
        $ended = $this->imported('From Monday Till Friday', 'من الاثنين إلى الجمعة', ['is_active' => false]);

        $this->artisan('contracts:fill-work-days')->assertSuccessful();

        foreach ([$conflict, $prose, $flexible, $ended] as $contract) {
            $contract->refresh();
            $this->assertNull($contract->work_day_from);
            $this->assertNull($contract->work_weekdays);
        }

        $this->assertSame([0, 4], [$set->fresh()->work_day_from, $set->fresh()->work_day_to]);
    }

    public function test_a_dry_run_writes_nothing_and_names_the_days_that_differ_from_the_center(): void
    {
        // The center's weekend is Friday; this contract's text gives Saturday and Sunday off.
        $contract = $this->imported('From Monday Till Friday', 'من الاثنين إلى الجمعة');
        $contract->position->center->update(['weekends' => '5']);

        $this->artisan('contracts:fill-work-days', ['--dry-run' => true])
            ->expectsOutputToContain('1 of them state days off other than their center')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertNull($contract->fresh()->work_day_from);
    }

    public function test_the_text_readers_take_the_imports_phrasings(): void
    {
        $this->assertSame([0, 4], WorkdayClock::dayRangeFromText('Sunday Till Thursday'));
        $this->assertSame([1, 5], WorkdayClock::dayRangeFromText('monday till friday'));
        $this->assertNull(WorkdayClock::dayRangeFromText('Monday, Wednesday'));

        $this->assertSame([1, 5], FillContractWorkDays::dayRangeFromArabic('من الإثنين إلى الجمعة'));
        $this->assertSame([1, 5], FillContractWorkDays::dayRangeFromArabic('الاثنين إلى يوم الجمعة'));
        $this->assertSame([0, 4], FillContractWorkDays::dayRangeFromArabic('الاحد إلى الخميس'));
        $this->assertNull(FillContractWorkDays::dayRangeFromArabic('من السبت إلى الخيمس'));
    }

    private function imported(string $en, string $ar, array $overrides = []): Contract
    {
        $employee = $this->makeEmployee($this->city, 'e'.uniqid());

        return $this->makeContractFor($employee, array_merge([
            'schedule_mode' => ScheduleMode::Fixed,
            'work_days_en' => $en,
            'work_days_ar' => $ar,
            'work_hours_from' => '09:00',
            'work_hours_to' => '17:00',
        ], $overrides));
    }
}
