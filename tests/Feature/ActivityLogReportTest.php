<?php

namespace Tests\Feature;

use App\Exports\ActivityReportExport;
use App\Livewire\ActivityLog\ActivityLogReport;
use App\Models\User;
use App\Services\ActivityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_the_most_active_users_in_the_period(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Activity::query()->delete();
        foreach (range(1, 3) as $_) {
            activity()->causedBy($a)->log('x');
        }
        activity()->causedBy($b)->log('y');

        $r = (new ActivityReport(30))->build();

        $this->assertSame(4, $r['total']);
        $this->assertSame(2, $r['activeUsers']);
        $this->assertSame([3, 1], $r['topUsers']->pluck('actions')->all());
    }

    public function test_trend_and_hours_are_zero_filled_and_add_up(): void
    {
        $a = User::factory()->create();
        Activity::query()->delete();
        activity()->causedBy($a)->log('x');
        activity()->causedBy($a)->log('y');

        $r = (new ActivityReport(7))->build();

        $this->assertCount(7, $r['daily']);
        $this->assertSame(2, $r['daily']->sum('actions'));
        $this->assertSame(2, $r['daily']->last()['actions']);
        $this->assertCount(24, $r['hours']);
        $this->assertSame(2, $r['hours']->sum('actions'));
    }

    public function test_the_report_renders_and_exports(): void
    {
        $a = User::factory()->create();
        activity()->causedBy($a)->log('x');

        $r = (new ActivityReport(30))->build();
        $this->assertCount(8, (new ActivityReportExport($r))->sheets());

        Livewire::test(ActivityLogReport::class)->assertOk()->assertSee('Activity report');
    }
}
