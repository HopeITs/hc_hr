<?php

namespace Tests\Feature;

use App\Enums\ContractType as ContractTypeEnum;
use App\Enums\HiringCampaignStatus;
use App\Livewire\Reports\Index;
use App\Livewire\Reports\LeaveReport as LeaveReportPage;
use App\Livewire\Reports\PositionVacancies;
use App\Livewire\Reports\ProfileCompleteness;
use App\Livewire\Reports\RecruitmentFunnel;
use App\Livewire\Reports\Training;
use App\Models\Center;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Department;
use App\Models\Education;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentJobOffer;
use App\Models\Relative;
use App\Services\LeaveRequestPricing;
use App\Services\Reports\LeaveReport;
use App\Services\Reports\PositionVacanciesReport;
use App\Services\Reports\ProfileCompletenessReport;
use App\Services\Reports\RecruitmentFunnelReport;
use App\Services\Reports\TrainingReport;
use App\Support\Reports\ReportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use Throwable;

/** The reports hub and the five reports built from data already in the system. */
class ReportsHubTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const ALL = ['contracts.view', 'leaves.view-all', 'positions.view', 'employees.view', 'recruitment-applicants.view'];

    private City $alp;

    private City $hms;

    private Center $center;

    private Program $program;

    private Department $department;

    private JobLevel $level;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo('2026-11-23 10:00:00');
        Storage::fake('local');

        $this->alp = $this->makeCity('ALP', '001');
        $this->hms = $this->makeCity('HMS', '002');
        $this->center = $this->makeCenter($this->alp);
        $this->program = Program::query()->forceCreate(['name' => 'Core', 'slug' => 'core', 'code' => 'CORE', 'is_active' => true]);
        $this->department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);
        $this->level = JobLevel::query()->forceCreate(['name_en' => 'Officer', 'name_ar' => 'مسؤول', 'grade' => 2]);
        $this->position = $this->makePosition('Officer', $this->alp, $this->center);
        ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوّع']);
        LeaveType::query()->forceCreate(['id' => 1, 'name_en' => 'Annual leave', 'name_ar' => 'إجازة سنوية', 'base_days' => 14]);
    }

    // ── The hub ──────────────────────────────────────────────────────

    public function test_the_hub_lists_only_the_reports_the_viewer_may_open(): void
    {
        // Roles accumulate permissions in the fixture, so narrower users go first.
        $narrow = $this->makeUser($this->alp, 'HR-ALP', ['contracts.view', 'positions.view']);
        $cards = fn () => Livewire::actingAs($narrow)->test(Index::class);

        $cards()->assertOk()
            ->assertSeeHtml('data-testid="report-card-leaving-reasons"')
            ->assertSeeHtml('data-testid="report-card-vacancies"')
            ->assertDontSeeHtml('data-testid="report-card-training"')
            ->assertDontSeeHtml('data-testid="report-card-leave"')
            ->assertDontSeeHtml('data-testid="report-card-recruitment-funnel"');

        $this->assertSame(['leaving-reasons', 'vacancies'], array_keys(ReportCatalog::visibleTo($narrow)));
    }

    public function test_a_manager_and_an_hr_user_with_no_matching_permission_get_nothing(): void
    {
        Livewire::actingAs($this->makeUser($this->alp, 'HR-HMS', []))->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->makeUser($this->alp, 'manager', self::ALL))->test(Index::class)->assertForbidden();
        $this->assertSame([], ReportCatalog::visibleTo($this->makeUser($this->alp, 'manager', self::ALL)));
    }

    public function test_every_report_page_refuses_a_manager_even_with_the_permission(): void
    {
        $manager = $this->makeUser($this->alp, 'manager', self::ALL);

        foreach ([RecruitmentFunnel::class, LeaveReportPage::class, PositionVacancies::class, Training::class, ProfileCompleteness::class] as $page) {
            Livewire::actingAs($manager)->test($page)->assertForbidden();
        }
    }

    public function test_every_report_page_renders_and_exports_for_head_office(): void
    {
        $head = $this->headOffice();

        foreach ([RecruitmentFunnel::class, LeaveReportPage::class, PositionVacancies::class, Training::class, ProfileCompleteness::class] as $page) {
            try {
                Livewire::actingAs($head)->test($page)->assertOk()->call('export')->assertFileDownloaded();
            } catch (Throwable $e) {
                $this->fail($page.': '.$e->getMessage());
            }
        }
    }

    public function test_every_report_opens_through_its_route_for_head_office_and_not_for_a_manager(): void
    {
        $routes = ['reports.index', 'reports.leaving-reasons', 'reports.recruitment-funnel', 'reports.leave', 'reports.vacancies', 'reports.training', 'reports.profile-completeness'];
        $manager = $this->makeUser($this->alp, 'manager', self::ALL);

        foreach ($routes as $name) {
            $this->actingAs($manager)->get(route($name))->assertForbidden();
        }

        $head = $this->headOffice();
        foreach ($routes as $name) {
            $this->actingAs($head)->get(route($name))->assertOk();
        }
    }

    // ── Position vacancies ───────────────────────────────────────────

    public function test_vacancies_count_seats_and_flag_empty_over_and_critical_positions(): void
    {
        $this->makePosition('Coordinator', $this->alp, $this->center, ['max_emp' => 3, 'exist_emp' => 1, 'is_critical' => true]);
        $this->makePosition('Driver', $this->alp, $this->center, ['max_emp' => 1, 'exist_emp' => 1]);
        $this->makePosition('Cook', $this->alp, $this->center, ['max_emp' => 1, 'exist_emp' => 2]);
        $this->makePosition('Retired', $this->alp, $this->center, ['max_emp' => 5, 'exist_emp' => 0, 'is_active' => false]);
        $this->position->update(['max_emp' => 1, 'exist_emp' => 0]);

        $r = (new PositionVacanciesReport($this->headOffice()))->build();

        $this->assertSame(4, $r['positions'], 'The inactive position is left out.');
        $this->assertSame(6, $r['capacity']);
        $this->assertSame(4, $r['filled']);
        $this->assertSame(3, $r['vacantSeats'], 'Two on the coordinator, one on the officer.');
        $this->assertSame(1, $r['emptyPositions']);
        $this->assertSame(1, $r['overPositions']);
        $this->assertSame(1, $r['criticalVacant']);
        $this->assertSame('Coordinator', $r['list']->first()['title'], 'Critical positions come first.');

        $this->assertSame(['Cook'], (new PositionVacanciesReport($this->headOffice(), ['state' => 'over']))->build()['list']->pluck('title')->all());
        $this->assertSame(['Coordinator'], (new PositionVacanciesReport($this->headOffice(), ['critical' => true]))->build()['list']->pluck('title')->all());
        $this->assertSame(4, (new PositionVacanciesReport($this->headOffice(), ['department_id' => '']))->build()['positions'], 'A blank filter means all.');
        $this->assertSame(0, (new PositionVacanciesReport($this->headOffice(), ['department_id' => 999999]))->build()['positions']);
    }

    public function test_vacancies_of_a_city_hr_stay_inside_their_city(): void
    {
        $hmsCenter = $this->makeCenter($this->hms);
        $this->makePosition('Homs Officer', $this->hms, $hmsCenter, ['max_emp' => 2]);

        $hmsHr = $this->makeUser($this->hms, 'HR-HMS', ['positions.view']);
        $r = (new PositionVacanciesReport($hmsHr))->build();

        $this->assertSame(['Homs Officer'], $r['list']->pluck('title')->all());
        $this->assertSame(['HMS'], $r['byCity']->pluck('label')->all());
    }

    // ── Leave ────────────────────────────────────────────────────────

    public function test_leave_owed_idle_people_taken_days_and_waiting_requests(): void
    {
        $a = $this->person('lv.a');
        $b = $this->person('lv.b');
        $balanceA = $this->annual($a, 160, 0);
        $balanceB = $this->annual($b, 160, 40);
        $perDay = app(LeaveRequestPricing::class)->hoursPerDayFor(Contract::query()->where('employee_id', $a->id)->firstOrFail()) ?: 8.0;

        $this->request($balanceB, ['status' => 1, 'start_at' => '2026-10-05', 'end_at' => '2026-10-05', 'computed_hours' => 40, 'approved_at' => '2026-10-06 10:00:00', 'created_at' => '2026-10-04 10:00:00']);
        $this->request($balanceB, ['status' => 2, 'start_at' => '2026-10-20', 'end_at' => '2026-10-20', 'computed_hours' => 8, 'approved_at' => '2026-10-21 10:00:00', 'created_at' => '2026-10-19 10:00:00']);
        $this->request($balanceA, ['status' => 0, 'created_at' => now()->subDays(5)]);
        $this->request($balanceA, ['status' => 0, 'created_at' => now()->subDay()]);

        $r = (new LeaveReport($this->headOffice(), 365))->build();

        $this->assertSame(2, $r['people']);
        $this->assertSame(round(round(160 / $perDay, 1) + round(120 / $perDay, 1), 1), $r['owedDays']);
        $this->assertSame(1, $r['idleCount'], 'Only the one who has taken nothing in over 90 days of service.');
        $this->assertSame(round(40 / $perDay, 1), $r['takenDays']);
        $this->assertSame(2, $r['pending']);
        $this->assertSame(1, $r['pendingOverThree']);
        $this->assertSame(2.0, $r['daysToAnswer'], 'Two days for each of the two decided requests.');
        $this->assertSame(['Programs'], $r['byDepartment']->pluck('label')->all());

        $this->assertSame(2, (new LeaveReport($this->headOffice(), 365, $this->department->id))->build()['people']);
        $this->assertSame(0, (new LeaveReport($this->headOffice(), 365, 999999))->build()['people']);
    }

    // ── Training ─────────────────────────────────────────────────────

    public function test_training_counts_only_courses_that_count_and_measures_coverage(): void
    {
        [$e1, $e2, $e3] = [$this->person('tr.1'), $this->person('tr.2'), $this->person('tr.3')];
        $first = CourseCategory::query()->create(['name_en' => 'Safety', 'name_ar' => 'السلامة']);
        $second = CourseCategory::query()->create(['name_en' => 'Finance', 'name_ar' => 'المالية']);

        $this->course($e1, TrainingReport::APPROVED, '2026-03-01', ['cost' => 100, 'has_certificate' => true, 'category_id' => $first->id]);
        $this->course($e1, TrainingReport::FINISHED, '2026-05-01', ['cost' => 50, 'category_id' => $second->id]);
        $this->course($e2, TrainingReport::FINISHED, '2025-06-01');
        $this->course($e2, TrainingReport::REJECTED, '2026-04-01', ['cost' => 999]);
        $this->course($e3, TrainingReport::PENDING, '2026-04-01', ['cost' => 999]);

        $r = (new TrainingReport($this->headOffice(), 2026))->build();

        $this->assertSame(2, $r['courses'], 'Rejected and pending ones do not count.');
        $this->assertSame(1, $r['trained']);
        $this->assertSame(33.3, $r['coveragePercent']);
        $this->assertSame(150.0, $r['cost']);
        $this->assertSame(1, $r['certificates']);
        $this->assertSame(2, $r['untrainedCount']);
        $this->assertSame(['Safety' => 1, 'Finance' => 1], $r['byCategory']->pluck('count', 'label')->all());
        $this->assertSame(4, $r['byStatus']->sum('count'), 'The status split shows every 2026 course.');
        $this->assertSame(['2026-03' => 1, '2026-05' => 1], $r['byMonth']->all());

        $all = (new TrainingReport($this->headOffice(), null))->build();
        $this->assertSame(3, $all['courses']);
        $this->assertSame(2, $all['trained']);
        $this->assertSame(1, (new TrainingReport($this->headOffice(), 2026, $first->id))->build()['courses']);
    }

    // ── Profile completeness ─────────────────────────────────────────

    public function test_completeness_checks_presence_only_and_lists_the_emptiest_files_first(): void
    {
        $full = $this->person('pc.full', [
            'national_number' => '01010101010', 'dob' => '1990-01-01', 'gender' => 1, 'nationality' => 1,
            'personal_phone' => '0999000000', 'personal_email' => 'full@example.test', 'address_en' => 'Aleppo',
        ]);
        $full->addMediaFromString('not-really-a-png')->usingFileName('a.png')->toMediaCollection('avatar');
        Education::query()->forceCreate(['employee_id' => $full->id, 'name' => 'BSc', 'specialization' => 'IT']);
        Relative::query()->forceCreate(['employee_id' => $full->id, 'name' => 'Kin', 'phone' => '0999111111', 'relation' => 'Mother']);

        $this->person('pc.part', ['personal_phone' => '0999000001', 'gender' => 1]);
        $empty = $this->person('pc.empty');

        $r = (new ProfileCompletenessReport($this->headOffice()))->build();

        $this->assertSame(3, $r['people']);
        $this->assertSame(1, $r['complete']);
        $this->assertSame($empty->username, $r['list']->first()['username'], 'The emptiest file is first.');
        $this->assertSame(2, $r['list']->count());
        $this->assertSame(1, $r['byItem']->firstWhere('key', 'photo')['count']);
        $this->assertSame(['Photo', 'National number'], array_slice($r['list']->first()['missingLabels'], 0, 2));
        $this->assertArrayNotHasKey('personal_phone', $r['list']->first(), 'No value from the file is carried into the report.');

        $this->assertSame(2, (new ProfileCompletenessReport($this->headOffice(), null, 'photo'))->build()['list']->count(), 'The partial and the empty file lack a photo.');
        $this->assertSame([$empty->username], (new ProfileCompletenessReport($this->headOffice(), null, 'personal_phone'))->build()['list']->pluck('username')->all(), 'Only the empty file lacks a phone.');
        $this->assertSame(0, (new ProfileCompletenessReport($this->headOffice(), $this->hms->id))->build()['people']);
    }

    // ── Recruitment funnel ───────────────────────────────────────────

    public function test_the_funnel_counts_the_stages_applicants_reached_inside_the_viewers_scope(): void
    {
        $campaign = $this->makeCampaignIn($this->alp);
        $apps = collect(['One', 'Two', 'Three'])->map(fn ($n) => $this->applyTo($campaign, $this->makeApplicant('Applicant '.$n)));
        $creator = $campaign->created_by_user_id;

        foreach ([[$apps[0], 'scheduled'], [$apps[1], 'scheduled'], [$apps[2], 'cancelled']] as [$app, $status]) {
            RecruitmentInterview::query()->forceCreate([
                'application_id' => $app->id, 'interview_type' => 'hr', 'status' => $status, 'scheduled_at' => now()->addDay(),
                'location_type' => 'online', 'created_by_user_id' => $creator,
            ]);
        }
        RecruitmentJobOffer::query()->forceCreate([
            'application_id' => $apps[0]->id, 'vacancy_id' => $campaign->vacancy_id, 'position_id' => $campaign->vacancy->position_id,
            'status' => 'accepted', 'created_by_user_id' => $creator, 'sent_at' => now()->addDays(2), 'accepted_at' => now()->addDays(3),
        ]);

        $hr = $this->makeUser($this->alp, 'HR-ALP', ['recruitment-campaigns.view', 'recruitment-applicants.view']);
        $r = (new RecruitmentFunnelReport($hr, 365))->build();

        $this->assertSame([3, 2, 1, 1, 0], $r['stages']->pluck('count')->all(), 'Applications, interviewed (the cancelled one is out), offered, accepted, hired.');
        $this->assertSame(100.0, $r['offerAcceptancePercent']);
        $this->assertSame(1, $r['requests']['submitted']);
        $this->assertSame(1, $r['requests']['approved']);
        $this->assertNotNull($r['times']['toOffer']);
        $this->assertSame(['IT Specialist'], $r['byPosition']->pluck('label')->all());
        $this->assertSame(3, $r['byPosition']->first()['applications']);

        $hmsHr = $this->makeUser($this->hms, 'HR-HMS', ['recruitment-campaigns.view', 'recruitment-applicants.view']);
        $this->assertSame(0, (new RecruitmentFunnelReport($hmsHr, 365))->build()['stages']->first()['count'], 'Another governorate sees none of it.');
    }

    public function test_the_funnel_page_follows_the_applicant_permission(): void
    {
        $noPermission = $this->makeUser($this->alp, 'HR-ALP', ['contracts.view']);
        Livewire::actingAs($noPermission)->test(RecruitmentFunnel::class)->assertForbidden();

        $this->makeCampaignIn($this->alp, HiringCampaignStatus::Open);
        $withPermission = $this->makeUser($this->alp, 'HR-ALP', ['recruitment-applicants.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Livewire::actingAs($withPermission->fresh())->test(RecruitmentFunnel::class)->assertOk()->assertSeeHtml('data-testid="funnel-requests"');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function headOffice()
    {
        return $this->makeUser($this->alp, 'super-admin', self::ALL);
    }

    private function makeCenter(City $city): Center
    {
        return Center::query()->create([
            'name_en' => 'Center '.$city->slug, 'name_ar' => 'مركز', 'city_id' => $city->id,
            'is_active' => true, 'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
    }

    private function makePosition(string $title, City $city, Center $center, array $attributes = []): Position
    {
        return Position::query()->forceCreate(array_merge([
            'title_en' => $title, 'title_ar' => $title, 'center_id' => $center->id, 'city_id' => $city->id,
            'department_id' => $this->department->id, 'job_level_id' => $this->level->id, 'program_id' => $this->program->id,
            'max_emp' => 1, 'exist_emp' => 0, 'is_active' => true,
        ], $attributes));
    }

    /** An employee of ALP with a contract in force today. */
    private function person(string $username, array $attributes = []): Employee
    {
        $employee = $this->makeEmployee($this->alp, $username, $attributes);
        $this->contract($employee);

        return $employee;
    }

    private function contract(Employee $employee, array $overrides = []): Contract
    {
        return Contract::query()->create(array_merge([
            'employee_id' => $employee->id, 'position_id' => $this->position->id, 'contract_type_id' => 1,
            'type' => ContractTypeEnum::FullTime, 'place' => 1, 'currency' => 2, 'salary' => 1000, 'salary_per' => 1,
            'weekly_hours' => 40, 'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00', 'is_active' => true,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ], $overrides));
    }

    /** The annual balance of the employee's contract, however the contract came to have one. */
    private function annual(Employee $employee, int $deserved, int $used): LeaveBalance
    {
        $contract = Contract::query()->where('employee_id', $employee->id)->firstOrFail();

        return LeaveBalance::query()->updateOrCreate(
            ['contract_id' => $contract->id, 'type_id' => 1],
            ['deserved' => $deserved, 'used' => $used],
        );
    }

    private function request(LeaveBalance $balance, array $attributes): LeaveRequest
    {
        return LeaveRequest::query()->forceCreate(array_merge([
            'leave_balance_id' => $balance->id, 'type' => 'day', 'start_at' => '2026-11-01', 'end_at' => '2026-11-01',
            'status' => 0, 'computed_hours' => 8,
        ], $attributes));
    }

    private function course(Employee $employee, int $status, string $start, array $attributes = []): Course
    {
        return Course::query()->forceCreate(array_merge([
            'employee_id' => $employee->id, 'title' => 'Course', 'status' => $status, 'start_date' => $start,
        ], $attributes));
    }
}
