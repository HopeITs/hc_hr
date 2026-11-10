<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as AppStatus;
use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
use App\Enums\RecruitmentInterviewStatus as Status;
use App\Enums\RecruitmentInterviewType;
use App\Jobs\SendRecruitmentInterviewNotice;
use App\Livewire\Recruitment\Applicants\Show as ApplicantShow;
use App\Livewire\Recruitment\Interviews\Board;
use App\Livewire\Recruitment\Interviews\Calendar;
use App\Livewire\Recruitment\Interviews\EvaluationForm;
use App\Livewire\Recruitment\Interviews\EvaluationSummary;
use App\Livewire\Recruitment\Interviews\Form;
use App\Livewire\Recruitment\Interviews\Index;
use App\Livewire\Recruitment\Interviews\Show;
use App\Mail\RecruitmentInterviewMail;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\RecruitmentInterviewNotice as Notice;
use App\Models\RecruitmentInterviewParticipant as Participant;
use App\Models\User;
use App\Notifications\RecruitmentInterviewParticipantNotice as ParticipantNotice;
use App\Services\RecruitmentApplicationStatusService;
use App\Services\RecruitmentInterviewEvaluationService;
use App\Services\RecruitmentInterviewNoticeService;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Task 7: interview scheduling and notifications.
 *
 * The numbers in [brackets] on each test refer to the Task 7 test brief:
 * acceptance criteria 1–5, business rules 6–9, security 10–13, exceptional
 * cases 14–19, data integrity 20–23, errors to avoid 24–27, and the Task 6
 * status-machine integration 28–32.
 *
 * The clock is frozen: every "future" and "past" below is relative to it, in
 * the application timezone (Europe/Istanbul).
 */
class RecruitmentInterviewTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const MEETING_URL = 'https://meet.example.test/j/secret-room-4711';

    private const SECRET_EVALUATION = 'EVAL-SECRET weak on budgeting';

    private const SECRET_CANCEL_NOTE = 'CANCEL-SECRET interviewer thinks candidate is weak';

    private const SECRET_RESCHEDULE_REASON = 'RESCHEDULE-SECRET panel wants a stronger candidate first';

    private const SECRET_DECISION_REASON = 'DECISION-SECRET salary expectations too high';

    private City $homs;

    private City $damascus;

    private User $hr;

    private User $interviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00:00', 'Europe/Istanbul'));

        $this->homs = $this->makeCity('HMS', '002');
        $this->damascus = $this->makeCity('DMS', '003');
        $this->hr = $this->hr($this->homs);
        $this->interviewer = $this->makeUser($this->homs, 'employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Acceptance criteria
    // ------------------------------------------------------------------

    /** [1] [2] [6] [7] [28] */
    public function test_hr_schedules_an_interview_for_a_shortlisted_candidate_with_participants(): void
    {
        Notification::fake();
        $application = $this->shortlisted();
        $this->actingAs($this->hr);

        Livewire::test(Form::class, ['applicationId' => $application->id])
            ->set('type', RecruitmentInterviewType::Technical)
            ->set('startsAt', '2026-10-08T10:30')
            ->set('duration', 45)
            ->set('locationType', 'online')
            ->set('meetingUrl', self::MEETING_URL)
            ->set('newParticipantId', (string) $this->interviewer->id)
            ->set('newParticipantRole', Participant::ROLE_INTERVIEWER)
            ->call('addParticipant')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $interview = RecruitmentInterview::query()->sole();
        $this->assertSame($application->id, $interview->application_id);
        $this->assertSame(Status::Scheduled, $interview->status);
        $this->assertSame(RecruitmentInterviewType::Technical, $interview->interview_type);
        $this->assertSame('2026-10-08 10:30:00', $interview->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 11:15:00', $interview->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame([$this->interviewer->id => Participant::ROLE_INTERVIEWER], $interview->currentParticipants()->pluck('role', 'user_id')->all());

        // [28] shortlisted -> interview_scheduled, recorded as a decision.
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status);
        $this->assertTrue(RecruitmentApplicationDecision::query()
            ->where('application_id', $application->id)
            ->where('from_status', AppStatus::Shortlisted)
            ->where('to_status', AppStatus::InterviewScheduled)
            ->where('reason_code', RecruitmentApplicationStatusService::REASON_INTERVIEW)
            ->exists());

        Notification::assertSentTo($this->interviewer, ParticipantNotice::class, fn (ParticipantNotice $n) => $n->kind === ParticipantNotice::SCHEDULED);
    }

    /** [7] The type must be HR, technical or final. */
    public function test_the_interview_type_is_one_of_hr_technical_or_final(): void
    {
        $application = $this->shortlisted();

        $this->assertSame(['hr', 'technical', 'final'], RecruitmentInterviewType::values());
        $this->assertValidationError(fn () => $this->schedule($application, ['type' => 'lunch']), 'type');

        foreach (RecruitmentInterviewType::values() as $i => $type) {
            $this->schedule($application, ['type' => $type, 'startsAt' => '2026-10-1'.$i.'T10:00']);
        }
        $this->assertSame(RecruitmentInterviewType::values(), RecruitmentInterview::query()->orderBy('id')->pluck('interview_type')->all());
    }

    /** [3] The candidate email is queued as a job carrying only the notice id, then sent with the details. */
    public function test_the_candidate_notification_is_dispatched_and_sent_with_the_interview_details(): void
    {
        Queue::fake();
        $application = $this->shortlisted();

        $interview = $this->schedule($application);

        $notice = Notice::query()->where('interview_id', $interview->id)->sole();
        $this->assertSame(Notice::KIND_SCHEDULED, $notice->kind);
        $this->assertSame($application->email_normalized, $notice->recipient_email);
        Queue::assertPushed(SendRecruitmentInterviewNotice::class, fn (SendRecruitmentInterviewNotice $job) => $job->noticeId === $notice->id);

        Mail::fake();
        $this->runNoticeJob($notice->id);

        Mail::assertSent(RecruitmentInterviewMail::class, function (RecruitmentInterviewMail $mail) use ($application) {
            $html = $mail->render();

            return $mail->hasTo($application->email_normalized)
                && str_contains($html, 'IT Specialist')
                && str_contains($html, '2026-10-08 10:00')
                && str_contains($html, self::MEETING_URL);
        });
        $this->assertSame(Notice::STATUS_SENT, $notice->fresh()->status);
    }

    /** [3] The reminder goes out once the interview is within the configured offset (24h). */
    public function test_the_reminder_is_sent_24_hours_before_and_only_once(): void
    {
        Mail::fake();
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);

        $this->travelTo(Carbon::parse('2026-10-09 09:59:00'));
        $this->assertSame(0, app(RecruitmentInterviewNoticeService::class)->queueDueReminders());

        $this->travelTo(Carbon::parse('2026-10-09 10:05:00'));
        $this->artisan('recruitment:interview-reminders')->assertSuccessful();
        $this->artisan('recruitment:interview-reminders')->assertSuccessful();

        $reminders = Notice::query()->where('interview_id', $interview->id)->where('kind', Notice::KIND_REMINDER)->get();
        $this->assertCount(1, $reminders);
        $this->assertSame(1440, $reminders->first()->offset_minutes);
        $this->assertSame(Notice::STATUS_SENT, $reminders->first()->status);
        Mail::assertSent(RecruitmentInterviewMail::class, fn (RecruitmentInterviewMail $mail) => str_contains($mail->render(), __('recruitment-applicant.interviews.mail.body.reminder', ['type' => __('recruitment-applicant.interviews.types.hr', [], 'en'), 'position' => 'IT Specialist'], 'en')));
    }

    /** [3] The offsets are configuration: a second reminder is a config change, not a code change. */
    public function test_reminder_offsets_are_configurable(): void
    {
        config(['recruitment.interviews.reminder_offsets_minutes' => [1440, 120]]);
        Mail::fake();
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);

        $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $this->travelTo(Carbon::parse('2026-10-10 08:30:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();

        $this->assertSame([120, 1440], Notice::query()->where('interview_id', $interview->id)->where('kind', Notice::KIND_REMINDER)->orderBy('offset_minutes')->pluck('offset_minutes')->all());
    }

    /** [4] List and calendar show each user exactly the interviews they may see. */
    public function test_interviews_appear_in_the_list_and_the_calendar(): void
    {
        $mine = $this->schedule($this->shortlisted('Candidate Visible'), ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
        $this->schedule($this->shortlisted('Candidate Hidden'), ['startsAt' => '2026-10-09T12:00']);

        // HR in scope: both, in the list and in October's calendar.
        $this->actingAs($this->hr);
        Livewire::test(Board::class)->assertSee('Candidate Visible')->assertSee('Candidate Hidden');
        Livewire::withQueryParams(['month' => '2026-10'])->test(Calendar::class)
            ->assertSee('Candidate Visible')
            ->assertSee('Candidate Hidden')
            ->assertSee('calendar-interview-'.$mine->id, false);
        Livewire::withQueryParams(['month' => '2026-11'])->test(Calendar::class)->assertDontSee('Candidate Visible');
        Livewire::test(Index::class)->assertSeeLivewire(Board::class)
            ->set('view', 'calendar')->assertSeeLivewire(Calendar::class);
        // The retired list view of old bookmarks falls back to the board.
        Livewire::withQueryParams(['view' => 'list'])->test(Index::class)->assertSet('view', 'board');

        // A participant with no recruitment permission: only their own.
        $this->actingAs($this->interviewer);
        Livewire::test(Board::class)->assertSee('Candidate Visible')->assertDontSee('Candidate Hidden');
        Livewire::withQueryParams(['month' => '2026-10'])->test(Calendar::class)->assertSee('Candidate Visible')->assertDontSee('Candidate Hidden');
    }

    /** [4] Cancelled interviews stay listed (nothing is deleted) and are hidden from the calendar unless asked for. */
    public function test_cancelled_interviews_stay_in_the_list_and_are_optional_in_the_calendar(): void
    {
        $interview = $this->schedule($this->shortlisted('Candidate Cancelled'));
        $this->cancel($interview, 'candidate_declined');
        $this->actingAs($this->hr);

        // On the board, in the cancelled / no-show lane.
        $this->assertSame([$interview->id], Livewire::test(Board::class)
            ->assertSee('Candidate Cancelled')
            ->viewData('lanes')['closed']['items']->modelKeys());
        Livewire::withQueryParams(['month' => '2026-10'])->test(Calendar::class)
            ->assertDontSee('Candidate Cancelled')
            ->set('showCancelled', true)
            ->assertSee('Candidate Cancelled');
    }

    /** [5] [6] One application can hold several interviews; each points at the application, not the applicant. */
    public function test_an_application_can_have_several_interviews(): void
    {
        $application = $this->shortlisted();

        $this->schedule($application, ['type' => 'hr']);
        $this->schedule($application->fresh(), ['type' => 'technical', 'startsAt' => '2026-10-09T10:00']);
        $this->schedule($application->fresh(), ['type' => 'final', 'startsAt' => '2026-10-12T10:00']);

        $this->assertSame(3, $application->interviews()->count());
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status);
        $this->assertSame(1, RecruitmentApplicationDecision::query()->where('to_status', AppStatus::InterviewScheduled)->count());
        $this->assertNotContains('applicant_id', app('db')->getSchemaBuilder()->getColumnListing('recruitment_interviews'));
    }

    // ------------------------------------------------------------------
    // Business rules
    // ------------------------------------------------------------------

    /** [8] [25] Cancelled interviews get no reminder, and a reminder queued before the cancel is dropped. */
    public function test_no_reminder_is_sent_for_a_cancelled_interview(): void
    {
        Queue::fake();
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);

        $this->travelTo(Carbon::parse('2026-10-09 11:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $queuedReminder = Notice::query()->where('kind', Notice::KIND_REMINDER)->sole();

        $this->cancel($interview->fresh(), 'interviewer_unavailable');

        $this->assertSame(Notice::STATUS_CANCELLED, $queuedReminder->fresh()->status);
        Mail::fake();
        $this->runNoticeJob($queuedReminder->id);
        Mail::assertNothingSent();

        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $this->assertSame(1, Notice::query()->where('kind', Notice::KIND_REMINDER)->count());
    }

    /** [9] [21] A reschedule writes history with old and new values, and an audit entry; it never just overwrites. */
    public function test_rescheduling_writes_history_and_an_audit_entry(): void
    {
        $interview = $this->schedule($this->shortlisted());

        $this->assertValidationError(fn () => $this->reschedule($interview, ['startsAt' => '2026-10-09T14:00'], reason: null), 'reason');
        $this->assertSame(0, $interview->reschedules()->count());

        $interview = $this->reschedule($interview, ['startsAt' => '2026-10-09T14:00']);
        $interview = $this->reschedule($interview, ['startsAt' => '2026-10-09T14:00', 'locationType' => 'in_person', 'locationValue' => 'Homs office']);

        $this->assertSame(Status::Rescheduled, $interview->status);
        $this->assertSame(2, $interview->reschedule_count);
        [$first, $second] = $interview->reschedules()->orderBy('id')->get()->all();
        $this->assertSame('2026-10-08 10:00', $first->from_scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-09 14:00', $first->to_scheduled_at->format('Y-m-d H:i'));
        $this->assertFalse($first->location_changed);
        $this->assertTrue($second->location_changed);
        $this->assertSame('online', $second->from_location_type);
        $this->assertSame('in_person', $second->to_location_type);
        $this->assertSame($this->hr->id, $first->rescheduled_by_user_id);
        $this->assertSame(self::SECRET_RESCHEDULE_REASON, $first->reason);
        $this->assertSame(2, Activity::query()->where('description', 'recruitment_interview.rescheduled')->where('subject_id', $interview->id)->count());

        // History is append-only.
        $this->expectException(LogicException::class);
        $first->forceFill(['reason' => 'rewritten'])->save();
    }

    /** [21] Editing only the type or participants is audited but is not a reschedule. */
    public function test_editing_without_moving_is_audited_but_not_a_reschedule(): void
    {
        $interview = $this->schedule($this->shortlisted());

        $interview = $this->reschedule($interview, ['type' => 'final', 'participants' => [$this->interviewer->id => Participant::ROLE_OBSERVER]], reason: null);

        $this->assertSame(Status::Scheduled, $interview->status);
        $this->assertSame(0, $interview->reschedules()->count());
        $this->assertSame('final', $interview->interview_type);
        $this->assertTrue(Activity::query()->where('description', 'recruitment_interview.updated')->where('subject_id', $interview->id)->exists());
    }

    // ------------------------------------------------------------------
    // Security & permissions
    // ------------------------------------------------------------------

    /** [10] Only authorized HR in scope may create or edit; everyone else is refused. */
    public function test_only_authorized_hr_can_create_or_edit_interviews(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application);
        $employee = $this->makeUser($this->homs, 'employee');
        $otherCityHr = $this->hr($this->damascus);

        // Non-HR: the route middleware refuses (403), and so do the component and the service.
        $this->actingAs($employee)->get(route('recruitment.interviews.create', ['applicationId' => $application->id]))->assertForbidden();
        $this->actingAs($employee)->get(route('recruitment.interviews.edit', ['interviewId' => $interview->id]))->assertForbidden();
        $this->assertThrows(fn () => $this->service()->schedule($employee, $application, $this->input()), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service()->cancel($employee, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), 'other', 'long enough note'), AuthorizationException::class);

        // A participant is not HR: can open the interview, cannot manage it.
        $this->actingAs($this->interviewer);
        $this->get(route('recruitment.interviews.edit', ['interviewId' => $interview->id]))->assertForbidden();

        // HR of another governorate: the application is out of scope.
        $this->actingAs($otherCityHr);
        Livewire::test(Form::class, ['applicationId' => $application->id])->assertNotFound();
        $this->assertThrows(fn () => $this->service()->schedule($otherCityHr, $application, $this->input()), AuthorizationException::class);

        // HR in scope who lost schedule-interviews.
        $reader = $this->without($this->hr, ['recruitment-applicants.schedule-interviews']);
        $this->actingAs($reader);
        $this->get(route('recruitment.interviews.create', ['applicationId' => $application->id]))->assertForbidden();
        Livewire::test(Form::class, ['applicationId' => $application->id])->assertForbidden();
        Livewire::test(Show::class, ['interviewId' => $interview->id])->call('beginCancel')->assertForbidden();
        $this->assertThrows(fn () => $this->service()->update($reader, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), $this->input(), 'long enough reason'), AuthorizationException::class);
    }

    /** [10] The authorized HR can open the create and edit pages. */
    public function test_authorized_hr_can_open_the_create_and_edit_pages(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application);
        $this->actingAs($this->hr);

        $this->get(route('recruitment.interviews.create', ['applicationId' => $application->id]))->assertOk();
        $this->get(route('recruitment.interviews.edit', ['interviewId' => $interview->id]))->assertOk();
        $this->get(route('recruitment.interviews.show', ['interviewId' => $interview->id]))->assertOk();
        $this->get(route('recruitment.interviews.index'))->assertOk();
    }

    /** [11] Participants see the interviews they are on; nothing else. Removal takes it away. */
    public function test_participants_see_only_their_own_interviews_and_hr_only_its_scope(): void
    {
        $theirs = $this->schedule($this->shortlisted(), ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
        $other = $this->schedule($this->shortlisted('Someone Else'), ['startsAt' => '2026-10-09T10:00']);
        $damascusInterview = $this->schedule($this->shortlisted('Damascus Candidate', $this->damascus), actor: $this->hr($this->damascus));

        $this->actingAs($this->interviewer);
        Livewire::test(Show::class, ['interviewId' => $theirs->id])->assertOk();
        Livewire::test(Show::class, ['interviewId' => $other->id])->assertNotFound();
        $this->get(route('recruitment.interviews.index'))->assertOk();

        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $other->id])->assertOk();
        Livewire::test(Show::class, ['interviewId' => $damascusInterview->id])->assertNotFound();
        $this->assertEqualsCanonicalizing([$theirs->id, $other->id], RecruitmentInterview::query()->visibleTo($this->hr)->pluck('id')->all());

        // Taken off the interview: it disappears for them.
        $this->reschedule($theirs, ['participants' => []], reason: null);
        $this->actingAs($this->interviewer->fresh());
        Livewire::test(Show::class, ['interviewId' => $theirs->id])->assertNotFound();

        // No permission and never a participant: the interviews page itself is refused.
        $stranger = $this->makeUser($this->homs, 'manager');
        $this->actingAs($stranger)->get(route('recruitment.interviews.index'))->assertForbidden();
    }

    /** [12] The meeting link: managing HR and participants see it; an in-scope reader who is neither does not. */
    public function test_the_meeting_link_is_shown_only_to_managing_hr_and_participants(): void
    {
        $interview = $this->schedule($this->shortlisted(), ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);

        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $interview->id])->assertSee(self::MEETING_URL);

        $this->actingAs($this->interviewer);
        Livewire::test(Show::class, ['interviewId' => $interview->id])->assertSee(self::MEETING_URL);

        // HR in scope with view but without schedule-interviews, not a participant.
        $reader = $this->without($this->hr, ['recruitment-applicants.schedule-interviews']);
        $this->actingAs($reader);
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertOk()
            ->assertDontSee(self::MEETING_URL)
            ->assertSee(__('recruitment-applicant.interviews.meeting_url_hidden'));

        // Never serialized with the model (e.g. into a Livewire payload or JSON).
        $this->assertArrayNotHasKey('meeting_url', $interview->fresh()->toArray());
    }

    /** [13] [27] Every candidate email is rendered and checked: no evaluation, note or reason can leak. */
    public function test_candidate_emails_never_contain_internal_evaluations_notes_or_reasons(): void
    {
        Mail::fake();
        $application = $this->shortlisted();
        $interview = $this->schedule($application, ['startsAt' => '2026-10-10T10:00']);

        RecruitmentInterviewEvaluation::query()->forceCreate([
            'interview_id' => $interview->id, 'reviewer_user_id' => $this->interviewer->id,
            'pros' => self::SECRET_EVALUATION, 'cons' => self::SECRET_EVALUATION, 'notes' => self::SECRET_EVALUATION,
            'recommendation' => 'reject', 'submitted_at' => now(),
        ]);
        RecruitmentApplicationDecision::query()->forceCreate([
            'application_id' => $application->id, 'from_status' => AppStatus::Shortlisted, 'to_status' => AppStatus::Shortlisted,
            'reason_code' => 'other', 'reason_text' => self::SECRET_DECISION_REASON, 'decided_by_user_id' => $this->hr->id, 'decided_at' => now(),
        ]);

        $interview = $this->reschedule($interview, ['startsAt' => '2026-10-11T10:00']);
        $this->travelTo(Carbon::parse('2026-10-10 11:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $this->cancel($interview->fresh(), 'other', self::SECRET_CANCEL_NOTE);

        $kinds = Notice::query()->where('status', Notice::STATUS_SENT)->orderBy('id')->pluck('kind')->all();
        $this->assertSame([Notice::KIND_SCHEDULED, Notice::KIND_RESCHEDULED, Notice::KIND_REMINDER, Notice::KIND_CANCELLED], $kinds);

        $rendered = [];
        Mail::assertSent(RecruitmentInterviewMail::class, function (RecruitmentInterviewMail $mail) use (&$rendered) {
            $rendered[] = $mail->render();

            return true;
        });
        $this->assertCount(4, $rendered);

        foreach ($rendered as $html) {
            foreach ([self::SECRET_EVALUATION, self::SECRET_CANCEL_NOTE, self::SECRET_RESCHEDULE_REASON, self::SECRET_DECISION_REASON] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
            $this->assertStringContainsString('IT Specialist', $html);
        }
    }

    // ------------------------------------------------------------------
    // Exceptional cases
    // ------------------------------------------------------------------

    /**
     * [14] HR picks any date and time, a past one included (an interview
     * already held is recorded after the fact) — on create and on
     * reschedule. The candidate is emailed only about a slot still ahead.
     */
    public function test_any_slot_may_be_chosen_and_only_a_future_one_is_emailed(): void
    {
        $application = $this->shortlisted();

        $past = $this->schedule($application, ['startsAt' => '2026-10-01T10:00']);
        $this->assertSame('2026-10-01 10:00', $past->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(0, $past->notices()->count(), 'No invitation to a slot already over.');

        $interview = $this->schedule($application->fresh());
        $this->assertSame(1, $interview->notices()->where('kind', Notice::KIND_SCHEDULED)->count());
        $this->reschedule($interview, ['startsAt' => '2026-10-02T10:00']);
        $this->assertSame('2026-10-02 10:00', $interview->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(0, $interview->notices()->where('kind', Notice::KIND_RESCHEDULED)->count());

        // A date that cannot exist is still refused, not rolled over.
        $this->assertValidationError(fn () => $this->schedule($application->fresh(), ['startsAt' => '2026-02-30T10:00']), 'startsAt');
    }

    /**
     * The join button is for an interview still to come. Once held,
     * cancelled or a no-show the link stays as plain text, for the record.
     */
    public function test_the_join_button_goes_once_the_interview_is_over(): void
    {
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-06T10:00']);
        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertSeeHtml('href="'.self::MEETING_URL.'"')
            ->assertSee(__('recruitment-applicant.interviews.join_meeting'));

        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->service()->recordOutcome($this->hr, $interview->fresh(), $interview->fresh()->status, $interview->scheduled_at->toIso8601String(), Status::Completed, null);
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertSee(self::MEETING_URL)
            ->assertSee(__('recruitment-applicant.interviews.meeting_over'))
            ->assertDontSeeHtml('href="'.self::MEETING_URL.'"')
            ->assertDontSee(__('recruitment-applicant.interviews.join_meeting'));
    }

    /** The interview form's dropdowns are the project's Select2, searchable as on the Leaves page — on create and on edit. */
    public function test_the_interview_form_uses_searchable_select2_for_every_dropdown(): void
    {
        $application = $this->shortlisted();
        $this->actingAs($this->hr);

        foreach ([
            Livewire::test(Form::class, ['applicationId' => $application->id])->html(),
            Livewire::test(Form::class, ['interviewId' => $this->schedule($application)->id])->html(),
        ] as $html) {
            foreach (['type', 'duration', 'locationType', 'newParticipantId', 'newParticipantRole'] as $id) {
                $this->assertMatchesRegularExpression('/<select x-ref="select"[^>]*id="'.$id.'"/', $html, $id.' is a Select2 dropdown');
            }
            $this->assertSame(substr_count($html, '<select'), substr_count($html, '<select x-ref="select"'), 'No plain select is left.');
            $this->assertSame(5, substr_count($html, 'minimumResultsForSearch: 0'));
            $this->assertStringNotContainsString('minimumResultsForSearch: Infinity', $html);
        }
    }

    /** [15] An inactive employee cannot be added; one who becomes inactive stays on but is no longer notified. */
    public function test_inactive_participants(): void
    {
        Notification::fake();
        $inactive = $this->makeUser($this->homs, 'employee', [], $this->makeEmployee($this->homs, 'gone-'.uniqid(), ['is_active' => false]));
        $application = $this->shortlisted();

        $this->assertValidationError(fn () => $this->schedule($application, ['participants' => [$inactive->id => Participant::ROLE_INTERVIEWER]]), 'participants');

        $interview = $this->schedule($application, ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
        Notification::assertSentToTimes($this->interviewer, ParticipantNotice::class, 1);

        $this->interviewer->profile->forceFill(['is_active' => false])->save();
        $this->reschedule($interview, ['startsAt' => '2026-10-09T10:00', 'participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);

        $participant = $interview->participants()->sole();
        $this->assertNull($participant->removed_at);
        $this->assertSame(Participant::NOTIFICATION_SKIPPED, $participant->notification_status);
        Notification::assertSentToTimes($this->interviewer, ParticipantNotice::class, 1);
    }

    /** [16] [24] A rejected candidate cannot be scheduled until the decision is reopened. */
    public function test_a_rejected_candidate_cannot_be_scheduled_until_reopened(): void
    {
        $application = $this->shortlisted();
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $application, AppStatus::Shortlisted, AppStatus::Rejected, null, 'position_filled');

        $errors = $this->assertValidationError(fn () => $this->schedule($application->fresh()), 'interview');
        $this->assertSame(__('recruitment-applicant.interviews.errors.application_rejected'), $errors['interview'][0]);

        $this->actingAs($this->hr);
        Livewire::test(ApplicantShow::class, ['applicantId' => $application->applicant_id])
            ->assertSee(__('recruitment-applicant.interviews.errors.application_rejected'))
            ->assertDontSee(route('recruitment.interviews.create', ['applicationId' => $application->id]));

        // Still refused while only reopened.
        $statuses->change($this->hr, $application->fresh(), AppStatus::Rejected, AppStatus::Submitted, 'Reopened after a second look at the CV');
        $this->assertValidationError(fn () => $this->schedule($application->fresh()), 'interview');

        $statuses->change($this->hr, $application->fresh(), AppStatus::Submitted, AppStatus::Shortlisted, null);
        $this->assertSame(Status::Scheduled, $this->schedule($application->fresh())->status);
        $this->assertSame(0, RecruitmentInterview::query()->where('status', '!=', Status::Scheduled)->count());
    }

    /** [17] A reminder sent for the old slot is recorded; the new slot gets its own reminder. */
    public function test_rescheduling_after_the_reminder_was_sent(): void
    {
        Mail::fake();
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $this->assertSame(Notice::STATUS_SENT, Notice::query()->where('kind', Notice::KIND_REMINDER)->sole()->status);

        $interview = $this->reschedule($interview->fresh(), ['startsAt' => '2026-10-14T10:00']);

        $this->assertTrue($interview->reschedules()->sole()->reminder_already_sent);
        $this->travelTo(Carbon::parse('2026-10-13 10:30:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();

        $reminders = Notice::query()->where('kind', Notice::KIND_REMINDER)->orderBy('id')->get();
        $this->assertCount(2, $reminders);
        $this->assertSame('2026-10-14 10:00', $reminders[1]->scheduled_for->format('Y-m-d H:i'));
        $this->assertSame(Notice::STATUS_SENT, $reminders[1]->status);
    }

    /** [17] A reminder still queued for the old slot never fires once the interview has moved. */
    public function test_a_queued_reminder_for_the_old_slot_never_fires(): void
    {
        Queue::fake();
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        $old = Notice::query()->where('kind', Notice::KIND_REMINDER)->sole();

        $interview = $this->reschedule($interview->fresh(), ['startsAt' => '2026-10-14T10:00']);

        $this->assertFalse($interview->reschedules()->sole()->reminder_already_sent);
        $this->assertSame(Notice::STATUS_CANCELLED, $old->fresh()->status);
        Mail::fake();
        $this->runNoticeJob($old->id);
        Mail::assertNothingSent();
    }

    /** [18] The candidate declines: cancelled with that reason; the application returns to shortlisted. */
    public function test_the_candidate_declines(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application);

        $interview = $this->cancel($interview, 'candidate_declined');

        $this->assertSame(Status::Cancelled, $interview->status);
        $this->assertSame('candidate_declined', $interview->cancellation_reason);
        $this->assertSame($this->hr->id, $interview->cancelled_by_user_id);
        $this->assertSame(AppStatus::Shortlisted, $application->fresh()->status);

        $this->assertValidationError(fn () => $this->cancel($this->schedule($application->fresh()), 'other', 'short'), 'cancelNote');
        $this->assertValidationError(fn () => $this->cancel(RecruitmentInterview::query()->latest('id')->first(), 'application_closed'), 'cancelReason');
    }

    /** [18] No-show only once the interview has started; the application goes back to shortlisted. */
    public function test_a_no_show_is_recorded_only_after_the_start_time(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application);

        $this->assertValidationError(fn () => $this->outcome($interview, Status::NoShow), 'outcome');
        $this->assertSame(Status::Scheduled, $interview->fresh()->status);

        $this->travelTo(Carbon::parse('2026-10-08 10:00:00'));
        $interview = $this->outcome($interview->fresh(), Status::NoShow);

        $this->assertSame(Status::NoShow, $interview->status);
        $this->assertSame($this->hr->id, $interview->outcome_recorded_by_user_id);
        $this->assertSame(AppStatus::Shortlisted, $application->fresh()->status);
    }

    /** [18] [29] A no-show after another interview was held leaves the application interviewed. */
    public function test_a_no_show_after_a_completed_interview_leaves_the_application_interviewed(): void
    {
        $application = $this->shortlisted();
        $first = $this->schedule($application, ['startsAt' => '2026-10-06T10:00']);
        $second = $this->schedule($application->fresh(), ['startsAt' => '2026-10-07T10:00']);

        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->outcome($first, Status::Completed);
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status, 'Another interview is still open.');

        $this->travelTo(Carbon::parse('2026-10-07 11:00:00'));
        $this->outcome($second->fresh(), Status::NoShow);
        $this->assertSame(AppStatus::Interviewed, $application->fresh()->status);
    }

    /** [19] Invalid dates and formats are refused; times are stored and shown in Europe/Istanbul. */
    public function test_dates_are_strict_and_times_follow_the_application_timezone(): void
    {
        $application = $this->shortlisted();

        foreach (['2026-02-30T10:00', '2026-10-10T25:00', '2026-10-10T10:61', '10/10/2026 10:00', '2026-10-10', 'tomorrow', ''] as $bad) {
            $this->assertValidationError(fn () => $this->schedule($application, ['startsAt' => $bad]), 'startsAt');
        }

        $this->assertSame('Europe/Istanbul', config('app.timezone'));
        Mail::fake();
        $interview = $this->schedule($application, ['startsAt' => '2026-10-10 18:45']);

        $this->assertSame('Europe/Istanbul', $interview->timezone);
        $this->assertSame('2026-10-10 18:45:00', DB::table('recruitment_interviews')->where('id', $interview->id)->value('scheduled_at'));

        $this->actingAs($this->hr);
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertSee('2026-10-10 18:45')
            ->assertSee('Europe/Istanbul (UTC+03:00)');
        Livewire::test(Form::class, ['interviewId' => $interview->id])->assertSet('startsAt', '2026-10-10T18:45');

        Mail::assertSent(RecruitmentInterviewMail::class, fn (RecruitmentInterviewMail $mail) => str_contains($mail->render(), '2026-10-10 18:45')
            && str_contains($mail->render(), 'Europe/Istanbul (UTC+03:00)'));
    }

    // ------------------------------------------------------------------
    // Data integrity
    // ------------------------------------------------------------------

    /** [20] Every legal transition, and every illegal one refused with the status left as it was. */
    public function test_the_interview_state_machine(): void
    {
        $open = [Status::Scheduled, Status::Rescheduled];
        $final = [Status::Cancelled, Status::Completed, Status::NoShow];

        foreach ($open as $from) {
            foreach ([Status::Rescheduled, Status::Cancelled, Status::Completed, Status::NoShow] as $to) {
                $this->assertTrue(Status::canMove($from, $to), "$from -> $to");
            }
            $this->assertFalse(Status::canMove($from, Status::Scheduled), "$from -> scheduled");
        }
        foreach ($final as $from) {
            foreach (Status::values() as $to) {
                $this->assertFalse(Status::canMove($from, $to), "$from -> $to");
            }
        }

        // Through the service: scheduled -> rescheduled -> completed, then everything refused.
        $interview = $this->reschedule($this->schedule($this->shortlisted()), ['startsAt' => '2026-10-06T10:00']);
        $this->assertSame(Status::Rescheduled, $interview->status);
        $this->assertValidationError(fn () => $this->outcome($interview, Status::Scheduled), 'outcome');
        $this->assertValidationError(fn () => $this->outcome($interview, Status::Cancelled), 'outcome');

        $this->travelTo(Carbon::parse('2026-10-06 10:30:00'));
        $interview = $this->outcome($interview->fresh(), Status::Completed);
        $this->assertSame(Status::Completed, $interview->fresh()->status);

        $this->assertValidationError(fn () => $this->cancel($interview, 'other', 'long enough note'), 'interview');
        $this->assertValidationError(fn () => $this->outcome($interview, Status::NoShow), 'interview');
        $this->assertValidationError(fn () => $this->reschedule($interview, ['startsAt' => '2026-10-20T10:00']), 'interview');
        $this->assertSame(Status::Completed, $interview->fresh()->status);

        // A stale edit (the user looked at an older state) is refused too.
        $other = $this->schedule($this->shortlisted('Stale Candidate'), ['startsAt' => '2026-10-20T10:00']);
        $this->assertValidationError(fn () => $this->service()->update($this->hr, $other, Status::Rescheduled, $other->scheduled_at->toIso8601String(), $this->input(), 'long enough reason'), 'interview');
        $this->assertValidationError(fn () => $this->service()->cancel($this->hr, $other, $other->status, now()->toIso8601String(), 'candidate_declined', null), 'interview');
    }

    /** [22] [25] Nothing is dispatched before the outermost commit, and a rollback leaves no job, notice or notification. */
    public function test_notifications_are_dispatched_only_after_commit(): void
    {
        Queue::fake();
        Notification::fake();
        $application = $this->shortlisted();

        try {
            DB::transaction(function () use ($application) {
                $this->schedule($application, ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
                $this->assertSame(1, Notice::query()->count(), 'The notice row is written inside the transaction...');
                Queue::assertNothingPushed();
                Notification::assertNothingSent();

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, RecruitmentInterview::query()->count());
        $this->assertSame(0, Notice::query()->count());
        Queue::assertNothingPushed();
        Notification::assertNothingSent();

        DB::transaction(function () use ($application) {
            $this->schedule($application->fresh(), ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
            Queue::assertNothingPushed();
        });
        Queue::assertPushed(SendRecruitmentInterviewNotice::class, 1);
        Notification::assertSentTo($this->interviewer, ParticipantNotice::class);
    }

    /** [22] [25] Reminders written by the sweep inside a transaction are dispatched only once it commits. */
    public function test_reminders_are_dispatched_only_after_commit(): void
    {
        Queue::fake();
        $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-10T10:00']);
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-09 12:00:00'));

        try {
            DB::transaction(function () {
                app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
                Queue::assertNothingPushed();

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, Notice::query()->where('kind', Notice::KIND_REMINDER)->count());
    }

    /** [23] [26] No delete path: the model refuses, and no route, component or service exposes one. */
    public function test_interviews_are_never_deleted(): void
    {
        $interview = $this->schedule($this->shortlisted());

        $this->assertThrows(fn () => $interview->delete(), LogicException::class);
        $this->assertThrows(fn () => RecruitmentInterview::query()->whereKey($interview->id)->first()->delete(), LogicException::class);
        // A query-level delete bypasses model events; the builder refuses it too.
        $this->assertThrows(fn () => RecruitmentInterview::query()->whereKey($interview->id)->delete(), LogicException::class);
        $this->assertThrows(fn () => RecruitmentInterview::query()->forceDelete(), LogicException::class);
        $this->assertThrows(fn () => $interview->application->interviews()->delete(), LogicException::class);
        $this->assertTrue(RecruitmentInterview::query()->whereKey($interview->id)->exists());

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'interviews')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }

        foreach ([Form::class, Show::class, Index::class, Calendar::class, Board::class, RecruitmentInterviewSchedulingService::class, RecruitmentInterviewNoticeService::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $class) {
                    $this->assertDoesNotMatchRegularExpression('/delete|destroy|remove(?!Participant)|purge/i', $method->getName(), "$class::{$method->getName()}");
                }
            }
        }

        $this->cancel($interview, 'scheduled_in_error');
        $this->assertTrue(RecruitmentInterview::query()->whereKey($interview->id)->where('status', Status::Cancelled)->exists());
    }

    // ------------------------------------------------------------------
    // Integration with the Task 6 status machine
    // ------------------------------------------------------------------

    /** [29] The last open interview cancelled: back to shortlisted. */
    public function test_cancelling_every_interview_returns_the_application_to_shortlisted(): void
    {
        $application = $this->shortlisted();
        $first = $this->schedule($application);
        $second = $this->schedule($application->fresh(), ['startsAt' => '2026-10-09T10:00']);

        $this->cancel($first, 'interviewer_unavailable');
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status);

        $this->cancel($second->fresh(), 'position_on_hold');
        $this->assertSame(AppStatus::Shortlisted, $application->fresh()->status);
        $this->assertSame(
            [AppStatus::Shortlisted, AppStatus::InterviewScheduled, AppStatus::Shortlisted],
            RecruitmentApplicationDecision::query()->where('application_id', $application->id)->orderBy('id')->pluck('to_status')->all(),
        );
    }

    /** [29] [30] Completed -> interviewed; another interview -> interview_scheduled again; then a manual rejection. */
    public function test_interviewed_and_additional_interview_transitions(): void
    {
        $application = $this->shortlisted();
        $first = $this->schedule($application, ['startsAt' => '2026-10-06T10:00']);
        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        $this->outcome($first, Status::Completed);
        $this->assertSame(AppStatus::Interviewed, $application->fresh()->status);

        // [30] interviewed -> interview_scheduled
        $this->schedule($application->fresh(), ['type' => 'final', 'startsAt' => '2026-10-12T10:00']);
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status);

        // [29] interview_scheduled -> rejected stays a manual decision.
        $statuses = app(RecruitmentApplicationStatusService::class);
        $this->assertSame([AppStatus::Rejected], $statuses->targets($application->fresh()));
        $statuses->change($this->hr, $application->fresh(), AppStatus::InterviewScheduled, AppStatus::Rejected, null, 'stronger_candidates');
        $this->assertSame(AppStatus::Rejected, $application->fresh()->status);

        $this->assertSame(
            [
                [AppStatus::Submitted, AppStatus::Shortlisted],
                [AppStatus::Shortlisted, AppStatus::InterviewScheduled],
                [AppStatus::InterviewScheduled, AppStatus::Interviewed],
                [AppStatus::Interviewed, AppStatus::InterviewScheduled],
                [AppStatus::InterviewScheduled, AppStatus::Rejected],
            ],
            RecruitmentApplicationDecision::query()->where('application_id', $application->id)->orderBy('id')->get()
                ->map(fn ($d) => [$d->from_status, $d->to_status])->all(),
        );
    }

    /** [31] The decision panel can neither enter nor leave the interview-driven states by hand. */
    public function test_interview_driven_transitions_cannot_be_made_manually(): void
    {
        $statuses = app(RecruitmentApplicationStatusService::class);
        $application = $this->shortlisted();

        $this->assertNotContains(AppStatus::InterviewScheduled, $statuses->targets($application));
        $this->assertValidationError(fn () => $statuses->change($this->hr, $application, AppStatus::Shortlisted, AppStatus::InterviewScheduled, null), 'statusTarget');

        $this->actingAs($this->hr);
        Livewire::test(ApplicantShow::class, ['applicantId' => $application->applicant_id])
            ->call('beginStatusChange', $application->id, AppStatus::InterviewScheduled)
            ->assertForbidden();

        $this->schedule($application);
        $application->refresh();
        foreach ([AppStatus::Interviewed, AppStatus::Shortlisted] as $to) {
            $this->assertValidationError(fn () => $statuses->change($this->hr, $application, AppStatus::InterviewScheduled, $to, 'long enough reason text'), 'statusTarget');
            Livewire::test(ApplicantShow::class, ['applicantId' => $application->applicant_id])
                ->call('beginStatusChange', $application->id, $to)
                ->assertForbidden();
        }

        // And the interview-only entry point refuses a screening move.
        $this->assertValidationError(fn () => DB::transaction(fn () => $statuses->applyInterviewTransition($this->hr, $application, AppStatus::Rejected)), 'statusTarget');
        $this->assertValidationError(fn () => DB::transaction(fn () => $statuses->applyInterviewTransition($this->hr, $application, AppStatus::Selected)), 'statusTarget');
        $this->assertSame(AppStatus::InterviewScheduled, $application->fresh()->status);
    }

    /** [32] Rejecting with an open interview cancels it (application_closed), tells the participants, and sends the candidate no interview email. */
    public function test_rejecting_an_application_cancels_its_open_interviews(): void
    {
        Mail::fake();
        Notification::fake();
        $application = $this->shortlisted();
        $open = $this->schedule($application, ['participants' => [$this->interviewer->id => Participant::ROLE_INTERVIEWER]]);
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        Mail::fake();

        app(RecruitmentApplicationStatusService::class)->change(
            $this->hr, $application->fresh(), AppStatus::InterviewScheduled, AppStatus::Rejected, null, 'position_filled', notify: true,
        );

        $open->refresh();
        $this->assertSame(Status::Cancelled, $open->status);
        $this->assertSame(RecruitmentInterviewSchedulingService::REASON_APPLICATION_CLOSED, $open->cancellation_reason);
        $this->assertSame(0, Notice::query()->where('status', Notice::STATUS_QUEUED)->count());
        Notification::assertSentTo($this->interviewer, ParticipantNotice::class, fn (ParticipantNotice $n) => $n->kind === ParticipantNotice::CANCELLED);
        Mail::assertNotSent(RecruitmentInterviewMail::class);
        $this->assertFalse(Notice::query()->where('kind', Notice::KIND_CANCELLED)->exists());

        // The reminder window passes: nothing goes out for it.
        app(RecruitmentInterviewNoticeService::class)->queueDueReminders();
        Mail::assertNotSent(RecruitmentInterviewMail::class);
    }

    // ------------------------------------------------------------------
    // The UI actions themselves (the service is covered above)
    // ------------------------------------------------------------------

    /** [9] [18] Cancel and record the outcome through the interview page's own panels. */
    public function test_hr_cancels_and_records_outcomes_from_the_interview_page(): void
    {
        $application = $this->shortlisted();
        $toCancel = $this->schedule($application);
        $toClose = $this->schedule($application->fresh(), ['startsAt' => '2026-10-06T10:00']);
        $this->actingAs($this->hr);

        Livewire::test(Show::class, ['interviewId' => $toCancel->id])
            ->call('beginCancel')
            ->set('cancelReason', 'candidate_declined')
            ->set('cancelNote', self::SECRET_CANCEL_NOTE)
            ->call('cancelInterview')
            ->assertHasNoErrors()
            ->assertSet('panel', null)
            ->assertSee(__('recruitment-applicant.interviews.cancellation_reasons.candidate_declined'))
            ->assertSee(self::SECRET_CANCEL_NOTE);
        $this->assertSame(Status::Cancelled, $toCancel->fresh()->status);

        // The outcome buttons appear only once the interview has started.
        Livewire::test(Show::class, ['interviewId' => $toClose->id])
            ->assertDontSee(__('recruitment-applicant.interviews.actions.completed'));
        $this->travelTo(Carbon::parse('2026-10-06 11:00:00'));
        Livewire::test(Show::class, ['interviewId' => $toClose->id])
            ->assertSee(__('recruitment-applicant.interviews.actions.completed'))
            ->call('beginOutcome', Status::Completed)
            ->set('outcomeNote', 'Held as planned')
            ->call('recordOutcome')
            ->assertHasNoErrors();
        $this->assertSame(Status::Completed, $toClose->fresh()->status);
        $this->assertSame(AppStatus::Interviewed, $application->fresh()->status);

        // An outcome code that is not one is refused before any panel opens.
        Livewire::test(Show::class, ['interviewId' => $toClose->id])->call('beginOutcome', Status::Cancelled)->assertForbidden();
    }

    /**
     * The interview's inline panels move focus the way the applicant page does
     * (a `focus-element` listener in app.js does the moving): into the panel on
     * open, back to its opener on dismiss, and to the interview title once the
     * action has closed the interview and its buttons are gone.
     */
    public function test_interview_panels_move_focus_and_keep_their_errors_inside(): void
    {
        $interview = $this->schedule($this->shortlisted());
        $this->actingAs($this->hr);

        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->call('beginCancel')
            ->assertSet('panelOpen', true)
            ->assertSeeHtml('id="interviewActionModal"')
            ->assertDispatched('focus-element', id: 'interview-panel-title')
            ->assertSeeHtml('aria-expanded="true"')
            ->call('dismissPanel')
            ->assertSet('panel', null)
            ->assertSet('panelOpen', false)
            ->assertDispatched('focus-element', id: 'interview-action-cancel');

        // A refusal from the service shows inside the open panel, with a way to reload.
        $page = Livewire::test(Show::class, ['interviewId' => $interview->id])->call('beginCancel');
        DB::table('recruitment_interviews')->where('id', $interview->id)->update(['status' => Status::Rescheduled]);
        $page->set('cancelReason', 'candidate_declined')
            ->call('cancelInterview')
            ->assertHasErrors('interview')
            ->assertSet('panel', 'cancel')
            ->assertSet('panelOpen', true)
            ->assertSee(__('recruitment-applicant.interviews.reload'));

        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->call('beginCancel')
            ->set('cancelReason', 'candidate_declined')
            ->call('cancelInterview')
            ->assertHasNoErrors()
            ->assertDispatched('focus-element', id: 'interview-title');
    }

    /** [9] [21] Rescheduling through the edit form keeps history; a missing reason is shown under the field. */
    public function test_hr_reschedules_from_the_edit_form(): void
    {
        $interview = $this->schedule($this->shortlisted());
        $this->actingAs($this->hr);

        Livewire::test(Form::class, ['interviewId' => $interview->id])
            ->assertSet('meetingUrl', self::MEETING_URL)
            ->set('startsAt', '2026-10-09T15:00')
            ->call('save')
            ->assertHasErrors(['reason'])
            ->set('reason', 'Panel member travelling that day')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('recruitment.interviews.show', ['interviewId' => $interview->id]));

        $interview->refresh();
        $this->assertSame(Status::Rescheduled, $interview->status);
        $this->assertSame('2026-10-09 15:00', $interview->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('Panel member travelling that day', $interview->reschedules()->sole()->reason);

        // A closed interview cannot be opened for editing at all.
        $this->cancel($interview, 'scheduled_in_error');
        Livewire::test(Form::class, ['interviewId' => $interview->id])->assertNotFound();
    }

    /** [3] [22] An email whose job never reached the queue is dispatched again by the sweep. */
    public function test_a_stalled_candidate_email_is_dispatched_again(): void
    {
        $interview = $this->schedule($this->shortlisted(), ['startsAt' => '2026-10-20T10:00']);
        $stalled = Notice::query()->forceCreate([
            'interview_id' => $interview->id, 'kind' => Notice::KIND_SCHEDULED, 'scheduled_for' => $interview->scheduled_at,
            'recipient_email' => 'candidate@example.test', 'status' => Notice::STATUS_QUEUED, 'queued_at' => now()->subHour(),
        ]);
        $fresh = Notice::query()->forceCreate([
            'interview_id' => $interview->id, 'kind' => Notice::KIND_RESCHEDULED, 'scheduled_for' => $interview->scheduled_at,
            'recipient_email' => 'candidate@example.test', 'status' => Notice::STATUS_QUEUED, 'queued_at' => now(),
        ]);
        Queue::fake();

        $this->artisan('recruitment:interview-reminders')->assertSuccessful();

        Queue::assertPushed(SendRecruitmentInterviewNotice::class, fn (SendRecruitmentInterviewNotice $job) => $job->noticeId === $stalled->id);
        Queue::assertNotPushed(SendRecruitmentInterviewNotice::class, fn (SendRecruitmentInterviewNotice $job) => $job->noticeId === $fresh->id);
    }

    /** A shortlisted application in $city (Homs by default), moved there through the decision service. */
    // ------------------------------------------------------------------
    // The manager who requested the position (read-only viewer)
    // ------------------------------------------------------------------

    /**
     * The requester follows every interview of the position he asked for —
     * every candidate, every round — read-only: the page, the meeting link,
     * the applicant button and every evaluation, but no HR action and no
     * evaluation form of his own.
     */
    public function test_the_requester_follows_every_interview_of_his_position_read_only(): void
    {
        $first = $this->shortlisted('Candidate One');
        $second = $this->sameCampaign($first, 'Candidate Two');
        $requester = $this->requesterOf($first);
        $one = $this->schedule($first, ['participants' => [$this->interviewer->id => 'interviewer']]);
        $two = $this->schedule($second);
        $round2 = $this->schedule($first->fresh(), ['type' => RecruitmentInterviewType::Final, 'startsAt' => '2026-10-09T10:00']);
        $elsewhere = $this->schedule($this->shortlisted('Someone Else'));

        $this->assertTrue($requester->can('viewAny', RecruitmentInterview::class));
        $this->assertEqualsCanonicalizing([$one->id, $two->id, $round2->id], RecruitmentInterview::query()->visibleTo($requester)->pluck('id')->all());

        $this->actingAs($requester);
        $this->get(route('recruitment.interviews.index'))->assertOk();
        Livewire::test(Show::class, ['interviewId' => $one->id])
            ->assertSee(self::MEETING_URL)
            ->assertSee(route('recruitment.applicants.show', ['applicantId' => $first->applicant_id]))
            ->assertDontSeeHtml('id="interview-action-cancel"')
            ->assertSeeLivewire(EvaluationSummary::class)
            ->call('beginCancel')->assertForbidden();
        Livewire::test(EvaluationForm::class, ['interviewId' => $one->id])->assertDontSee(__('recruitment-applicant.evaluations.my_title'));
        $this->get(route('recruitment.interviews.edit', ['interviewId' => $one->id]))->assertForbidden();
        $this->get(route('recruitment.interviews.create', ['applicationId' => $first->id]))->assertForbidden();

        // Every HR action is refused by the service as well, whatever the page shows.
        $at = $one->scheduled_at->toIso8601String();
        $this->assertThrows(fn () => $this->service()->schedule($requester, $first->fresh(), $this->input()), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service()->cancel($requester, $one, $one->status, $at, 'candidate_declined', null), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service()->update($requester, $one, $one->status, $at, $this->input(['startsAt' => '2026-10-10T10:00']), 'A reason long enough.'), AuthorizationException::class);
        $this->assertThrows(fn () => $this->service()->recordOutcome($requester, $one, $one->status, $at, Status::Completed, null), AuthorizationException::class);

        // Every evaluation, read-only; no form of his own to fill in.
        $this->travelTo(Carbon::parse('2026-10-08 11:00:00'));
        $this->service()->recordOutcome($this->hr, $one->fresh(), $one->fresh()->status, $one->scheduled_at->toIso8601String(), Status::Completed, null);
        $this->evaluations()->submit($this->interviewer, $one->fresh(), ['recommendation' => Recommendation::No, 'notes' => self::SECRET_EVALUATION], null);
        $evaluation = RecruitmentInterviewEvaluation::query()->sole();
        Livewire::test(EvaluationSummary::class, ['interviewId' => $one->id])
            ->assertSee(self::SECRET_EVALUATION)
            ->assertDontSeeHtml('id="evaluation-'.$evaluation->id.'-correct"')
            ->call('beginCorrect', $evaluation->id)->assertForbidden();
        $this->assertFalse($requester->can('correct', $evaluation));
        $this->assertThrows(fn () => $this->evaluations()->submit($requester, $one->fresh(), ['recommendation' => Recommendation::Yes], null), AuthorizationException::class);

        // Another position's interview stays out of reach.
        Livewire::test(Show::class, ['interviewId' => $elsewhere->id])->assertNotFound();
    }

    /** HR opened the position directly on his behalf: actual_requester_user_id makes him the requester. */
    public function test_the_manager_a_position_was_opened_for_is_its_requester(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application);
        $manager = $this->makeUser($this->homs, 'manager');
        $this->assertFalse($manager->can('view', $interview));

        $application->vacancy->createdFromRequest->forceFill(['actual_requester_user_id' => $manager->id])->save();

        $this->assertTrue($manager->can('view', $interview->fresh()));
        $this->assertTrue($manager->can('viewEvaluations', $interview->fresh()));
    }

    /**
     * Requester and participant at once: both sets of rights. He follows the
     * whole position and writes his own evaluation while evaluation is open —
     * never anyone else's, and not after it closes.
     */
    public function test_a_requester_on_the_panel_has_both_sets_of_rights(): void
    {
        $application = $this->shortlisted();
        $requester = $this->requesterOf($application);
        $interview = $this->schedule($application, ['participants' => [$requester->id => 'interviewer', $this->interviewer->id => 'interviewer']]);
        $other = $this->schedule($this->sameCampaign($application, 'Candidate Two'));

        $this->assertEqualsCanonicalizing([$interview->id, $other->id], RecruitmentInterview::query()->visibleTo($requester)->pluck('id')->all());

        $this->travelTo(Carbon::parse('2026-10-08 11:00:00'));
        $this->service()->recordOutcome($this->hr, $interview->fresh(), $interview->fresh()->status, $interview->scheduled_at->toIso8601String(), Status::Completed, null);
        $theirs = $this->evaluations()->submit($this->interviewer, $interview->fresh(), ['recommendation' => Recommendation::Maybe], null);

        $this->actingAs($requester);
        Livewire::test(EvaluationForm::class, ['interviewId' => $interview->id])
            ->set('recommendation', Recommendation::Yes)
            ->set('pros', 'Strong on budgets')
            ->call('save')
            ->assertHasNoErrors();
        $own = RecruitmentInterviewEvaluation::query()->where('reviewer_user_id', $requester->id)->sole();
        Livewire::test(EvaluationForm::class, ['interviewId' => $interview->id])->assertSee(__('recruitment-applicant.evaluations.my_title'));
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertSeeLivewire(EvaluationSummary::class)
            ->assertDontSeeHtml('id="interview-action-cancel"');
        $this->assertTrue($requester->can('update', $own));
        $this->assertFalse($requester->can('update', $theirs));
        $this->assertFalse($requester->can('correct', $theirs));

        // Closed: no more saving through the form, for him as for everyone.
        $this->evaluations()->close($this->hr, $interview->fresh(), Status::Completed);
        $this->assertThrows(fn () => $this->evaluations()->submit($requester, $interview->fresh(), ['recommendation' => Recommendation::No], $own->version), ValidationException::class);
    }

    /**
     * A participant who is neither HR nor the requester: only his own
     * interview and his own evaluation — no summary, no applicant button.
     * Another manager reaches nothing.
     */
    public function test_a_participant_only_and_another_manager_stay_within_option_one(): void
    {
        $application = $this->shortlisted();
        $interview = $this->schedule($application, ['participants' => [$this->interviewer->id => 'interviewer']]);
        $sameCampaign = $this->schedule($this->sameCampaign($application, 'Candidate Two'));

        $this->assertSame([$interview->id], RecruitmentInterview::query()->visibleTo($this->interviewer)->pluck('id')->all());
        $this->actingAs($this->interviewer);
        Livewire::test(Show::class, ['interviewId' => $interview->id])
            ->assertSee(self::MEETING_URL)
            ->assertDontSee(route('recruitment.applicants.show', ['applicantId' => $application->applicant_id]))
            ->assertDontSeeLivewire(EvaluationSummary::class);
        Livewire::test(EvaluationSummary::class, ['interviewId' => $interview->id])->assertForbidden();
        Livewire::test(Show::class, ['interviewId' => $sameCampaign->id])->assertNotFound();

        $manager = $this->makeUser($this->homs, 'manager');
        $this->actingAs($manager);
        $this->assertFalse($manager->can('viewAny', RecruitmentInterview::class));
        Livewire::test(Show::class, ['interviewId' => $interview->id])->assertNotFound();
        Livewire::test(EvaluationSummary::class, ['interviewId' => $interview->id])->assertNotFound();
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
        \App\Models\Role::findByName($user->myRole(), 'web')->revokePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function service(): RecruitmentInterviewSchedulingService
    {
        return app(RecruitmentInterviewSchedulingService::class);
    }

    /** The user who raised the request behind this application's vacancy. */
    private function requesterOf(RecruitmentApplication $application): User
    {
        return User::query()->findOrFail($application->vacancy->createdFromRequest->requester_user_id);
    }

    /** Another shortlisted candidate on the same campaign — the same position, the same requester. */
    private function sameCampaign(RecruitmentApplication $application, string $name): RecruitmentApplication
    {
        $other = $this->applyTo($application->campaign, $this->makeApplicant($name));
        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($this->hr, $other, AppStatus::Submitted, AppStatus::UnderReview, null);
        $statuses->change($this->hr, $other->fresh(), AppStatus::UnderReview, AppStatus::Shortlisted, null);

        return $other->fresh();
    }

    private function evaluations(): RecruitmentInterviewEvaluationService
    {
        return app(RecruitmentInterviewEvaluationService::class);
    }

    private function shortlisted(string $name = 'Candidate One', ?City $city = null): RecruitmentApplication
    {
        $city ??= $this->homs;
        $application = $this->applyTo($this->makeCampaignIn($city), $this->makeApplicant($name));
        $hr = $city->is($this->homs) ? $this->hr : $this->hr($city);

        $statuses = app(RecruitmentApplicationStatusService::class);
        $statuses->change($hr, $application, AppStatus::Submitted, AppStatus::Shortlisted, null);

        return $application->fresh();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'type' => RecruitmentInterviewType::Hr,
            'startsAt' => '2026-10-08T10:00',
            'duration' => 60,
            'locationType' => 'online',
            'locationValue' => 'Microsoft Teams',
            'meetingUrl' => self::MEETING_URL,
            'participants' => [],
        ], $overrides);
    }

    private function schedule(RecruitmentApplication $application, array $overrides = [], ?User $actor = null): RecruitmentInterview
    {
        return $this->service()->schedule($actor ?? $this->hr, $application, $this->input($overrides));
    }

    /** Edits the interview; everything not overridden is kept as it is. */
    private function reschedule(RecruitmentInterview $interview, array $overrides = [], ?string $reason = self::SECRET_RESCHEDULE_REASON): RecruitmentInterview
    {
        $interview = $interview->fresh();
        $current = [
            'type' => $interview->interview_type,
            'startsAt' => $interview->scheduled_at->format('Y-m-d\TH:i'),
            'duration' => (int) $interview->scheduled_at->diffInMinutes($interview->ends_at),
            'locationType' => $interview->location_type,
            'locationValue' => $interview->location_value,
            'meetingUrl' => $interview->meeting_url,
            'participants' => $interview->currentParticipants()->pluck('role', 'user_id')->all(),
        ];

        return $this->service()->update($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), array_merge($current, $overrides), $reason)->fresh();
    }

    private function cancel(RecruitmentInterview $interview, string $reason, ?string $note = null): RecruitmentInterview
    {
        $interview = $interview->fresh();

        return $this->service()->cancel($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), $reason, $note)->fresh();
    }

    private function outcome(RecruitmentInterview $interview, string $outcome): RecruitmentInterview
    {
        $interview = $interview->fresh();

        return $this->service()->recordOutcome($this->hr, $interview, $interview->status, $interview->scheduled_at->toIso8601String(), $outcome, null)->fresh();
    }

    private function runNoticeJob(int $noticeId): void
    {
        (new SendRecruitmentInterviewNotice($noticeId))->handle(app(RecruitmentInterviewNoticeService::class));
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
