<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\HiringCampaignStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\Nationality;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as OfferStatus;
use App\Enums\SalaryPer;
use App\Models\AnnualLeaveRule;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OffboardingJourney;
use App\Models\OnboardingJourney;
use App\Models\ProbationReview;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\LeaveBalanceService;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentHiringConversionService;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use ReflectionProperty;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\Concerns\RunsTheNightlySchedule;
use Tests\TestCase;

/**
 * From a selected candidate to a volunteer in post, across the calendar.
 *
 * The recruitment tests stop at the hire and the onboarding tests start from
 * an employee. This chain is the join: selected → offer sent → accepted →
 * hired (employee, contract, balances, seat) → onboarding journey → the
 * contract running and renewing, with the nightly schedule in between.
 */
class HiringChainSimulationTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase, RunsTheNightlySchedule;

    private City $city;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));

        $this->city = $this->makeCity('HMS', '002');
        $this->hr = $this->makeUser($this->city, 'HR-HMS', [
            'recruitment-campaigns.view', 'recruitment-campaigns.manage', 'contracts.create', 'employees.create', 'employees.update',
            ...array_map(fn (string $action): string => "recruitment-applicants.{$action}", config('access-control.groups.recruitment-applicants')),
        ]);

        foreach ([1 => 'Volunteer Contract', 3 => 'Trial Volunteer Contract', 5 => 'Specific Voluntary Service Contract'] as $id => $name) {
            ContractType::query()->forceCreate(['id' => $id, 'name_en' => $name, 'name_ar' => $name]);
        }
        AnnualLeaveRule::query()->create(['days' => 14, 'day_per_month' => 1.5, 'years_of_volunteering' => 0]);
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_hired_candidate_is_onboarded_runs_their_contract_and_is_renewed_through_the_nightly_schedule(): void
    {
        $this->nightFromHour = 0;

        $campaign = $this->makeCampaignIn($this->city);
        $position = $campaign->vacancy->position;

        // selected → offer sent → accepted → hired, as the web does it.
        $application = $this->applyTo($campaign, $this->makeApplicant('Candidate One'), ['status' => AppStatus::Interviewed]);
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $application, AppStatus::Interviewed, AppStatus::Finalist, null);
        $statuses->change($this->hr, $application->fresh(), AppStatus::Finalist, AppStatus::Selected, null);

        $offers = app(RecruitmentJobOfferService::class);
        $offer = $offers->create($this->hr, $application->fresh(), [
            'contractTypeId' => 3, 'salary' => '1500', 'currency' => Currency::USD, 'salaryPer' => SalaryPer::Month,
            'startDate' => '2026-11-01', 'endDate' => '2026-12-31', 'weeklyHours' => 40,
            'employmentType' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite, 'probationDays' => 90, 'notes' => 'Transport allowance included.',
        ])->fresh();
        $offer = $offers->send($this->hr, $offer, $offer->current_version_id, null)->fresh();
        $offer = $offers->recordResponse($this->hr, $offer, $offer->current_version_id, OfferStatus::Accepted, 'Accepted.')->fresh();
        $this->assertInstanceOf(Conversion::class, $offer->hiringConversion);

        // The hire runs as a web request, where the seat check is live.
        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);
        app(RecruitmentHiringConversionService::class)->convert($this->hr, $offer->hiringConversion->fresh(), [
            'mode' => RecruitmentHiringConversionService::MODE_CREATE,
            'employee' => [
                'firstNameEn' => 'Candidate', 'lastNameEn' => 'One', 'firstNameAr' => 'مرشح', 'lastNameAr' => 'واحد',
                'fatherNameEn' => 'Father', 'fatherNameAr' => 'الأب', 'motherNameEn' => 'Mother', 'motherNameAr' => 'الأم',
                'nationality' => (string) Nationality::Syrian, 'nationalNumber' => '01234567890', 'gender' => '1',
                'maritalStatus' => '1', 'dob' => '1995-05-01', 'personalPhone' => '0944000000', 'personalEmail' => '',
                'username' => 'candidate.one',
            ],
        ]);
        $console->setValue($this->app, true);

        $employee = Employee::query()->where('username', 'candidate.one')->sole();
        $first = Contract::query()->where('employee_id', $employee->id)->sole();

        // The hire left everything behind it in place.
        $this->assertSame(AppStatus::Hired, $application->fresh()->status);
        $this->assertSame([Vacancy::FILLED, 1], [$offer->vacancy->fresh()->status, $offer->vacancy->fresh()->filled_count]);
        $this->assertSame(HiringCampaignStatus::Closed, $campaign->fresh()->status);
        $this->assertSame(1, $position->fresh()->exist_emp);
        $this->assertTrue($first->leaveBalances()->exists());

        $journey = OnboardingJourney::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame('2026-11-15', $journey->orientation_due_on->toDateString(), 'Orientation is due two weeks after the contract starts.');

        // A trial: it never rolls over, so HR signs the annual contract ahead
        // (the contract page does Contract + LeaveBalanceService::create).
        $annual = Contract::query()->create([
            'employee_id' => $employee->id, 'position_id' => $position->id, 'contract_type_id' => 1,
            'type' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite,
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'currency' => Currency::USD, 'salary_per' => SalaryPer::Month,
            'salary' => 1600, 'weekly_hours' => 40, 'work_day_from' => 0, 'work_day_to' => 4,
            'work_hours_from' => '09:00:00', 'work_hours_to' => '17:00:00', 'is_active' => true, 'auto_renew' => true,
        ]);
        app(LeaveBalanceService::class)->create($annual->refresh());

        $this->runNights('2026-10-05', '2027-01-04', function (string $day) use ($employee, $position, $campaign, $first): void {
            $this->assertTrue($employee->fresh()->is_active, "{$day}: a hired volunteer is never deactivated while a contract runs or is signed ahead.");
            $this->assertSame(1, OnboardingJourney::query()->where('employee_id', $employee->id)->count(), "{$day}: one onboarding journey");
            $this->assertSame(HiringCampaignStatus::Closed, $campaign->fresh()->status, "{$day}: the filled campaign stays closed");
            $this->assertSame(1, Vacancy::query()->where('position_id', $position->id)->count());
            $this->assertSame(0, OffboardingJourney::query()->where('employee_id', $employee->id)->count(), "{$day}: not a departure");
            $this->assertSame($day >= '2027-01-01' ? 0 : 1, (int) $first->fresh()->is_active, "{$day}: the trial ends on 31 December");
        });

        $this->assertSame(1, Contract::query()->where('employee_id', $employee->id)->where('contract_type_id', 3)->count(), 'The trial did not renew itself.');
        $this->assertTrue($annual->fresh()->is_active);
        $this->assertSame(1, OnboardingJourney::query()->where('employee_id', $employee->id)->count(), 'The annual contract started no second onboarding.');
        $this->assertSame(1, ProbationReview::query()->where('contract_id', $first->id)->count(), 'The trial got its review.');
    }
}
