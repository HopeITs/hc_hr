<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Jobs\SendRecruitmentRejectionNotice;
use App\Livewire\Recruitment\Applicants\ApplicationTable;
use App\Livewire\Recruitment\Applicants\BulkReject;
use App\Livewire\Recruitment\Applicants\Show;
use App\Mail\RecruitmentRejectionMail;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentRejectionNotice;
use App\Models\Role;
use App\Models\User;
use App\Services\RecruitmentApplicationStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentDecisionTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const NOTE = 'Internal: weak interview answers about budgeting';

    private City $homs;

    private City $damascus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);

        $this->homs = $this->makeCity('HMS', '002');
        $this->damascus = $this->makeCity('DMS', '003');
    }

    // ------------------------------------------------------------------
    // Single decisions
    // ------------------------------------------------------------------

    public function test_a_candidate_can_be_shortlisted_directly_but_must_be_interviewed_before_acceptance(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $this->actingAs($hr);

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Shortlisted)
            ->set('statusReason', 'Candidate meets the requirements for an interview.')
            ->call('changeStatus')
            ->assertHasNoErrors()
            ->assertDontSee(__('recruitment-applicant.decisions.actions.selected'))
            ->call('beginStatusChange', $application->id, Status::Selected)
            ->assertForbidden();

        $this->assertSame(Status::Shortlisted, $application->fresh()->status);
        $this->assertSame(
            [[Status::Submitted, Status::Shortlisted]],
            RecruitmentApplicationDecision::query()->orderBy('id')->get()
                ->map(fn ($decision): array => [$decision->from_status, $decision->to_status])->all(),
        );
        $this->assertSame([$hr->id], RecruitmentApplicationDecision::query()->pluck('decided_by_user_id')->unique()->map(fn ($id) => (int) $id)->values()->all());
    }

    public function test_accepting_needs_its_own_permission_on_top_of_change_status(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant, ['status' => Status::Interviewed]);
        $hr = $this->without($this->hr($this->homs), ['recruitment-applicants.accept']);
        $this->actingAs($hr);

        $this->assertNotContains(Status::Selected, app(RecruitmentApplicationStatusService::class)->targetsFor($hr, $application));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertDontSee(__('recruitment-applicant.decisions.actions.selected'))
            ->call('beginStatusChange', $application->id, Status::Selected)
            ->assertForbidden();

        // The service refuses too, whoever calls it.
        $this->expectException(AuthorizationException::class);
        app(RecruitmentApplicationStatusService::class)->change($hr, $application, Status::Interviewed, Status::Selected, null);
    }

    public function test_selected_applications_move_forward_to_offer_not_back_to_screening(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant, ['status' => Status::Interviewed]);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);
        $this->actingAs($hr);

        $statuses->change($hr, $application, Status::Interviewed, Status::Selected, null);
        $application->refresh();

        $this->assertSame(Status::Selected, $application->status);
        $this->assertSame([], $statuses->targetsFor($hr, $application));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSee(__('recruitment-applicant.offers.create'))
            ->assertDontSee(__('recruitment-applicant.decisions.actions.shortlisted'))
            ->assertDontSee(__('recruitment-applicant.decisions.actions.rejected'))
            ->call('beginStatusChange', $application->id, Status::Rejected)
            ->assertForbidden();
    }

    public function test_rejecting_requires_a_configured_reason(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Rejected)
            ->set('statusReason', 'Application does not meet the required language level.')
            ->call('changeStatus')
            ->assertHasErrors('statusReasonCode')
            ->set('statusReasonCode', 'made_up_reason')
            ->call('changeStatus')
            ->assertHasErrors('statusReasonCode')
            ->set('statusReasonCode', 'language_requirement')
            ->set('statusNotify', false)
            ->call('changeStatus')
            ->assertHasNoErrors();

        $decision = RecruitmentApplicationDecision::query()->sole();
        $this->assertSame([Status::Rejected, 'language_requirement'], [$application->fresh()->status, $decision->reason_code]);
    }

    public function test_status_cannot_be_changed_outside_the_decision_service(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        // A tampered request cannot swap the target the button fixed.
        try {
            Livewire::test(Show::class, ['applicantId' => $applicant->id])
                ->call('beginStatusChange', $application->id, Status::Shortlisted)
                ->set('statusTarget', Status::Hired);
            $this->fail('statusTarget must be locked.');
        } catch (CannotUpdateLockedPropertyException) {
        }

        // Nor can a move this user is not offered be opened by hand.
        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Hired)
            ->assertForbidden();

        // And nothing in the code base can write status past the service.
        $this->expectException(LogicException::class);
        try {
            $application->update(['status' => Status::Hired]);
        } finally {
            $this->assertSame(Status::Submitted, $application->fresh()->status);
            $this->assertSame(0, RecruitmentApplicationDecision::query()->count());
        }
    }

    // ------------------------------------------------------------------
    // Rejection email
    // ------------------------------------------------------------------

    public function test_the_rejection_email_carries_no_reason_and_is_never_sent_twice(): void
    {
        Mail::fake();
        $applicant = $this->makeApplicant('Rana Haddad');
        $application = $this->applyTo($this->makeCampaignIn($this->homs, title: 'Field Officer'), $applicant);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        $statuses->change($hr, $application, Status::Submitted, Status::Rejected, self::NOTE, 'language_requirement', notify: true);

        Mail::assertSent(RecruitmentRejectionMail::class, function (RecruitmentRejectionMail $mail) use ($application): bool {
            $html = $mail->render();

            return $mail->hasTo($application->email_normalized)
                && str_contains($html, 'Rana Haddad')
                && str_contains($html, 'Field Officer')
                && ! str_contains($html, self::NOTE)
                && ! str_contains($html, __('recruitment-applicant.decisions.rejection_reasons.language_requirement', [], 'en'))
                && ! str_contains($html, __('recruitment-applicant.decisions.rejection_reasons.language_requirement', [], 'ar'));
        });
        $this->assertSame(RecruitmentRejectionNotice::STATUS_SENT, $application->rejectionNotice()->sole()->status);

        // Reopen and reject again: a second decision, but no second email.
        $statuses->change($hr, $application, Status::Rejected, Status::Submitted, 'New references arrived for review');
        $statuses->change($hr, $application, Status::Submitted, Status::Rejected, null, 'stronger_candidates', notify: true);
        $statuses->bulkReject($hr, [$application->id], 'stronger_candidates', null, notify: true);

        Mail::assertSentCount(1);
        $this->assertSame(1, RecruitmentRejectionNotice::query()->count());
        $this->assertSame(3, RecruitmentApplicationDecision::query()->count());
    }

    public function test_a_failed_email_never_reverts_the_decision_and_can_be_retried(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        // A mail server that refuses everything.
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('SMTP refused');
            }

            public function __toString(): string
            {
                return 'refusing';
            }
        });
        config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);

        $result = $statuses->bulkReject($hr, [$application->id], 'qualifications_mismatch', null, notify: true);

        $this->assertSame([1, 0, 1], [$result['rejected'], $result['queued'], $result['queue_failed']]);
        $this->assertSame(Status::Rejected, $application->fresh()->status);
        $notice = $application->rejectionNotice()->sole();
        $this->assertSame(RecruitmentRejectionNotice::STATUS_FAILED, $notice->status);
        $this->assertNotNull($notice->failure_reason);

        // Retrying reaches only the email; the decision is not taken again.
        Mail::fake();
        $result = $statuses->bulkReject($hr, [$application->id], 'qualifications_mismatch', null, notify: true);

        $this->assertSame([0, 1, 1], [$result['rejected'], $result['already_rejected'], $result['queued']]);
        Mail::assertSentCount(1);
        $this->assertSame(RecruitmentRejectionNotice::STATUS_SENT, $notice->fresh()->status);
        $this->assertSame(1, RecruitmentApplicationDecision::query()->count());
    }

    public function test_a_queued_email_is_cancelled_when_the_application_is_reopened(): void
    {
        Queue::fake();
        Mail::fake();
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        $statuses->change($hr, $application, Status::Submitted, Status::Rejected, null, 'position_filled', notify: true);
        Queue::assertPushed(SendRecruitmentRejectionNotice::class, 1);

        $statuses->change($hr, $application, Status::Rejected, Status::Submitted, 'Rejected by mistake, reviewing again');
        app()->call([new SendRecruitmentRejectionNotice($application->rejectionNotice->id), 'handle']);

        Mail::assertNothingSent();
        $this->assertSame(RecruitmentRejectionNotice::STATUS_CANCELLED, $application->rejectionNotice()->sole()->status);
    }

    public function test_a_notice_is_sent_at_most_once_even_when_its_job_runs_twice(): void
    {
        Queue::fake();
        Mail::fake();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        $statuses->change($hr, $application, Status::Submitted, Status::Rejected, null, 'position_filled', notify: true);
        $job = new SendRecruitmentRejectionNotice($application->rejectionNotice->id);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        Mail::assertSentCount(1);

        // A worker that died mid-send leaves "sending": never re-sent, never re-queued.
        $application->rejectionNotice->forceFill(['status' => RecruitmentRejectionNotice::STATUS_SENDING])->save();
        app()->call([$job, 'handle']);
        $result = $statuses->bulkReject($hr, [$application->id], 'position_filled', null, notify: true);

        Mail::assertSentCount(1);
        $this->assertSame(1, $result['already_notified']);
    }

    public function test_a_queued_notice_whose_job_never_ran_is_dispatched_again(): void
    {
        Queue::fake();
        Mail::fake();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        $statuses->change($hr, $application, Status::Submitted, Status::Rejected, null, 'position_filled', notify: true);
        Queue::fake(); // the job is lost: committed as queued, never on the queue

        // Counted as settled, so a retry from the bulk action cannot reach it.
        $result = $statuses->bulkReject($hr, [$application->id], 'position_filled', null, notify: true);
        $this->assertSame(1, $result['already_notified']);

        // Too recent to be called stalled: it may still be waiting in the queue.
        $this->artisan('recruitment:rejection-notices')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(20)->minutes();
        $this->artisan('recruitment:rejection-notices')->assertSuccessful();
        Queue::assertPushed(SendRecruitmentRejectionNotice::class, 1);

        // The next sweep leaves it alone: its clock was pushed forward.
        $this->artisan('recruitment:rejection-notices')->assertSuccessful();
        Queue::assertPushed(SendRecruitmentRejectionNotice::class, 1);

        app()->call([new SendRecruitmentRejectionNotice($application->rejectionNotice->id), 'handle']);
        Mail::assertSent(RecruitmentRejectionMail::class, 1);
        $this->assertSame(RecruitmentRejectionNotice::STATUS_SENT, $application->rejectionNotice()->sole()->status);

        $this->travel(20)->minutes();
        $this->artisan('recruitment:rejection-notices')->assertSuccessful();
        Queue::assertPushed(SendRecruitmentRejectionNotice::class, 1);
    }

    // ------------------------------------------------------------------
    // Bulk rejection
    // ------------------------------------------------------------------

    public function test_bulk_rejection_filters_scope_and_status_and_logs_every_outcome(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaignIn($this->homs);
        $submitted = $this->applyTo($campaign, $this->makeApplicant('One'));
        $shortlisted = $this->applyTo($campaign, $this->makeApplicant('Two'), ['status' => Status::Shortlisted]);
        $alreadyRejected = $this->applyTo($campaign, $this->makeApplicant('Three'), ['status' => Status::Rejected]);
        $interviewed = $this->applyTo($campaign, $this->makeApplicant('Four'), ['status' => Status::Interviewed]);
        $anonymized = $this->makeApplicant('Five');
        $anonymized->forceFill(['anonymized_at' => now()])->save();
        $noEmail = $this->applyTo($campaign, $anonymized);
        $theirs = $this->applyTo($this->makeCampaignIn($this->damascus), $this->makeApplicant('Six'));
        $hr = $this->hr($this->homs);
        $this->actingAs($hr);

        Livewire::test(BulkReject::class)
            ->call('open', [$submitted->id, $shortlisted->id, $alreadyRejected->id, $interviewed->id, $noEmail->id, $theirs->id, 'x', -3])
            ->call('apply')
            ->assertHasErrors('reasonCode')
            ->set('reasonCode', 'other')
            ->call('apply')
            ->assertHasErrors('reason')
            ->set('reason', self::NOTE)
            ->call('apply')
            ->assertHasNoErrors()
            ->assertSet('result', [
                'rejected' => 3, 'already_rejected' => 1, 'ineligible' => 1, 'out_of_scope' => 1,
                'queued' => 3, 'already_notified' => 0, 'no_recipient' => 1, 'queue_failed' => 0,
            ])
            ->assertDispatched('recruitment-decisions-changed');

        $this->assertSame(
            [Status::Rejected, Status::Rejected, Status::Rejected, Status::Interviewed, Status::Rejected, Status::Submitted],
            array_map(fn (RecruitmentApplication $a): string => $a->fresh()->status, [$submitted, $shortlisted, $alreadyRejected, $interviewed, $noEmail, $theirs]),
        );
        // The already-rejected application keeps its original decision; out-of-scope gets nothing.
        $this->assertSame(3, RecruitmentApplicationDecision::query()->count());
        $this->assertSame(0, RecruitmentApplicationDecision::query()->where('application_id', $theirs->id)->count());
        $this->assertSame(0, RecruitmentRejectionNotice::query()->where('application_id', $theirs->id)->count());
        $this->assertSame(0, RecruitmentRejectionNotice::query()->where('application_id', $noEmail->id)->count());
        Mail::assertSentCount(3);
        Mail::assertNotSent(RecruitmentRejectionMail::class, fn (RecruitmentRejectionMail $mail): bool => $mail->hasTo($theirs->email_normalized));
        Mail::assertSent(RecruitmentRejectionMail::class, fn (RecruitmentRejectionMail $mail): bool => ! str_contains($mail->render(), self::NOTE));

        $log = Activity::query()->where('description', 'recruitment_application.bulk_rejected')->sole();
        $this->assertSame(1, $log->properties['out_of_scope_count']);
        $this->assertEqualsCanonicalizing([$submitted->id, $shortlisted->id, $noEmail->id], $log->properties['rejected_ids']);
        $this->assertSame([$interviewed->id], $log->properties['ineligible_ids']);
        $this->assertNotContains($theirs->id, $log->properties['rejected_ids']);
    }

    public function test_bulk_rejection_needs_its_own_permission_everywhere(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $hr = $this->without($this->hr($this->homs), ['recruitment-applicants.bulk-reject']);
        $this->actingAs($hr);
        $ids = [$application->id];

        Livewire::test(BulkReject::class)->call('open', $ids)->assertForbidden();
        Livewire::test(BulkReject::class)->set('applicationIds', $ids)->set('reasonCode', 'other')->call('apply')->assertForbidden();
        Livewire::test(ApplicationTable::class)->set('selected', ['1'])->call('executeAction', ApplicationTable::BULK_REJECT)->assertForbidden();
        Livewire::test(ApplicationTable::class)->call('executeItemAction', ApplicationTable::BULK_REJECT, '1')->assertForbidden();
        Livewire::test(ApplicationTable::class)->call('executeAction', 'delete_everything')->assertForbidden();

        try {
            app(RecruitmentApplicationStatusService::class)->bulkReject($hr, $ids, 'position_filled', null, notify: false);
            $this->fail('The service must refuse without the permission.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(Status::Submitted, $application->fresh()->status);

        Role::findByName($hr->myRole(), 'web')->givePermissionTo('recruitment-applicants.bulk-reject');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($hr->fresh());

        Livewire::test(ApplicationTable::class)
            ->set('selected', [(string) $application->id])
            ->call('executeAction', ApplicationTable::BULK_REJECT)
            ->assertDispatchedTo(BulkReject::class, 'recruitment-bulk-reject');
    }

    public function test_bulk_rejection_refuses_an_empty_or_oversized_selection(): void
    {
        config(['recruitment.decisions.bulk_limit' => 2]);
        $hr = $this->hr($this->homs);
        $statuses = app(RecruitmentApplicationStatusService::class);

        foreach ([[], ['abc'], [1, 2, 3]] as $ids) {
            try {
                $statuses->bulkReject($hr, $ids, 'position_filled', null, notify: false);
                $this->fail('Expected the selection to be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('applicationIds', $exception->errors());
            }
        }
    }

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
}
