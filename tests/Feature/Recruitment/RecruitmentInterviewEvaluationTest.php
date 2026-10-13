<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Livewire\Recruitment\Applicants\Show as ApplicantShow;
use App\Livewire\Recruitment\Interviews\EvaluationForm;
use App\Livewire\Recruitment\Interviews\EvaluationSummary;
use App\Livewire\Recruitment\Interviews\Index;
use App\Livewire\Recruitment\Interviews\Show;
use App\Mail\RecruitmentInterviewMail;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation as Evaluation;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RecruitmentInterviewParticipantNotice as ParticipantNotice;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewNoticeService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
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
 * Phase 2 Task 8: interview evaluations.
 *
 * Each test names the spec section it covers: نطاق التنفيذ / المطلوب تنفيذه
 * (scope), قواعد العمل (rules), الأمان والصلاحيات (security), الحالات
 * الاستثنائية (edge), سلامة البيانات (integrity), معايير القبول (acceptance),
 * أخطاء يجب تجنبها (avoid).
 *
 * The clock is frozen on 2026-10-05 09:00 (Europe/Istanbul). The interview is
 * on 2026-10-06 at 10:00; most tests move to 11:00 that day to evaluate it.
 */
class RecruitmentInterviewEvaluationTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const PROS_A = 'A-PROS strong Excel modelling';

    private const CONS_A = 'A-CONS weak on donor reporting';

    private const NOTES_B = 'B-NOTES asked good questions about the budget';

    private City $homs;

    private User $hr;

    private User $reviewerA;

    private User $reviewerB;

    private RecruitmentApplication $application;

    private RecruitmentInterview $interview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));

        $this->homs = $this->makeCity('HMS', '002');
        $this->hr = $this->hr($this->homs);
        $this->reviewerA = $this->makeUser($this->homs, 'employee');
        $this->reviewerB = $this->makeUser($this->homs, 'manager');

        $this->application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant('Candidate One'));
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $this->application, AppStatus::Submitted, AppStatus::Shortlisted, null);
        $this->interview = $this->scheduleInterview($this->application->fresh());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Acceptance / scope
    // ------------------------------------------------------------------

    /** acceptance: participant adds an evaluation; pros/cons/notes/recommendation are saved. scope: form, fields. */
    public function test_a_participant_adds_an_evaluation_through_the_form(): void
    {
        $this->atInterviewTime();
        $this->actingAs($this->reviewerA);

        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])
            ->assertSee(__('recruitment-applicant.evaluations.my_title'))
            ->set('recommendation', Recommendation::Yes)
            ->set('pros', self::PROS_A)
            ->set('cons', self::CONS_A)
            ->set('notes', '  trimmed note  ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('expectedVersion', 1);

        $evaluation = Evaluation::query()->sole();
        $this->assertSame($this->interview->id, $evaluation->interview_id);
        $this->assertSame($this->reviewerA->id, $evaluation->reviewer_user_id);
        $this->assertSame(Recommendation::Yes, $evaluation->recommendation);
        $this->assertSame(self::PROS_A, $evaluation->pros);
        $this->assertSame(self::CONS_A, $evaluation->cons);
        $this->assertSame('trimmed note', $evaluation->notes);
        $this->assertSame(1, $evaluation->version);
        $this->assertSame($this->reviewerA->id, $evaluation->updated_by_user_id);
        $this->assertSame('2026-10-06 11:00', $evaluation->submitted_at->format('Y-m-d H:i'));
        $this->assertSame(Evaluation::ACTION_CREATED, $evaluation->revisions()->sole()->action);
    }

    /** scope: the three recommendation values; anything else, or none, is refused. */
    public function test_the_recommendation_is_one_of_three_values_and_required(): void
    {
        $this->assertSame(['yes', 'maybe', 'no'], Recommendation::values());
        $this->atInterviewTime();

        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['recommendation' => 'hire']), 'recommendation');
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['recommendation' => 'strong_yes']), 'recommendation');
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['recommendation' => null]), 'recommendation');
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['pros' => str_repeat('x', 5001)]), 'pros');
        $this->assertSame(0, Evaluation::query()->count());
    }

    /** scope: multiple reviewer inputs. rules: each reviewer has their own opinion. acceptance: HR sees them all, aggregated. */
    public function test_several_reviewers_each_keep_their_own_evaluation_and_hr_sees_the_aggregate(): void
    {
        $this->atInterviewTime();
        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Yes, 'pros' => self::PROS_A]);
        $this->evaluate($this->reviewerB, ['recommendation' => Recommendation::No, 'notes' => self::NOTES_B]);

        $this->assertSame(2, $this->interview->evaluations()->count());
        $this->assertSame(
            ['total' => 2, 'counts' => ['yes' => 1, 'maybe' => 0, 'no' => 1]],
            app(RecruitmentInterviewEvaluationService::class)->summary($this->interview),
        );

        $this->actingAs($this->hr);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->assertSee(self::PROS_A)
            ->assertSee(self::NOTES_B)
            ->assertSee(__('recruitment-applicant.evaluations.recommendations.yes').': 1')
            ->assertSee(__('recruitment-applicant.evaluations.advisory'));

        // The applicant profile shows HR the tally per interview.
        Livewire::test(ApplicantShow::class, ['applicantId' => $this->application->applicant_id])
            ->assertSee(__('recruitment-applicant.evaluations.recommendations.no').' 1');
    }

    /** acceptance: HR's aggregated view is on the interview page; the reviewer's form is not (HR is not a participant). */
    public function test_the_interview_page_shows_the_right_panel_to_each_user(): void
    {
        $this->atInterviewTime();
        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Yes, 'pros' => self::PROS_A]);
        $this->evaluate($this->reviewerB, ['recommendation' => Recommendation::Maybe, 'notes' => self::NOTES_B]);

        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])
            ->assertSeeLivewire(EvaluationSummary::class);
        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])
            ->assertDontSee(__('recruitment-applicant.evaluations.my_title'));

        // Reviewer A: their own form with their own content, never B's.
        $this->actingAs($this->reviewerA);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])
            ->assertSeeLivewire(EvaluationForm::class)
            ->assertDontSeeLivewire(EvaluationSummary::class)
            ->assertDontSee(self::NOTES_B);
        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])
            ->assertSet('pros', self::PROS_A)
            ->assertDontSee(self::NOTES_B);
    }

    /** A reviewer who still owes an evaluation is pointed to it; once saved, the form says so in words. */
    public function test_the_page_points_a_reviewer_to_an_evaluation_they_still_owe(): void
    {
        $due = __('recruitment-applicant.interviews.evaluation_due');

        // Before the interview starts there is nothing to evaluate yet.
        $this->actingAs($this->reviewerA);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])->assertDontSee($due);

        $this->atInterviewTime();
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])->assertSee($due);

        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])
            ->set('recommendation', Recommendation::Yes)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee(__('recruitment-applicant.evaluations.saved_on', ['date' => '2026-10-06 11:00']));
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])->assertDontSee($due);

        // HR, who is not a reviewer here, is never told an evaluation is due.
        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])->assertDontSee($due);
    }

    /** HR's correct / withdraw panels move focus in and back out, like the interview's own panels. */
    public function test_evaluation_panels_move_focus(): void
    {
        $this->atInterviewTime();
        $evaluation = $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No]);
        $this->actingAs($this->hr);

        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->call('beginWithdraw', $evaluation->id)
            ->assertSet('panelOpen', true)
            ->assertSeeHtml('id="evaluationActionModal"')
            ->assertDispatched('focus-element', id: 'evaluation-panel-title')
            ->call('dismissPanel')
            ->assertSet('panel', null)
            ->assertSet('panelOpen', false)
            ->assertDispatched('focus-element', id: 'evaluation-'.$evaluation->id.'-withdraw')
            ->call('beginWithdraw', $evaluation->id)
            ->set('reason', 'Reviewer evaluated the wrong candidate')
            ->call('withdraw')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'evaluations-title');
    }

    /** acceptance + avoid: the recommendation never becomes the hiring decision. */
    public function test_recommendations_never_touch_the_application_decision(): void
    {
        $this->atInterviewTime();
        $decisionsBefore = RecruitmentApplicationDecision::query()->count();

        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No]);
        $this->evaluate($this->reviewerB, ['recommendation' => Recommendation::No]);
        $statusAfterInterview = $this->application->fresh()->status;
        $this->assertSame(AppStatus::Interviewed, $statusAfterInterview, 'Interviewed comes from the interview outcome (Task 7), not from the recommendations.');

        $evaluation = Evaluation::query()->where('reviewer_user_id', $this->reviewerA->id)->sole();
        app(RecruitmentInterviewEvaluationService::class)->correct($this->hr, $evaluation, 1, ['recommendation' => Recommendation::Yes], 'Reviewer mis-clicked the recommendation');

        // Nothing the evaluations did created a decision or moved the application.
        $this->assertSame($decisionsBefore, RecruitmentApplicationDecision::query()->count(), 'The evaluations recorded no decision.');
        $this->assertSame($statusAfterInterview, $this->application->fresh()->status);

        // Unanimous "no" still leaves the decision entirely to HR.
        $statuses = app(RecruitmentApplicationStatusService::class);
        $this->assertContains(AppStatus::Selected, $statuses->targets($this->application->fresh()));
        $statuses->change($this->hr, $this->application->fresh(), AppStatus::Interviewed, AppStatus::Selected, null);
        $this->assertSame(AppStatus::Selected, $this->application->fresh()->status);
        $this->assertSame(Recommendation::No, Evaluation::query()->where('reviewer_user_id', $this->reviewerB->id)->value('recommendation'));
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    /** rules: only an authorized participant evaluates. edge: a non-participant tries. */
    public function test_only_current_participants_in_a_reviewer_role_may_evaluate(): void
    {
        $this->atInterviewTime();
        $outsider = $this->makeUser($this->homs, 'employee');

        // HR (who can schedule) but not on the interview.
        $this->assertThrows(fn () => $this->evaluate($this->hr), AuthorizationException::class);
        $this->assertThrows(fn () => $this->evaluate($outsider), AuthorizationException::class);
        $this->actingAs($outsider);
        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])->assertNotFound();

        // A role the policy does not list.
        config(['recruitment.evaluations.reviewer_roles' => ['interviewer']]);
        $this->assertThrows(fn () => $this->evaluate($this->reviewerB), AuthorizationException::class);
        $this->evaluate($this->reviewerA);

        // Taken off the interview: can no longer evaluate. (A held interview
        // is no longer edited, so the row is ended directly.)
        Participant::query()->where('interview_id', $this->interview->id)->where('user_id', $this->reviewerA->id)->update(['removed_at' => now()]);
        $this->assertThrows(fn () => $this->evaluate($this->reviewerA, [], 1), AuthorizationException::class);

        // An inactive account cannot evaluate.
        config(['recruitment.evaluations.reviewer_roles' => ['interviewer', 'observer']]);
        $this->reviewerB->profile->forceFill(['is_active' => false])->save();
        $this->assertThrows(fn () => $this->evaluate($this->reviewerB->fresh()), AuthorizationException::class);
        $this->assertSame(1, Evaluation::query()->count());
    }

    /** security: nobody edits someone else's evaluation without correct-evaluations. acceptance: same. */
    public function test_no_one_edits_another_reviewers_evaluation_without_permission(): void
    {
        $this->atInterviewTime();
        $evaluationA = $this->evaluate($this->reviewerA, ['pros' => self::PROS_A]);

        // Reviewer B saving writes B's own row; A's is untouched.
        $this->evaluate($this->reviewerB, ['pros' => 'B overwrites?']);
        $this->assertSame(self::PROS_A, $evaluationA->fresh()->pros);
        $this->assertTrue($this->reviewerB->cannot('update', $evaluationA));
        $this->assertTrue($this->reviewerB->cannot('correct', $evaluationA));
        $this->assertThrows(fn () => $this->service()->correct($this->reviewerB, $evaluationA, 1, ['recommendation' => 'no'], 'long enough reason'), AuthorizationException::class);

        // HR who can view but not correct.
        $viewer = $this->without($this->hr, ['recruitment-applicants.correct-evaluations']);
        $this->assertThrows(fn () => $this->service()->correct($viewer, $evaluationA, 1, ['recommendation' => 'no'], 'long enough reason'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service()->withdraw($viewer, $evaluationA, 1, 'long enough reason'), AuthorizationException::class);
        $this->actingAs($viewer);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->assertDontSee(__('recruitment-applicant.evaluations.correct'))
            ->call('beginCorrect', $evaluationA->id)->assertForbidden();

        $this->assertSame(self::PROS_A, $evaluationA->fresh()->pros);
        $this->assertSame(1, $evaluationA->fresh()->version);
    }

    /** security: a reviewer sees nothing outside their own evaluation unless HR; HR only in scope; sensitive fields hidden. */
    public function test_evaluations_are_visible_only_to_their_author_and_hr_in_scope(): void
    {
        $this->atInterviewTime();
        $this->evaluate($this->reviewerA, ['pros' => self::PROS_A]);
        $evaluationB = $this->evaluate($this->reviewerB, ['notes' => self::NOTES_B]);

        $this->assertTrue($this->reviewerA->cannot('view', $evaluationB));
        $this->assertTrue($this->reviewerA->cannot('viewEvaluations', $this->interview));
        $this->actingAs($this->reviewerA);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])->assertForbidden();

        // HR of another governorate: not in scope.
        $otherHr = $this->hr($this->makeCity('DMS', '003'));
        $this->actingAs($otherHr);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])->assertNotFound();

        // HR in scope who can read applicants but not evaluations: sees the interview, not a word of any evaluation.
        $reader = $this->without($this->hr, ['recruitment-applicants.view-evaluations']);
        $this->actingAs($reader);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])
            ->assertOk()
            ->assertDontSeeLivewire(EvaluationSummary::class)
            ->assertDontSee(self::PROS_A)
            ->assertDontSee(self::NOTES_B);
        Livewire::test(ApplicantShow::class, ['applicantId' => $this->application->applicant_id])
            ->assertDontSee(trans_choice('recruitment-applicant.evaluations.count', 2, ['count' => 2]));

        // Who evaluated, and when, is itself evaluation data: another
        // participant cannot tell whether B has evaluated at all.
        $this->actingAs($this->reviewerA);
        Livewire::test(Show::class, ['interviewId' => $this->interview->id])
            ->assertDontSee(self::NOTES_B)
            ->assertDontSee(trans_choice('recruitment-applicant.evaluations.count', 2, ['count' => 2]))
            ->assertDontSee(__('recruitment-applicant.evaluations.submitted_on', ['date' => '2026-10-06 11:00']));

        // Never serialized with the model — content, recommendation and the
        // reasons HR gave (withdrawal, correction) alike.
        Role::findByName($this->hr->myRole(), 'web')->givePermissionTo('recruitment-applicants.view-evaluations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->hr = $this->hr->fresh();
        $this->service()->correct($this->hr, $evaluationB, 1, ['recommendation' => Recommendation::No, 'notes' => self::NOTES_B], 'CORRECTION-REASON secret');
        $this->service()->withdraw($this->hr, $evaluationB, 2, 'WITHDRAWAL-REASON secret');
        $serialized = json_encode($evaluationB->fresh()->toArray()).json_encode($evaluationB->revisions()->get()->toArray());
        foreach (['pros', 'cons', 'notes', 'recommendation', 'withdrawal_reason', 'reason'] as $field) {
            $this->assertArrayNotHasKey($field, $evaluationB->fresh()->toArray());
            $this->assertArrayNotHasKey($field, $evaluationB->revisions()->first()->toArray());
        }
        foreach ([self::NOTES_B, 'CORRECTION-REASON', 'WITHDRAWAL-REASON'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    /** security + avoid: evaluations are internal and never reach the candidate. */
    public function test_evaluations_never_reach_the_candidate(): void
    {
        Mail::fake();
        $this->atInterviewTime(); // 2026-10-06 11:00
        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No, 'pros' => self::PROS_A, 'cons' => self::CONS_A]);

        // The candidate hears about a further interview; nothing of the evaluation travels with it.
        $second = $this->scheduleInterview($this->application->fresh(), '2026-10-09T10:00');
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();

        $count = 0;
        Mail::assertSent(RecruitmentInterviewMail::class, function (RecruitmentInterviewMail $mail) use (&$count) {
            $html = $mail->render();
            $count++;

            foreach ([self::PROS_A, self::CONS_A] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }

            return true;
        });
        // The second interview's invitation and its reminder (the first invitation went before the fake).
        $this->assertSame(2, $count);
        $this->assertNotNull($second);
    }

    // ------------------------------------------------------------------
    // Exceptional cases
    // ------------------------------------------------------------------

    /**
     * edge: evaluation opens when HR records the interview as held — not
     * before its time, and not merely because its time has come.
     */
    public function test_evaluation_before_the_interview_is_held_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 09:59:00'));
        $errors = $this->assertValidationError(fn () => $this->evaluate($this->reviewerA), 'evaluation');
        $this->assertSame(__('recruitment-applicant.evaluations.errors.not_held'), $errors['evaluation'][0]);

        // Its time has come, but nobody has said it took place.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA), 'evaluation');
        $this->actingAs($this->reviewerA);
        Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id])
            ->assertSee(__('recruitment-applicant.evaluations.errors.not_held'))
            ->assertDontSee(__('recruitment-applicant.evaluations.submit'));
        $this->assertSame([], RecruitmentInterview::query()->awaitingEvaluationBy($this->reviewerA)->pluck('id')->all());

        // HR records it as held: evaluation opens.
        $this->outcome(Status::Completed);
        $this->assertSame([$this->interview->id], RecruitmentInterview::query()->awaitingEvaluationBy($this->reviewerA)->pluck('id')->all());
        $this->assertSame(1, $this->evaluate($this->reviewerA)->version);
    }

    /** edge + avoid: a cancelled interview (and a no-show) cannot be evaluated. */
    public function test_cancelled_and_no_show_interviews_cannot_be_evaluated(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->scheduling()->cancel($this->hr, $this->interview->fresh(), Status::Scheduled, $this->interview->scheduled_at->toIso8601String(), 'candidate_declined', null);

        $errors = $this->assertValidationError(fn () => $this->evaluate($this->reviewerA), 'evaluation');
        $this->assertSame(__('recruitment-applicant.evaluations.errors.cancelled'), $errors['evaluation'][0]);

        $other = $this->scheduleInterview($this->application->fresh(), '2026-10-06T11:30');
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $this->scheduling()->recordOutcome($this->hr, $other, $other->status, $other->scheduled_at->toIso8601String(), Status::NoShow, null);
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, [], null, $other->fresh()), 'evaluation');
        $this->assertSame(0, Evaluation::query()->count());
    }

    /** edge: several reviewers writing at the same time; the same reviewer from two tabs. */
    public function test_concurrent_writes(): void
    {
        $this->atInterviewTime();

        // Different reviewers: each writes their own row, nothing is lost.
        foreach ([$this->reviewerA, $this->reviewerB] as $reviewer) {
            $this->evaluate($reviewer, ['notes' => 'by '.$reviewer->id]);
        }
        $this->assertSame(['by '.$this->reviewerA->id, 'by '.$this->reviewerB->id], Evaluation::query()->orderBy('id')->pluck('notes')->all());

        // Same reviewer, second tab opened before the first save: refused, not a second row.
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['notes' => 'from tab 2'], null), 'evaluation');

        // Both tabs editing version 1: the first wins, the second is refused.
        $this->evaluate($this->reviewerA, ['notes' => 'tab 1 edit'], 1);
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['notes' => 'tab 2 edit'], 1), 'evaluation');
        $this->assertSame('tab 1 edit', Evaluation::query()->where('reviewer_user_id', $this->reviewerA->id)->value('notes'));
        $this->assertSame(1, Evaluation::query()->where('reviewer_user_id', $this->reviewerA->id)->count());

        // The database itself refuses a second row per reviewer per interview.
        $this->assertThrows(fn () => DB::table('recruitment_interview_evaluations')->insert([
            'interview_id' => $this->interview->id, 'reviewer_user_id' => $this->reviewerA->id,
            'recommendation' => 'yes', 'version' => 1, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]), UniqueConstraintViolationException::class);

        // The Livewire form offers a reload after a stale save.
        $this->actingAs($this->reviewerB);
        $form = Livewire::test(EvaluationForm::class, ['interviewId' => $this->interview->id]);
        $this->evaluate($this->reviewerB, ['notes' => 'saved elsewhere'], 1);
        $form->set('notes', 'stale edit')->call('save')
            ->assertHasErrors('evaluation')
            ->assertSee(__('recruitment-applicant.evaluations.reload'))
            ->call('reload')
            ->assertSet('notes', 'saved elsewhere')
            ->assertSet('expectedVersion', 2);
    }

    /** edge: HR corrects a mistake in an earlier evaluation — with a reason, into the history. */
    public function test_hr_corrects_a_mistake_with_a_reason_and_keeps_the_history(): void
    {
        $this->atInterviewTime();
        $evaluation = $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No, 'pros' => self::PROS_A]);

        $this->assertValidationError(fn () => $this->service()->correct($this->hr, $evaluation, 1, ['recommendation' => Recommendation::Yes, 'pros' => self::PROS_A], 'short'), 'reason');
        $this->assertValidationError(fn () => $this->service()->correct($this->hr, $evaluation, 1, ['recommendation' => Recommendation::No, 'pros' => self::PROS_A], 'no change at all here'), 'evaluation');
        $this->assertValidationError(fn () => $this->service()->correct($this->hr, $evaluation, 7, ['recommendation' => Recommendation::Yes], 'reviewer mis-clicked'), 'evaluation');

        $this->actingAs($this->hr);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->call('beginCorrect', $evaluation->id)
            ->assertSet('recommendation', Recommendation::No)
            ->set('recommendation', Recommendation::Yes)
            ->set('reason', 'Reviewer confirmed by phone they mis-clicked')
            ->call('saveCorrection')
            ->assertHasNoErrors()
            ->assertSee(__('recruitment-applicant.evaluations.corrected_badge'));

        $evaluation->refresh();
        $this->assertSame(Recommendation::Yes, $evaluation->recommendation);
        $this->assertSame(2, $evaluation->version);
        $this->assertSame($this->hr->id, $evaluation->updated_by_user_id);
        $this->assertSame($this->reviewerA->id, $evaluation->reviewer_user_id, 'It stays the reviewer’s evaluation.');

        [$original, $correction] = $evaluation->revisions()->orderBy('version')->get()->all();
        $this->assertSame([Evaluation::ACTION_CREATED, Recommendation::No, $this->reviewerA->id], [$original->action, $original->recommendation, $original->changed_by_user_id]);
        $this->assertSame([Evaluation::ACTION_CORRECTED, Recommendation::Yes, $this->hr->id], [$correction->action, $correction->recommendation, $correction->changed_by_user_id]);
        $this->assertSame('Reviewer confirmed by phone they mis-clicked', $correction->reason);

        // The reviewer's stale form cannot silently undo the correction.
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No], 1), 'evaluation');
    }

    // ------------------------------------------------------------------
    // Closing, history, deletion
    // ------------------------------------------------------------------

    /** rules: evaluation can be closed after completion; reviewers then stop, HR can still correct. */
    public function test_evaluation_closes_after_completion(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->assertValidationError(fn () => $this->service()->close($this->hr, $this->interview->fresh(), Status::Scheduled), 'evaluation');
        $this->assertThrows(fn () => $this->service()->close($this->reviewerA, $this->interview->fresh(), Status::Scheduled), AuthorizationException::class);

        $this->outcome(Status::Completed);
        $evaluation = $this->evaluate($this->reviewerA);
        $this->actingAs($this->hr);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->call('closeEvaluations')
            ->assertHasNoErrors()
            ->assertSee(__('recruitment-applicant.evaluations.closed_on', ['date' => '2026-10-06 11:00']));
        $this->assertValidationError(fn () => $this->service()->close($this->hr, $this->interview->fresh(), Status::Completed), 'evaluation');

        $errors = $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['notes' => 'late edit'], 1), 'evaluation');
        $this->assertSame(__('recruitment-applicant.evaluations.errors.closed'), $errors['evaluation'][0]);
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerB), 'evaluation');

        $closeAudit = Activity::query()->where('description', 'recruitment_interview.evaluations_closed')->sole();
        $this->assertSame($this->interview->id, $closeAudit->subject_id);
        $this->assertSame($this->hr->id, $closeAudit->causer_id);

        // HR corrects after closing: saved, in the history, audited without the content.
        $this->service()->correct($this->hr, $evaluation, 1, ['recommendation' => Recommendation::Maybe], 'Typo in the recommendation');
        $evaluation->refresh();
        $this->assertSame(Recommendation::Maybe, $evaluation->recommendation);
        $this->assertSame(2, $evaluation->version);
        $correction = $evaluation->revisions()->where('version', 2)->sole();
        $this->assertSame([Evaluation::ACTION_CORRECTED, 'Typo in the recommendation', $this->hr->id], [$correction->action, $correction->reason, $correction->changed_by_user_id]);
        $audit = Activity::query()->where('description', 'recruitment_interview_evaluation.corrected')->where('subject_id', $evaluation->id)->sole();
        $this->assertSame(['recommendation'], $audit->properties['changed_fields']);
        $this->assertStringNotContainsString('Typo in the recommendation', json_encode($audit->properties));
        $this->assertStringNotContainsString(Recommendation::Maybe, json_encode($audit->properties));
        $this->assertTrue($this->interview->fresh()->evaluationsClosed(), 'Correcting does not reopen evaluation.');
    }

    /** rules: the optional policy — evaluation closes itself N days after completion. */
    public function test_evaluation_can_close_automatically_by_policy(): void
    {
        config(['recruitment.evaluations.auto_close_days' => 3]);
        $this->atInterviewTime();

        $this->travelTo(Carbon::parse('2026-10-09 10:59:00'));
        $this->evaluate($this->reviewerA);

        $this->travelTo(Carbon::parse('2026-10-09 11:01:00'));
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerB), 'evaluation');
    }

    /** integrity + avoid: every change keeps the full history; audit entries name changes without repeating content. */
    public function test_edits_never_lose_history_and_are_audited(): void
    {
        $this->atInterviewTime();
        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Maybe, 'pros' => 'first']);
        $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Yes, 'pros' => 'second'], 1);
        $evaluation = $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Yes, 'pros' => 'second'], 2);
        $this->assertSame(2, $evaluation->version, 'Saving the same content again is not a change.');
        $evaluation = $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No, 'pros' => 'third'], 2);

        $this->assertSame(
            [[1, 'created', 'first', 'maybe'], [2, 'updated', 'second', 'yes'], [3, 'updated', 'third', 'no']],
            $evaluation->revisions()->orderBy('version')->get()->map(fn ($r) => [$r->version, $r->action, $r->pros, $r->recommendation])->all(),
        );

        $audit = Activity::query()->where('log_name', RecruitmentInterviewEvaluationService::AUDIT_LOG)->orderBy('id')->get();
        $this->assertSame(['recruitment_interview_evaluation.created', 'recruitment_interview_evaluation.updated', 'recruitment_interview_evaluation.updated'], $audit->pluck('description')->all());
        $this->assertSame(['pros', 'recommendation'], $audit->last()->properties['changed_fields']);
        foreach ($audit as $entry) {
            $this->assertStringNotContainsString('third', json_encode($entry->properties));
            $this->assertStringNotContainsString('"no"', json_encode($entry->properties));
        }

        // History is append-only.
        $this->expectException(LogicException::class);
        $evaluation->revisions()->first()->forceFill(['pros' => 'rewritten'])->save();
    }

    /** integrity: linked to its interview and its reviewer. */
    public function test_an_evaluation_belongs_to_an_interview_and_a_reviewer(): void
    {
        $this->atInterviewTime();
        $evaluation = $this->evaluate($this->reviewerA);

        $this->assertTrue($evaluation->interview->is($this->interview));
        $this->assertTrue($evaluation->reviewer->is($this->reviewerA));
        $this->assertTrue($this->interview->evaluations->first()->is($evaluation));
    }

    /** integrity: no deletion without audit — there is no deletion; withdrawal is audited and keeps everything. */
    public function test_evaluations_are_withdrawn_with_audit_never_deleted(): void
    {
        $this->atInterviewTime();
        $evaluation = $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::No, 'pros' => self::PROS_A]);
        $this->evaluate($this->reviewerB, ['recommendation' => Recommendation::Yes]);

        $this->assertThrows(fn () => $evaluation->delete(), LogicException::class);
        $this->assertThrows(fn () => Evaluation::query()->whereKey($evaluation->id)->delete(), LogicException::class);
        $this->assertThrows(fn () => $this->interview->evaluations()->delete(), LogicException::class);
        $this->assertThrows(fn () => $evaluation->revisions()->first()->delete(), LogicException::class);

        $this->assertValidationError(fn () => $this->service()->withdraw($this->hr, $evaluation, 1, 'no'), 'reason');

        $this->actingAs($this->hr);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $this->interview->id])
            ->call('beginWithdraw', $evaluation->id)
            ->set('reason', 'Reviewer evaluated the wrong candidate')
            ->call('withdraw')
            ->assertHasNoErrors()
            ->assertSee('Reviewer evaluated the wrong candidate');

        $evaluation->refresh();
        $this->assertTrue($evaluation->isWithdrawn());
        $this->assertSame(self::PROS_A, $evaluation->pros, 'The content stays.');
        $this->assertSame(Evaluation::ACTION_WITHDRAWN, $evaluation->revisions()->orderByDesc('version')->first()->action);
        $this->assertTrue(Activity::query()->where('description', 'recruitment_interview_evaluation.withdrawn')->where('subject_id', $evaluation->id)->exists());
        $this->assertSame(['total' => 1, 'counts' => ['yes' => 1, 'maybe' => 0, 'no' => 0]], $this->service()->summary($this->interview));

        // The reviewer cannot undo the withdrawal by saving again.
        $this->assertValidationError(fn () => $this->evaluate($this->reviewerA, ['recommendation' => Recommendation::Yes], 2), 'evaluation');
    }

    /** Held: each reviewer is told their evaluation is needed, with a link straight to their form. */
    public function test_reviewers_are_notified_when_the_interview_is_held(): void
    {
        Notification::fake();
        $this->atInterviewTime();

        $link = route('recruitment.interviews.show', ['interviewId' => $this->interview->id]).'#my-evaluation';
        foreach ([$this->reviewerA, $this->reviewerB] as $reviewer) {
            Notification::assertSentTo($reviewer, ParticipantNotice::class, fn (ParticipantNotice $notice): bool => $notice->kind === ParticipantNotice::EVALUATION_OPEN
                && $notice->toArray($reviewer)['url'] === $link);
        }
        Notification::assertNotSentTo($this->hr, ParticipantNotice::class, fn (ParticipantNotice $notice): bool => $notice->kind === ParticipantNotice::EVALUATION_OPEN);
    }

    /** scope: reviewers find the interviews waiting on their evaluation. */
    public function test_reviewers_can_list_the_interviews_waiting_for_their_evaluation(): void
    {
        $later = $this->scheduleInterview($this->application->fresh(), '2026-10-12T10:00');
        $this->atInterviewTime();
        $this->actingAs($this->reviewerA);
        $evaluateLink = route('recruitment.interviews.show', ['interviewId' => $this->interview->id]).'#my-evaluation';

        // Held and not yet evaluated: waiting. The later one has not been held.
        $this->assertSame([$this->interview->id], RecruitmentInterview::query()->awaitingEvaluationBy($this->reviewerA)->pluck('id')->all());
        Livewire::test(Index::class)
            ->assertViewHas('waitingCount', 1)
            ->assertSee('Candidate One')
            ->assertSee($evaluateLink, false);

        $this->evaluate($this->reviewerA);

        $this->assertSame([], RecruitmentInterview::query()->awaitingEvaluationBy($this->reviewerA)->pluck('id')->all());
        Livewire::test(Index::class)
            ->assertViewHas('waitingCount', 0)
            ->assertDontSee($evaluateLink, false);

        // Reviewer B still owes theirs.
        $this->assertSame([$this->interview->id], RecruitmentInterview::query()->awaitingEvaluationBy($this->reviewerB)->pluck('id')->all());
        $this->assertNotNull($later);
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

    private function service(): RecruitmentInterviewEvaluationService
    {
        return app(RecruitmentInterviewEvaluationService::class);
    }

    private function scheduling(): RecruitmentInterviewSchedulingService
    {
        return app(RecruitmentInterviewSchedulingService::class);
    }

    /** An interview with reviewer A (interviewer) and reviewer B (observer). */
    private function scheduleInterview(RecruitmentApplication $application, string $startsAt = '2026-10-06T10:00'): RecruitmentInterview
    {
        return $this->scheduling()->schedule($this->hr, $application, [
            'type' => 'hr',
            'startsAt' => $startsAt,
            'duration' => 60,
            'locationType' => 'in_person',
            'locationValue' => 'Homs office',
            'participants' => [
                $this->reviewerA->id => Participant::ROLE_INTERVIEWER,
                $this->reviewerB->id => Participant::ROLE_OBSERVER,
            ],
        ]);
    }

    private function reschedule(array $overrides): void
    {
        $interview = $this->interview->fresh();

        $this->scheduling()->update($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), array_merge([
            'type' => $interview->interview_type,
            'startsAt' => $interview->scheduled_at->format('Y-m-d\TH:i'),
            'duration' => 60,
            'locationType' => $interview->location_type,
            'locationValue' => $interview->location_value,
            'participants' => $interview->currentParticipants()->pluck('role', 'user_id')->all(),
        ], $overrides), null);
    }

    /** Evaluation opens when HR records the interview as held: after it, at 11:00. */
    private function atInterviewTime(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->outcome(Status::Completed);
    }

    private function outcome(string $outcome): void
    {
        $interview = $this->interview->fresh();
        $this->scheduling()->recordOutcome($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), $outcome, null);
    }

    private function evaluate(User $reviewer, array $input = [], ?int $expectedVersion = null, ?RecruitmentInterview $interview = null): Evaluation
    {
        return $this->service()->submit($reviewer, ($interview ?? $this->interview)->fresh(), array_merge([
            'recommendation' => Recommendation::Yes,
            'pros' => null,
            'cons' => null,
            'notes' => null,
        ], $input), $expectedVersion);
    }

    /** @return array<string, array<int, string>> */
    private function assertValidationError(callable $action, string $field): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return $exception->errors();
        }

        $this->fail("Expected a validation error on '{$field}'.");
    }
}
