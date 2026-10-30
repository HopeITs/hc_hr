<?php

namespace Tests\Feature\Assistant;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeCases;
use App\Livewire\Employee\Leave\MyLeaveRequestModule;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\NewLeaveRequest;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\EmployeeTools;
use App\Services\Assistant\LeaveRequestSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A leave request asked for in the chat: the model only drafts it through
 * the My leaves page's own form, and only the employee's confirmation
 * (LeaveRequestSubmitter) writes it, exactly as the page would.
 */
class LeaveRequestByAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private User $user;

    private User $manager;

    private User $hr;

    private LeaveBalance $annual;

    private LeaveBalance $sick;

    private int $managerEmployeeId;

    private int $programId;

    private int $departmentId;

    private int $jobLevelId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.enabled' => true, 'assistant.gemini.key' => 'gemini-key']);

        // Wednesday 21 October 2026; 23 and 24 November are a Monday and a
        // Tuesday (weekend 0,6), clear of the pending request and December.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->createFixture();
    }

    public function test_the_leave_tools_are_declared_only_with_the_create_permission(): void
    {
        $tools = app(EmployeeTools::class);
        $declared = collect($tools->declarations($this->user))->keyBy('name');

        $this->assertTrue($declared->has('get_leave_options'));
        $this->assertSame(['leave_balance_id', 'type', 'start', 'end'], $declared['draft_leave_request']['parameters']['required']);

        $this->user->revokePermissionTo('leaves.create');
        $this->user->unsetRelation('permissions');
        $this->user = $this->user->fresh();

        $names = collect($tools->declarations($this->user))->pluck('name');
        $this->assertNotContains('get_leave_options', $names);
        $this->assertNotContains('draft_leave_request', $names);
        $this->assertArrayHasKey('error', $tools->call($this->user, 'draft_leave_request', $this->sickDay('2026-11-23')));
    }

    public function test_leave_options_list_only_own_balances_and_allowed_alternatives(): void
    {
        [, $foreignEmployeeId] = $this->foreignBalance();

        $options = app(EmployeeTools::class)->call($this->user, 'get_leave_options');

        $this->assertEqualsCanonicalizing([$this->annual->id, $this->sick->id], array_column($options['balances'], 'id'));
        $hours = array_column($options['balances'], 'hours_allowed', 'id');
        $this->assertTrue($hours[$this->annual->id]);
        $this->assertFalse($hours[$this->sick->id]);
        $this->assertContains($this->managerEmployeeId, array_column($options['alternatives'], 'id'));
        $this->assertNotContains($foreignEmployeeId, array_column($options['alternatives'], 'id'));
    }

    public function test_a_draft_never_writes(): void
    {
        Notification::fake();
        $this->actingAs($this->user);
        $before = $this->snapshotTables();

        $result = app(EmployeeTools::class)->call($this->user, 'draft_leave_request', $this->sickDay('2026-11-23') + [
            'alternative_employee_id' => $this->managerEmployeeId,
        ]);

        $this->assertSame('drafted', $result['status']);
        $this->assertSame([
            'leave_balance_id' => $this->sick->id,
            'leave_type' => 'Sick Leave',
            'type' => 'day',
            'start' => '2026-11-23',
            'end' => '2026-11-23',
            'reason' => 'A valid one-day request.',
            'alternative_employee_id' => $this->managerEmployeeId,
            'alternative_employee' => 'Rami Haddad',
            'is_exceptional' => false,
        ], collect($result['proposal'])->except('duration')->all());
        $this->assertNotSame('', $result['proposal']['duration']);
        $this->assertSame($before, $this->snapshotTables());
        Notification::assertNothingSent();
    }

    public function test_a_draft_reports_the_pages_errors_without_writing(): void
    {
        $tools = app(EmployeeTools::class);
        $before = LeaveRequest::query()->count();

        // Overlap with the pending annual request (Mon-Tue 2-3 November).
        $overlap = $tools->call($this->user, 'draft_leave_request', $this->sickDay('2026-11-03'));
        $this->assertSame('invalid', $overlap['status']);
        $this->assertStringContainsString('02-11-2026', $overlap['errors'][0]);

        // A past date, a missing reason, and hours on a non-annual balance.
        $this->assertSame('invalid', $tools->call($this->user, 'draft_leave_request', $this->sickDay('2026-10-01'))['status']);
        $this->assertSame('invalid', $tools->call($this->user, 'draft_leave_request', ['reason' => ''] + $this->sickDay('2026-11-23'))['status']);
        $this->assertSame('invalid', $tools->call($this->user, 'draft_leave_request', [
            'leave_balance_id' => $this->sick->id, 'type' => 'hour',
            'start' => '2026-11-24 10:00', 'end' => '2026-11-24 12:00', 'reason' => 'Hours of sick leave.',
        ])['status']);

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    public function test_another_employees_balance_or_an_unlisted_alternative_is_refused(): void
    {
        [$foreignBalanceId, $foreignEmployeeId] = $this->foreignBalance();
        $tools = app(EmployeeTools::class);

        $theirs = $tools->call($this->user, 'draft_leave_request', ['leave_balance_id' => $foreignBalanceId] + $this->sickDay('2026-11-23'));
        $this->assertSame('invalid', $theirs['status']);
        $this->assertArrayNotHasKey('proposal', $theirs);

        $stranger = $tools->call($this->user, 'draft_leave_request', ['alternative_employee_id' => $foreignEmployeeId] + $this->sickDay('2026-11-23'));
        $this->assertSame('invalid', $stranger['status']);

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_an_hourly_annual_leave_is_drafted_with_its_times(): void
    {
        $result = app(EmployeeTools::class)->call($this->user, 'draft_leave_request', [
            'leave_balance_id' => $this->annual->id, 'type' => 'hour',
            'start' => '2026-11-24 10:00', 'end' => '12:00', 'reason' => 'A doctor appointment.',
        ]);

        $this->assertSame('drafted', $result['status']);
        $this->assertSame('hour', $result['proposal']['type']);
        $this->assertSame('2026-11-24 10:00', $result['proposal']['start']);
        $this->assertSame('2026-11-24 12:00', $result['proposal']['end']);
    }

    public function test_the_draft_reaches_the_reply_and_the_model_is_told_it_is_not_submitted(): void
    {
        $this->actingAs($this->user);

        Http::fake([self::GEMINI => Http::sequence()
            ->push($this->callTurn('draft_leave_request', $this->sickDay('2026-11-23')))
            ->push($this->textTurn('Please review the draft and press Confirm.'))]);

        $reply = app(Assistant::class)->reply($this->user, [], 'I need a sick day on 23 November, I have a fever.');

        $this->assertSame('Please review the draft and press Confirm.', $reply->text);
        $this->assertSame($this->sick->id, $reply->leaveProposal['leave_balance_id']);
        $this->assertSame('2026-11-23', $reply->leaveProposal['start']);
        $this->assertSame(1, LeaveRequest::query()->count());

        Http::assertSent(fn (Request $request) => str_contains($request['systemInstruction']['parts'][0]['text'], 'never say it was submitted'));
        $response = Http::recorded()[1][0]['contents'][2]['parts'][0]['functionResponse']['response'];
        $this->assertSame('drafted', $response['status']);
    }

    public function test_a_failed_draft_gives_no_proposal(): void
    {
        $this->actingAs($this->user);

        Http::fake([self::GEMINI => Http::sequence()
            ->push($this->callTurn('draft_leave_request', $this->sickDay('2026-11-03')))
            ->push($this->textTurn('Those days overlap another request.'))]);

        $reply = app(Assistant::class)->reply($this->user, [], 'Sick day on 3 November.');

        $this->assertNull($reply->leaveProposal);
    }

    public function test_submitting_a_draft_creates_what_the_page_creates(): void
    {
        Notification::fake();

        // The page, for Monday 23 November.
        Livewire::actingAs($this->user)
            ->test(MyLeaveRequestModule::class)
            ->call('create')
            ->set('form.leave_balance_id', (string) $this->sick->id)
            ->set('form.start_at', '2026-11-23')
            ->set('form.end_at', '2026-11-23')
            ->set('form.reason', 'A valid one-day request.')
            ->set('form.alternative_employee', $this->managerEmployeeId)
            ->call('confirmSave')
            ->assertHasNoErrors()
            ->call('processSave')
            ->assertHasNoErrors();
        $byPage = LeaveRequest::query()->latest('id')->firstOrFail();

        // The assistant, for Tuesday 24 November: drafted, then confirmed.
        $proposal = app(EmployeeTools::class)->call($this->user, 'draft_leave_request', $this->sickDay('2026-11-24') + [
            'alternative_employee_id' => $this->managerEmployeeId,
        ])['proposal'];
        $byAssistant = app(LeaveRequestSubmitter::class)->submit($this->user, $proposal, 'draft-1');

        $columns = ['leave_balance_id', 'type', 'reason', 'alternative_employee_id', 'status', 'computed_hours',
            'rule_version', 'is_exceptional', 'approved_by', 'approved_at', 'reject_reason'];
        $this->assertSame($byPage->only($columns), $byAssistant->only($columns));
        $this->assertSame(LeaveRequestStatus::Pending, (int) $byAssistant->status);
        $this->assertSame('2026-11-24 00:00', $byAssistant->start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-24 00:00', $byAssistant->end_at->format('Y-m-d H:i'));
        $this->assertSame(
            Activity::query()->where('subject_type', $byPage->getMorphClass())->where('subject_id', $byPage->id)->pluck('event')->all(),
            Activity::query()->where('subject_type', $byAssistant->getMorphClass())->where('subject_id', $byAssistant->id)->pluck('event')->all(),
        );

        // Notified like the page: once per request to the manager and to HR.
        Notification::assertSentToTimes($this->manager, NewLeaveRequest::class, 2);
        Notification::assertSentToTimes($this->hr, NewLeaveRequest::class, 2);
        Notification::assertNotSentTo($this->user, NewLeaveRequest::class);
        Notification::assertCount(4);
    }

    public function test_confirming_the_same_draft_twice_creates_one_request_and_one_set_of_notifications(): void
    {
        Notification::fake();
        $proposal = $this->draft($this->sickDay('2026-11-23'));
        $submitter = app(LeaveRequestSubmitter::class);

        $first = $submitter->submit($this->user, $proposal, 'draft-1');
        $second = $submitter->submit($this->user, $proposal, 'draft-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, LeaveRequest::query()->count());
        Notification::assertCount(2);
    }

    public function test_submit_refuses_without_the_permission(): void
    {
        Notification::fake();
        $proposal = $this->draft($this->sickDay('2026-11-23'));

        $this->user->revokePermissionTo('leaves.create');
        $this->user = $this->user->fresh();

        $this->assertRefused(fn () => app(LeaveRequestSubmitter::class)->submit($this->user, $proposal));
        Notification::assertNothingSent();
    }

    public function test_submit_revalidates_a_stale_draft(): void
    {
        Notification::fake();
        $proposal = $this->draft($this->sickDay('2026-11-23'));
        $submitter = app(LeaveRequestSubmitter::class);

        // Another request now covers the same day.
        DB::table('leave_requests')->insert([
            'leave_balance_id' => $this->annual->id, 'type' => 'day',
            'start_at' => '2026-11-23 00:00:00', 'end_at' => '2026-11-23 00:00:00',
            'reason' => 'Sent from the page meanwhile.', 'status' => LeaveRequestStatus::Pending,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRefused(fn () => $submitter->submit($this->user, $proposal));

        // The day has passed.
        DB::table('leave_requests')->where('reason', 'Sent from the page meanwhile.')->delete();
        $this->travelTo(Carbon::parse('2026-11-25 09:00:00'));
        $this->assertRefused(fn () => $submitter->submit($this->user, $proposal));

        // A forged balance id in the proposal.
        [$foreignBalanceId] = $this->foreignBalance();
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $this->assertRefused(fn () => $submitter->submit($this->user, ['leave_balance_id' => $foreignBalanceId] + $proposal));

        Notification::assertNothingSent();
    }

    private function assertRefused(callable $submit): void
    {
        $before = LeaveRequest::query()->count();

        try {
            $submit();
            $this->fail('The submission was not refused.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame($before, LeaveRequest::query()->count());
    }

    private function draft(array $args): array
    {
        $result = app(EmployeeTools::class)->call($this->user, 'draft_leave_request', $args);
        $this->assertSame('drafted', $result['status'], json_encode($result));

        return $result['proposal'];
    }

    private function sickDay(string $day): array
    {
        return [
            'leave_balance_id' => $this->sick->id, 'type' => 'day',
            'start' => $day, 'end' => $day, 'reason' => 'A valid one-day request.',
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

    /** A balance of an employee in another city, with an account. @return array{0: int, 1: int} */
    private function foreignBalance(): array
    {
        $now = now();
        $cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Homs', 'name_ar' => 'حمص', 'slug' => 'HMS', 'code' => '002', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'city_id' => $cityId, 'program_id' => $this->programId, 'department_id' => $this->departmentId,
            'job_level_id' => $this->jobLevelId, 'title_en' => 'Elsewhere', 'title_ar' => 'بعيد', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'city_id' => $cityId, 'first_name_en' => 'Other', 'first_name_ar' => 'آخر', 'last_name_en' => 'Person',
            'last_name_ar' => 'شخص', 'username' => 'other-person', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId, 'position_id' => $positionId, 'contract_type_id' => DB::table('contract_types')->value('id'),
            'start_date' => $now->copy()->subMonth(), 'end_date' => $now->copy()->addYear(),
            'currency' => 2, 'weekly_hours' => 40, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $balanceId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId, 'type_id' => LeaveTypeCases::Annual + 1,
            'deserved' => 120, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$balanceId, $employeeId];
    }

    private function callTurn(string $name, array $args): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => $name, 'args' => $args]],
        ]]]]];
    }

    private function textTurn(string $text): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]];
    }

    /**
     * As LeaveRequestSubmitFlowTest: a staff member in Aleppo with annual and
     * sick balances and one pending annual request (2-3 November), a direct
     * manager holding the manager role, and one active HR-ALP account.
     */
    private function createFixture(): void
    {
        $now = now();
        $cityId = DB::table('cities')->insertGetId([
            'name_en' => 'Aleppo', 'name_ar' => 'حلب', 'slug' => 'ALP', 'code' => '001',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->programId = $programId = DB::table('programs')->insertGetId([
            'name' => 'Program', 'code' => 'PRG', 'slug' => 'program', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $centerId = DB::table('centers')->insertGetId([
            'name_en' => 'Center', 'name_ar' => 'مركز', 'weekends' => '0,6', 'city_id' => $cityId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->departmentId = $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Department', 'code' => 'DEP', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->jobLevelId = $jobLevelId = DB::table('job_levels')->insertGetId([
            'name_en' => 'Officer', 'name_ar' => 'موظف', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $contractTypeId = DB::table('contract_types')->insertGetId([
            'name_en' => 'Annual', 'name_ar' => 'سنوي', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $position = fn (string $en, string $ar, ?int $managerPositionId = null) => DB::table('positions')->insertGetId([
            'program_id' => $programId, 'center_id' => $centerId, 'city_id' => $cityId,
            'job_level_id' => $jobLevelId, 'department_id' => $departmentId,
            'title_en' => $en, 'title_ar' => $ar, 'direct_manager_id' => $managerPositionId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $employee = fn (string $firstEn, string $firstAr, string $lastEn, string $lastAr, string $username) => DB::table('employees')->insertGetId([
            'city_id' => $cityId, 'first_name_en' => $firstEn, 'first_name_ar' => $firstAr,
            'last_name_en' => $lastEn, 'last_name_ar' => $lastAr, 'username' => $username,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $contract = fn (int $employeeId, int $positionId) => DB::table('contracts')->insertGetId([
            'employee_id' => $employeeId, 'position_id' => $positionId, 'contract_type_id' => $contractTypeId,
            'start_date' => $now->copy()->subMonth(), 'end_date' => $now->copy()->addYear(),
            'currency' => 2, 'weekly_hours' => 40, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $managerPositionId = $position('Manager', 'مدير');
        $staffPositionId = $position('Officer', 'موظف', $managerPositionId);

        $this->managerEmployeeId = $employee('Rami', 'رامي', 'Haddad', 'حداد', 'rami-manager');
        $contract($this->managerEmployeeId, $managerPositionId);

        $staffEmployeeId = $employee('Test', 'اختبار', 'Employee', 'موظف', 'assistant-leave-employee');
        $contractId = $contract($staffEmployeeId, $staffPositionId);

        $hrEmployeeId = $employee('Hana', 'هناء', 'HR', 'موارد', 'hana-hr');

        DB::table('leave_types')->insert([
            [
                'id' => LeaveTypeCases::Annual, 'name_en' => 'Annual Leave', 'name_ar' => 'إجازة سنوية',
                'base_days' => 20, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => LeaveTypeCases::Annual + 1, 'name_en' => 'Sick Leave', 'name_ar' => 'إجازة مرضية',
                'base_days' => 15, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $annualId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId, 'type_id' => LeaveTypeCases::Annual,
            'deserved' => 160, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sickId = DB::table('leave_balances')->insertGetId([
            'contract_id' => $contractId, 'type_id' => LeaveTypeCases::Annual + 1,
            'deserved' => 120, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('leave_requests')->insert([
            'leave_balance_id' => $annualId, 'type' => 'day',
            'start_at' => '2026-11-02 00:00:00', 'end_at' => '2026-11-03 00:00:00',
            'reason' => 'Pending annual leave.', 'status' => LeaveRequestStatus::Pending,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->user = User::query()->create([
            'employee_id' => $staffEmployeeId, 'email' => 'assistant.leave@example.test', 'password' => 'password',
        ]);
        $this->user->givePermissionTo(['leaves.create', 'leaves.view-own']);

        $this->manager = User::query()->create([
            'employee_id' => $this->managerEmployeeId, 'email' => 'rami.manager@example.test', 'password' => 'password',
        ]);
        $this->manager->assignRole(Role::findOrCreate('manager', 'web'));

        $this->hr = User::query()->create([
            'employee_id' => $hrEmployeeId, 'email' => 'hana.hr@example.test', 'password' => 'password',
        ]);
        $this->hr->assignRole(Role::findOrCreate('HR-ALP', 'web'));

        $this->annual = LeaveBalance::query()->findOrFail($annualId);
        $this->sick = LeaveBalance::query()->findOrFail($sickId);
    }
}
