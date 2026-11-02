<?php

namespace Tests\Feature\Assistant;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Models\City;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\Assistant\Drafts\LeaveForEmployeeDraft;
use App\Services\Assistant\EmployeeTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * HR files a leave request for an employee from the chat: the model drafts
 * it through the All leaves page's own form and employee list, and only the
 * user's Confirm (LeaveForEmployeeDraft::submit) writes it.
 */
class LeaveForEmployeeDraftTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    private const TOOLS = ['find_employee_for_leave', 'get_employee_leave_options', 'draft_leave_request_for_employee'];

    private User $hr;

    private City $aleppo;

    private City $homs;

    /** @var array{employee: int, annual: int, sick: int} */
    private array $sami;

    /** @var array{employee: int, annual: int, sick: int} */
    private array $foreignSami;

    private int $contractTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 21 October 2026; 23 November is a Monday (weekend 0,6).
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));

        $now = now();
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->homs = $this->makeCity('HMS', '002');
        $this->contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('leave_types')->insert([
            ['id' => LeaveTypeCases::Annual, 'name_en' => 'Annual Leave', 'name_ar' => 'إجازة سنوية', 'base_days' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['id' => LeaveTypeCases::Annual + 1, 'name_en' => 'Sick Leave', 'name_ar' => 'إجازة مرضية', 'base_days' => 15, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->sami = $this->staff($this->aleppo, 'sami-alp');
        $this->foreignSami = $this->staff($this->homs, 'sami-hms');

        $this->hr = $this->makeUser($this->aleppo, 'HR-ALP', ['leaves.create', 'leaves.view-all']);
        $this->actingAs($this->hr);
    }

    public function test_the_tools_follow_the_all_leaves_add_rule(): void
    {
        $registry = app(DraftRegistry::class);

        $this->assertSame(self::TOOLS, array_column(app(LeaveForEmployeeDraft::class)->declarations($this->hr), 'name'));
        $this->assertSame(LeaveForEmployeeDraft::class, $registry->owner($this->hr, 'draft_leave_request_for_employee')::class);

        // A plain employee who may request their own leave.
        $employee = User::factory()->create(['employee_id' => $this->sami['employee']]);
        $employee->givePermissionTo(['leaves.create', 'leaves.view-own']);
        $names = array_column(app(EmployeeTools::class)->declarations($employee->fresh()), 'name');
        $this->assertSame([], array_intersect(self::TOOLS, $names));
        $this->assertArrayHasKey('error', app(EmployeeTools::class)->call($employee->fresh(), 'find_employee_for_leave', ['name' => 'Sami']));

        // HR without the All leaves page.
        $partial = $this->makeUser($this->aleppo, 'HR-HMS', ['leaves.create']);
        $this->assertSame([], app(LeaveForEmployeeDraft::class)->declarations($partial));
    }

    public function test_hr_finds_only_employees_of_its_own_city(): void
    {
        $result = $this->tools()->call($this->hr, 'find_employee_for_leave', ['name' => 'sami']);

        $this->assertSame([$this->sami['employee']], array_column($result['employees'], 'id'));
        $this->assertSame('Sami Khoury', $result['employees'][0]['name']);
        $this->assertSame('Officer', $result['employees'][0]['position']);

        $this->assertSame([], $this->tools()->call($this->hr, 'find_employee_for_leave', ['name' => 'nobody'])['employees']);
    }

    public function test_an_employee_of_another_city_is_refused(): void
    {
        $options = $this->tools()->call($this->hr, 'get_employee_leave_options', ['employee_id' => $this->foreignSami['employee']]);
        $this->assertSame(['error' => __('assistant-leave-for.errors.employee')], $options);

        $draft = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->foreignSami, '2026-11-23'));
        $this->assertSame('invalid', $draft['status']);
        $this->assertSame([__('assistant-leave-for.errors.employee')], $draft['errors']);

        // An allowed employee with a balance of the foreign one.
        $mixed = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', [
            'employee_id' => $this->sami['employee'], 'leave_balance_id' => $this->foreignSami['sick'],
        ] + $this->sickDay($this->sami, '2026-11-23'));
        $this->assertSame('invalid', $mixed['status']);

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_leave_options_are_the_employees_own_balances(): void
    {
        $options = $this->tools()->call($this->hr, 'get_employee_leave_options', ['employee_id' => $this->sami['employee']]);

        $this->assertEqualsCanonicalizing([$this->sami['annual'], $this->sami['sick']], array_column($options['balances'], 'id'));
        $this->assertTrue(array_column($options['balances'], 'hours_allowed', 'id')[$this->sami['annual']]);
    }

    public function test_invalid_dates_give_errors_and_write_nothing(): void
    {
        $before = $this->snapshotTables();

        $past = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->sami, '2026-10-01'));
        $this->assertSame('invalid', $past['status']);
        $this->assertNotEmpty($past['errors']);

        $reversed = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', [
            'start' => '2026-11-24', 'end' => '2026-11-23',
        ] + $this->sickDay($this->sami, '2026-11-23'));
        $this->assertSame('invalid', $reversed['status']);
        $this->assertContains(__('leaves.validation.end-before-start'), $reversed['errors']);

        $hourlySick = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', [
            'type' => 'hour', 'start' => '2026-11-23 09:00', 'end' => '2026-11-23 11:00',
        ] + $this->sickDay($this->sami, '2026-11-23'));
        $this->assertSame('invalid', $hourlySick['status']);

        $this->assertSame($before, $this->snapshotTables());
    }

    public function test_a_valid_draft_writes_nothing(): void
    {
        Notification::fake();
        $before = $this->snapshotTables();

        $result = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->sami, '2026-11-23'));

        $this->assertSame('drafted', $result['status']);
        $this->assertSame('leave_for_employee', $result['draft_type']);
        $this->assertSame($this->sami['employee'], $result['proposal']['employee_id']);
        $this->assertSame('Sami Khoury', $result['proposal']['employee']);
        $this->assertSame($this->sami['sick'], $result['proposal']['leave_balance_id']);
        $this->assertSame('2026-11-23', $result['proposal']['start']);
        $this->assertSame($before, $this->snapshotTables());
        Notification::assertNothingSent();

        $card = app(LeaveForEmployeeDraft::class)->card($result['proposal']);
        $this->assertSame('calendar', $card['icon']);
        $this->assertSame('Sami Khoury', $card['rows'][__('assistant-leave-for.fields.employee')]);
    }

    public function test_submit_creates_the_request_as_the_page_does_once_per_token(): void
    {
        Notification::fake();
        $proposal = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->sami, '2026-11-23'))['proposal'];
        $draft = app(LeaveForEmployeeDraft::class);

        $result = $draft->submit($this->hr, $proposal, 'token-1');
        $again = $draft->submit($this->hr, $proposal, 'token-1');

        $this->assertSame(1, LeaveRequest::query()->count());
        $request = LeaveRequest::query()->sole();
        $this->assertSame($this->sami['sick'], (int) $request->leave_balance_id);
        $this->assertSame(LeaveRequestStatus::Pending, (int) $request->status);
        $this->assertSame('2026-11-23', $request->start_at->format('Y-m-d'));
        $this->assertGreaterThan(0, (float) $request->computed_hours);
        $this->assertStringContainsString('leave='.$request->id, $result['url']);
        $this->assertSame($result, $again);
        // As the page's processSave(): no notification for a request HR files.
        Notification::assertNothingSent();
    }

    public function test_submit_is_refused_when_the_permission_is_gone(): void
    {
        $proposal = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->sami, '2026-11-23'))['proposal'];

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('leaves.view-all');

        $this->assertForbidden(fn () => app(LeaveForEmployeeDraft::class)->submit($this->hr->fresh(), $proposal, 'token-2'));
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_submit_refuses_a_proposal_for_another_city(): void
    {
        $proposal = $this->tools()->call($this->hr, 'draft_leave_request_for_employee', $this->sickDay($this->sami, '2026-11-23'))['proposal'];
        $proposal['employee_id'] = $this->foreignSami['employee'];
        $proposal['leave_balance_id'] = $this->foreignSami['sick'];

        $this->assertForbidden(fn () => app(LeaveForEmployeeDraft::class)->submit($this->hr, $proposal, 'token-3'));
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    private function assertForbidden(callable $submit): void
    {
        try {
            $submit();
            $this->fail('The submit was not refused.');
        } catch (ValidationException $e) {
            $this->assertSame([__('assistant.draft.forbidden')], collect($e->errors())->flatten()->all());
        }
    }

    private function tools(): EmployeeTools
    {
        return app(EmployeeTools::class);
    }

    /** @param  array{employee: int, annual: int, sick: int}  $staff */
    private function sickDay(array $staff, string $day): array
    {
        return [
            'employee_id' => $staff['employee'], 'leave_balance_id' => $staff['sick'], 'type' => 'day',
            'start' => $day, 'end' => $day, 'reason' => 'A valid one-day request.',
        ];
    }

    /** An employee named Sami Khoury with a current contract and two balances in $city. */
    private function staff(City $city, string $username): array
    {
        $now = now();
        $ids = [
            'program_id' => DB::table('programs')->insertGetId(['name' => 'Program '.$username, 'code' => 'P'.$city->code, 'slug' => 'program-'.$username, 'created_at' => $now, 'updated_at' => $now]),
            'department_id' => DB::table('departments')->insertGetId(['name' => 'Department', 'code' => 'D'.$city->code, 'created_at' => $now, 'updated_at' => $now]),
            'job_level_id' => DB::table('job_levels')->insertGetId(['name_en' => 'Officer', 'name_ar' => 'موظف', 'created_at' => $now, 'updated_at' => $now]),
        ];
        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center', 'name_ar' => 'مركز', 'weekends' => '0,6', 'city_id' => $city->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $positionId = DB::table('positions')->insertGetId($ids + [
            'center_id' => $centerId, 'city_id' => $city->id, 'title_en' => 'Officer', 'title_ar' => 'موظف',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $employeeId = $this->makeEmployee($city, $username, [
            'first_name_en' => 'Sami', 'first_name_ar' => 'سامي', 'last_name_en' => 'Khoury', 'last_name_ar' => 'خوري',
        ])->id;
        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId, 'position_id' => $positionId, 'contract_type_id' => $this->contractTypeId,
            'start_date' => $now->copy()->subMonth(), 'end_date' => $now->copy()->addYear(),
            'currency' => 2, 'weekly_hours' => 40, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $balance = fn (int $type, int $hours) => DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId, 'type_id' => $type, 'deserved' => $hours, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [
            'employee' => (int) $employeeId,
            'annual' => $balance(LeaveTypeCases::Annual, 160),
            'sick' => $balance(LeaveTypeCases::Annual + 1, 120),
        ];
    }

    /** Rows of the tables a request touches, to prove nothing changed. */
    private function snapshotTables(): array
    {
        return json_decode(json_encode([
            DB::table('leave_requests')->get(),
            DB::table('leave_balances')->get(),
            DB::table('activity_log')->count(),
            DB::table('notifications')->count(),
        ]), true);
    }
}
