<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Livewire\Dashboard\UpcomingLeavesCard;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

class UpcomingLeavesCardTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    #[DataProvider('leaveDates')]
    public function test_dashboard_includes_ongoing_and_upcoming_leaves(string $type, string $start, string $end, bool $visible): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $city = $this->makeCity('DMS', '003');
        $hr = $this->makeUser($city, 'HR-DMS');
        $leave = $this->makeLeave($city, $type, $start, $end);

        $component = Livewire::actingAs($hr)->test(UpcomingLeavesCard::class);

        $this->assertSame($visible ? [$leave->id] : [], $component->viewData('upcomingLeaves')->pluck('id')->all());
    }

    public static function leaveDates(): array
    {
        return [
            'ongoing across days' => ['day', '2026-10-11', '2026-10-13', true],
            'ends today at midnight' => ['day', '2026-10-11', '2026-10-12', true],
            'finished yesterday' => ['day', '2026-10-10', '2026-10-11', false],
            'starts today' => ['day', '2026-10-12', '2026-10-12', true],
            // C4: the card reads TeamCoverage, bounded to today + 61 days (team-coverage-contract.md §4.2).
            'future within the horizon' => ['day', '2026-12-10', '2026-12-11', true],
            'beyond the 62-day horizon' => ['day', '2027-01-01', '2027-01-02', false],
            'hourly ended earlier today remains visible' => ['hour', '2026-10-12 08:00', '2026-10-12 09:00', true],
            'hourly currently in progress' => ['hour', '2026-10-12 11:00', '2026-10-12 13:00', true],
            'hourly finished yesterday' => ['hour', '2026-10-11 11:00', '2026-10-11 13:00', false],
        ];
    }

    /**
     * City scope and start order kept. C4 (team-coverage-contract.md §3.1 Q2,
     * DEC-05 rev., T-3): the card shows accepted leave only for every role, so
     * the rejected and pending leaves HR used to see here are no longer listed.
     */
    public function test_ongoing_leaves_preserve_city_scoping_statuses_and_start_date_order(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $city = $this->makeCity('DMS', '003');
        $otherCity = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-DMS');
        $future = $this->makeLeave($city, 'day', '2026-10-13', '2026-10-14');
        $ongoing = $this->makeLeave($city, 'day', '2026-10-11', '2026-10-13');
        $rejected = $this->makeLeave($city, 'day', '2026-10-12', '2026-10-12');
        $rejected->update(['status' => LeaveRequestStatus::Rejected]);
        $pending = $this->makeLeave($city, 'day', '2026-10-12', '2026-10-12');
        $pending->update(['status' => LeaveRequestStatus::Pending]);
        $this->makeLeave($otherCity, 'day', '2026-10-10', '2026-10-13');
        $this->makeLeave($otherCity, 'day', '2026-10-14', '2026-10-15');

        $component = Livewire::actingAs($hr)->test(UpcomingLeavesCard::class);

        $this->assertSame([$ongoing->id, $future->id], $component->viewData('upcomingLeaves')->pluck('id')->all());
    }

    #[DataProvider('viewAllCases')]
    public function test_view_all_requires_permission_and_more_than_three_leaves(string $role, bool $permitted, int $count, bool $shown): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $city = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, $role, $permitted ? ['leaves.view-all'] : []);
        $this->makeContractFor($user->profile);
        for ($index = 0; $index < $count; $index++) {
            $leave = $this->makeLeave($city, 'day', '2026-10-12', '2026-10-12');
            $leave->update(['status' => LeaveRequestStatus::Accepted]);
        }

        $component = Livewire::actingAs($user)->test(UpcomingLeavesCard::class);

        $this->assertCount($count, $component->viewData('upcomingLeaves'));
        // C4: "View all" opens the team calendar, which every role may open.
        if ($shown) {
            $component->assertSeeHtml('href="'.route('team.calendar').'"');
        } else {
            $component->assertDontSeeHtml('href="'.route('team.calendar').'"');
        }
    }

    public static function viewAllCases(): array
    {
        return [
            'HR with many leaves' => ['HR-DMS', true, 4, true],
            // An employee sees colleagues only; these volunteers share no team with them.
            'employee with no colleagues on leave' => ['employee', false, 0, false],
            'HR with three leaves' => ['HR-DMS', true, 3, false],
        ];
    }

    private function makeLeave(\App\Models\City $city, string $type, string $start, string $end): LeaveRequest
    {
        $employee = $this->makeEmployee($city, 'leave-'.uniqid());
        $contract = $this->makeContractFor($employee);
        $leaveType = LeaveType::query()->firstOrCreate(['name_en' => 'Annual'], ['name_ar' => 'سنوية', 'base_days' => 20]);
        $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => $leaveType->id, 'deserved' => 160, 'used' => 0]);

        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => $type,
            'start_at' => $start,
            'end_at' => $end,
            'status' => LeaveRequestStatus::Accepted,
        ]);
    }
}
