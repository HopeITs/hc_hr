<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Timesheets;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use App\Models\City;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\AttendanceImporter;
use App\Services\Attendance\MonthWorkflow;
use App\Services\Attendance\WorkflowRefused;
use App\Services\ContractPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The month's life: the employee confirms, the manager approves (which approves the overtime), HR
 * locks (which gives the overtime back as free days on the annual balance), and the signed paper
 * is kept. Also the exports and who may take them.
 */
class AttendanceWorkflowTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    /** @var array<string, Employee> */
    private array $e = [];

    private LeaveBalance $balance;

    private CarbonImmutable $september;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->september = CarbonImmutable::create(2026, 9, 1);
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 10));
        $this->withoutVite();

        $aleppo = $this->makeCity('ALP', '001');
        $damascus = $this->makeCity('DMS', '002');

        $boss = $this->staff('boss', $aleppo, 'Boss', 'Manager');
        $report = $this->staff('report', $aleppo, 'Dana', 'Reporter', true);
        $this->staff('far', $damascus, 'Fay', 'Faraway');

        // A ready month for the report: every weekday worked, three of them for 10 hours
        // (2 hours of overtime each, 6 in all, which is 0.75 of an 8 hour day).
        for ($d = 1; $d <= 30; $d++) {
            $day = $this->september->day($d);

            if ($day->isWeekend()) {
                continue;
            }

            $out = in_array($d, [7, 8, 9], true) ? 19 : 17;
            $this->punch($report, $day->setTime(9, 0));
            $this->punch($report, $day->setTime($out, 0));
        }

        LeaveType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual leave', 'name_ar' => 'إجازة سنوية', 'base_days' => 12]);
        $this->balance = LeaveBalance::query()->create([
            'contract_id' => $report->contracts()->first()->id, 'type_id' => 1, 'deserved' => 96, 'used' => 0,
        ]);
    }

    public function test_an_employee_confirms_their_own_ready_month_only(): void
    {
        $user = $this->user('report', 'employee', ['attendance.view-own']);

        $record = $this->workflow()->confirm($user, $this->e['report'], $this->september);

        $this->assertSame(AttendanceMonth::CONFIRMED, $record->state());

        // Someone else's month is not theirs to confirm.
        $this->expectException(WorkflowRefused::class);
        $this->workflow()->confirm($user, $this->e['boss'], $this->september);
    }

    public function test_days_that_wait_block_confirming_and_approving(): void
    {
        // One weekday with no record: the month is not ready.
        AttendancePunch::query()->where('employee_id', $this->e['report']->id)->whereDate('punched_at', '2026-09-10')->delete();

        try {
            $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
            $this->fail('approved a month with a day waiting');
        } catch (WorkflowRefused $e) {
            $this->assertSame('attendance.workflow.refused.days-waiting', $e->getMessage());
        }
    }

    public function test_a_manager_approves_a_report_and_the_overtime_is_set_at_that_moment(): void
    {
        $record = $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        $this->assertSame(AttendanceMonth::APPROVED, $record->state());
        $this->assertSame(6.0, $record->overtime_hours_approved);
        $this->assertSame($this->boss()->id, $record->approved_by);
    }

    public function test_nobody_approves_their_own_month(): void
    {
        $this->expectException(WorkflowRefused::class);

        // HR of the city covers the report, but the report is not HR: use the report as a manager.
        $self = $this->user('report', 'manager', ['attendance.view-own', 'attendance.view']);
        $this->workflow()->approve($self, $this->e['report'], $this->september);
    }

    public function test_hr_locks_an_approved_month_and_the_overtime_becomes_free_days(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        $before = $this->balance->fresh()->availableHours();

        $record = $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);

        $this->assertSame(AttendanceMonth::LOCKED, $record->state());
        $this->assertSame(6.0, $record->credited_hours);
        $this->assertSame(6.0, $this->balance->fresh()->overtime_credit);
        // The free days are there at once, not earned over the contract like the rest.
        $this->assertSame($before + 6.0, $this->balance->fresh()->availableHours());
    }

    public function test_only_an_approved_month_can_be_locked_and_only_hr_locks(): void
    {
        $this->expectException(WorkflowRefused::class);

        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);
    }

    public function test_a_manager_cannot_lock(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        $this->expectException(WorkflowRefused::class);

        $this->workflow()->lock($this->boss(), $this->e['report'], $this->september);
    }

    public function test_reopening_takes_the_credit_back_unless_it_has_been_used(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);

        $record = $this->workflow()->reopen($this->hr(), $this->e['report'], $this->september);

        $this->assertSame(AttendanceMonth::OPEN, $record->state());
        $this->assertSame(0.0, $this->balance->fresh()->overtime_credit);

        // Again, but this time the employee has already taken the free days as leave.
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);
        $this->balance->update(['used' => 100]);

        try {
            $this->workflow()->reopen($this->hr(), $this->e['report'], $this->september);
            $this->fail('reopened a month whose free days were used');
        } catch (WorkflowRefused $e) {
            $this->assertSame('attendance.workflow.refused.credit-used', $e->getMessage());
        }

        $this->assertSame(6.0, $this->balance->fresh()->overtime_credit);
    }

    public function test_a_balance_recalculation_and_a_new_working_day_keep_the_credit(): void
    {
        $this->balance->update(['overtime_credit' => 6]);

        // The leave module rescales a balance when the working day changes: 8 hours to 4.
        $contract = $this->balance->contract;
        app(\App\Services\LeaveBalanceService::class)->rescaleForDailyHours($contract, 8, 4);

        $this->assertSame(3.0, $this->balance->fresh()->overtime_credit, 'the same number of days, on a shorter day');
    }

    public function test_a_decision_on_an_approved_month_takes_the_approval_back(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        // Sept 7 was worked for 10 hours; saying it was an absence changes what was approved.
        \App\Models\AttendanceDayDecision::query()->create(['employee_id' => $this->e['report']->id, 'work_date' => '2026-09-14', 'kind' => 'worked']);
        $this->workflow()->withdrawApproval($this->e['report'], $this->september);

        $this->assertSame(AttendanceMonth::OPEN, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());
    }

    public function test_new_punches_withdraw_an_approval_and_a_locked_month_takes_none(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        $file = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($file, "AC-No,Name,Time\n{$this->e['report']->id},x,05/09/2026 07:00\n");
        $import = app(AttendanceImporter::class)->import($file, 'more.csv', null);

        $this->assertSame(1, $import->punches_new);
        $this->assertSame(AttendanceMonth::OPEN, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());

        // Approve again and lock: the next upload does not touch the locked month.
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);

        $file2 = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($file2, "AC-No,Name,Time\n{$this->e['report']->id},x,05/09/2026 07:30\n");
        $second = app(AttendanceImporter::class)->import($file2, 'again.csv', null);

        $this->assertSame(0, $second->punches_new);
        $this->assertSame(1, $second->punches_locked);
        $this->assertSame(AttendanceMonth::LOCKED, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());
    }

    public function test_the_page_lets_a_manager_decide_a_reports_day_but_not_their_own(): void
    {
        AttendancePunch::query()->where('employee_id', $this->e['report']->id)->whereDate('punched_at', '2026-09-10')->delete();
        $boss = $this->boss();

        Livewire::actingAs($boss)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $this->e['report']->id, '2026-09-10')
            ->assertSet('dayDate', '2026-09-10')
            ->set('dayKind', 'absent')
            ->call('saveDecision')
            ->assertHasNoErrors();

        $this->assertTrue(\App\Models\AttendanceDayDecision::query()->where('employee_id', $this->e['report']->id)->whereDate('work_date', '2026-09-10')->where('kind', 'absent')->where('decided_by', $boss->id)->exists());

        // The manager's own days (nothing punched in September) are not theirs to answer.
        Livewire::actingAs($boss)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $this->e['boss']->id, '2026-09-10')
            ->assertForbidden();

        // A locked month cannot be decided either. (Put the day back, so the month is ready.)
        \App\Models\AttendanceDayDecision::query()->delete();
        $this->punch($this->e['report'], $this->september->day(10)->setTime(9, 0));
        $this->punch($this->e['report'], $this->september->day(10)->setTime(17, 0));
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        AttendancePunch::query()->where('employee_id', $this->e['report']->id)->whereDate('punched_at', '2026-09-11')->delete();
        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);

        Livewire::actingAs($boss)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $this->e['report']->id, '2026-09-11')
            ->assertForbidden();
    }

    public function test_the_page_steps_and_the_bulk_buttons_follow_the_same_rules(): void
    {
        $boss = $this->boss();

        Livewire::actingAs($boss)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->assertSee('attendance-bulk', false)
            ->call('approveAllReady');

        $this->assertSame(AttendanceMonth::APPROVED, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());

        // The manager cannot lock, whatever the page offers: nothing changes.
        Livewire::actingAs($boss)->test(Timesheets::class)->set('month', '2026-09')->call('lockAllApproved');
        $this->assertSame(AttendanceMonth::APPROVED, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());

        Livewire::actingAs($this->hr())->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('lockAllApproved');

        $this->assertSame(AttendanceMonth::LOCKED, AttendanceMonth::for($this->e['report'], $this->september)->fresh()->state());
        $this->assertSame(6.0, $this->balance->fresh()->overtime_credit);

    }

    public function test_the_signed_copy_is_kept_privately_and_read_back_within_the_scope(): void
    {
        Storage::fake('local');
        $hr = $this->hr();
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openMonth', $this->e['report']->id)
            ->set('signedFile', UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'))
            ->call('saveSigned')
            ->assertHasNoErrors();

        $record = AttendanceMonth::for($this->e['report'], $this->september)->fresh();
        $this->assertNotNull($record->getFirstMedia('signed'));

        $this->actingAs($hr)->get(route('attendance.signed', $record->id))->assertOk();
        $this->actingAs($this->boss())->get(route('attendance.signed', $record->id))->assertOk();

        // The other city's HR and the employee's colleague cannot read it.
        $other = $this->makeUser($this->e['far']->city, 'HR-DMS', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
        $this->actingAs($other)->get(route('attendance.signed', $record->id))->assertForbidden();

        // A manager does not upload it.
        Livewire::actingAs($this->boss())->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openMonth', $this->e['report']->id)
            ->set('signedFile', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
            ->call('saveSigned')
            ->assertForbidden();
    }

    public function test_the_excel_file_holds_the_form_in_arabic_and_follows_the_scope(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);

        $response = $this->actingAs($this->boss())->get(route('attendance.export.xlsx', ['month' => '2026-09']));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        // The boss and the report are in scope, but only the report has punches in September: one
        // pair of sheets, no overview for one person. The boss, with none, is left out like on the page.
        $this->assertSame(['TS '.$this->e['report']->id, 'SUM '.$this->e['report']->id], $book->getSheetNames());

        $sheet = $book->getSheetByName('TS '.$this->e['report']->id);
        $this->assertStringStartsWith('Monthly TimeSheet', $sheet->getCell('A1')->getValue());
        $this->assertSame('Dana Reporter', $sheet->getCell('B3')->getValue());
        $this->assertSame('الثلاثاء', $sheet->getCell('A7')->getValue()); // Tue 1 Sep
        $this->assertSame('01/09/2026', $sheet->getCell('B7')->getValue());
        // The monthly total row: overtime 6 hours (E), the 30 days of the month (F).
        $this->assertSame(6.0, (float) $sheet->getCell('E37')->getValue());
        $this->assertSame(30.0, (float) $sheet->getCell('F37')->getValue());

        $this->assertSame(6.0, (float) $book->getSheetByName('SUM '.$this->e['report']->id)->getCell('B6')->getValue());

        // An employee gets only their own sheets.
        $employee = $this->user('report', 'employee', ['attendance.view-own']);
        $own = $this->actingAs($employee)->get(route('attendance.export.xlsx', ['month' => '2026-09']));
        file_put_contents($path, $own->streamedContent());
        $this->assertSame(['TS '.$this->e['report']->id, 'SUM '.$this->e['report']->id], IOFactory::load($path)->getSheetNames());

        $this->actingAs($employee)->get(route('attendance.export.xlsx', ['month' => '2026-09', 'employees' => [$this->e['boss']->id]]))->assertNotFound();
    }

    public function test_the_pdf_is_drawn_from_the_print_page_with_fonts_inlined_and_failures_answer_503(): void
    {
        $captured = null;
        $this->mock(ContractPdfRenderer::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('renderHtml')->once()->andReturnUsing(function (string $html) use (&$captured) {
                $captured = $html;

                return "%PDF-1.7\n% synthetic\n%%EOF";
            });
        });

        $response = $this->actingAs($this->boss())->get(route('attendance.export.pdf', ['month' => '2026-09', 'employees' => [$this->e['report']->id]]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('data:font/ttf;base64,', $captured);
        $this->assertStringNotContainsString('window.print()', $captured);
        $this->assertStringContainsString('Dana Reporter', $captured);

        $this->mock(ContractPdfRenderer::class, fn ($mock) => $mock->shouldReceive('renderHtml')->andThrow(new RuntimeException('no chrome')));

        $this->actingAs($this->boss())->get(route('attendance.export.pdf', ['month' => '2026-09']))->assertStatus(503);
    }

    public function test_reminders_go_to_the_manager_then_to_hr_and_only_once_a_day(): void
    {
        $boss = $this->boss();
        $hr = $this->hr();

        $this->artisan('attendance:send-reminders', ['--month' => '2026-09'])->assertSuccessful();

        // The manager has one timesheet ready to approve; nothing is approved yet, so HR hears nothing.
        $this->assertCount(1, $boss->notifications);
        $this->assertSame('approve', $boss->notifications->first()->data['kind']);
        $this->assertSame(1, $boss->notifications->first()->data['count']);
        $this->assertCount(0, $hr->notifications);

        // The same morning again changes nothing.
        $this->artisan('attendance:send-reminders', ['--month' => '2026-09'])->assertSuccessful();
        $this->assertCount(1, $boss->fresh()->notifications);

        $this->workflow()->approve($boss, $this->e['report'], $this->september);
        $this->travelTo(now()->addDay());

        $this->artisan('attendance:send-reminders', ['--month' => '2026-09'])->assertSuccessful();

        $this->assertSame('lock', $hr->fresh()->notifications->first()->data['kind']);
        $this->assertSame(1, $hr->fresh()->notifications->first()->data['count']);
    }

    public function test_a_locked_month_is_nobodys_reminder(): void
    {
        $this->workflow()->approve($this->boss(), $this->e['report'], $this->september);
        $this->workflow()->lock($this->hr(), $this->e['report'], $this->september);

        $this->artisan('attendance:send-reminders', ['--month' => '2026-09'])->assertSuccessful();

        $this->assertCount(0, $this->hr()->fresh()->notifications);
    }

    private function staff(string $key, City $city, string $first, string $last, bool $reportsToBoss = false): Employee
    {
        $position = $this->makePosition($city);
        $position->center->update(['weekends' => '0,6']);

        if ($reportsToBoss) {
            $position->forceFill(['direct_manager_id' => $this->e['boss']->contracts()->first()->position_id])->save();
        }

        $employee = $this->makeEmployee($city, $key, [
            'first_name_en' => $first, 'last_name_en' => $last, 'first_name_ar' => $first, 'last_name_ar' => $last,
        ]);
        $this->makeContractFor($employee, ['position_id' => $position->id]);

        return $this->e[$key] = $employee;
    }

    private function punch(Employee $employee, CarbonImmutable $at): void
    {
        AttendancePunch::query()->create([
            'import_id' => \App\Models\AttendanceImport::query()->firstOrCreate(['file_name' => 'x.xls'])->id,
            'device_no' => (string) $employee->id, 'employee_id' => $employee->id, 'punched_at' => $at,
        ]);
    }

    private function user(string $key, string $role, array $permissions): User
    {
        return $this->makeUser($this->e[$key]->city, $role, $permissions, $this->e[$key]);
    }

    private function boss(): User
    {
        return $this->users['boss'] ??= $this->user('boss', 'manager', ['attendance.view-own', 'attendance.view']);
    }

    private function hr(): User
    {
        return $this->users['hr'] ??= $this->makeUser($this->e['boss']->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
    }

    private function workflow(): MonthWorkflow
    {
        return app(MonthWorkflow::class);
    }
}
