<?php

namespace Tests\Feature\Recruitment;

use App\Enums\ContractPlace;
use App\Enums\ContractType as EmploymentType;
use App\Enums\Currency;
use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentJobOfferStatus as Status;
use App\Enums\SalaryPer;
use App\Livewire\Recruitment\Applicants\ApplicationOffer;
use App\Livewire\Recruitment\Applicants\Show as ApplicantShow;
use App\Livewire\Recruitment\Offers\Form;
use App\Livewire\Recruitment\Offers\OfferTable;
use App\Livewire\Recruitment\Offers\Revise;
use App\Livewire\Recruitment\Offers\Show;
use App\Models\City;
use App\Models\ContractType;
use App\Models\HiringCampaign;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentJobOffer as Offer;
use App\Models\RecruitmentJobOfferVersion as Version;
use App\Models\Role;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentJobOfferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 9: the job-offer workflow.
 *
 * Each test names the spec section it covers: scope (نطاق التنفيذ /
 * المطلوب تنفيذه), rules (قواعد العمل), security (الأمان والصلاحيات), edge
 * (الحالات الاستثنائية), integrity (سلامة البيانات), acceptance (معايير
 * القبول), avoid (أخطاء يجب تجنبها).
 *
 * The clock is frozen on 2026-10-05 09:00 (Europe/Istanbul).
 */
class RecruitmentJobOfferTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const SALARY = '1500';

    private City $homs;

    private User $hr;

    private ContractType $contractType;

    private HiringCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));

        $this->homs = $this->makeCity('HMS', '002');
        $this->hr = $this->hr($this->homs);
        // An offer is a trial or a service contract, as on the recruitment request.
        $this->contractType = ContractType::query()->forceCreate(['id' => 3, 'name_en' => 'Trial Volunteer Contract', 'name_ar' => 'عقد تطوع تجريبي']);
        $this->campaign = $this->makeCampaignIn($this->homs);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    /** acceptance + scope: HR creates an offer for a selected candidate, with salary, start date, probation and contract details. */
    public function test_hr_creates_an_offer_for_a_selected_candidate(): void
    {
        $application = $this->selected();
        $this->actingAs($this->hr);

        Livewire::test(Form::class, ['applicationId' => $application->id])
            ->set('contractTypeId', $this->contractType->id)
            ->set('salary', self::SALARY)
            ->set('currency', Currency::USD)
            ->set('salaryPer', SalaryPer::Month)
            ->set('startDate', '2026-11-01')
            ->set('endDate', '2027-10-31')
            ->set('probationDays', 90)
            ->set('weeklyHours', 40)
            ->set('employmentType', EmploymentType::FullTime)
            ->set('place', ContractPlace::Onsite)
            ->set('notes', 'Transport allowance included.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $offer = Offer::query()->sole();
        $version = $offer->currentVersion;
        $this->assertSame([Status::Draft, 1, Version::STATUS_DRAFT], [$offer->status, $version->version_no, $version->status]);
        $this->assertSame([$application->id, $application->vacancy_id, $this->campaign->vacancy->position_id], [$offer->application_id, $offer->vacancy_id, $offer->position_id]);
        $this->assertSame('1500.00', $version->salary_amount);
        $this->assertSame([Currency::USD, SalaryPer::Month, 90, 40], [$version->currency, $version->salary_per, $version->probation_days, $version->weekly_hours]);
        $this->assertSame(['2026-11-01', '2027-10-31'], [$version->start_date->toDateString(), $version->end_date->toDateString()]);
        $this->assertSame($this->contractType->id, $version->contract_type_id);
        $this->assertSame(['employment_type' => EmploymentType::FullTime, 'place' => ContractPlace::Onsite, 'notes' => 'Transport allowance included.'], $version->terms_json);

        // A draft reaches nobody and leaves the application selected.
        $this->assertSame(AppStatus::Selected, $application->fresh()->status);

        // The offer page shows the terms to HR who may see pay.
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee('1,500.00')
            ->assertSee('2026-11-01')
            ->assertSee(__('recruitment-applicant.offers.days', ['count' => 90]))
            ->assertSee('Trial Volunteer Contract');
    }

    /** rules + avoid: an offer only for a selected candidate, and one offer per application. */
    public function test_an_offer_is_made_only_for_a_selected_candidate_and_only_once(): void
    {
        $statuses = app(RecruitmentApplicationStatusService::class);

        $submitted = $this->applyTo($this->campaign, $this->makeApplicant('Submitted One'));
        $this->assertValidationError(fn () => $this->create($submitted), 'offer', 'not_selected');

        $statuses->change($this->hr, $submitted, AppStatus::Submitted, AppStatus::Shortlisted, null);
        $this->assertValidationError(fn () => $this->create($submitted->fresh()), 'offer', 'not_selected');

        $statuses->change($this->hr, $submitted->fresh(), AppStatus::Shortlisted, AppStatus::Rejected, null, 'position_filled');
        $this->assertValidationError(fn () => $this->create($submitted->fresh()), 'offer', 'application_rejected');

        $selected = $this->selected('Selected One');
        $this->send($this->create($selected));
        // Its offer is out (offer_sent): the answer names the existing offer, not "not selected".
        $this->assertValidationError(fn () => $this->create($selected->fresh()), 'offer', 'already_exists');
        $this->assertSame(1, Offer::query()->count());

        // The database itself refuses a second offer row for the application.
        $this->assertThrows(fn () => DB::table('recruitment_job_offers')->insert([
            'application_id' => $selected->id, 'vacancy_id' => $selected->vacancy_id, 'position_id' => Offer::query()->value('position_id'),
            'status' => 'draft', 'created_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now(),
        ]), UniqueConstraintViolationException::class);
    }

    /** scope: every term is validated; bad dates are refused, not rolled over. */
    public function test_the_terms_are_validated(): void
    {
        $application = $this->selected();

        foreach ([
            ['salary', ['salary' => '0']], ['currency', ['currency' => 0]], ['salaryPer', ['salaryPer' => 9]],
            ['contractTypeId', ['contractTypeId' => 99999]], ['startDate', ['startDate' => '2026-02-30']],
            ['endDate', ['endDate' => '2026-10-31']], ['weeklyHours', ['weeklyHours' => 90]],
            ['employmentType', ['employmentType' => EmploymentType::Draft]],
        ] as [$field, $bad]) {
            $this->assertValidationError(fn () => $this->create($application, $bad), $field);
        }

        // Every field is required, the other terms (notes) and the probation included.
        $this->assertValidationError(fn () => $this->create($application, ['endDate' => '']), 'endDate', 'end_date');
        $this->assertValidationError(fn () => $this->create($application, ['weeklyHours' => '']), 'weeklyHours');
        $this->assertValidationError(fn () => $this->create($application, ['employmentType' => '']), 'employmentType', 'employment_type_required');
        $this->assertValidationError(fn () => $this->create($application, ['place' => '']), 'place', 'place_required');
        $this->assertValidationError(fn () => $this->create($application, ['notes' => '  ']), 'notes', 'notes_required');
        $this->assertValidationError(fn () => $this->create($application, ['probationDays' => '']), 'probationDays', 'probation_required');
        $this->assertSame(0, Offer::query()->count());
    }

    /** The dates are HR's to choose — a start already past included — on create, edit and send. */
    public function test_the_contract_dates_are_free_a_past_start_included(): void
    {
        $offer = $this->create($this->selected(), ['startDate' => '2026-09-01', 'endDate' => '2026-12-31']);
        $this->assertSame('2026-09-01', $offer->currentVersion->start_date->toDateString());

        $offer = $this->updateDraft($offer, ['startDate' => '2026-08-15', 'endDate' => '2027-08-14']);
        $offer = $this->send($offer);
        $this->assertSame([Status::Sent, '2026-08-15'], [$offer->status, $offer->currentVersion->start_date->toDateString()]);

        // The end still follows the start, within the year a contract may run.
        $this->assertValidationError(fn () => $this->create($this->selected('Other One'), ['startDate' => '2026-09-01', 'endDate' => '2026-08-01']), 'endDate', 'end_date');
    }

    /**
     * The probation is HR's to type, and is not checked: any whole number of
     * days is kept, anything else (or nothing) is left empty.
     */
    public function test_the_probation_is_typed_freely(): void
    {
        foreach ([['90', 90], ['400', 400], ['0', 0]] as $i => [$typed, $kept]) {
            $offer = $this->create($this->selected('Probation '.$i), ['probationDays' => $typed]);
            $this->assertSame($kept, $offer->currentVersion->probation_days, "typed '{$typed}'");
        }
        foreach (['', '-5', 'abc'] as $typed) {
            $this->assertValidationError(fn () => $this->create($this->selected('Probation '.$typed), ['probationDays' => $typed]), 'probationDays', 'probation_required');
        }

        // A draft keeps what was typed when it is opened again.
        $this->actingAs($this->hr);
        Livewire::test(Form::class, ['offerId' => Offer::query()->oldest('id')->value('id')])
            ->assertSet('probationDays', 90);
    }

    /** Only a trial or a service contract can be offered — the choices of the recruitment request. */
    public function test_only_trial_or_service_contracts_are_offered(): void
    {
        $volunteer = ContractType::query()->forceCreate(['id' => 1, 'name_en' => 'Volunteer Contract', 'name_ar' => 'عقد تطوع']);
        $service = ContractType::query()->forceCreate(['id' => 5, 'name_en' => 'Specific Voluntary Service Contract', 'name_ar' => 'عقد خدمة تطوعية']);

        $this->assertValidationError(fn () => $this->create($this->selected('Volunteer One'), ['contractTypeId' => $volunteer->id]), 'contractTypeId', 'contract_type_required');
        $offer = $this->create($this->selected('Service One'), ['contractTypeId' => $service->id, 'employmentType' => EmploymentType::Freelance]);
        $this->assertSame($service->id, $offer->currentVersion->contract_type_id);

        $this->actingAs($this->hr);
        Livewire::test(Form::class, ['applicationId' => $this->selected('Form One')->id])
            ->assertSeeHtml('value="'.$this->contractType->id.'"')
            ->assertSeeHtml('value="'.$service->id.'"')
            ->assertDontSee('>Volunteer Contract<', false);
    }

    // ------------------------------------------------------------------
    // The workflow
    // ------------------------------------------------------------------

    /** acceptance + scope: status tracking through send, refusal, revision, acceptance — and the application follows. */
    public function test_status_tracking_through_the_whole_workflow(): void
    {
        $application = $this->selected();
        $offer = $this->create($application);

        $offer = $this->send($offer);
        $this->assertSame([Status::Sent, Version::STATUS_SENT], [$offer->status, $offer->currentVersion->status]);
        $this->assertSame('2026-10-12', $offer->expires_at->toDateString(), 'Default validity is seven days.');
        $this->assertSame(AppStatus::OfferSent, $application->fresh()->status);

        $offer = $this->respond($offer, Status::Rejected, 'Wants a higher salary.');
        $this->assertSame([Status::Rejected, Version::STATUS_REJECTED], [$offer->status, $offer->currentVersion->status]);
        $this->assertSame(AppStatus::OfferRejected, $application->fresh()->status);

        $offer = $this->revise($offer, ['salary' => '1800']);
        $this->assertSame([Status::Revised, 2, Version::STATUS_DRAFT], [$offer->status, $offer->currentVersion->version_no, $offer->currentVersion->status]);
        $this->assertSame(AppStatus::OfferRejected, $application->fresh()->status, 'Nothing new reached the candidate yet.');

        $offer = $this->send($offer);
        $this->assertSame(AppStatus::OfferSent, $application->fresh()->status);

        $offer = $this->respond($offer, Status::Accepted);
        $this->assertSame(Status::Accepted, $offer->status);
        $this->assertSame(AppStatus::OfferAccepted, $application->fresh()->status);

        $this->assertSame(
            [
                [AppStatus::Interviewed, AppStatus::Selected],
                [AppStatus::Selected, AppStatus::OfferSent], [AppStatus::OfferSent, AppStatus::OfferRejected],
                [AppStatus::OfferRejected, AppStatus::OfferSent], [AppStatus::OfferSent, AppStatus::OfferAccepted],
            ],
            RecruitmentApplicationDecision::query()->where('application_id', $application->id)->orderBy('id')->get()
                ->map(fn ($d) => [$d->from_status, $d->to_status])->all(),
        );
        $this->assertSame(
            ['created', 'sent', 'rejected', 'revised', 'sent', 'accepted'],
            Activity::query()->where('log_name', RecruitmentJobOfferService::AUDIT_LOG)->orderBy('id')->pluck('description')
                ->map(fn ($d) => str_replace('recruitment_job_offer.', '', $d))->all(),
        );
    }

    /** acceptance + rules + output: accepting hands the offer over for conversion — once. avoid: never converted twice. */
    public function test_an_accepted_offer_is_handed_over_for_conversion_exactly_once(): void
    {
        $application = $this->selected();
        $offer = $this->respond($this->send($this->create($application)), Status::Accepted);

        $conversion = RecruitmentHiringConversion::query()->sole();
        $this->assertSame([$offer->id, $offer->current_version_id, $application->applicant_id, 'pending'], [$conversion->job_offer_id, $conversion->job_offer_version_id, $conversion->applicant_id, $conversion->status]);
        $this->assertSame($application->applicant->full_name, $conversion->employee_payload_json['full_name']);
        $this->assertStringNotContainsString('1500', json_encode($conversion->employee_payload_json), 'The payload names the person; the pay stays in the encrypted version.');

        // Accepting again, from a stale screen or a replayed request, is refused.
        $this->assertValidationError(fn () => $this->respond($offer, Status::Accepted), 'offer', 'already_accepted');
        $this->assertValidationError(fn () => $this->respond($offer, Status::Rejected), 'offer', 'already_accepted');
        $this->assertValidationError(fn () => $this->revise($offer, ['salary' => '2000']), 'offer', 'not_revisable');
        $this->assertValidationError(fn () => $this->cancel($offer), 'offer', 'not_cancellable');
        $this->assertSame(1, RecruitmentHiringConversion::query()->count());

        // And the database refuses a second conversion of the same offer.
        $this->assertThrows(fn () => DB::table('recruitment_hiring_conversions')->insert([
            'job_offer_id' => $offer->id, 'job_offer_version_id' => $offer->current_version_id, 'applicant_id' => $application->applicant_id,
            'employee_payload_json' => '{}', 'status' => 'pending', 'started_at' => now(), 'converted_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now(),
        ]), UniqueConstraintViolationException::class);
    }

    /** rules + acceptance + avoid: a refused, expired or cancelled offer never closes or fills the vacancy. */
    public function test_refused_expired_and_cancelled_offers_leave_the_vacancy_alone(): void
    {
        $vacancy = $this->campaign->vacancy;
        $before = $vacancy->only(['status', 'needed_count', 'filled_count']);

        $this->respond($this->send($this->create($this->selected('Refuses'))), Status::Rejected);
        $this->cancel($this->create($this->selected('Cancelled')));
        $this->send($this->create($this->selected('Expires')), '2026-10-06');
        $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));
        $this->artisan('recruitment:expire-offers')->assertSuccessful();

        $this->assertSame([Status::Rejected, Status::Cancelled, Status::Expired], Offer::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame($before, $vacancy->fresh()->only(['status', 'needed_count', 'filled_count']));
        $this->assertSame(1, app(RecruitmentJobOfferService::class)->seatsLeft($vacancy->fresh()));
    }

    /** rules: after a refusal HR can send a revised offer, or move on and choose another candidate. */
    public function test_after_a_refusal_hr_revises_or_chooses_another_candidate(): void
    {
        $first = $this->selected('First Choice');
        $refused = $this->respond($this->send($this->create($first)), Status::Rejected);

        // Choose another candidate: the first is rejected by hand (the only manual move from offer_rejected).
        $statuses = app(RecruitmentApplicationStatusService::class);
        $this->assertSame([AppStatus::Rejected], $statuses->targets($first->fresh()));
        $statuses->change($this->hr, $first->fresh(), AppStatus::OfferRejected, AppStatus::Rejected, null, 'stronger_candidates');
        $this->assertValidationError(fn () => $this->revise($refused), 'offer', 'not_selected');

        $second = $this->selected('Second Choice');
        $this->assertSame(Status::Accepted, $this->respond($this->send($this->create($second)), Status::Accepted)->status);
    }

    // ------------------------------------------------------------------
    // Exceptional cases
    // ------------------------------------------------------------------

    /** edge: once selected, the candidate moves through offers rather than back to screening. */
    public function test_a_selected_candidate_cannot_be_manually_rejected_or_sent_back_before_offer(): void
    {
        $statuses = app(RecruitmentApplicationStatusService::class);

        $rejected = $this->selected('Rejected Later');
        $draft = $this->create($rejected);
        $this->assertValidationError(
            fn () => $statuses->change($this->hr, $rejected->fresh(), AppStatus::Selected, AppStatus::Rejected, 'References came back negative.', 'stronger_candidates'),
            'statusTarget',
        );

        $draft->refresh();
        $this->assertSame([Status::Draft, null], [$draft->status, $draft->cancellation_reason]);
        $this->assertSame(Version::STATUS_DRAFT, $draft->currentVersion->status);

        $backToShortlist = $this->selected('Back To Shortlist');
        $second = $this->create($backToShortlist);
        $this->assertValidationError(
            fn () => $statuses->change($this->hr, $backToShortlist->fresh(), AppStatus::Selected, AppStatus::Shortlisted, 'Wait for the second round.'),
            'statusTarget',
        );
        $this->assertSame(Status::Draft, $second->fresh()->status);
    }

    /** edge + avoid: HR changes the salary — a draft is edited (audited); a sent offer is only ever revised into a new version. */
    public function test_hr_changes_the_salary_after_creating_the_offer(): void
    {
        $offer = $this->create($this->selected());

        $offer = $this->updateDraft($offer, ['salary' => '1600']);
        $this->assertSame('1600.00', $offer->currentVersion->salary_amount);
        $this->assertSame(1, $offer->versions()->count());
        $audit = Activity::query()->where('description', 'recruitment_job_offer.draft_updated')->sole();
        $this->assertSame(['salary_amount'], $audit->properties['changed_fields']);
        $this->assertStringNotContainsString('1600', json_encode($audit->properties));

        $offer = $this->send($offer);
        $this->assertValidationError(fn () => $this->updateDraft($offer, ['salary' => '1700']), 'offer', 'not_editable');

        $offer = $this->send($this->revise($offer, ['salary' => '1700']));
        [$v1, $v2] = $offer->versions()->orderBy('version_no')->get()->all();
        $this->assertSame(['1600.00', Version::STATUS_SUPERSEDED], [$v1->salary_amount, $v1->status], 'The version the candidate had is kept as it was.');
        $this->assertSame(['1700.00', Version::STATUS_SENT], [$v2->salary_amount, $v2->status]);
        $this->assertSame(AppStatus::OfferSent, $offer->application->fresh()->status);
    }

    /** edge: the offer expires — by the sweep, or when an answer comes in too late. */
    public function test_an_offer_expires(): void
    {
        $application = $this->selected();
        $offer = $this->send($this->create($application), '2026-10-08');

        $this->assertValidationError(fn () => $this->send($offer, '2026-10-05'), 'expiresOn');
        $this->assertValidationError(fn () => $this->send($offer, '2027-10-05'), 'expiresOn');

        // Late answer, sweep not run yet: expired on the spot, answer refused.
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00'));
        $this->assertValidationError(fn () => $this->respond($offer, Status::Accepted), 'offer', 'expired');
        $offer->refresh();
        $this->assertSame([Status::Expired, Version::STATUS_EXPIRED], [$offer->status, $offer->currentVersion->status]);
        $this->assertSame(AppStatus::Selected, $application->fresh()->status);
        $this->assertSame(0, RecruitmentHiringConversion::query()->count());

        // A new version can go out after an expiry.
        $this->assertSame(Status::Sent, $this->send($this->revise($offer), '2026-10-15')->status);

        // The sweep.
        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));
        $this->artisan('recruitment:expire-offers')->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame(Status::Expired, $offer->fresh()->status);
        $this->assertTrue(Activity::query()->where('description', 'recruitment_job_offer.expired')->exists());
    }

    /** edge: the candidate refuses. */
    public function test_the_candidate_refuses(): void
    {
        $application = $this->selected();
        $offer = $this->respond($this->send($this->create($application)), Status::Rejected, 'Accepted another job.');

        $this->assertSame([Status::Rejected, 'Accepted another job.', $this->hr->id], [$offer->status, $offer->response_note, $offer->response_recorded_by_user_id]);
        $this->assertSame(AppStatus::OfferRejected, $application->fresh()->status);
        $this->assertSame(0, RecruitmentHiringConversion::query()->count());
    }

    /** edge: the vacancy fills up before an offer is accepted. */
    public function test_a_vacancy_filled_before_acceptance_refuses_the_acceptance(): void
    {
        $first = $this->send($this->create($this->selected('First')));
        $second = $this->send($this->create($this->selected('Second')));

        // Two offers out for one seat: the first acceptance takes it.
        $this->respond($first, Status::Accepted);
        $this->assertValidationError(fn () => $this->respond($second, Status::Accepted), 'offer', 'vacancy_full');
        $this->assertSame(Status::Sent, $second->fresh()->status);
        $this->respond($second, Status::Rejected);

        // A second seat opens, a third offer goes out — then the vacancy is
        // filled or closed by other means before the answer: refused as well.
        $vacancy = $this->campaign->vacancy;
        $vacancy->forceFill(['needed_count' => 2])->save();
        $third = $this->send($this->create($this->selected('Third')));
        $vacancy->forceFill(['filled_count' => 1])->save();
        $this->assertValidationError(fn () => $this->respond($third, Status::Accepted), 'offer', 'vacancy_full');
        $vacancy->forceFill(['filled_count' => 0, 'status' => Vacancy::CLOSED])->save();
        $this->assertValidationError(fn () => $this->respond($third, Status::Accepted), 'offer', 'vacancy_full');
        $this->assertSame(Status::Sent, $third->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------

    /** security + acceptance: creating an offer needs its permission (and the right to see pay). */
    public function test_creating_an_offer_needs_a_clear_permission(): void
    {
        $application = $this->selected();
        $employee = $this->makeUser($this->homs, 'employee');

        $this->actingAs($employee)->get(route('recruitment.offers.create', ['applicationId' => $application->id]))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->create($employee, $application, $this->terms()), AuthorizationException::class);

        // HR who can follow offers but not write them.
        $viewer = $this->without($this->hr, ['recruitment-applicants.manage-offers']);
        $this->actingAs($viewer)->get(route('recruitment.offers.create', ['applicationId' => $application->id]))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->create($viewer, $application, $this->terms()), AuthorizationException::class);

        // super-admin holds every permission but is salary-blind by design, so cannot write an offer.
        $admin = $this->makeUser($this->homs, 'super-admin');
        $this->assertTrue($admin->hasActivePermission('recruitment-applicants.manage-offers'));
        $this->assertThrows(fn () => $this->service()->create($admin, $application, $this->terms()), AuthorizationException::class);
        $this->actingAs($admin);
        Livewire::test(Form::class, ['applicationId' => $application->id])->assertForbidden();

        $this->assertSame(0, Offer::query()->count());
    }

    /** security + avoid: salary and contract details are hidden from anyone not allowed to see them. */
    public function test_the_salary_is_never_shown_to_a_user_who_may_not_see_pay(): void
    {
        $offer = $this->send($this->create($this->selected()));

        // Encrypted at rest.
        $raw = DB::table('recruitment_job_offer_versions')->where('id', $offer->current_version_id)->value('salary_amount');
        $this->assertStringNotContainsString('1500', (string) $raw);

        // super-admin follows the offer, but sees no figure.
        $admin = $this->makeUser($this->homs, 'super-admin');
        $this->actingAs($admin);
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertOk()
            ->assertSee(__('recruitment-applicant.offers.salary_hidden'))
            ->assertDontSee('1,500.00')
            ->assertDontSee('1500');
        Livewire::test(OfferTable::class)->assertOk()->assertDontSee('1,500');

        // Never serialized, never in the audit trail.
        $this->assertArrayNotHasKey('salary_amount', $offer->currentVersion->fresh()->toArray());
        $this->assertArrayNotHasKey('terms_json', $offer->currentVersion->fresh()->toArray());
        foreach (Activity::query()->where('log_name', RecruitmentJobOfferService::AUDIT_LOG)->get() as $entry) {
            $this->assertStringNotContainsString('1500', json_encode($entry->properties));
        }

        // Users without view-offers (and not the position's requester) do not see the offer at all.
        $reader = $this->without($this->hr, ['recruitment-applicants.view-offers']);
        $this->actingAs($reader)->get(route('recruitment.offers.show', ['offerId' => $offer->id]))->assertNotFound();
        Livewire::test(ApplicationOffer::class, ['applicationId' => $offer->application_id])->assertDontSee(__('recruitment-applicant.offers.job_offer'));
    }

    /** security: an offer outside the user's scope is not shown. */
    public function test_offers_outside_scope_are_not_shown(): void
    {
        $offer = $this->create($this->selected('Homs Candidate'));
        $otherHr = $this->hr($this->makeCity('DMS', '003'));

        $this->actingAs($otherHr);
        Livewire::test(Show::class, ['offerId' => $offer->id])->assertNotFound();
        Livewire::test(OfferTable::class)->assertDontSee('Homs Candidate');
        $this->assertThrows(fn () => $this->service()->send($otherHr, $offer, $offer->current_version_id, null), AuthorizationException::class);

        $this->actingAs($this->hr);
        Livewire::test(OfferTable::class)->assertSee('Homs Candidate');
    }

    /**
     * The manager who requested the position reads its offers — the list and
     * each offer page, with the link to the applicant — and nothing more: no
     * pay figure, no action, and no other position's offer.
     */
    public function test_the_requester_reads_his_positions_offers_only(): void
    {
        $application = $this->selected();
        $offer = $this->send($this->create($application));
        $requester = User::query()->findOrFail($application->vacancy->createdFromRequest->requester_user_id);
        $elsewhere = $this->create($this->selected('Other Position', $this->makeCampaignIn($this->homs)));

        $this->assertTrue($requester->can('viewAny', Offer::class));
        $this->assertSame([$offer->id], Offer::query()->visibleTo($requester)->pluck('id')->all());

        $this->actingAs($requester);
        $this->get(route('recruitment.offers.index'))->assertOk();
        $this->get(route('recruitment.offers.show', ['offerId' => $offer->id]))->assertOk();
        Livewire::test(OfferTable::class)->assertSee('Candidate One')->assertDontSee('Other Position');
        $applicantLink = route('recruitment.applicants.show', ['applicantId' => $application->applicant_id]);
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee($applicantLink)
            ->assertSee(__('recruitment-applicant.offers.salary_hidden'))
            ->assertDontSee('1,500.00')
            ->assertDontSee(route('recruitment.offers.revise', ['offerId' => $offer->id]))
            ->call('beginCancel')->assertForbidden();
        $this->get(route('recruitment.offers.revise', ['offerId' => $offer->id]))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->cancel($requester, $offer->fresh(), $offer->current_version_id, 'another_candidate_chosen', null), AuthorizationException::class);
        Livewire::test(Show::class, ['offerId' => $elsewhere->id])->assertNotFound();

        // The link is shown; the applicant page itself keeps its own permission (Team 1's), untouched here.
        $this->assertTrue($requester->can('viewApplicant', $offer));
        $this->get($applicantLink)->assertForbidden();

        // HR in scope still gets the link, as before.
        $this->assertTrue($this->hr->can('viewApplicant', $offer));

        // Another manager reaches nothing.
        $manager = $this->makeUser($this->homs, 'manager');
        $this->actingAs($manager);
        $this->assertFalse($manager->can('viewAny', Offer::class));
        $this->assertFalse($manager->can('viewApplicant', $offer));
        Livewire::test(Show::class, ['offerId' => $offer->id])->assertNotFound();
    }

    /**
     * Option A: the requester sees the salary only when granted
     * view-offer-salary (to his role or to him), only for his own positions,
     * and only for versions that were sent — never a draft HR is still on.
     * He still writes nothing.
     */
    public function test_the_requester_with_view_offer_salary_sees_the_pay_of_sent_versions_only(): void
    {
        $application = $this->selected();
        $requester = User::query()->findOrFail($application->vacancy->createdFromRequest->requester_user_id);
        $draft = $this->create($application);
        $this->actingAs($requester);

        // Without the permission: hidden, as for any manager.
        $this->send($draft);
        Livewire::test(Show::class, ['offerId' => $draft->id])->assertDontSee('1,500.00')->assertSee(__('recruitment-applicant.offers.salary_hidden'));

        $requester->givePermissionTo('recruitment-applicants.view-offer-salary');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $requester = $requester->fresh();
        $this->actingAs($requester);

        // Sent: the figure shows.
        $offer = $draft->fresh();
        $this->assertTrue($requester->can('viewSalary', $offer));
        Livewire::test(Show::class, ['offerId' => $offer->id])->assertSee('1,500.00');

        // Refused, then revised with a new figure still in draft: the draft stays hidden, the sent version stays visible.
        $offer = $this->revise($this->respond($offer, Status::Rejected), ['salary' => '1650']);
        $this->assertFalse($requester->can('viewSalary', $offer));
        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->assertSee('1,500.00')
            ->assertDontSee('1,650.00')
            ->assertSee(__('recruitment-applicant.offers.salary_hidden'));

        // Once that revision is sent, its figure shows too.
        $offer = $this->send($offer);
        Livewire::test(Show::class, ['offerId' => $offer->id])->assertSee('1,650.00');

        // Seeing pay never means writing an offer.
        $this->assertFalse($requester->can('manage', $offer));
        Livewire::test(Show::class, ['offerId' => $offer->id])->call('beginCancel')->assertForbidden();

        // The permission alone is not enough: another manager holding it sees nothing of this position.
        $manager = $this->makeUser($this->homs, 'manager', ['recruitment-applicants.view-offer-salary']);
        $this->assertFalse($manager->can('viewSalary', $offer));
        $this->actingAs($manager);
        Livewire::test(Show::class, ['offerId' => $offer->id])->assertNotFound();
    }

    /** The offer form's dropdowns are the project's Select2 (x-forms.select2-input); none is a plain select. */
    public function test_the_offer_form_uses_select2_for_every_dropdown(): void
    {
        $this->actingAs($this->hr);
        $html = Livewire::test(Form::class, ['applicationId' => $this->selected()->id])->html();

        foreach (['currency', 'salaryPer', 'contractTypeId', 'employmentType', 'place'] as $id) {
            $this->assertMatchesRegularExpression('/<select x-ref="select"[^>]*id="'.$id.'"/', $html, $id.' is a Select2 dropdown');
        }
        $this->assertSame(substr_count($html, '<select'), substr_count($html, '<select x-ref="select"'), 'No plain select is left.');
        $this->assertStringContainsString('initSelect2()', $html);

        // Searchable, as on the Leaves page: the component's search box is never switched off.
        $this->assertSame(5, substr_count($html, 'minimumResultsForSearch: 0'));
        $this->assertStringNotContainsString('minimumResultsForSearch: Infinity', $html);
    }

    /** security: there is no public offer endpoint; if one comes later it needs its own token, not an id. */
    public function test_no_offer_is_reachable_without_signing_in(): void
    {
        $offer = $this->create($this->selected());

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'offer')) {
                $this->assertContains('auth', $route->gatherMiddleware(), $route->uri().' must require sign-in');
            }
        }

        auth()->logout();
        $this->get(route('recruitment.offers.show', ['offerId' => $offer->id]))->assertRedirect();
    }

    // ------------------------------------------------------------------
    // Integrity
    // ------------------------------------------------------------------

    /** integrity + scope: offer status transitions are controlled; illegal moves and stale screens are refused. */
    public function test_offer_status_transitions_are_controlled(): void
    {
        foreach (Status::values() as $from) {
            foreach (Status::values() as $to) {
                $expected = in_array([$from, $to], [
                    [Status::Draft, Status::Sent], [Status::Draft, Status::Cancelled],
                    [Status::Sent, Status::Accepted], [Status::Sent, Status::Rejected], [Status::Sent, Status::Expired],
                    [Status::Sent, Status::Cancelled], [Status::Sent, Status::Revised],
                    [Status::Revised, Status::Sent], [Status::Revised, Status::Cancelled],
                    [Status::Rejected, Status::Revised], [Status::Expired, Status::Revised], [Status::Cancelled, Status::Revised],
                ], true);
                $this->assertSame($expected, Status::canMove($from, $to), "$from -> $to");
            }
        }

        $offer = $this->create($this->selected());
        $this->assertValidationError(fn () => $this->respond($offer, Status::Accepted), 'offer', 'not_sent');
        $this->assertValidationError(fn () => $this->revise($offer), 'offer', 'not_revisable');
        $this->assertValidationError(fn () => $this->service()->send($this->hr, $offer, $offer->current_version_id + 99, null), 'offer', 'stale');
        $this->assertValidationError(fn () => $this->service()->recordResponse($this->hr, $offer, $offer->current_version_id, 'maybe', null), 'response');

        $sent = $this->send($offer);
        $this->assertValidationError(fn () => $this->send($sent), 'offer', 'not_sendable');
        $revised = $this->revise($sent, ['salary' => '1900']);
        // A screen still showing version 1 cannot record an answer against version 2's offer.
        $this->assertValidationError(fn () => $this->service()->recordResponse($this->hr, $revised, $sent->current_version_id, Status::Accepted, null), 'offer', 'stale');
        $this->assertSame(Status::Revised, $revised->fresh()->status);
    }

    /** integrity + avoid: every version is kept, a sent version is a frozen snapshot, and nothing is deleted. */
    public function test_versions_are_snapshots_and_nothing_is_deleted(): void
    {
        $offer = $this->send($this->create($this->selected()));
        $sentVersion = $offer->currentVersion;

        $this->assertThrows(fn () => $sentVersion->forceFill(['salary_amount' => '9999'])->save(), LogicException::class);
        $this->assertThrows(fn () => $sentVersion->forceFill(['start_date' => '2027-01-01'])->save(), LogicException::class);
        $this->assertThrows(fn () => $sentVersion->delete(), LogicException::class);
        $this->assertThrows(fn () => $offer->delete(), LogicException::class);
        $this->assertThrows(fn () => Offer::query()->whereKey($offer->id)->delete(), LogicException::class);
        $this->assertSame('1500.00', $sentVersion->fresh()->salary_amount);

        $offer = $this->cancel($this->revise($offer, ['salary' => '2000', 'startDate' => '2026-12-01']));
        $this->assertSame(2, $offer->versions()->count());
        [$v1, $v2] = $offer->versions()->orderBy('version_no')->get()->all();
        $this->assertSame(['1500.00', '2026-11-01'], [$v1->salary_amount, $v1->start_date->toDateString()]);
        $this->assertSame(['2000.00', '2026-12-01'], [$v2->salary_amount, $v2->start_date->toDateString()]);
        $this->assertSame(Version::STATUS_CANCELLED, $v2->status);
        $this->assertSame('long enough reason to revise', $v2->revision_reason);
    }

    /** integrity: the offer-driven application statuses cannot be set by hand in the decision panel. */
    public function test_offer_driven_transitions_cannot_be_made_by_hand(): void
    {
        $statuses = app(RecruitmentApplicationStatusService::class);
        $application = $this->selected();

        $this->assertNotContains(AppStatus::OfferSent, $statuses->targets($application));
        $this->assertValidationError(fn () => $statuses->change($this->hr, $application, AppStatus::Selected, AppStatus::OfferSent, null), 'statusTarget');
        $this->actingAs($this->hr);
        Livewire::test(ApplicantShow::class, ['applicantId' => $application->applicant_id])
            ->call('beginStatusChange', $application->id, AppStatus::OfferSent)
            ->assertForbidden();

        $this->send($this->create($application));
        $this->assertSame([], $statuses->targets($application->fresh()), 'Nothing manual while the offer is with the candidate.');
        $this->assertValidationError(fn () => DB::transaction(fn () => $statuses->applyOfferTransition($this->hr, $application->fresh(), AppStatus::Hired)), 'statusTarget');
    }

    // ------------------------------------------------------------------
    // UI
    // ------------------------------------------------------------------

    /** scope: the offer page drives send, answer and cancel; the applicant profile links to it. */
    public function test_the_offer_page_and_the_applicant_profile(): void
    {
        $application = $this->selected();
        $this->actingAs($this->hr);

        Livewire::test(ApplicationOffer::class, ['applicationId' => $application->id])
            ->assertSee(route('recruitment.offers.create', ['applicationId' => $application->id]));

        $offer = $this->create($application);
        Livewire::test(ApplicationOffer::class, ['applicationId' => $application->id])
            ->assertSee(route('recruitment.offers.show', ['offerId' => $offer->id]))
            ->assertSee(__('recruitment-applicant.offers.statuses.draft'));

        Livewire::test(Show::class, ['offerId' => $offer->id])
            ->call('beginSend')
            ->assertSet('expiresOn', '2026-10-12')
            ->call('send')
            ->assertHasNoErrors()
            ->call('beginResponse', Status::Accepted)
            ->set('responseNote', 'Accepted by phone.')
            ->call('recordResponse')
            ->assertHasNoErrors()
            ->assertSee(__('recruitment-applicant.offers.statuses.accepted'));
        $this->assertSame(Status::Accepted, $offer->fresh()->status);

        Livewire::test(Show::class, ['offerId' => $offer->id])->call('beginResponse', 'maybe')->assertForbidden();

        // Point 1: each action opens one popup (<x-modal.dialog>); a mistake keeps it open, success closes it.
        // Point 2: the offer view no longer prints.
        $other = $this->create($this->selected('Other'));
        Livewire::test(Show::class, ['offerId' => $other->id])
            ->assertDontSeeHtml('window.print()')
            ->assertSet('panelOpen', false)
            ->call('beginCancel')
            ->assertSet('panelOpen', true)
            ->assertSeeHtml('id="offerActionModal"')
            ->call('cancelOffer')
            ->assertHasErrors('cancelReason')
            ->assertSet('panelOpen', true)
            ->set('cancelReason', 'another_candidate_chosen')
            ->call('cancelOffer')
            ->assertHasNoErrors()
            ->assertSet('panelOpen', false);
        $this->assertSame(Status::Cancelled, $other->fresh()->status);

        Livewire::test(Revise::class, ['offerId' => $other->id])
            ->assertSet('salary', '1500.00')
            ->set('salary', '1650')
            ->call('save')
            ->assertHasErrors('reason')
            ->set('reason', 'Candidate came back and is available again.')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame([Status::Revised, '1650.00'], [$other->fresh()->status, $other->fresh()->currentVersion->salary_amount]);
    }

    /**
     * scope: the real URLs HR uses — create, edit a draft, revise — open
     * through the router, not only through Livewire::test(). Regression: the
     * revise route passed its parameters to mount() in the wrong places.
     */
    public function test_hr_opens_the_create_edit_and_revise_pages_by_url(): void
    {
        $application = $this->selected();
        $this->actingAs($this->hr);

        $this->get(route('recruitment.offers.create', ['applicationId' => $application->id]))
            ->assertOk()->assertSee(__('recruitment-applicant.offers.form_title.create'));

        $draft = $this->create($application);
        $this->get(route('recruitment.offers.edit', ['offerId' => $draft->id]))
            ->assertOk()->assertSee(__('recruitment-applicant.offers.form_title.edit'));

        $sent = $this->send($draft);
        $this->get(route('recruitment.offers.revise', ['offerId' => $sent->id]))
            ->assertOk()->assertSee(__('recruitment-applicant.offers.form_title.revise'))->assertSee('1500.00');
        $this->get(route('recruitment.offers.edit', ['offerId' => $sent->id]))->assertNotFound();
        $this->get(route('recruitment.offers.show', ['offerId' => $sent->id]))->assertOk();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function hr(City $city): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, [
            'recruitment-campaigns.view',
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

    private function service(): RecruitmentJobOfferService
    {
        return app(RecruitmentJobOfferService::class);
    }

    /** A selected application on the Homs campaign, moved there through the decision service. */
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
            'contractTypeId' => $this->contractType->id,
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

    private function create(RecruitmentApplication $application, array $overrides = []): Offer
    {
        return $this->service()->create($this->hr, $application, $this->terms($overrides))->fresh();
    }

    private function updateDraft(Offer $offer, array $overrides): Offer
    {
        $offer = $offer->fresh();

        return $this->service()->updateDraft($this->hr, $offer, $offer->current_version_id, $this->terms($overrides))->fresh();
    }

    private function send(Offer $offer, ?string $expiresOn = null): Offer
    {
        $offer = $offer->fresh();

        return $this->service()->send($this->hr, $offer, $offer->current_version_id, $expiresOn)->fresh();
    }

    private function revise(Offer $offer, array $overrides = []): Offer
    {
        $offer = $offer->fresh();

        return $this->service()->revise($this->hr, $offer, $offer->current_version_id, $this->terms($overrides), 'long enough reason to revise')->fresh();
    }

    private function respond(Offer $offer, string $response, ?string $note = null): Offer
    {
        $offer = $offer->fresh();

        return $this->service()->recordResponse($this->hr, $offer, $offer->current_version_id, $response, $note)->fresh();
    }

    private function cancel(Offer $offer): Offer
    {
        $offer = $offer->fresh();

        return $this->service()->cancel($this->hr, $offer, $offer->current_version_id, 'created_in_error', null)->fresh();
    }

    /** @return array<string, array<int, string>> */
    private function assertValidationError(callable $action, string $field, ?string $errorKey = null): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));
            if ($errorKey !== null) {
                $this->assertSame(__('recruitment-applicant.offers.errors.'.$errorKey), $exception->errors()[$field][0]);
            }

            return $exception->errors();
        }

        $this->fail("Expected a validation error on '{$field}'.");
    }
}
