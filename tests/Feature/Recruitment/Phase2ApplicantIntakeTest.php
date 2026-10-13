<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus;
use App\Enums\RecruitmentCampaignLinkMode;
use App\Enums\RecruitmentRequestMode;
use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Http\Controllers\PublicCareerController;
use App\Livewire\Recruitment\Campaigns\InviteTable;
use App\Livewire\Recruitment\Campaigns\PublishingHub;
use App\Livewire\Recruitment\PublicLinks\Manager;
use App\Models\ApplicationForm;
use App\Models\City;
use App\Models\Department;
use App\Models\HiringCampaign;
use App\Models\JobLevel;
use App\Models\JobPost;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Models\Vacancy;
use App\Policies\RecruitmentApplicantPolicy;
use App\Policies\RecruitmentApplicationPolicy;
use App\Policies\RecruitmentCampaignInvitePolicy;
use App\Services\PublicRecruitmentLinkService;
use App\Services\RecruitmentApplicantIdentityService;
use App\Services\RecruitmentCampaignInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Phase 2 Tasks 1 and 2 (recruitment-execution-plan-ar.md), end to end
 * through Team 1's real public endpoint and Team 2's additions on top of it.
 *
 * Each test names the plan item it covers. Everything that can go through
 * HTTP does, so the throttles and TrimStrings are part of what is tested.
 */
class Phase2ApplicantIntakeTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        Storage::fake('recruitment-quarantine');
        Storage::fake('recruitment-files');
        config([
            'recruitment-uploads.quarantine_disk' => 'recruitment-quarantine',
            'recruitment-uploads.clean_disk' => 'recruitment-files',
        ]);
    }

    // ------------------------------------------------------------------
    // Integration with Team 1 (merge regressions)
    // ------------------------------------------------------------------

    public function test_team2_policies_are_registered_for_the_real_models(): void
    {
        $this->assertInstanceOf(RecruitmentApplicantPolicy::class, Gate::getPolicyFor(RecruitmentApplicant::class));
        $this->assertInstanceOf(RecruitmentApplicationPolicy::class, Gate::getPolicyFor(RecruitmentApplication::class));
        $this->assertInstanceOf(RecruitmentCampaignInvitePolicy::class, Gate::getPolicyFor(RecruitmentCampaignInvite::class));
    }

    /** Our old test form once shadowed this route and every link opened it. */
    public function test_the_public_careers_route_belongs_to_team1s_real_form(): void
    {
        $this->assertSame(
            PublicCareerController::class.'@show',
            Route::getRoutes()->getByName('careers.show')->getActionName(),
        );
    }

    // ------------------------------------------------------------------
    // Task 2 — public submission
    // ------------------------------------------------------------------

    /** Task 1 + 2 acceptance: a valid submission creates applicant, application and answers. */
    public function test_a_valid_submission_creates_the_applicant_application_and_answers(): void
    {
        [$campaign, , $token] = $this->campaignWithLink();

        $this->submit($token, $this->answers('Applicant@Example.com'))->assertRedirect();

        $application = RecruitmentApplication::query()->sole();
        $applicant = RecruitmentApplicant::query()->sole();
        $this->assertSame(RecruitmentApplicationStatus::Submitted, $application->status);
        $this->assertSame($campaign->id, (int) $application->campaign_id);
        $this->assertSame($campaign->vacancy_id, (int) $application->vacancy_id);
        $this->assertSame((int) $campaign->applicationForm->current_version_id, (int) $application->form_version_id);
        $this->assertSame('applicant@example.com', $application->email_normalized);
        $this->assertSame($application->applicant_id, $applicant->id);
        $this->assertSame($this->city->id, (int) $applicant->city_id);
        $this->assertGreaterThan(0, $application->answers()->count());
        $this->assertSame(['value' => 'Applicant Name'], $application->answers()->where('field_key', 'full_name')->sole()->answer_json);
    }

    /** Task 2 notes: "Create audit/submission log" — identifiers only, no PII. */
    public function test_a_submission_writes_an_audit_entry_without_personal_data(): void
    {
        [, , $token] = $this->campaignWithLink();
        $this->submit($token, $this->answers('audit@example.com'))->assertRedirect();

        $entry = DB::table('activity_log')->where('description', 'recruitment_application_submitted')->sole();
        $this->assertSame(RecruitmentApplication::query()->sole()->id, (int) $entry->subject_id);
        $this->assertStringNotContainsString('audit@example.com', (string) $entry->properties);
        $this->assertStringNotContainsString('Applicant Name', (string) $entry->properties);
    }

    /** Task 2: double submit / browser retry after a timeout. */
    public function test_the_same_submission_key_never_creates_a_second_application(): void
    {
        [, , $token] = $this->campaignWithLink();
        $key = bin2hex(random_bytes(32));

        $this->submit($token, $this->answers('retry@example.com'), $key)->assertRedirect();
        $this->submit($token, $this->answers('retry@example.com'), $key)->assertRedirect();

        $this->assertSame(1, RecruitmentApplication::query()->count());
    }

    /** Task 1: same person, same campaign twice — prevented. Spaces and case do not dodge it. */
    public function test_the_same_email_cannot_apply_twice_to_one_campaign(): void
    {
        [, , $token] = $this->campaignWithLink();

        $this->submit($token, $this->answers('twice@example.com'))->assertRedirect();
        $this->submit($token, $this->answers('  TWICE@Example.com '))
            ->assertUnprocessable()
            ->assertSee(__('careers.generic_submission_error'));

        $this->assertSame(1, RecruitmentApplication::query()->count());
    }

    /** Task 1: same person, two campaigns — one applicant, two applications. */
    public function test_one_person_on_two_campaigns_is_one_applicant(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();

        $this->submit($first, $this->answers('both@example.com'))->assertRedirect();
        $this->submit($second, $this->answers('Both@Example.com'))->assertRedirect();

        $this->assertSame(1, RecruitmentApplicant::query()->count());
        $this->assertSame(2, RecruitmentApplication::query()->count());
    }

    /**
     * The form cannot tell the owner of an email from someone typing it, so a
     * second submission must not rewrite the person HR already has on file —
     * otherwise applying to any campaign with a known address would redirect
     * that person's interview call to someone else's phone.
     */
    public function test_a_later_submission_cannot_rewrite_an_existing_applicants_name_or_phone(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();

        $this->submit($first, $this->answers('victim@example.com', 'Real Person', '+963 111 111 111'))->assertStatus(202);
        $this->submit($second, $this->answers('VICTIM@example.com', 'Someone Else', '+963 999 999 999'))->assertStatus(202);

        $applicant = RecruitmentApplicant::query()->sole();
        $this->assertSame('Real Person', $applicant->full_name);
        $this->assertSame('+963111111111', $applicant->phone_normalized);
        $this->assertSame(2, $applicant->applications()->count());
    }

    /** Refusing the overwrite must not lose what the second submission said. */
    public function test_a_refused_overwrite_still_keeps_the_submission_answers(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();

        $this->submit($first, $this->answers('kept@example.com', 'Real Person', '+963 111 111 111'))->assertStatus(202);
        $this->submit($second, $this->answers('kept@example.com', 'Someone Else', '+963 999 999 999'))->assertStatus(202);

        $latest = RecruitmentApplication::query()->latest('id')->first();
        $this->assertSame(['value' => 'Someone Else'], $latest->answers()->where('field_key', 'full_name')->sole()->answer_json);
        $this->assertSame(['value' => '+963 999 999 999'], $latest->answers()->where('field_key', 'phone')->sole()->answer_json);
    }

    /** Only what the record has no value for is filled in. */
    public function test_a_later_submission_fills_a_missing_phone_but_nothing_else(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();

        $this->submit($first, $this->answers('gap@example.com', 'Real Person', '+963 111 111 111'))->assertStatus(202);
        RecruitmentApplicant::query()->sole()->forceFill(['phone_normalized' => null])->save();

        $this->submit($second, $this->answers('gap@example.com', 'Someone Else', '+963 999 999 999'))->assertStatus(202);

        $applicant = RecruitmentApplicant::query()->sole();
        $this->assertSame('Real Person', $applicant->full_name);
        $this->assertSame('+963999999999', $applicant->phone_normalized);
    }

    /** A new application restarts the retention clock, but never cancels an erasure request. */
    public function test_a_later_submission_keeps_a_pending_erasure_request_and_its_date(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();
        [, , $third] = $this->campaignWithLink();

        $this->submit($first, $this->answers('erase@example.com'))->assertStatus(202);
        $requested = now()->subDay()->startOfSecond();
        $applicant = RecruitmentApplicant::query()->sole();
        $applicant->forceFill(['retention_until' => now()->addDays(30)])->save();

        // No erasure request: the clock restarts, as before.
        $this->submit($second, $this->answers('erase@example.com'))->assertStatus(202);
        $this->assertNull($applicant->fresh()->retention_until);

        $applicant->forceFill(['erasure_requested_at' => $requested, 'retention_until' => $requested])->save();
        $this->submit($third, $this->answers('erase@example.com'))->assertStatus(202);

        $applicant = $applicant->fresh();
        $this->assertTrue($requested->equalTo($applicant->erasure_requested_at));
        $this->assertTrue($requested->equalTo($applicant->retention_until));
    }

    /** Team 2's intake service writes the same record, so it follows the same rule. */
    public function test_the_shared_identity_service_only_fills_blanks_for_an_existing_applicant(): void
    {
        $identity = app(RecruitmentApplicantIdentityService::class);
        $identity->findOrCreate(['full_name' => 'Real Person', 'email' => 'shared@example.com', 'phone' => '+963 111 111 111', 'source' => 'public_link']);

        $identity->findOrCreate(['full_name' => 'Someone Else', 'email' => ' SHARED@example.com', 'phone' => '+963 999 999 999', 'source' => 'public_link']);

        $applicant = RecruitmentApplicant::query()->sole();
        $this->assertSame('Real Person', $applicant->full_name);
        $this->assertSame('+963111111111', $applicant->phone_normalized);

        $applicant->forceFill(['phone_normalized' => null])->save();
        $identity->findOrCreate(['full_name' => 'Someone Else', 'email' => 'shared@example.com', 'phone' => '+963 999 999 999', 'source' => 'public_link']);

        $applicant = $applicant->fresh();
        $this->assertSame('Real Person', $applicant->full_name);
        $this->assertSame('+963999999999', $applicant->phone_normalized);
    }

    /** Task 1: two people with the same name are never merged. */
    public function test_two_people_with_the_same_name_stay_two_applicants(): void
    {
        [, , $token] = $this->campaignWithLink();

        $this->submit($token, $this->answers('first@example.com', 'Sam Haddad', '0944000001'))->assertRedirect();
        $this->submit($token, $this->answers('second@example.com', 'Sam Haddad', '0944000002'))->assertRedirect();

        $this->assertSame(2, RecruitmentApplicant::query()->count());
        $this->assertNull(RecruitmentApplicant::query()->where('email_normalized', 'second@example.com')->value('possible_duplicate_of_applicant_id'));
    }

    /** Task 1: email different, phone the same — flagged, never merged. */
    public function test_a_shared_phone_under_another_email_is_flagged_not_merged(): void
    {
        [, , $token] = $this->campaignWithLink();

        $this->submit($token, $this->answers('owner@example.com', 'Phone Owner', '0944123456'))->assertRedirect();
        $this->submit($token, $this->answers('relative@example.com', 'Relative', '0944123456'))->assertRedirect();

        $owner = RecruitmentApplicant::query()->where('email_normalized', 'owner@example.com')->sole();
        $relative = RecruitmentApplicant::query()->where('email_normalized', 'relative@example.com')->sole();

        $this->assertNotSame($owner->id, $relative->id, 'Never merged.');
        $this->assertNull($owner->possible_duplicate_of_applicant_id, 'The earliest is the anchor.');
        $this->assertSame($owner->id, $relative->possible_duplicate_of_applicant_id);
    }

    /**
     * Task 1: email the same, phone different — one applicant. The phone on
     * file stays: nothing proves the second submitter owns the email (see
     * test_a_later_submission_cannot_rewrite_an_existing_applicants_name_or_phone),
     * so the new number lives on that application's answers, not on the person.
     */
    public function test_the_same_email_with_a_new_phone_is_the_same_applicant(): void
    {
        [, , $first] = $this->campaignWithLink();
        [, , $second] = $this->campaignWithLink();

        $this->submit($first, $this->answers('moved@example.com', 'Moved', '0944000111'))->assertRedirect();
        $this->submit($second, $this->answers('moved@example.com', 'Moved', '0955000222'))->assertRedirect();

        $applicant = RecruitmentApplicant::query()->sole();
        $this->assertSame('0944000111', $applicant->phone_normalized);
        $this->assertSame(
            ['value' => '0955000222'],
            RecruitmentApplication::query()->latest('id')->first()->answers()->where('field_key', 'phone')->sole()->answer_json,
        );
    }

    /** Task 2: the public cannot set status, campaign_id or score; honeypot trips. */
    public function test_internal_fields_and_the_honeypot_are_refused(): void
    {
        [, , $token] = $this->campaignWithLink();

        foreach (['status' => 'hired', 'campaign_id' => 999, 'score' => 100, 'website' => 'http://spam.test'] as $field => $value) {
            $this->post(route('careers.submit', $token), $this->payload($this->answers("x-$field@example.com")) + [$field => $value])
                ->assertUnprocessable();
        }

        $this->submit($token, $this->answers('answers@example.com') + ['score' => 100])->assertUnprocessable();

        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 2: a submission without an idempotency key. */
    public function test_a_submission_without_an_idempotency_key_is_refused(): void
    {
        [, , $token] = $this->campaignWithLink();

        $payload = $this->payload($this->answers('nokey@example.com'));
        unset($payload['submission_key']);

        $this->post(route('careers.submit', $token), $payload)->assertUnprocessable();
        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 2: submit on a closed campaign, and on one whose end has passed before the scheduler ran. */
    public function test_closed_and_ended_campaigns_refuse_submissions(): void
    {
        [$closed, , $closedToken] = $this->campaignWithLink();
        $closed->forceFill(['status' => HiringCampaignStatus::Closed])->save();
        $this->submit($closedToken, $this->answers('closed@example.com'))->assertUnprocessable();

        [$ended, , $endedToken] = $this->campaignWithLink();
        $ended->forceFill(['ends_at' => now()->subMinute()])->save(); // status still Open
        $this->submit($endedToken, $this->answers('ended@example.com'))->assertUnprocessable();

        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 1: the form changed after the applicant opened it — the link's version is used. */
    public function test_a_form_republished_after_the_link_does_not_move_the_application(): void
    {
        [$campaign, , $token] = $this->campaignWithLink();
        $frozen = (int) $campaign->applicationForm->current_version_id;

        $newer = $campaign->applicationForm->versions()->create([
            'version_no' => 2,
            'fields_json' => [['key' => 'full_name'], ['key' => 'email'], ['key' => 'motivation']],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $campaign->applicationForm->forceFill(['current_version_id' => $newer->id])->save();

        $this->submit($token, $this->answers('frozen@example.com'))->assertRedirect();

        $this->assertSame($frozen, (int) RecruitmentApplication::query()->sole()->form_version_id);
    }

    // ------------------------------------------------------------------
    // Task 2 — invitation-only
    // ------------------------------------------------------------------

    /** Invited email typed with spaces and capitals is accepted and linked to its invite. */
    public function test_an_invited_person_is_linked_to_the_invite_that_let_them_in(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $invite = app(PublicRecruitmentLinkService::class)->addInvite($campaign->fresh(), $hr, 'Invited', 'Invited@Example.com');

        $this->submit($token, $this->answers('  INVITED@example.COM '))->assertRedirect();

        $application = RecruitmentApplication::query()->sole();
        $this->assertSame($invite->id, (int) $application->campaign_invite_id);
        $this->assertSame(RecruitmentCampaignInvite::STATUS_USED, $invite->fresh()->status);
        $this->assertNotNull($invite->fresh()->used_at);
    }

    /**
     * Uninvited, cancelled and already-used invites: the same generic answer,
     * and — the CV rule — no file is stored for any of them.
     */
    public function test_uninvited_cancelled_and_used_invites_are_refused_and_store_no_cv(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $links = app(PublicRecruitmentLinkService::class);
        $used = $links->addInvite($campaign->fresh(), $hr, 'Used', 'used@example.com');
        $cancelled = $links->addInvite($campaign->fresh(), $hr, 'Cancelled', 'cancelled@example.com');
        $links->cancelInvite($cancelled, $hr);

        $this->submit($token, $this->answers('used@example.com'))->assertRedirect();
        $storedAfterTheOneValidSubmission = count(Storage::disk('recruitment-quarantine')->allFiles());

        foreach (['stranger@example.com', 'cancelled@example.com', 'used@example.com'] as $email) {
            $this->submit($token, $this->answers($email))
                ->assertUnprocessable()
                ->assertSee(__('careers.generic_submission_error'))
                ->assertDontSee('used@example.com');
        }

        $this->assertSame(1, RecruitmentApplication::query()->count());
        $this->assertSame($used->id, (int) RecruitmentApplication::query()->sole()->campaign_invite_id);
        $this->assertSame(
            $storedAfterTheOneValidSubmission,
            count(Storage::disk('recruitment-quarantine')->allFiles()),
            'A refused invitation-only submission must not leave a CV behind.',
        );
    }

    // ------------------------------------------------------------------
    // Invite rules shared by both screens
    // ------------------------------------------------------------------

    /** Team 1's design, kept: re-adding a pending email edits the name. */
    public function test_re_adding_a_pending_invite_edits_it(): void
    {
        [$campaign, $hr] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $links = app(PublicRecruitmentLinkService::class);

        $first = $links->addInvite($campaign->fresh(), $hr, 'Wrong Name', 'person@example.com');
        $second = $links->addInvite($campaign->fresh(), $hr, 'Right Name', ' PERSON@example.com ');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Right Name', $second->full_name);
        $this->assertSame(RecruitmentCampaignInvite::STATUS_PENDING, $second->status);
    }

    /**
     * Team 1's addInvite (updateOrCreate) revived a used invite and wiped
     * used_at; their cancelInvite cancelled it. RecruitmentCampaignInviteObserver
     * now refuses both at the model — Team 1's code itself is unchanged.
     */
    public function test_a_used_invite_can_be_neither_revived_nor_cancelled_through_team1s_service(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $links = app(PublicRecruitmentLinkService::class);
        $invite = $links->addInvite($campaign->fresh(), $hr, 'Applied', 'applied@example.com');
        $this->submit($token, $this->answers('applied@example.com'))->assertRedirect();
        $usedAt = $invite->fresh()->used_at;

        $this->assertThrows(fn () => $links->addInvite($campaign->fresh(), $hr, 'Again', 'applied@example.com'), ValidationException::class);
        $this->assertThrows(fn () => $links->cancelInvite($invite->fresh(), $hr), ValidationException::class);

        $this->assertSame(RecruitmentCampaignInvite::STATUS_USED, $invite->fresh()->status);
        $this->assertEquals($usedAt, $invite->fresh()->used_at);
    }

    /** On Team 1's publishing page the refusal shows under their email field, not as a failed request. */
    public function test_refusing_a_used_invite_on_the_publishing_page_shows_an_error(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $invite = app(PublicRecruitmentLinkService::class)->addInvite($campaign->fresh(), $hr, 'Applied', 'applied@example.com');
        $this->submit($token, $this->answers('applied@example.com'))->assertRedirect();

        Livewire::actingAs($hr)
            ->test(Manager::class, ['campaign' => $campaign->fresh()])
            ->call('cancelInvite', $invite->id)
            ->assertHasErrors('inviteEmail');

        $this->assertSame(RecruitmentCampaignInvite::STATUS_USED, $invite->fresh()->status);
    }

    public function test_a_cancelled_invite_can_be_re_activated(): void
    {
        [$campaign, $hr] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $links = app(PublicRecruitmentLinkService::class);
        $invite = $links->addInvite($campaign->fresh(), $hr, 'Back', 'back@example.com');
        $links->cancelInvite($invite, $hr);

        $this->assertSame(
            RecruitmentCampaignInvite::STATUS_PENDING,
            $links->addInvite($campaign->fresh(), $hr, 'Back', 'back@example.com')->status,
        );
    }

    public function test_team2_invites_are_refused_on_a_public_campaign(): void
    {
        [$campaign] = $this->campaignWithLink(RecruitmentCampaignLinkMode::PublicOpen);
        $hr = $this->makeUser($this->city, 'HR-HMS', ['recruitment-applicants.view', 'recruitment-applicants.manage']);

        $this->assertThrows(
            fn () => app(RecruitmentCampaignInviteService::class)->add($hr, $campaign, 'N', 'n@example.com'),
            ValidationException::class,
        );
    }

    /**
     * The publishing page stays Team 1's: their own invite list, not ours.
     * Team 2's rules still apply to it through the model observers.
     */
    public function test_the_publishing_page_keeps_team1s_own_invite_list(): void
    {
        [$campaign, $hr] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        app(PublicRecruitmentLinkService::class)->addInvite($campaign->fresh(), $hr, 'Listed', 'listed@example.com');

        // Same approach as Team 1's CampaignPublishingHubTest.
        Livewire::actingAs($hr)->test(PublishingHub::class, ['campaign' => $campaign->fresh()])
            ->assertOk()
            ->assertSeeLivewire(Manager::class)
            ->assertDontSeeLivewire(InviteTable::class);

        Livewire::actingAs($hr)->test(Manager::class, ['campaign' => $campaign->fresh()])
            ->assertSee('listed@example.com');
    }

    /**
     * PII behind the applicant permission: Team 2's invite screen is HR's.
     * A manager is refused even holding the campaign and link permissions —
     * applicant permissions are never part of a manager's baseline.
     */
    public function test_the_team2_invite_screen_is_for_hr_only(): void
    {
        [$campaign] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        // makeUser() grants to the role; HR-HMS gets the applicant
        // permissions the Phase 2 migration gives every HR role.
        $hr = $this->makeUser($this->city, 'HR-HMS', ['recruitment-applicants.view', 'recruitment-applicants.manage']);
        $manager = $this->makeUser($this->city, 'general-manager', [
            'recruitment-campaigns.view', 'recruitment-links.view', 'recruitment-links.manage',
        ]);

        Livewire::actingAs($hr)->test(InviteTable::class, ['campaignId' => $campaign->id])->assertOk();
        Livewire::actingAs($manager)->test(InviteTable::class, ['campaignId' => $campaign->id])->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Remaining plan items (added for the full Task 1/2 audit)
    // ------------------------------------------------------------------

    /** Task 1 acceptance: relationships work Applicant → Applications and Application → Campaign. */
    public function test_relationships_work_in_both_directions(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $invite = app(PublicRecruitmentLinkService::class)->addInvite($campaign->fresh(), $hr, 'Rel', 'rel@example.com');
        $this->submit($token, $this->answers('rel@example.com'))->assertRedirect();

        $applicant = RecruitmentApplicant::query()->sole();
        $application = $applicant->applications()->sole();

        $this->assertSame($campaign->id, $application->campaign->id);
        $this->assertSame($campaign->vacancy_id, $application->vacancy->id);
        $this->assertSame((int) $campaign->applicationForm->current_version_id, $application->formVersion->id);
        $this->assertSame($invite->id, $application->campaignInvite->id);
        $this->assertSame($application->id, $campaign->applications()->sole()->id);
    }

    /** Task 1: status is a controlled value, never free text. */
    public function test_the_stored_status_is_one_of_the_controlled_values(): void
    {
        [, , $token] = $this->campaignWithLink();
        $this->submit($token, $this->answers('status@example.com'))->assertRedirect();

        $this->assertContains(RecruitmentApplication::query()->sole()->status, RecruitmentApplicationStatus::values());
    }

    /** Task 2 rules: a disabled campaign refuses, like closed and expired ones. */
    public function test_a_disabled_campaign_refuses_submissions(): void
    {
        [$campaign, , $token] = $this->campaignWithLink();
        $campaign->forceFill(['status' => HiringCampaignStatus::Disabled])->save();

        $this->submit($token, $this->answers('disabled@example.com'))->assertUnprocessable();
        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 2: a disabled or expired link refuses, with the same generic answer. */
    public function test_a_disabled_or_expired_link_refuses_submissions(): void
    {
        [$campaign, , $token] = $this->campaignWithLink();
        $link = $campaign->publicLinks()->sole();

        $link->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->submit($token, $this->answers('expired@example.com'))->assertUnprocessable();

        $link->forceFill(['expires_at' => now()->addDay(), 'status' => 'disabled'])->save();
        $this->submit($token, $this->answers('disabled-link@example.com'))->assertUnprocessable();

        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 2 edge case: an oversized payload is refused, not truncated into the database. */
    public function test_an_oversized_answer_is_refused(): void
    {
        [, , $token] = $this->campaignWithLink();

        $this->submit($token, $this->answers('big@example.com', str_repeat('x', 300)))->assertUnprocessable();
        $this->assertSame(0, RecruitmentApplication::query()->count());
    }

    /** Task 1 security: applicant PII only for authorised internal users inside their scope. */
    public function test_applicant_data_is_visible_only_inside_the_hr_scope(): void
    {
        [, , $token] = $this->campaignWithLink();
        $this->submit($token, $this->answers('pii@example.com'))->assertRedirect();
        $application = RecruitmentApplication::query()->sole();

        $sameCity = $this->makeUser($this->city, 'HR-HMS', ['recruitment-applicants.view', 'recruitment-applications.view']);
        $otherCity = $this->makeUser($this->makeCity('ALP', '001'), 'HR-ALP', ['recruitment-applicants.view', 'recruitment-applications.view']);

        $this->assertTrue(Gate::forUser($sameCity)->allows('view', $application));
        $this->assertTrue(Gate::forUser($sameCity)->allows('view', $application->applicant));
        $this->assertFalse(Gate::forUser($otherCity)->allows('view', $application), 'HR of another governorate');
        $this->assertFalse(Gate::forUser($otherCity)->allows('view', $application->applicant));
        $this->assertSame(0, RecruitmentApplication::query()->visibleTo($otherCity)->count());
    }

    /** Task 1 security: the invite list is internal — the public page never shows it. */
    public function test_the_public_page_never_shows_the_invite_list(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        app(PublicRecruitmentLinkService::class)->addInvite($campaign->fresh(), $hr, 'Secret Person', 'secret.person@example.com');

        $this->get(route('careers.show', $token))
            ->assertOk()
            ->assertDontSee('secret.person@example.com')
            ->assertDontSee('Secret Person');
    }

    /** Task 1 edge case: HR added a wrong email — cancel it, add the right one. */
    public function test_hr_can_correct_a_wrong_invite_email(): void
    {
        [$campaign, $hr, $token] = $this->campaignWithLink(RecruitmentCampaignLinkMode::InvitationOnly);
        $links = app(PublicRecruitmentLinkService::class);
        $wrong = $links->addInvite($campaign->fresh(), $hr, 'Person', 'persn@example.com');
        $links->cancelInvite($wrong, $hr);
        $links->addInvite($campaign->fresh(), $hr, 'Person', 'person@example.com');

        $this->submit($token, $this->answers('persn@example.com'))->assertUnprocessable();
        $this->submit($token, $this->answers('person@example.com'))->assertRedirect();
    }

    /** Task 1 data integrity: answers cannot exist without an application. */
    public function test_answers_cannot_be_stored_without_an_application(): void
    {
        [$campaign] = $this->campaignWithLink();

        $this->assertThrows(fn () => DB::table('recruitment_application_answers')->insert([
            'application_id' => 999999,
            'form_version_id' => $campaign->applicationForm->current_version_id,
            'field_key' => 'full_name',
            'answer_json' => json_encode(['value' => 'x']),
            'created_at' => now(),
            'updated_at' => now(),
        ]), \Illuminate\Database\QueryException::class);
    }

    // ------------------------------------------------------------------
    // Fixtures (same shape as PublicRecruitmentLinkTest's)
    // ------------------------------------------------------------------

    /** @return array{HiringCampaign, User, string} */
    private function campaignWithLink(string $linkMode = RecruitmentCampaignLinkMode::PublicOpen): array
    {
        $this->city ??= $this->makeCity('HMS', '002');
        $program = Program::query()->forceCreate(['name' => 'DISC', 'code' => 'D', 'slug' => 'disc-'.uniqid(), 'is_active' => true]);
        $department = Department::query()->forceCreate(['name' => 'IT', 'code' => 'IT-'.uniqid()]);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Specialist', 'name_ar' => 'اختصاصي', 'grade' => 6]);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id, 'city_id' => $this->city->id, 'job_level_id' => $jobLevel->id,
            'department_id' => $department->id, 'title_en' => 'IT Specialist', 'title_ar' => 'اختصاصي',
            'max_emp' => 2, 'exist_emp' => 0, 'is_active' => true,
        ]);
        $requester = $this->makeUser($this->city, 'employee');
        $request = RecruitmentRequest::query()->forceCreate([
            'request_number' => 'REQ-'.strtoupper(uniqid()), 'requester_user_id' => $requester->id,
            'request_type' => RecruitmentRequestType::CreatePosition, 'mode' => RecruitmentRequestMode::Normal,
            'status' => RecruitmentRequestStatus::Applied, 'scope_city_id' => $this->city->id,
            'target_position_id' => $position->id, 'submitted_at' => now(), 'applied_at' => now(),
        ]);
        RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id, 'version_no' => 1, 'snapshot_json' => ['approved' => true],
            'created_by_user_id' => $requester->id, 'submitted_at' => now(),
        ]);
        $vacancy = Vacancy::query()->forceCreate([
            'position_id' => $position->id, 'created_from_request_id' => $request->id, 'status' => 'open',
            'needed_count' => 1, 'filled_count' => 0, 'open_at' => now(),
        ]);
        // Team 1's link-manager HR: link permissions only, no applicant permission.
        $hr = $this->makeUser($this->city, 'HR-HMS', ['recruitment-campaigns.view', 'recruitment-links.view', 'recruitment-links.manage']);
        $campaign = HiringCampaign::query()->forceCreate([
            'vacancy_id' => $vacancy->id, 'status' => HiringCampaignStatus::Open, 'link_mode' => $linkMode,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(14), 'created_by_user_id' => $hr->id,
        ]);
        $jobPost = JobPost::query()->forceCreate(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
        $jobVersion = $jobPost->versions()->create([
            'version_no' => 1, 'title_en' => 'IT Specialist', 'title_ar' => 'اختصاصي', 'body_en' => 'b', 'body_ar' => 'b',
            'public_payload_json' => ['template' => 'hope_center_hiring_v1', 'city' => 'HOMS', 'program' => 'DISC', 'job_title' => 'IT Specialist', 'outputs' => []],
            'status' => 'published', 'published_at' => now(),
        ]);
        $jobPost->forceFill(['current_version_id' => $jobVersion->id])->save();
        $form = ApplicationForm::query()->forceCreate(['campaign_id' => $campaign->id, 'status' => 'published', 'created_by_user_id' => $hr->id]);
        $formVersion = $form->versions()->create([
            'version_no' => 1, 'fields_json' => [['key' => 'full_name'], ['key' => 'email']],
            'privacy_notice_version' => 'v1', 'status' => 'published', 'published_at' => now(),
        ]);
        $form->forceFill(['current_version_id' => $formVersion->id])->save();

        // Link generation goes through Team 1's service, which also writes
        // the campaign's link_mode — only the two enum values are accepted.
        $token = null;
        if (in_array($linkMode, [RecruitmentCampaignLinkMode::PublicOpen, RecruitmentCampaignLinkMode::InvitationOnly], true)) {
            $token = app(PublicRecruitmentLinkService::class)->generate($campaign, $hr, now()->addDays(10), $linkMode)['token'];
        }

        return [$campaign->fresh(['applicationForm']), $hr, (string) $token];
    }

    /** Answers for the fixture form: full_name, email + the locked phone, cv and consent. */
    private function answers(string $email, string $name = 'Applicant Name', string $phone = '0944123456'): array
    {
        return [
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n"),
            'privacy_consent' => '1',
        ];
    }

    private function payload(array $answers, ?string $key = null): array
    {
        return [
            'locale' => 'en',
            'submission_key' => $key ?? bin2hex(random_bytes(32)),
            'city_id' => isset($this->city) ? $this->city->id : null,
            'answers' => $answers,
        ];
    }

    private function submit(string $token, array $answers, ?string $key = null)
    {
        return $this->post(route('careers.submit', $token), $this->payload($answers, $key));
    }
}
