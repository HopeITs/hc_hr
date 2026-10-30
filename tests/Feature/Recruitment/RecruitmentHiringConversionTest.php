<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\HiringCampaignStatus;
use App\Enums\LeaveTypeCases;
use App\Enums\Nationality;
use App\Enums\PayMode;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentJobOfferStatus as OfferStatus;
use App\Enums\SalaryPer;
use App\Enums\SessionDuration;
use App\Livewire\Recruitment\Applicants\ApplicationOffer;
use App\Livewire\Recruitment\Offers\Convert;
use App\Livewire\Recruitment\Offers\Show;
use App\Models\AnnualLeaveRule;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\HiringCampaign;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\OnboardingJourney;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion as Conversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Notifications\NewEmployeeWelcome;
use App\Notifications\RecruitmentCandidateHired;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentHiringConversionService as Conversions;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use ReflectionProperty;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 10: an accepted offer becomes an employee and a contract.
 *
 * Each test names the spec section it covers: scope (نطاق التنفيذ /
 * المطلوب تنفيذه), rules (قواعد العمل), security (الأمان والصلاحيات), edge
 * (الحالات الاستثنائية), integrity (سلامة البيانات), acceptance (معايير
 * القبول), avoid (أخطاء يجب تجنبها).
 *
 * Runs as a web request (runningInConsole() off), so ContractObserver's own
 * capacity check is live exactly as in production. The clock is frozen on
 * 2026-10-05 09:00 (Europe/Istanbul).
 */
class RecruitmentHiringConversionTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const SALARY = '1500';

    /** The ids config('contracts.map_by_id') gives a category. */
    private const ANNUAL = 1;

    private const TRIAL = 3;

    private const SERVICE = 5;

    private City $homs;

    private User $hr;

    private HiringCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

        $this->homs = $this->makeCity('HMS', '002');
        $this->hr = $this->hr($this->homs);

        foreach ([self::ANNUAL => 'Volunteer Contract', self::TRIAL => 'Trial Volunteer Contract', self::SERVICE => 'Specific Voluntary Service Contract'] as $id => $name) {
            ContractType::query()->forceCreate(['id' => $id, 'name_en' => $name, 'name_ar' => $name]);
        }

        AnnualLeaveRule::query()->create(['days' => 14, 'day_per_month' => 1.5, 'years_of_volunteering' => 0]);
        foreach ([LeaveTypeCases::Annual, LeaveTypeCases::FullSick, LeaveTypeCases::HalfSick, LeaveTypeCases::Unpaid] as $id) {
            LeaveType::query()->forceCreate(['id' => $id, 'name_en' => "Leave {$id}", 'name_ar' => "إجازة {$id}", 'base_days' => 12]);
        }

        $this->campaign = $this->makeCampaignIn($this->homs);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The hire
    // ------------------------------------------------------------------

    /** acceptance + scope: the accepted candidate becomes an employee with a contract, from the hire page, and everything around it follows. */
    public function test_hr_hires_the_accepted_candidate_as_a_new_employee_with_a_contract(): void
    {
        Notification::fake();
        [$application, $offer, $conversion] = $this->accepted();
        $position = $offer->position;
        $employeesBefore = Employee::query()->count();
        $this->actingAs($this->hr);

        // Point 1: asked first, like the Leaves page; nothing is created until HR confirms.
        $page = Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSet('mode', Conversions::MODE_CREATE)
            ->set('employee', $this->person())
            ->call('confirmHire')
            ->assertHasNoErrors();
        $page->assertDispatched('confirm-dialog', method: 'save', danger: false);
        $this->assertSame($employeesBefore, Employee::query()->count());

        // The confirm button runs save(); the page stays, now showing the hire.
        $page->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee(__('recruitment-applicant.conversions.statuses.converted'));

        // The employee, in the HR system.
        $this->assertSame($employeesBefore + 1, Employee::query()->count());
        $employee = Employee::query()->where('username', 'candidate.one')->sole();
        $this->assertSame(['Candidate', 'One', 'مرشح', 'واحد'], [$employee->first_name_en, $employee->last_name_en, $employee->first_name_ar, $employee->last_name_ar]);
        $this->assertSame([$this->homs->id, '01234567890', '0944000000', true], [$employee->city_id, $employee->national_number, $employee->personal_phone, $employee->is_active]);

        // The contract, on the position, from the accepted terms.
        $contract = Contract::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame([$position->id, self::TRIAL, EmploymentType::FullTime, ContractPlace::Onsite], [$contract->position_id, $contract->contract_type_id, $contract->type, $contract->place]);
        $this->assertSame(['2026-11-01', '2027-10-31', 40], [$contract->start_date->toDateString(), $contract->end_date->toDateString(), $contract->weekly_hours]);
        $this->assertSame([1500.0, Currency::USD, SalaryPer::Month, PayMode::Fixed, true], [(float) $contract->salary, $contract->currency, (int) $contract->salary_per, $contract->pay_mode, $contract->is_active]);
        $this->assertStringNotContainsString('1500', (string) DB::table('contracts')->where('id', $contract->id)->value('salary'), 'Encrypted at rest, like every contract.');
        $this->assertGreaterThan(0, LeaveBalance::query()->where('contract_id', $contract->id)->count(), 'Leave balances, as the contract page creates them.');
        $this->assertSame(1, $position->fresh()->exist_emp);

        // D4-I: the hire starts the onboarding journey, due 14 days after the start.
        $journey = OnboardingJourney::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame([$contract->id, '2026-11-15'], [$journey->contract_id, $journey->orientation_due_on->toDateString()]);

        // The vacancy needed one person: it is filled, and its campaign closed.
        $vacancy = $offer->vacancy->fresh();
        $this->assertSame([Vacancy::FILLED, 1], [$vacancy->status, $vacancy->filled_count]);
        $this->assertNotNull($vacancy->closed_at);
        $this->assertSame(HiringCampaignStatus::Closed, $this->campaign->fresh()->status);
        $this->assertSame($this->hr->id, $this->campaign->fresh()->closed_by_user_id);
        $this->assertTrue(DB::table('recruitment_request_events')
            ->where('event_type', RecruitmentEventType::CampaignStateChanged)
            ->where('meta->campaign_id', $this->campaign->id)
            ->where('meta->reason', Conversions::CAMPAIGN_CLOSE_REASON)
            ->exists());

        // The application, the applicant and the conversion.
        $this->assertSame(AppStatus::Hired, $application->fresh()->status);
        $this->assertSame($employee->id, $application->applicant->fresh()->employee_id);
        $conversion->refresh();
        $this->assertSame([Conversion::STATUS_CONVERTED, $employee->id, $contract->id, $this->hr->id, 1, null],
            [$conversion->status, $conversion->employee_id, $conversion->contract_id, $conversion->converted_by_user_id, $conversion->attempt_count, $conversion->failure_reason]);
        $this->assertNotNull($conversion->converted_at);
        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);

        // Colleagues are welcomed, as for any first contract.
        Notification::assertSentTo($this->hr, NewEmployeeWelcome::class);

        // Point 4: the message says what is next — a login account on the
        // employee page — and its button opens it; the contract is linked too.
        $page->assertDispatched('confirm-dialog', method: 'openEmployee', danger: false);
        $page->assertSee(route('employees.edit', $employee->id))
            ->assertSee(route('contracts.show', $contract->id))
            ->assertSee(__('recruitment-applicant.conversions.no_account', ['label' => __('general.create_login_account')]))
            ->call('openEmployee')
            ->assertRedirect(route('employees.edit', $employee->id));
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->call('openContract')
            ->assertRedirect(route('contracts.edit', $contract->id));
    }

    /** rules + avoid: the contract is built from the accepted version — never an earlier one, never the live offer. */
    public function test_the_contract_takes_the_accepted_snapshot(): void
    {
        $application = $this->selected();
        $offer = $this->offers()->send($this->hr, $offer = $this->createOffer($application, ['salary' => '1200']), $offer->current_version_id, null)->fresh();
        $offer = $this->offers()->revise($this->hr, $offer, $offer->current_version_id, $this->terms(['salary' => '1750', 'weeklyHours' => 30, 'place' => ContractPlace::Online]), 'Better terms after talking to the candidate.')->fresh();
        $offer = $this->offers()->send($this->hr, $offer, $offer->current_version_id, null)->fresh();
        $offer = $this->offers()->recordResponse($this->hr, $offer, $offer->current_version_id, OfferStatus::Accepted, null)->fresh();
        $conversion = $offer->hiringConversion;
        $this->assertSame($offer->current_version_id, $conversion->job_offer_version_id);

        $this->hire($conversion);

        $contract = Contract::query()->findOrFail($conversion->fresh()->contract_id);
        $this->assertSame([1750.0, 30, ContractPlace::Online], [(float) $contract->salary, $contract->weekly_hours, $contract->place]);

        // The accepted version is frozen: there is no live offer to drift from.
        $this->assertThrows(fn () => $offer->currentVersion->forceFill(['salary_amount' => '9'])->save(), LogicException::class);
    }

    /**
     * rules: what the offer does not carry starts where the contract composer
     * starts a new contract. A new offer carries every term (Point 3 of the
     * Tasks 7-10 review), so only an offer accepted before that can lack one.
     */
    public function test_terms_the_offer_does_not_carry_take_the_composer_defaults(): void
    {
        // Annual: to the end of the year it starts in; 40 hours; on site; full time.
        $annual = $this->legacyTerms($this->accepted('Annual One')[2], ['contract_type_id' => self::ANNUAL, 'end_date' => null, 'weekly_hours' => null], ['place', 'employment_type']);
        $this->hire($annual, ['employee' => $this->person(['username' => 'annual.one', 'nationalNumber' => '01234567801'])]);
        $contract = $annual->fresh()->contract;
        $this->assertSame(['2026-11-01', '2026-12-31', 40, ContractPlace::Onsite, EmploymentType::FullTime],
            [$contract->start_date->toDateString(), $contract->end_date->toDateString(), $contract->weekly_hours, $contract->place, $contract->type]);
        $this->assertSame('09:00', substr((string) $contract->work_hours_from, 0, 5));

        // Trial: three months.
        $trial = $this->legacyTerms($this->accepted('Trial One', ['contractTypeId' => self::TRIAL, 'endDate' => '2027-01-15'], $this->makeCampaignIn($this->homs))[2], ['end_date' => null]);
        $this->hire($trial, ['employee' => $this->person(['username' => 'trial.one', 'nationalNumber' => '01234567802'])]);
        $this->assertSame('2027-02-01', $trial->fresh()->contract->end_date->toDateString());

        // Service: freelance, and 0 hours is allowed — and no leave clause.
        $service = $this->accepted('Service One', ['contractTypeId' => self::SERVICE, 'employmentType' => EmploymentType::Freelance, 'weeklyHours' => 0], $this->makeCampaignIn($this->homs))[2];
        $service = $this->legacyTerms($service, ['weekly_hours' => null], ['employment_type']);
        $this->hire($service, ['employee' => $this->person(['username' => 'service.one', 'nationalNumber' => '01234567803'])]);
        $contract = $service->fresh()->contract;
        $this->assertSame([EmploymentType::Freelance, 0], [$contract->type, $contract->weekly_hours]);
        $this->assertSame(0, LeaveBalance::query()->where('contract_id', $contract->id)->count());
    }

    /**
     * The Hire button opens the confirmation on the first click: it is locked
     * only while the hire runs, not by the field update that leaving an input
     * (the national number) sends just before the click.
     */
    public function test_the_hire_button_is_locked_only_by_the_hire_itself(): void
    {
        [, $offer] = $this->accepted();
        $this->actingAs($this->hr);

        $html = Livewire::test(Convert::class, ['offerId' => $offer->id])->html();
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*wire:target="confirmHire,save"/s', $html);
        $this->assertMatchesRegularExpression('/wire:model\.blur="employee\.nationalNumber"/', $html, 'The field whose update used to swallow the click.');
    }

    /**
     * The manager who requested the position is told once the hire is done,
     * in the application, and the notice opens the offer — which he can read.
     */
    public function test_the_requester_is_notified_of_the_hire_and_the_notice_opens_the_offer(): void
    {
        Notification::fake();
        [$application, $offer, $conversion] = $this->accepted();
        $requester = User::query()->findOrFail($offer->vacancy->createdFromRequest->requester_user_id);

        $this->hire($conversion);

        $url = route('recruitment.offers.show', ['offerId' => $offer->id]);
        Notification::assertSentTo($requester, RecruitmentCandidateHired::class, function (RecruitmentCandidateHired $notice) use ($requester, $url): bool {
            $data = $notice->toArray($requester);

            return $notice->via($requester) === ['database'] && $data['url'] === $url
                && str_contains($data['body']['en'], 'Candidate One') && $data['title']['ar'] !== '';
        });
        Notification::assertNotSentTo($this->hr, RecruitmentCandidateHired::class);

        // Following the link: the offer opens for him, read-only.
        $this->actingAs($requester);
        $this->get($url)->assertOk();
        Livewire::test(Show::class, ['offerId' => $offer->id])->call('beginCancel')->assertForbidden();
    }

    /** Opened by HR on a manager's behalf: the manager is told, not HR; the HR who made the hire is never told of his own hire. */
    public function test_the_manager_a_position_was_opened_for_is_the_one_notified(): void
    {
        Notification::fake();
        [, $offer, $conversion] = $this->accepted();
        $request = $offer->vacancy->createdFromRequest;
        $raisedBy = User::query()->findOrFail($request->requester_user_id);
        $manager = $this->makeUser($this->homs, 'manager');
        $request->forceFill(['actual_requester_user_id' => $manager->id])->save();

        $this->hire($conversion);

        Notification::assertSentTo($manager, RecruitmentCandidateHired::class);
        Notification::assertNotSentTo($raisedBy, RecruitmentCandidateHired::class);

        // HR who requested the position himself and hired: nobody to tell.
        [, $own, $ownConversion] = $this->accepted('Candidate Two', [], $this->makeCampaignIn($this->homs));
        $own->vacancy->createdFromRequest->forceFill(['requester_user_id' => $this->hr->id, 'actual_requester_user_id' => null])->save();
        $this->hire($ownConversion, ['employee' => $this->person(['firstNameEn' => 'Candidate', 'lastNameEn' => 'Two', 'username' => 'candidate.two', 'nationalNumber' => '01234567891'])]);
        Notification::assertNotSentTo($this->hr, RecruitmentCandidateHired::class);
    }

    /** A hire that fails tells nobody: the notice goes out only after the commit. */
    public function test_a_failed_hire_notifies_nobody(): void
    {
        Notification::fake();
        [, $offer, $conversion] = $this->accepted();
        $offer->position->forceFill(['max_emp' => 0])->save();

        $this->assertBlocked($conversion, 'position_full');

        Notification::assertNothingSentTo(User::query()->findOrFail($offer->vacancy->createdFromRequest->requester_user_id));
    }

    /** The hire page's dropdowns are the project's Select2 (x-forms.select2-input); none is a plain select. */
    public function test_the_hire_page_uses_select2_for_every_dropdown(): void
    {
        [, $offer] = $this->accepted('Session Two', ['salaryPer' => SalaryPer::Session]);
        $this->actingAs($this->hr);
        $html = Livewire::test(Convert::class, ['offerId' => $offer->id])->html();

        foreach (['nationality', 'gender', 'maritalStatus', 'sessionDuration'] as $id) {
            $this->assertMatchesRegularExpression('/<select x-ref="select"[^>]*id="'.$id.'"/', $html, $id.' is a Select2 dropdown');
        }
        $this->assertSame(substr_count($html, '<select'), substr_count($html, '<select x-ref="select"'), 'No plain select is left.');

        // Searchable, as on the Leaves page: the component's search box is never switched off.
        $this->assertSame(4, substr_count($html, 'minimumResultsForSearch: 0'));
        $this->assertStringNotContainsString('minimumResultsForSearch: Infinity', $html);
    }

    /** scope: pay per session needs the session length the offer does not carry. */
    public function test_pay_per_session_needs_the_session_length(): void
    {
        [, $offer, $conversion] = $this->accepted('Session One', ['salaryPer' => SalaryPer::Session]);
        $this->actingAs($this->hr);

        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSee(__('recruitment-applicant.conversions.session_duration'))
            ->set('employee', $this->person())
            ->call('save')
            ->assertHasErrors(['sessionDuration']);
        $this->assertSame(Conversion::STATUS_PENDING, $conversion->fresh()->status, 'A form mistake is not a failed attempt.');

        $this->hire($conversion, ['sessionDuration' => (string) SessionDuration::HourAndHalf]);
        $this->assertSame(SessionDuration::HourAndHalf, $conversion->fresh()->contract->session_duration);
    }

    // ------------------------------------------------------------------
    // Existing people
    // ------------------------------------------------------------------

    /**
     * edge + avoid (the identity rule): an employee with the candidate's name
     * and email is them, and is never created twice silently; an employee
     * with only their phone, under another name, is not them.
     */
    public function test_an_existing_employee_with_the_same_email_or_phone_is_linked_not_duplicated(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        $existing = $this->makeEmployee($this->homs, 'already.here', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => strtoupper($application->email_normalized)]);
        $byPhone = $this->makeEmployee($this->homs, 'same.phone', ['work_phone' => '0944111222']);
        $application->applicant->forceFill(['phone_normalized' => '+963944111222'])->save();

        $matches = $this->conversions()->matches($conversion->fresh());
        $this->assertEqualsCanonicalizing([[$existing->id, ['email'], Conversions::MATCH_LINKABLE], [$byPhone->id, ['phone'], Conversions::MATCH_WARNING]],
            $matches->map(fn (array $match): array => [$match['employee']->id, $match['reasons'], $match['kind']])->all());

        // Creating someone new needs HR to say it is a different person.
        $employees = Employee::query()->count();
        $this->assertValidationError(fn () => $this->hire($conversion), 'confirmNotDuplicate', 'possible_duplicate');
        $this->assertSame($employees, Employee::query()->count());
        $this->assertSame([Conversion::STATUS_PENDING, 0], [$conversion->fresh()->status, $conversion->fresh()->attempt_count]);

        // The phone alone does not make them the other employee.
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $byPhone->id]), 'employeeId', 'name_mismatch');

        // Linking: no new employee; the contract is theirs.
        $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $existing->id]);
        $this->assertSame($employees, Employee::query()->count());
        $conversion->refresh();
        $this->assertSame([$existing->id, $existing->id], [$conversion->employee_id, $conversion->contract->employee_id]);
        $this->assertSame(Conversions::MODE_LINK, $conversion->employee_payload_json['mode']);
        $this->assertSame($existing->id, $application->applicant->fresh()->employee_id);
    }

    /** edge: HR confirms the same-name match is someone else, and a new employee is created. */
    public function test_confirming_a_different_person_creates_a_new_employee(): void
    {
        [$application, , $conversion] = $this->accepted();
        $this->makeEmployee($this->homs, 'namesake', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => $application->email_normalized]);

        $this->assertValidationError(fn () => $this->hire($conversion), 'confirmNotDuplicate', 'possible_duplicate');
        $this->hire($conversion, ['confirmNotDuplicate' => true]);

        $this->assertSame('candidate.one', $conversion->fresh()->employee->username);
    }

    /**
     * edge: a member of staff who signed in to apply is that employee; a
     * national number on file under the same name is that person. Both can
     * only be linked.
     */
    public function test_an_internal_applicant_or_a_known_national_number_can_only_be_linked(): void
    {
        // Signed in as staff to apply: linking is preselected, creating is refused.
        [$application, $offer, $conversion] = $this->accepted();
        $staff = $this->makeEmployee($this->homs, 'staff.member');
        $other = $this->makeEmployee($this->homs, 'other.one', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => $application->email_normalized]);
        $application->applicant->forceFill(['employee_id' => $staff->id, 'identity_verified_at' => now()])->save();

        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSet('mode', Conversions::MODE_LINK)
            ->assertSet('employeeId', (string) $staff->id)
            ->assertSee(__('recruitment-applicant.conversions.match_reasons.internal'));

        $this->assertValidationError(fn () => $this->hire($conversion, ['confirmNotDuplicate' => true]), 'mode', 'internal_must_link');
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $other->id]), 'employeeId', 'internal_must_link');
        $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $staff->id]);
        $this->assertSame($staff->id, $conversion->fresh()->employee_id);

        // A national number already on file, under the same name: that person, linked.
        $second = $this->accepted('Second One', [], $this->makeCampaignIn($this->homs))[2];
        $known = $this->makeEmployee($this->homs, 'known.person', ['first_name_en' => 'Second', 'last_name_en' => 'One', 'national_number' => '09876543210']);
        $this->assertValidationError(fn () => $this->hire($second, ['employee' => $this->person(['nationalNumber' => '09876543210', 'username' => 'second.one'])]), 'employee.nationalNumber', 'national_number_taken');

        $this->hire($second, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $known->id, 'employee' => ['nationalNumber' => '09876543210']]);
        $this->assertSame($known->id, $second->fresh()->employee_id);
    }

    /** edge: a former employee hired back is active again. */
    public function test_a_former_employee_hired_back_is_reactivated(): void
    {
        [$application, , $conversion] = $this->accepted();
        $former = $this->makeEmployee($this->homs, 'former.one', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => $application->email_normalized, 'is_active' => false]);

        $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $former->id]);

        $this->assertTrue($former->fresh()->is_active);
        $this->assertTrue(Activity::query()->where('description', 'recruitment_hiring_conversion.converted')->sole()->properties['employee_reactivated']);
    }

    /** edge + integrity: an employee already holding this position is renewed, not hired onto it a second time. */
    public function test_an_employee_already_on_the_position_is_not_hired_onto_it_again(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        $holder = $this->makeEmployee($this->homs, 'seat.holder', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => $application->email_normalized, 'work_phone' => '0944555666']);
        Contract::query()->create([
            'employee_id' => $holder->id, 'position_id' => $offer->position_id, 'contract_type_id' => self::ANNUAL,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'currency' => Currency::USD, 'salary_per' => SalaryPer::Month,
            'salary' => 900, 'weekly_hours' => 40, 'is_active' => true,
        ]);
        $contracts = Contract::query()->count();

        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $holder->id]), 'employeeId', 'already_on_position');
        $this->assertSame([$contracts, Conversion::STATUS_PENDING], [Contract::query()->count(), $conversion->fresh()->status]);

        // On another position they can be hired: a second post is a real hire.
        [$other, , $elsewhere] = $this->accepted('Candidate One', [], $this->makeCampaignIn($this->homs));
        $other->applicant->forceFill(['phone_normalized' => '+963944555666'])->save();
        $this->hire($elsewhere, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $holder->id]);
        $this->assertSame($holder->id, $elsewhere->fresh()->employee_id);
    }

    /** security: only a matched employee can be linked, and only one in the city HR manages. */
    public function test_linking_is_limited_to_matched_employees_in_scope(): void
    {
        [$application, , $conversion] = $this->accepted();
        $stranger = $this->makeEmployee($this->homs, 'stranger');
        $elsewhere = $this->makeEmployee($this->makeCity('ALP', '001'), 'elsewhere', ['first_name_en' => 'Candidate', 'last_name_en' => 'One', 'personal_email' => $application->email_normalized]);

        // A tampered id links nobody.
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $stranger->id]), 'employeeId', 'employee_not_matched');
        // Matched, but in another city.
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $elsewhere->id]), 'employeeId', 'employee_out_of_scope');

        $this->assertNull($conversion->fresh()->contract_id);
        $this->assertSame(0, Contract::query()->count());
    }

    /**
     * The identity rule, as it happened on 2026-09-30: a candidate typed a
     * phone number that is an employee's under another name. HR is told
     * plainly, nothing offers to link them, and a new employee is created
     * without any tick.
     */
    public function test_a_phone_on_file_under_another_name_is_a_warning_never_a_link(): void
    {
        [$application, $offer, $conversion] = $this->accepted('فريال نعمة');
        $rafi = $this->makeEmployee($this->homs, 'rafi.louliki', [
            'first_name_en' => 'Rafi', 'last_name_en' => 'Louliki', 'first_name_ar' => 'رافي', 'last_name_ar' => 'لوليكي',
            'personal_phone' => '0993482692',
        ]);
        $application->applicant->forceFill(['phone_normalized' => '+963993482692'])->save();

        $this->assertSame([[$rafi->id, ['phone'], Conversions::MATCH_WARNING]],
            $this->conversions()->matches($conversion->fresh())->map(fn (array $match): array => [$match['employee']->id, $match['reasons'], $match['kind']])->all());

        // Said plainly; no employee to pick, no link, no tick.
        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSet('mode', Conversions::MODE_CREATE)
            ->assertSet('employeeId', '')
            ->assertSee(__('recruitment-applicant.conversions.warnings.phone', ['name' => $rafi->name]))
            ->assertSee(__('recruitment-applicant.conversions.no_linkable'))
            ->assertDontSeeHtml('wire:model.live="employeeId"')
            ->assertDontSee(__('recruitment-applicant.conversions.confirm_not_duplicate'));

        // Linking to him is refused, and creates nothing.
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $rafi->id]), 'employeeId', 'name_mismatch');
        $this->assertSame(0, Contract::query()->count());

        // A new employee, without any tick. The applicant is that employee, not him.
        $this->hire($conversion);
        $conversion->refresh();
        $this->assertNotSame($rafi->id, $conversion->employee_id);
        $this->assertSame($conversion->employee_id, $application->applicant->fresh()->employee_id);
        $this->assertSame(0, Contract::query()->where('employee_id', $rafi->id)->count());
        $this->assertSame([['employee_id' => $rafi->id, 'reasons' => ['phone']]],
            Activity::query()->where('description', 'recruitment_hiring_conversion.converted')->sole()->properties['identity_warnings']);
    }

    /** The identity rule: a national number on file under another name stops the hire, and nothing is created. */
    public function test_a_national_number_on_file_under_another_name_stops_the_hire(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $holder = $this->makeEmployee($this->homs, 'number.holder', ['national_number' => '09876543210']);
        $employees = Employee::query()->count();
        $mismatch = __('recruitment-applicant.conversions.errors.national_number_mismatch', ['name' => $holder->name]);

        $errors = $this->assertValidationError(fn () => $this->hire($conversion, ['employee' => $this->person(['nationalNumber' => '09876543210'])]), 'employee.nationalNumber');
        $this->assertSame($mismatch, $errors['employee.nationalNumber'][0]);
        $errors = $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => Conversions::MODE_LINK, 'employeeId' => (string) $holder->id, 'employee' => ['nationalNumber' => '09876543210']]), 'employeeId');
        $this->assertSame($mismatch, $errors['employeeId'][0]);

        $this->assertSame([$employees, 0], [Employee::query()->count(), Contract::query()->count()]);
        $this->assertSame([Conversion::STATUS_PENDING, 0], [$conversion->fresh()->status, $conversion->fresh()->attempt_count]);

        // The page says so as soon as the number is typed.
        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->set('employee.nationalNumber', '09876543210')
            ->assertSee(__('recruitment-applicant.conversions.id_conflict_title'))
            ->assertSee(__('recruitment-applicant.conversions.id_conflict', ['number' => '09876543210', 'name' => $holder->name]));
    }

    /** The identity rule: an employee_id the applicant carries without having signed in proves nothing. */
    public function test_an_employee_id_without_a_sign_in_is_not_an_internal_applicant(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        $wrong = $this->makeEmployee($this->homs, 'wrongly.linked');
        $application->applicant->forceFill(['employee_id' => $wrong->id, 'identity_verified_at' => null])->save();

        $this->assertTrue($this->conversions()->matches($conversion->fresh())->isEmpty());
        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSet('mode', Conversions::MODE_CREATE)
            ->assertSet('employeeId', '');

        // Not staff: created as usual, and from now on the applicant is the new employee.
        $this->hire($conversion);
        $employee = $conversion->fresh()->employee;
        $this->assertNotSame($wrong->id, $employee->id);
        $this->assertSame($employee->id, $application->applicant->fresh()->employee_id);
        $this->assertSame(0, Contract::query()->where('employee_id', $wrong->id)->count());
    }

    // ------------------------------------------------------------------
    // Once, and all or nothing
    // ------------------------------------------------------------------

    /** integrity + acceptance + avoid: the conversion runs once; a retry after a timeout returns the same hire. */
    public function test_the_conversion_runs_once_and_a_retry_returns_the_same_hire(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $first = $this->hire($conversion);

        $retry = $this->hire($conversion->fresh(), ['employee' => $this->person(['username' => 'someone.else', 'nationalNumber' => '01111111111'])]);

        $this->assertTrue($retry->is($first));
        $this->assertSame([1, 1, 1, 1], [
            Contract::query()->count(),
            Employee::query()->where('username', 'candidate.one')->count() + Employee::query()->where('username', 'someone.else')->count(),
            $offer->position->fresh()->exist_emp,
            $offer->vacancy->fresh()->filled_count,
        ]);
        $this->assertSame(1, $conversion->fresh()->attempt_count);
        $this->assertSame(1, Activity::query()->where('description', 'recruitment_hiring_conversion.converted')->count());
        $this->assertSame(1, OnboardingJourney::query()->count(), 'One onboarding journey, however often the hire is retried.');

        // The hire page shows what was done instead of the form.
        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->assertSee(__('recruitment-applicant.conversions.statuses.converted'))
            ->assertDontSee(__('recruitment-applicant.conversions.confirm_hint'));

        // A converted row is final, and nothing is deleted.
        $this->assertThrows(fn () => $conversion->fresh()->forceFill(['contract_id' => null])->save(), LogicException::class);
        $this->assertThrows(fn () => $conversion->fresh()->delete(), LogicException::class);
        $this->assertThrows(fn () => Conversion::query()->whereKey($conversion->id)->delete(), LogicException::class);

        // The database refuses a second conversion of the offer, or of the contract.
        $this->assertThrows(fn () => DB::table('recruitment_hiring_conversions')->insert([
            'job_offer_id' => $offer->id, 'job_offer_version_id' => $offer->current_version_id, 'applicant_id' => $conversion->applicant_id,
            'employee_payload_json' => '{}', 'status' => 'pending', 'started_at' => now(), 'converted_by_user_id' => $this->hr->id,
        ]), UniqueConstraintViolationException::class);
    }

    /** edge + integrity + avoid: the contract fails after the employee was created — nothing is left behind, and a retry works. */
    public function test_a_contract_that_fails_leaves_no_employee_behind(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        $employees = Employee::query()->count();
        $fail = true;
        Contract::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated failure while saving the contract.');
            }
        });

        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])
            ->set('employee', $this->person())
            ->call('save')
            ->assertHasErrors(['conversion']);

        // Rolled back as a whole.
        $this->assertSame($employees, Employee::query()->count());
        $this->assertSame(0, Contract::query()->count());
        $this->assertSame([0, 0], [$offer->position->fresh()->exist_emp, $offer->vacancy->fresh()->filled_count]);
        $this->assertSame(AppStatus::OfferAccepted, $application->fresh()->status);
        $this->assertNull($application->applicant->fresh()->employee_id);

        // …and recorded as a failed attempt.
        $this->assertSame([Conversion::STATUS_FAILED, 'unexpected', 1], [$conversion->fresh()->status, $conversion->fresh()->failure_reason, $conversion->fresh()->attempt_count]);
        $this->assertSame('unexpected', Activity::query()->where('description', 'recruitment_hiring_conversion.failed')->sole()->properties['reason']);

        $fail = false;
        $this->hire($conversion);
        $this->assertSame([Conversion::STATUS_CONVERTED, 2, null], [$conversion->fresh()->status, $conversion->fresh()->attempt_count, $conversion->fresh()->failure_reason]);
    }

    // ------------------------------------------------------------------
    // What stops a hire
    // ------------------------------------------------------------------

    /** edge: the offer was accepted but the vacancy has since been closed. */
    public function test_a_closed_vacancy_blocks_the_hire(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $offer->vacancy->forceFill(['status' => Vacancy::CLOSED, 'closed_at' => now(), 'close_reason' => 'On hold'])->save();

        $this->assertBlocked($conversion, 'vacancy_closed');
        $this->assertSame(['vacancy_closed'], $this->conversions()->blockers($conversion->fresh()));

        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])->assertSee(__('recruitment-applicant.conversions.errors.vacancy_closed'));
    }

    /** edge: the position was deactivated. */
    public function test_an_inactive_position_blocks_the_hire(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $offer->position->forceFill(['is_active' => false])->save();

        $this->assertBlocked($conversion, 'position_inactive');
    }

    /** edge: the position is at capacity; once a seat is freed, the retry goes through. */
    public function test_a_full_position_blocks_the_hire_until_a_seat_is_freed(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $offer->position->forceFill(['max_emp' => 1, 'exist_emp' => 1])->save();

        $this->assertBlocked($conversion, 'position_full');

        $offer->position->forceFill(['max_emp' => 2])->save();
        $this->hire($conversion);
        $this->assertSame([Conversion::STATUS_CONVERTED, 2], [$conversion->fresh()->status, $conversion->fresh()->attempt_count]);
        $this->assertSame(2, $offer->position->fresh()->exist_emp);
    }

    /** rules + avoid: a vacancy needing two people stays open after the first hire and is filled by the second — never closed early. */
    public function test_a_vacancy_needing_several_people_stays_open_until_every_seat_is_filled(): void
    {
        $vacancy = $this->campaign->vacancy;
        $vacancy->forceFill(['needed_count' => 2])->save();
        $first = $this->accepted('First One')[2];
        $second = $this->accepted('Second One')[2];

        $this->hire($first);
        $vacancy->refresh();
        $this->assertSame([Vacancy::OPEN, 1, null], [$vacancy->status, $vacancy->filled_count, $vacancy->closed_at]);
        $this->assertSame(HiringCampaignStatus::Open, $this->campaign->fresh()->status, 'Still recruiting for the second seat.');

        $this->hire($second, ['employee' => $this->person(['username' => 'second.one', 'nationalNumber' => '01234567899'])]);
        $vacancy->refresh();
        $this->assertSame([Vacancy::FILLED, 2], [$vacancy->status, $vacancy->filled_count]);
        $this->assertSame(HiringCampaignStatus::Closed, $this->campaign->fresh()->status);
    }

    /** edge: the vacancy filled up (its need was cut) before this accepted offer was converted. */
    public function test_a_vacancy_already_filled_blocks_the_hire(): void
    {
        $vacancy = $this->campaign->vacancy;
        $vacancy->forceFill(['needed_count' => 2])->save();
        $first = $this->accepted('First One')[2];
        $second = $this->accepted('Second One')[2];
        $vacancy->forceFill(['needed_count' => 1])->save();

        $this->hire($first);
        $this->assertBlocked($second, 'vacancy_full');
    }

    /** avoid: only the accepted version of an accepted offer is converted. */
    public function test_only_the_accepted_version_of_an_accepted_offer_is_converted(): void
    {
        // A conversion row pointing at an offer still with the candidate.
        $sent = $this->offers()->send($this->hr, $offer = $this->createOffer($this->selected()), $offer->current_version_id, null)->fresh();
        $forged = Conversion::query()->forceCreate([
            'job_offer_id' => $sent->id, 'job_offer_version_id' => $sent->current_version_id, 'applicant_id' => $sent->application->applicant_id,
            'employee_payload_json' => ['full_name' => 'Candidate One'], 'status' => Conversion::STATUS_PENDING, 'started_at' => now(), 'converted_by_user_id' => $this->hr->id,
        ]);
        $this->assertBlocked($forged, 'offer_not_accepted');

        // No conversion at all (a draft offer): no hire page.
        $draft = $this->createOffer($this->selected('Draft One'));
        $this->actingAs($this->hr);
        Livewire::test(Convert::class, ['offerId' => $draft->id])->assertNotFound();
    }

    /** avoid: an offer cannot promise terms the contract cannot hold, and an old one that does is refused, not bent. */
    public function test_offer_terms_that_cannot_be_a_contract_are_refused(): void
    {
        $application = $this->selected();
        $this->assertValidationError(fn () => $this->createOffer($application, ['endDate' => '2027-11-02']), 'endDate', 'end_date_too_far', 'offers');
        $this->assertValidationError(fn () => $this->createOffer($application, ['employmentType' => EmploymentType::Freelance]), 'employmentType', 'employment_type_mismatch', 'offers');
        $this->assertValidationError(fn () => $this->createOffer($application, ['contractTypeId' => self::SERVICE, 'employmentType' => EmploymentType::FullTime]), 'employmentType', 'employment_type_mismatch', 'offers');

        // One accepted before the rule: blocked at the hire, never silently shortened.
        [, $offer, $conversion] = $this->accepted('Legacy One', [], $this->makeCampaignIn($this->homs));
        DB::table('recruitment_job_offer_versions')->where('id', $offer->current_version_id)->update(['end_date' => '2028-11-01']);
        $this->assertBlocked($conversion, 'contract_term_invalid');
    }

    // ------------------------------------------------------------------
    // Security, audit, status
    // ------------------------------------------------------------------

    /** security: hiring needs its own permission, contracts.create, sight of pay, and the offer in scope. */
    public function test_hiring_needs_its_own_permission_pay_visibility_and_scope(): void
    {
        [, $offer, $conversion] = $this->accepted();
        $url = route('recruitment.offers.convert', ['offerId' => $offer->id]);

        // Nobody without recruitment rights.
        $employee = $this->makeUser($this->homs, 'employee');
        $this->actingAs($employee)->get($url)->assertForbidden();
        $this->assertThrows(fn () => $this->conversions()->convert($employee, $conversion, $this->input()), AuthorizationException::class);

        // super-admin holds every permission but is salary-blind: it cannot put a salary into a contract.
        $admin = $this->makeUser($this->homs, 'super-admin');
        $this->assertTrue($admin->hasActivePermission('recruitment-applicants.convert-hires'));
        $this->assertFalse($admin->can('convert', $conversion));
        $this->assertThrows(fn () => $this->conversions()->convert($admin, $conversion, $this->input()), AuthorizationException::class);

        // HR of another city does not even see the offer.
        $otherHr = $this->hr($this->makeCity('DMS', '003'));
        $this->assertFalse($otherHr->can('convert', $conversion));
        $this->actingAs($otherHr);
        Livewire::test(Convert::class, ['offerId' => $offer->id])->assertNotFound();

        // HR without contracts.create, then without the permission itself.
        $noContracts = $this->without($this->hr, ['contracts.create']);
        $this->assertThrows(fn () => $this->conversions()->convert($noContracts, $conversion, $this->input()), AuthorizationException::class);
        $noConvert = $this->without($this->hr, ['recruitment-applicants.convert-hires']);
        $this->actingAs($noConvert)->get($url)->assertForbidden();
        $this->assertThrows(fn () => $this->conversions()->convert($noConvert, $conversion, $this->input()), AuthorizationException::class);

        // A refusal records nothing and creates nothing.
        $this->assertSame([Conversion::STATUS_PENDING, 0, 0], [$conversion->fresh()->status, $conversion->fresh()->attempt_count, Contract::query()->count()]);
    }

    /** security: the new employee is validated like the HR employee form; a form mistake is not a failed attempt. */
    public function test_the_new_employee_is_validated_like_the_hr_employee_form(): void
    {
        [, , $conversion] = $this->accepted();
        $this->makeEmployee($this->homs, 'taken.name');

        $errors = $this->assertValidationError(fn () => $this->hire($conversion, ['employee' => array_fill_keys(array_keys($this->person()), '')]), 'employee.firstNameEn');
        foreach (['firstNameAr', 'lastNameEn', 'lastNameAr', 'fatherNameEn', 'fatherNameAr', 'motherNameEn', 'motherNameAr', 'nationality', 'nationalNumber', 'gender', 'maritalStatus', 'username'] as $key) {
            $this->assertArrayHasKey('employee.'.$key, $errors);
        }

        foreach ([
            'nationalNumber' => ['nationalNumber' => '123'],
            'username' => ['username' => 'Has Spaces'],
            'personalPhone' => ['personalPhone' => '944000000'],
            'personalEmail' => ['personalEmail' => 'not-an-email'],
            'dob' => ['dob' => '2030-01-01'],
        ] as $field => $bad) {
            $this->assertValidationError(fn () => $this->hire($conversion, ['employee' => $this->person($bad)]), 'employee.'.$field);
        }

        $this->assertValidationError(fn () => $this->hire($conversion, ['employee' => $this->person(['username' => 'taken.name'])]), 'employee.username', 'username_taken');
        $this->assertValidationError(fn () => $this->hire($conversion, ['mode' => 'merge']), 'mode', 'mode_required');

        $this->assertSame([Conversion::STATUS_PENDING, 0, 1], [$conversion->fresh()->status, $conversion->fresh()->attempt_count, Employee::query()->where('username', 'taken.name')->count()]);

        // A foreign national number is not held to the Syrian 11 digits.
        $this->hire($conversion, ['employee' => $this->person(['nationality' => (string) Nationality::Lebanese, 'nationalNumber' => 'LB-778899'])]);
        $this->assertSame('LB-778899', $conversion->fresh()->employee->national_number);
    }

    /** security: the audit names the hire and each failed attempt — never the salary. */
    public function test_the_audit_records_the_hire_without_the_salary(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        $offer->position->forceFill(['max_emp' => 0])->save();
        $this->assertBlocked($conversion, 'position_full');
        $offer->position->forceFill(['max_emp' => 2])->save();
        $this->hire($conversion);

        $entries = Activity::query()->where('log_name', Conversions::AUDIT_LOG)->orderBy('id')->get();
        $this->assertSame(['recruitment_hiring_conversion.failed', 'recruitment_hiring_conversion.converted'], $entries->pluck('description')->all());

        $converted = $entries->last();
        $this->assertTrue($converted->causer->is($this->hr));
        $this->assertSame([$offer->id, $application->id, Conversions::MODE_CREATE, $conversion->fresh()->employee_id, $conversion->fresh()->contract_id, Vacancy::FILLED, [$this->campaign->id]],
            [$converted->properties['job_offer_id'], $converted->properties['application_id'], $converted->properties['mode'], $converted->properties['employee_id'], $converted->properties['contract_id'], $converted->properties['vacancy_status'], $converted->properties['campaigns_closed']]);

        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('1500', json_encode($entry->properties));
        }
    }

    /** acceptance: "hired" is set only by the conversion, never from the decision panel. */
    public function test_hired_is_set_only_by_the_conversion(): void
    {
        [$application, , $conversion] = $this->accepted();
        $statuses = app(RecruitmentApplicationStatusService::class);

        $this->assertSame([], $statuses->targets($application->fresh()));
        $this->assertThrows(fn () => $statuses->change($this->hr, $application->fresh(), AppStatus::OfferAccepted, AppStatus::Hired, null), ValidationException::class);

        $this->hire($conversion);
        $decision = RecruitmentApplicationDecision::query()->where('application_id', $application->id)->latest('id')->first();
        $this->assertSame([AppStatus::OfferAccepted, AppStatus::Hired, RecruitmentApplicationStatusService::REASON_CONVERSION],
            [$decision->from_status, $decision->to_status, $decision->reason_code]);
    }

    /** acceptance: the offer page and the applicant page follow the hire: waiting, failed, hired. */
    public function test_the_offer_and_applicant_pages_follow_the_hire(): void
    {
        [$application, $offer, $conversion] = $this->accepted();
        // A second campaign on the same seat, published and not yet open.
        $scheduled = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $offer->vacancy_id, 'status' => HiringCampaignStatus::Published, 'link_mode' => HiringCampaign::INVITE_ONLY,
            'starts_at' => now()->addWeek(), 'ends_at' => now()->addMonth(), 'created_by_user_id' => $this->campaign->created_by_user_id,
        ]);
        $this->actingAs($this->hr);

        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee(__('recruitment-applicant.conversions.statuses.pending'))
            ->assertSee(__('recruitment-applicant.conversions.open'));
        $this->get(route('recruitment.offers.convert', ['offerId' => $offer->id]))
            ->assertOk()->assertSee(__('recruitment-applicant.conversions.title'))->assertSee('1,500.00');

        $offer->position->forceFill(['max_emp' => 0])->save();
        $this->assertBlocked($conversion, 'position_full');
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee(__('recruitment-applicant.conversions.statuses.failed'))
            ->assertSee(__('recruitment-applicant.conversions.errors.position_full'));

        $offer->position->forceFill(['max_emp' => 2])->save();
        $this->hire($conversion);
        $conversion->refresh();
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee(__('recruitment-applicant.conversions.statuses.converted'))
            ->assertSee(route('contracts.show', $conversion->contract_id))
            ->assertSee(route('employees.show', 'candidate.one'))
            ->assertSee(__('recruitment-applicant.conversions.campaigns_still_open', ['ids' => '#'.$scheduled->id]))
            ->assertDontSee(__('recruitment-applicant.conversions.open'));
        $this->assertSame(HiringCampaignStatus::Published, $scheduled->fresh()->status, 'Not ours to decide: HR is told instead.');
        Livewire::test(ApplicationOffer::class, ['applicationId' => $application->id])->assertSee(__('recruitment-applicant.statuses.hired'));

        // Someone who may follow offers but not hire sees the status, not the button.
        $viewer = $this->without($this->hr, ['recruitment-applicants.convert-hires']);
        $pending = $this->accepted('Pending One', [], $this->makeCampaignIn($this->homs))[1];
        $this->actingAs($viewer);
        Livewire::test(Show::class, ['offerId' => $pending->id])
            ->assertSee(__('recruitment-applicant.conversions.statuses.pending'))
            ->assertDontSee(__('recruitment-applicant.conversions.open'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function hr(City $city): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, [
            'recruitment-campaigns.view',
            'recruitment-campaigns.manage',
            'contracts.create',
            'employees.create',
            'employees.update',
            ...array_map(
                fn (string $action): string => "recruitment-applicants.{$action}",
                config('access-control.groups.recruitment-applicants'),
            ),
        ]);
    }

    private function without(User $user, array $permissions): User
    {
        Role::findByName($user->myRole(), 'web')->revokePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function offers(): RecruitmentJobOfferService
    {
        return app(RecruitmentJobOfferService::class);
    }

    private function conversions(): Conversions
    {
        return app(Conversions::class);
    }

    private function selected(string $name = 'Candidate One', ?HiringCampaign $campaign = null): RecruitmentApplication
    {
        // This helper starts after a completed interview; interview coverage
        // owns the earlier recruitment stages.
        $application = $this->applyTo($campaign ?? $this->campaign, $this->makeApplicant($name), ['status' => AppStatus::Interviewed]);
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $application, AppStatus::Interviewed, AppStatus::Selected, null);

        return $application->fresh();
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'contractTypeId' => self::TRIAL,
            'salary' => self::SALARY,
            'currency' => Currency::USD,
            'salaryPer' => SalaryPer::Month,
            'startDate' => '2026-11-01',
            'endDate' => '2027-10-31',
            'weeklyHours' => 40,
            'employmentType' => EmploymentType::FullTime,
            'place' => ContractPlace::Onsite,
            'probationDays' => 90,
            'notes' => 'Transport allowance included.',
        ], $overrides);
    }

    private function createOffer(RecruitmentApplication $application, array $overrides = []): Offer
    {
        return $this->offers()->create($this->hr, $application, $this->terms($overrides))->fresh();
    }

    /**
     * A selected candidate whose offer was sent and accepted — the pending
     * conversion Task 9 hands over.
     *
     * @return array{0: RecruitmentApplication, 1: Offer, 2: Conversion}
     */
    private function accepted(string $name = 'Candidate One', array $terms = [], ?HiringCampaign $campaign = null): array
    {
        $application = $this->selected($name, $campaign);
        $offer = $this->createOffer($application, $terms);
        $offer = $this->offers()->send($this->hr, $offer, $offer->current_version_id, null)->fresh();
        $offer = $this->offers()->recordResponse($this->hr, $offer, $offer->current_version_id, OfferStatus::Accepted, 'Accepted by phone.')->fresh();

        return [$application->fresh(), $offer, $offer->hiringConversion];
    }

    /**
     * An offer accepted before every term was required: its version lacks
     * some of them. Written straight to the table — the accepted version is
     * frozen for the model, and that is how such a row already is.
     *
     * @param  array<string, mixed>  $columns
     * @param  array<int, string>  $dropTerms  keys removed from terms_json
     */
    private function legacyTerms(Conversion $conversion, array $columns, array $dropTerms = []): Conversion
    {
        $row = DB::table('recruitment_job_offer_versions')->where('id', $conversion->job_offer_version_id)->first();
        $terms = array_diff_key(json_decode((string) $row->terms_json, true) ?: [], array_flip($dropTerms));

        DB::table('recruitment_job_offer_versions')->where('id', $row->id)->update([...$columns, 'terms_json' => json_encode($terms)]);

        return $conversion->fresh();
    }

    /** The new-employee form, filled in. */
    private function person(array $overrides = []): array
    {
        return array_merge([
            'firstNameEn' => 'Candidate', 'lastNameEn' => 'One',
            'firstNameAr' => 'مرشح', 'lastNameAr' => 'واحد',
            'fatherNameEn' => 'Father', 'fatherNameAr' => 'الأب',
            'motherNameEn' => 'Mother', 'motherNameAr' => 'الأم',
            'nationality' => (string) Nationality::Syrian,
            'nationalNumber' => '01234567890',
            'gender' => '1',
            'maritalStatus' => '1',
            'dob' => '1995-05-01',
            'personalPhone' => '0944000000',
            'personalEmail' => '',
            'username' => 'candidate.one',
        ], $overrides);
    }

    private function input(array $overrides = []): array
    {
        return array_merge(['mode' => Conversions::MODE_CREATE, 'employee' => $this->person()], $overrides);
    }

    private function hire(Conversion $conversion, array $overrides = []): Conversion
    {
        return $this->conversions()->convert($this->hr, $conversion->fresh(), $this->input($overrides));
    }

    /** Refused for $reason, recorded as a failed attempt, nothing created. */
    private function assertBlocked(Conversion $conversion, string $reason): void
    {
        $before = [Employee::query()->count(), Contract::query()->count(), (int) $conversion->fresh()->attempt_count];

        $this->assertValidationError(fn () => $this->hire($conversion), 'conversion', $reason);

        $conversion->refresh();
        $this->assertSame([Conversion::STATUS_FAILED, $reason, $before[2] + 1], [$conversion->status, $conversion->failure_reason, $conversion->attempt_count]);
        $this->assertSame([$before[0], $before[1]], [Employee::query()->count(), Contract::query()->count()]);
        $this->assertNull($conversion->contract_id);
    }

    /** @return array<string, array<int, string>> */
    private function assertValidationError(callable $action, string $field, ?string $errorKey = null, string $section = 'conversions'): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));
            if ($errorKey !== null) {
                $this->assertSame(__("recruitment-applicant.{$section}.errors.{$errorKey}"), $exception->errors()[$field][0]);
            }

            return $exception->errors();
        }

        $this->fail("Expected a validation error on '{$field}'.");
    }
}
