<?php

namespace Tests\Feature\Assistant;

use App\Enums\LeaveRequestStatus;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Assistant\HrTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The HR tools read other people's records, so the rule that matters most is
 * that each one shows exactly the population of the page it mirrors: the
 * active role's cities, nothing beyond them, and never a pay figure.
 */
class HrToolsTest extends TestCase
{
    use CreatesContractFixtures;
    use RefreshDatabase;

    private const HR_PERMISSIONS = ['employees.view', 'contracts.view', 'leaves.view-all', 'leaves.decide', 'statistics.view'];

    private City $alp;

    private City $lat;

    private Employee $alpWorker;

    private Employee $latWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alp = $this->makeCity('ALP', '001');
        $this->lat = $this->makeCity('LAT', '002');

        $this->alpWorker = $this->makeEmployee($this->alp, 'aleppoworker', [
            'first_name_en' => 'Aleppo', 'last_name_en' => 'Worker',
            'first_name_ar' => 'حلبي', 'last_name_ar' => 'عامل',
        ]);
        $this->latWorker = $this->makeEmployee($this->lat, 'latakiaworker', [
            'first_name_en' => 'Latakia', 'last_name_en' => 'Worker',
            'first_name_ar' => 'لاذقاني', 'last_name_ar' => 'عامل',
        ]);
    }

    public function test_a_city_hr_finds_its_own_employees_and_not_another_citys(): void
    {
        $this->makeContractFor($this->alpWorker, ['salary' => 7777]);
        $this->makeContractFor($this->latWorker, ['salary' => 8888]);
        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);

        $tools = app(HrTools::class);
        $this->assertSame(HrTools::TOOLS, array_column($tools->declarations($hr), 'name'));

        $found = $tools->call($hr, 'find_employees', ['query' => 'Worker', 'limit' => 999]);

        $this->assertSame(['Aleppo Worker'], array_column($found['employees'], 'name'));
        $this->assertSame('Program Officer', $found['employees'][0]['position']);
        $this->assertSame('Hope Center ALP', $found['employees'][0]['center']);
        $this->assertSame('ALP', $found['employees'][0]['city']);

        $arabic = $tools->call($hr, 'find_employees', ['query' => 'عامل']);
        $this->assertSame([$this->alpWorker->id], array_column($arabic['employees'], 'id'));

        $byId = $tools->call($hr, 'find_employees', ['query' => (string) $this->latWorker->id]);
        $this->assertSame([], $byId['employees']);

        $this->assertNoPayFigure($found, $arabic, $byId);
    }

    public function test_the_summary_refuses_an_employee_outside_the_scope(): void
    {
        // An ended contract: history in the summary, while the balance below sits on the running one.
        $this->makeContractFor($this->alpWorker, ['salary' => 7777, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $this->makeContractFor($this->latWorker, ['salary' => 8888]);
        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);
        $tools = app(HrTools::class);

        $refused = $tools->call($hr, 'get_employee_summary', ['employee_id' => $this->latWorker->id]);
        $this->assertArrayHasKey('error', $refused);
        $this->assertStringNotContainsString('Latakia', json_encode($refused));

        $this->assertArrayHasKey('error', $tools->call($hr, 'get_employee_summary', ['employee_id' => 'abc']));
        $this->assertArrayHasKey('error', $tools->call($hr, 'get_employee_summary', []));

        $balance = $this->balanceFor($this->alpWorker, 'Annual', 160, 16);
        $this->requestOn($balance, '2026-03-02');

        $summary = $tools->call($hr, 'get_employee_summary', ['employee_id' => $this->alpWorker->id]);

        $this->assertSame('Aleppo Worker', $summary['name']);
        $this->assertCount(2, $summary['contracts']);
        $this->assertSame(['2026-01-01', '2025-01-01'], array_column($summary['contracts'], 'start_date'));
        $this->assertSame(40, $summary['contracts'][0]['weekly_hours']);
        $this->assertSame('Annual', $summary['leave_balances'][0]['leave_type']);
        $this->assertSame('2026-03-02', $summary['recent_leave_requests'][0]['start']);
        $this->assertNoPayFigure($summary);
    }

    public function test_a_plain_employee_has_no_hr_tools(): void
    {
        $this->makeContractFor($this->alpWorker);
        $staff = $this->makeUser($this->alp, 'assistant-test-staff', ['leaves.view-own', 'leaves.create']);
        $tools = app(HrTools::class);

        $this->assertSame([], $tools->declarations($staff));

        foreach (HrTools::TOOLS as $name) {
            $this->assertSame(
                ['error' => "The tool {$name} is not available to this user."],
                $tools->call($staff, $name, ['query' => 'Worker', 'employee_id' => $this->alpWorker->id]),
            );
        }
    }

    public function test_tools_are_declared_per_permission(): void
    {
        $manager = $this->makeUser($this->alp, 'manager', ['contracts.view', 'leaves.view-all', 'leaves.decide']);

        $this->assertSame(
            ['get_leave_requests_to_review', 'get_expiring_contracts'],
            array_column(app(HrTools::class)->declarations($manager), 'name'),
        );
        $this->assertArrayHasKey('error', app(HrTools::class)->call($manager, 'find_employees', ['query' => 'Worker']));
    }

    public function test_a_super_admin_sees_every_city(): void
    {
        $this->makeContractFor($this->alpWorker, ['salary' => 7777]);
        $this->makeContractFor($this->latWorker, ['salary' => 8888]);
        $admin = $this->makeUser($this->alp, 'super-admin');
        $tools = app(HrTools::class);

        $this->assertSame(HrTools::TOOLS, array_column($tools->declarations($admin), 'name'));

        $found = $tools->call($admin, 'find_employees', ['query' => 'worker']);
        $this->assertEqualsCanonicalizing(['Aleppo Worker', 'Latakia Worker'], array_column($found['employees'], 'name'));

        $summary = $tools->call($admin, 'get_employee_summary', ['employee_id' => $this->latWorker->id]);
        $this->assertSame('Latakia Worker', $summary['name']);

        $this->assertNoPayFigure($found, $summary);
    }

    public function test_leave_requests_to_review_follow_the_team_leaves_page(): void
    {
        $mine = $this->balanceFor($this->alpWorker, 'Annual', 160, 0, ['salary' => 7777]);
        $theirs = $this->balanceFor($this->latWorker, 'Annual', 160, 0, ['salary' => 8888]);
        $pending = $this->requestOn($mine, '2026-05-04');
        $this->requestOn($theirs, '2026-05-05');
        $decided = $this->requestOn($mine, '2026-04-01');
        $decided->update(['status' => LeaveRequestStatus::Accepted]);

        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);
        $tools = app(HrTools::class);

        $result = $tools->call($hr, 'get_leave_requests_to_review', ['status' => 'bogus', 'limit' => 0]);
        $this->assertSame('pending', $result['status_filter']);
        $this->assertSame([$pending->id], array_column($result['requests'], 'id'));
        $this->assertSame('Aleppo Worker', $result['requests'][0]['employee']);
        $this->assertSame('2026-05-04', $result['requests'][0]['start']);
        $this->assertSame(LeaveRequestStatus::of(0)->label(), $result['requests'][0]['status']);
        // The requester has no direct manager, so HR in scope decides (C3-Q1).
        $this->assertTrue($result['requests'][0]['you_can_decide']);
        $this->assertNotEmpty($result['requests'][0]['current_step']);

        $all = $tools->call($hr, 'get_leave_requests_to_review', ['status' => 'all']);
        $this->assertEqualsCanonicalizing([$pending->id, $decided->id], array_column($all['requests'], 'id'));
        $this->assertStringNotContainsString('2026-05-05', json_encode($all));

        $this->assertNoPayFigure($result, $all);
    }

    public function test_expiring_contracts_follow_the_contracts_page_scope(): void
    {
        $soon = $this->makeContractFor($this->alpWorker, [
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
            'auto_renew' => true,
            'salary' => 7777,
        ]);
        $this->makeContractFor($this->alpWorker, [
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addDays(200)->toDateString(),
        ]);
        $this->makeContractFor($this->latWorker, [
            'start_date' => now()->subMonths(6)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'salary' => 8888,
        ]);

        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);
        $tools = app(HrTools::class);

        $result = $tools->call($hr, 'get_expiring_contracts', ['days' => 'x']);
        $this->assertSame(60, $result['within_days']);
        $this->assertSame([$soon->id], array_column($result['contracts'], 'contract_id'));
        $this->assertSame('Aleppo Worker', $result['contracts'][0]['employee']);
        $this->assertSame(now()->addDays(20)->toDateString(), $result['contracts'][0]['end_date']);
        $this->assertSame(20, $result['contracts'][0]['days_left']);
        $this->assertTrue($result['contracts'][0]['auto_renew']);

        $wide = $tools->call($hr, 'get_expiring_contracts', ['days' => 9999]);
        $this->assertSame(366, $wide['within_days']);
        $this->assertCount(2, $wide['contracts']);

        $admin = $this->makeUser($this->alp, 'super-admin');
        $this->assertCount(2, $tools->call($admin, 'get_expiring_contracts', ['days' => 30])['contracts']);

        $this->assertNoPayFigure($result, $wide);
    }

    public function test_headcount_counts_running_contracts_in_scope(): void
    {
        $this->makeContractFor($this->alpWorker, ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        $this->makeContractFor($this->makeEmployee($this->alp, 'second'), ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        $this->makeContractFor($this->latWorker, ['start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        // An ended contract counts nobody.
        $this->makeContractFor($this->makeEmployee($this->alp, 'former'), ['start_date' => '2020-01-01', 'end_date' => '2020-12-31']);

        $tools = app(HrTools::class);

        $hr = $this->makeUser($this->alp, 'HR-ALP', self::HR_PERMISSIONS);
        $result = $tools->call($hr, 'get_team_headcount');
        $this->assertSame(2, $result['total_employees']);
        $this->assertSame(2, array_sum(array_column($result['by_center'], 'employees')));
        $this->assertSame([['department' => 'Programs', 'employees' => 2]], $result['by_department']);

        $admin = $this->makeUser($this->alp, 'super-admin');
        $this->assertSame(3, $tools->call($admin, 'get_team_headcount')['total_employees']);
    }

    private function balanceFor(Employee $employee, string $type, float $deserved, float $used, array $contract = []): LeaveBalance
    {
        $contract = $this->makeContractFor($employee, $contract);
        $leaveType = LeaveType::query()->firstOrCreate(['name_en' => $type], ['name_ar' => $type, 'base_days' => 20]);

        return LeaveBalance::query()->create([
            'contract_id' => $contract->id,
            'type_id' => $leaveType->id,
            'deserved' => $deserved,
            'used' => $used,
        ]);
    }

    private function requestOn(LeaveBalance $balance, string $day): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id,
            'type' => 'day',
            'start_at' => $day,
            'end_at' => $day,
            'status' => LeaveRequestStatus::Pending,
        ]);
    }

    /** No pay key and no pay figure anywhere in a tool's output. */
    private function assertNoPayFigure(array ...$results): void
    {
        foreach ($results as $result) {
            $json = json_encode($result, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsStringIgnoringCase('salary', $json);
            $this->assertStringNotContainsStringIgnoringCase('pay_tier', $json);
            $this->assertStringNotContainsString('7777', $json);
            $this->assertStringNotContainsString('8888', $json);
        }
    }
}
