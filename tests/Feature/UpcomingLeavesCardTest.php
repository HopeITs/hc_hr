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

        $component->assertSet('upcomingLeaves', fn ($leaves) => $leaves->modelKeys() === ($visible ? [$leave->id] : []));
    }

    public static function leaveDates(): array
    {
        return [
            'ongoing across days' => ['day', '2026-10-11', '2026-10-13', true],
            'ends today at midnight' => ['day', '2026-10-11', '2026-10-12', true],
            'finished yesterday' => ['day', '2026-10-10', '2026-10-11', false],
            'starts today' => ['day', '2026-10-12', '2026-10-12', true],
            'future without horizon limit' => ['day', '2027-01-01', '2027-01-02', true],
            'hourly ended earlier today remains visible' => ['hour', '2026-10-12 08:00', '2026-10-12 09:00', true],
            'hourly currently in progress' => ['hour', '2026-10-12 11:00', '2026-10-12 13:00', true],
            'hourly finished yesterday' => ['hour', '2026-10-11 11:00', '2026-10-11 13:00', false],
        ];
    }

    public function test_ongoing_leaves_preserve_city_scoping_statuses_and_start_date_order(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
        $city = $this->makeCity('DMS', '003');
        $otherCity = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($city, 'HR-DMS');
        $future = $this->makeLeave($city, 'day', '2026-10-13', '2026-10-14');
        $ongoing = $this->makeLeave($city, 'day', '2026-10-11', '2026-10-13');
        $ongoing->update(['status' => LeaveRequestStatus::Rejected]);
        $this->makeLeave($otherCity, 'day', '2026-10-10', '2026-10-13');
        $this->makeLeave($otherCity, 'day', '2026-10-14', '2026-10-15');

        $component = Livewire::actingAs($hr)->test(UpcomingLeavesCard::class);

        $component->assertSet('upcomingLeaves', fn ($leaves) => $leaves->modelKeys() === [$ongoing->id, $future->id]);
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

        $component->assertCount('upcomingLeaves', $count);
        if ($shown) {
            $component->assertSeeHtml('href="'.route('leaves.all').'"');
        } else {
            $component->assertDontSeeHtml('href="'.route('leaves.all').'"');
        }
    }

    public static function viewAllCases(): array
    {
        return [
            'authorized with many leaves' => ['HR-DMS', true, 4, true],
            'employee with many leaves' => ['employee', false, 4, false],
            'authorized with three leaves' => ['HR-DMS', true, 3, false],
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
            'status' => LeaveRequestStatus::Pending,
        ]);
    }
}
