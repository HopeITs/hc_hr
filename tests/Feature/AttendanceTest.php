<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Timesheets;
use App\Livewire\Attendance\Upload;
use App\Models\AttendanceDayDecision;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Attendance\AttendanceImporter;
use App\Services\Attendance\DeviceReportReader;
use App\Services\Attendance\Timesheet;
use App\Services\Attendance\UnreadableReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The device report becomes a monthly timesheet: it is read (including the program's own old
 * Excel export), stored once, matched to people, turned into worked days, overtime and days that
 * wait for HR, and printed in the form HR signs.
 */
class AttendanceTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_the_reader_reads_the_programs_raw_excel_report_and_a_csv(): void
    {
        $raw = $this->rawReport([
            ['AC-No.', 'No.', 'Name', 'Time', 'State'],
            ['8', '', '8', '01/09/2026 09:02', 'C/In'],
            ['8', '', '8', '01/09/2026 20:30', 'C/In'],
            ['11', '', '11', 'not a time', 'C/In'],
        ]);

        $report = app(DeviceReportReader::class)->read($raw);

        $this->assertCount(2, $report['punches']);
        $this->assertSame(1, $report['invalid']);
        $this->assertSame('8', $report['punches'][0]['device_no']);
        $this->assertSame('2026-09-01 09:02', $report['punches'][0]['at']->format('Y-m-d H:i'));
        $this->assertSame('2026-09-01 20:30', $report['punches'][1]['at']->format('Y-m-d H:i'));

        $csv = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($csv, "AC-No,Name,Time\n8,x,02/09/2026 09:03\n");

        $this->assertSame('2026-09-02 09:03', app(DeviceReportReader::class)->read($csv)['punches'][0]['at']->format('Y-m-d H:i'));
    }

    public function test_a_file_without_the_expected_columns_is_refused(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'att').'.csv';
        file_put_contents($csv, "Name,Salary\nx,1\n");

        $this->expectException(UnreadableReport::class);

        app(DeviceReportReader::class)->read($csv);
    }

    public function test_an_upload_is_stored_once_and_matched_by_employee_id(): void
    {
        $employee = $this->volunteer();
        $file = $this->rawReport([
            ['AC-No.', 'Name', 'Time'],
            [(string) $employee->id, 'x', '07/09/2026 08:55'],
            [(string) $employee->id, 'x', '07/09/2026 17:10'],
            ['99999', 'y', '07/09/2026 09:00'],
        ]);

        $importer = app(AttendanceImporter::class);

        $first = $importer->import($file, 'test.xls', null);
        $second = $importer->import($file, 'test.xls', null);

        $this->assertSame(3, $first->punches_new);
        $this->assertSame(0, $second->punches_new, 'the same file again adds nothing');
        $this->assertSame(3, AttendancePunch::query()->count());
        $this->assertSame(2, AttendancePunch::query()->where('employee_id', $employee->id)->count());
        $this->assertNull(AttendancePunch::query()->where('device_no', '99999')->value('employee_id'));

        // A number that is not an ID is tied to someone once, and its stored punches follow.
        $importer->link('99999', $employee, null);

        $this->assertSame(3, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_the_timesheet_counts_worked_days_overtime_weekends_leave_and_waits_for_hr(): void
    {
        $employee = $this->volunteer();
        $contract = $employee->contracts()->first();

        // Mon 7 Sep: 08:55 to 18:10 is 9h15, 1h15 beyond the 8h day, so 1 hour of overtime.
        $this->punch($employee, '2026-09-07 08:55:00');
        $this->punch($employee, '2026-09-07 18:10:00');
        // Tue 8 Sep: an ordinary day.
        $this->punch($employee, '2026-09-08 09:00:00');
        $this->punch($employee, '2026-09-08 17:00:00');
        // Wed 9 Sep: a single punch. Thu 10 Sep: nothing.
        $this->punch($employee, '2026-09-09 09:05:00');
        // Sat 12 Sep: a weekend, worked for 2 hours.
        $this->punch($employee, '2026-09-12 10:00:00');
        $this->punch($employee, '2026-09-12 12:00:00');

        // Fri 11 Sep: accepted annual leave for the whole day.
        LeaveType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual leave', 'name_ar' => 'إجازة سنوية', 'base_days' => 12]);
        $balance = LeaveBalance::query()->create(['contract_id' => $contract->id, 'type_id' => 1, 'deserved' => 96, 'used' => 8]);
        LeaveRequest::query()->create([
            'leave_balance_id' => $balance->id, 'type' => 'day', 'status' => 1, 'reason' => 'x',
            'start_at' => '2026-09-11 00:00:00', 'end_at' => '2026-09-11 23:59:59',
        ]);

        $sheet = app(Timesheet::class)->forEmployee($employee, $this->september());
        $rows = collect($sheet['rows'])->keyBy(fn ($r) => $r['date']->toDateString());

        $this->assertSame(8.0, $sheet['daily_hours']);
        $this->assertSame(Timesheet::WORKED, $rows['2026-09-07']['kind']);
        $this->assertSame(60, $rows['2026-09-07']['overtime_minutes']);
        $this->assertSame(0, $rows['2026-09-08']['overtime_minutes']);
        $this->assertSame(Timesheet::ONE_PUNCH, $rows['2026-09-09']['kind']);
        $this->assertSame(0.0, $rows['2026-09-09']['working_days'], 'nothing is assumed for a day that waits');
        $this->assertSame(Timesheet::NO_RECORD, $rows['2026-09-10']['kind']);
        $this->assertSame(Timesheet::LEAVE, $rows['2026-09-11']['kind']);
        $this->assertSame(8.0, $rows['2026-09-11']['leave_hours']);
        $this->assertSame(Timesheet::WEEKEND, $rows['2026-09-12']['kind']);
        $this->assertSame(120, $rows['2026-09-12']['overtime_minutes'], 'weekend work counts in full');
        $this->assertSame(1.0, $rows['2026-09-12']['working_days'], 'a weekend is a paid day on the form');

        $this->assertSame(3.0, $sheet['totals']['overtime_hours']);
        $this->assertSame(0.38, $sheet['totals']['overtime_days']);
        $this->assertSame(8.0, $sheet['totals']['leave_hours'], 'weekends are not leave');
        // The leave balance shown on page 2 is the leave module's own.
        $this->assertSame(11, $sheet['balances'][0]['remaining_days']);

        // HR answers the two waiting days.
        AttendanceDayDecision::query()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-09', 'kind' => 'worked']);
        AttendanceDayDecision::query()->create(['employee_id' => $employee->id, 'work_date' => '2026-09-10', 'kind' => 'absent']);

        $after = collect(app(Timesheet::class)->forEmployee($employee, $this->september())['rows'])->keyBy(fn ($r) => $r['date']->toDateString());

        $this->assertSame(Timesheet::DECIDED_WORKED, $after['2026-09-09']['kind']);
        $this->assertSame(1.0, $after['2026-09-09']['working_days']);
        $this->assertSame(Timesheet::DECIDED_ABSENT, $after['2026-09-10']['kind']);
        $this->assertSame(8.0, $after['2026-09-10']['leave_hours']);
        $this->assertSame(0.0, $after['2026-09-10']['working_days']);
    }

    public function test_days_outside_the_contract_are_not_asked_about(): void
    {
        $employee = $this->volunteer();
        $employee->contracts()->update(['start_date' => '2026-09-15']);

        $rows = collect(app(Timesheet::class)->forEmployee($employee, $this->september())['rows'])->keyBy(fn ($r) => $r['date']->toDateString());

        $this->assertSame(Timesheet::OUTSIDE, $rows['2026-09-10']['kind']);
        $this->assertSame(Timesheet::NO_RECORD, $rows['2026-09-16']['kind']);
    }

    public function test_hr_decides_a_day_on_the_page_and_cannot_touch_a_weekend(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $employee->id, '2026-09-10')
            ->assertSet('dayDate', '2026-09-10')
            ->set('dayKind', 'absent')
            ->set('dayNote', 'no call')
            ->call('saveDecision')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('attendance_day_decisions', [
            'employee_id' => $employee->id, 'kind' => 'absent', 'note' => 'no call', 'decided_by' => $hr->id,
        ]);

        // Sat 12 Sep is a weekend: nothing to decide, and nothing to open.
        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openDay', $employee->id, '2026-09-12')
            ->assertForbidden();
    }

    public function test_a_public_holiday_or_a_feast_is_a_day_off_with_its_name_never_a_day_to_answer(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        // A three-day feast, for everyone: no punches on those days and nothing to ask.
        \App\Models\Holiday::query()->create([
            'name_en' => 'Eid al-Adha', 'name_ar' => 'عيد الأضحى',
            'start_date' => '2026-09-15', 'end_date' => '2026-09-17',
            'is_recurring' => false, 'scope' => \App\Models\Holiday::SCOPE_NATIONAL, 'kind' => \App\Models\Holiday::KIND_OFFICIAL,
        ]);

        $rows = collect(app(Timesheet::class)->forEmployee($employee, $this->september())['rows'])
            ->keyBy(fn (array $row) => $row['date']->toDateString());

        foreach (['2026-09-15', '2026-09-16', '2026-09-17'] as $date) {
            $this->assertSame(Timesheet::HOLIDAY, $rows[$date]['kind']);
            $this->assertFalse($rows[$date]['decidable']);
        }

        // The person's calendar shows the feast by name, and the day cannot be opened as a question.
        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openMonth', $employee->id)
            ->assertSee('عيد الأضحى')
            ->call('openDay', $employee->id, '2026-09-16')
            ->assertForbidden();
    }

    public function test_the_calendar_names_the_persons_own_days_off_and_where_they_come_from(): void
    {
        $employee = $this->volunteer();   // the center's days off: Sunday and Saturday
        $contract = $employee->contracts()->first();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        $placement = app(Timesheet::class)->forEmployee($employee, $this->september())['placement'];
        $this->assertSame([0, 6], $placement['days_off']);
        $this->assertSame('center', $placement['days_off_from']);

        // A center whose only day off is Sunday ("0") is a real setting, not "nothing set".
        $contract->position->center->update(['weekends' => '0']);
        $timesheet = app(Timesheet::class)->forEmployee($employee->fresh(), $this->september());
        $this->assertSame([0], $timesheet['placement']['days_off']);
        $this->assertSame('center', $timesheet['placement']['days_off_from']);
        $rows = collect($timesheet['rows'])->keyBy(fn (array $row) => $row['date']->toDateString());
        $this->assertSame(Timesheet::WEEKEND, $rows['2026-09-13']['kind']);      // Sunday
        $this->assertNotSame(Timesheet::WEEKEND, $rows['2026-09-12']['kind']);   // Saturday is a working day

        // Days picked on the contract win over the center: Sunday, Tuesday, Thursday worked.
        $contract->update(['work_weekdays' => '0,2,4']);
        $placement = app(Timesheet::class)->forEmployee($employee->fresh(), $this->september())['placement'];
        $this->assertSame([1, 3, 5, 6], $placement['days_off']);
        $this->assertSame('contract', $placement['days_off_from']);

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openMonth', $employee->id)
            ->assertSeeHtml('data-testid="days-off"')
            ->assertSee(__('attendance.timesheets.days-off-from.contract'));
    }

    public function test_a_day_off_with_punches_shows_its_times_on_the_calendar(): void
    {
        $employee = $this->volunteer();   // days off: Sunday and Saturday
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
        $this->punch($employee, '2026-09-12 09:00');
        $this->punch($employee, '2026-09-12 15:30');

        Livewire::actingAs($hr)->test(Timesheets::class)
            ->set('month', '2026-09')
            ->call('openMonth', $employee->id)
            ->assertSee('09:00–15:30')
            ->assertSee(__('attendance.timesheets.worked-off'));
    }

    public function test_the_upload_says_what_happened_to_every_punch_when_the_same_file_comes_twice(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
        $csv = "AC-No.,Time\n{$employee->id},01/09/2026 08:00\n{$employee->id},01/09/2026 16:00\n";
        $file = fn () => UploadedFile::fake()->createWithContent('report.csv', $csv);

        Livewire::actingAs($hr)->test(Upload::class)->set('file', $file())->call('import');
        Livewire::actingAs($hr)->test(Upload::class)->set('file', $file())->call('import')->assertHasNoErrors();

        // The second upload stores nothing new, and its record says so: 2 in the file, 0 new.
        $second = \App\Models\AttendanceImport::query()->latest('id')->first();
        $this->assertSame([2, 0, 0], [$second->punches_total, $second->punches_new, $second->punches_locked]);
        $this->assertSame(2, AttendancePunch::query()->count());
    }

    public function test_a_new_report_replaces_what_was_stored_for_the_days_it_covers(): void
    {
        $employee = $this->volunteer();
        $other = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);
        $upload = fn (string $csv) => Livewire::actingAs($hr)->test(Upload::class)
            ->set('file', UploadedFile::fake()->createWithContent('report.csv', "AC-No.,Time\n".$csv))->call('import')->assertHasNoErrors();

        $upload("{$employee->id},01/09/2026 08:00\n{$employee->id},01/09/2026 16:00\n{$employee->id},02/09/2026 08:00\n");
        // Someone the next file does not mention, and a day outside it: both stay.
        $this->punch($other, '2026-09-01 08:30');
        $this->punch($employee, '2026-09-20 08:00');

        // The corrected export for 1 September: 08:00 became 08:05. The 2nd is outside it and stays.
        $upload("{$employee->id},01/09/2026 08:05\n{$employee->id},01/09/2026 16:00\n");

        $left = AttendancePunch::query()->where('employee_id', $employee->id)->orderBy('punched_at')->pluck('punched_at')->map->format('Y-m-d H:i')->all();
        $this->assertSame(['2026-09-01 08:05', '2026-09-01 16:00', '2026-09-02 08:00', '2026-09-20 08:00'], $left);
        $this->assertSame(1, AttendancePunch::query()->where('employee_id', $other->id)->count());

        $last = \App\Models\AttendanceImport::query()->latest('id')->first();
        $this->assertSame([2, 1], [$last->punches_total, $last->punches_new]);
    }

    public function test_the_pages_need_the_permission_and_a_city_hr_role_sees_only_its_city(): void
    {
        $this->withoutVite();

        $mine = $this->volunteer();
        $damascus = $this->makeCity('DMS', '002');
        $other = $this->makeEmployee($damascus, 'other', [
            'first_name_en' => 'Zed', 'last_name_en' => 'Faraway', 'first_name_ar' => 'زيد', 'last_name_ar' => 'بعيد',
        ]);
        $this->makeContractFor($other)->position->center->update(['weekends' => '0,6']);

        // A role with no baseline of its own (every real role holds attendance.view-own).
        $nobody = $this->makeUser($mine->city, 'guest', []);
        $hr = $this->makeUser($mine->city, 'HR-ALP', ['attendance.view-own', 'attendance.view']);

        $this->actingAs($nobody)->get(route('attendance.timesheets'))->assertForbidden();
        $this->actingAs($nobody)->get(route('attendance.print', ['month' => '2026-09']))->assertForbidden();
        $this->actingAs($hr)->get(route('attendance.upload'))->assertForbidden();

        $this->actingAs($hr)->get(route('attendance.print', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($other->name)
            ->assertSee('Monthly TimeSheet')
            ->assertSee('Monthly Summary');
    }

    public function test_a_sheet_with_waiting_days_prints_as_a_draft(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view']);

        $this->actingAs($hr)->get(route('attendance.print', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('مسودة');
    }

    public function test_a_print_link_without_a_month_opens_the_pages_default_month(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view']);

        // The Timesheets page opens on the month of ten days ago: early October means September.
        $this->travelTo('2026-10-05 09:00');

        $this->actingAs($hr)->get(route('attendance.print'))
            ->assertOk()
            ->assertSee($employee->name)
            ->assertSee('Monthly TimeSheet');
    }

    public function test_the_upload_page_previews_then_imports(): void
    {
        $employee = $this->volunteer();
        $hr = $this->makeUser($employee->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        $csv = "AC-No,Name,Time\n{$employee->id},x,07/09/2026 08:55\n{$employee->id},x,07/09/2026 17:10\n";
        $file = UploadedFile::fake()->createWithContent('report.csv', $csv);

        Livewire::actingAs($hr)->test(Upload::class)
            ->set('file', $file)
            ->assertHasNoErrors()
            ->assertSet('preview.total', 2)
            ->assertSet('preview.unmatched', 0)
            ->call('import');

        $this->assertSame(2, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_a_city_hr_role_cannot_link_a_number_to_someone_in_another_city(): void
    {
        $mine = $this->volunteer();
        $damascus = $this->makeCity('DMS', '002');
        $other = $this->makeEmployee($damascus, 'other');
        $hr = $this->makeUser($mine->city, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        Livewire::actingAs($hr)->test(Upload::class)
            ->set('employeeIds.77', (string) $other->id)
            ->call('linkNumber', '77')
            ->assertHasErrors('employeeIds.77');

        $this->assertDatabaseMissing('attendance_device_links', ['device_no' => '77']);
    }

    private function rawReport(array $rows): string
    {
        // BOF, then one text record (0x0004) per cell: row, col, 3 attribute bytes, length, text.
        $bytes = pack('vv', 0x0809, 6).str_repeat("\0", 6);

        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $text) {
                $bytes .= pack('vv', 0x0004, 8 + strlen($text)).pack('vv', $r, $c)."\0\0\0".chr(strlen($text)).$text;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function punch(Employee $employee, string $at): void
    {
        AttendancePunch::query()->create([
            'import_id' => $this->import()->id,
            'device_no' => (string) $employee->id,
            'employee_id' => $employee->id,
            'punched_at' => $at,
        ]);
    }

    private function import(): \App\Models\AttendanceImport
    {
        return \App\Models\AttendanceImport::query()->firstOrCreate(['file_name' => 'test.xls']);
    }

    private function september(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 9, 1);
    }

    private function volunteer(): Employee
    {
        $aleppo = \App\Models\City::query()->where('slug', 'ALP')->first() ?? $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami'.uniqid());
        $contract = $this->makeContractFor($employee);
        $contract->position->center->update(['weekends' => '0,6']);

        return $employee;
    }
}
