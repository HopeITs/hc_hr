<?php

namespace Tests\Feature\Recruitment;

use App\Enums\HiringCampaignStatus;
use App\Enums\RecruitmentApplicationStatus as Status;
use App\Enums\RecruitmentFileScanStatus;
use App\Livewire\Recruitment\Applicants\ApplicationTable;
use App\Livewire\Recruitment\Applicants\Index;
use App\Livewire\Recruitment\Applicants\Notes;
use App\Livewire\Recruitment\Applicants\Show;
use App\Models\City;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\Program;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\Role;
use App\Models\User;
use App\Services\RecruitmentApplicantNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use LogicException;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use ReflectionClass;
use ReflectionProperty;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentApplicantProfileTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

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
    // List
    // ------------------------------------------------------------------

    public function test_list_shows_only_applications_in_scope_and_flags_earlier_applicants(): void
    {
        $returning = $this->makeApplicant('Returning Rana');
        $damascusOnly = $this->makeApplicant('Damascus Dani');
        $this->applyTo($this->makeCampaignIn($this->damascus), $returning, ['submitted_at' => now()->subDays(5)]);
        $this->applyTo($this->makeCampaignIn($this->homs), $returning);
        $this->applyTo($this->makeCampaignIn($this->damascus), $damascusOnly);

        $this->actingAs($this->hr($this->homs));
        Livewire::test(ApplicationTable::class)
            ->assertSee('Returning Rana')
            ->assertSee(__('recruitment-applicant.previous_applicant_flag'))
            ->assertDontSee('Damascus Dani');

        // In Damascus the returning applicant's row is their first application.
        $this->actingAs($this->hr($this->damascus));
        Livewire::test(ApplicationTable::class)
            ->assertSee('Returning Rana')
            ->assertSee('Damascus Dani')
            ->assertDontSee(__('recruitment-applicant.previous_applicant_flag'));
    }

    public function test_filters_narrow_by_status_campaign_and_source(): void
    {
        $first = $this->makeCampaignIn($this->homs, title: 'Teacher');
        $second = $this->makeCampaignIn($this->homs, HiringCampaignStatus::Closed, 'Driver');
        $this->applyTo($first, $this->makeApplicant('Shortlisted Sami'), ['status' => Status::Shortlisted]);
        $this->applyTo($second, $this->makeApplicant('Submitted Suha', source: 'walk_in'));
        $this->applyTo($second, $this->makeApplicant('Shortlisted Shadi'), ['status' => Status::Shortlisted]);

        $this->actingAs($this->hr($this->homs));

        Livewire::test(ApplicationTable::class)
            ->set('filters.status', Status::Shortlisted)
            ->assertSee('Shortlisted Sami')
            ->assertDontSee('Submitted Suha');

        Livewire::test(ApplicationTable::class)
            ->call('filterByStatus', Status::Shortlisted)
            ->assertSee('Shortlisted Sami')
            ->assertDontSee('Submitted Suha')
            ->assertSee(__('recruitment-applicant.campaign_number', ['number' => $first->id]));

        Livewire::test(ApplicationTable::class, [
            'stageStatus' => Status::Shortlisted,
            'campaignFilter' => $first->id,
        ])
            ->assertSee('Shortlisted Sami')
            ->assertDontSee('Shortlisted Shadi')
            ->assertDontSee('Submitted Suha');

        Livewire::test(Index::class)
            ->assertSee(__('recruitment-applicant.campaign_number', ['number' => $first->id]))
            ->call('filterByStatus', Status::Shortlisted)
            ->set('activeCampaignId', $first->id)
            ->assertSet('activeStatus', Status::Shortlisted)
            ->assertSet('activeCampaignId', $first->id);

        Livewire::test(ApplicationTable::class)
            ->set('filters.campaign_id', $second->id)
            ->assertSee('Submitted Suha')
            ->assertSee('Shortlisted Shadi')
            // A closed campaign's applications stay listed, marked as closed.
            ->assertSee(HiringCampaignStatus::getLocalizedName(HiringCampaignStatus::Closed)['value'])
            ->assertDontSee('Shortlisted Sami');

        Livewire::test(ApplicationTable::class)
            ->set('filters.applicant_source', 'walk_in')
            ->assertSee('Submitted Suha')
            ->assertDontSee('Shortlisted Sami');
    }

    public function test_list_is_refused_without_the_applicant_permission(): void
    {
        $this->actingAs($this->makeUser($this->homs, 'employee', ['recruitment-campaigns.view']));

        Livewire::test(ApplicationTable::class)->assertForbidden();
        $this->get(route('recruitment.applicants.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Profile and IDOR
    // ------------------------------------------------------------------

    public function test_profile_is_a_404_outside_scope_and_403_without_permission(): void
    {
        $applicant = $this->makeApplicant('Homs Hala');
        $this->applyTo($this->makeCampaignIn($this->homs), $applicant);

        $this->actingAs($this->hr($this->homs))
            ->get(route('recruitment.applicants.show', ['applicantId' => $applicant->id]))
            ->assertOk()
            ->assertSee('Homs Hala');

        // Another governorate guessing the id learns nothing, not even that it exists.
        $this->actingAs($this->hr($this->damascus));
        Livewire::test(Show::class, ['applicantId' => $applicant->id])->assertNotFound();
        Livewire::test(Notes::class, ['applicantId' => $applicant->id])->assertNotFound();

        $this->actingAs($this->makeUser($this->homs, 'employee'))
            ->get(route('recruitment.applicants.show', ['applicantId' => $applicant->id]))
            ->assertForbidden();
    }

    public function test_profile_shows_only_in_scope_applications_and_notes(): void
    {
        $applicant = $this->makeApplicant('Split Salma');
        $homsApplication = $this->applyTo($this->makeCampaignIn($this->homs, title: 'Homs Role'), $applicant);
        $damascusApplication = $this->applyTo($this->makeCampaignIn($this->damascus, title: 'Damascus Role'), $applicant);

        $notes = app(RecruitmentApplicantNoteService::class);
        $notes->add($this->hr($this->damascus), $applicant, $damascusApplication, 'Damascus-only remark');
        $homsHr = $this->hr($this->homs);
        $notes->add($homsHr, $applicant, $homsApplication, 'Homs remark');

        $this->actingAs($homsHr);

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSee('Homs Role')
            ->assertDontSee('Damascus Role')
            ->assertSee(trans_choice('recruitment-applicant.hidden_applications', 1, ['count' => 1]))
            ->assertSee(__('recruitment-applicant.previous_applicant_flag'));

        Livewire::test(Notes::class, ['applicantId' => $applicant->id])
            ->assertSee('Homs remark')
            ->assertDontSee('Damascus-only remark');
    }

    public function test_profile_uses_campaign_city_when_the_applicant_city_is_blank(): void
    {
        $applicant = $this->makeApplicant('Cityless Clara');
        $this->applyTo($this->makeCampaignIn($this->homs, title: 'Homs Role'), $applicant);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSee('Cityless Clara')
            ->assertSee($this->homs->name);
    }

    public function test_profile_shows_the_visible_application_form_answers(): void
    {
        $applicant = $this->makeApplicant('Answer Aida');
        $homsApplication = $this->applyTo($this->makeCampaignIn($this->homs, title: 'Homs Role'), $applicant);
        $damascusApplication = $this->applyTo($this->makeCampaignIn($this->damascus, title: 'Damascus Role'), $applicant);

        foreach ([$homsApplication, $damascusApplication] as $application) {
            $application->formVersion->forceFill([
                'fields_json' => [
                    ['key' => 'motivation'],
                    ['key' => 'english_level'],
                ],
            ])->save();
        }

        $homsApplication->answers()->create([
            'form_version_id' => $homsApplication->form_version_id,
            'field_key' => 'motivation',
            'answer_json' => ['value' => 'Built case management workflows'],
        ]);
        $homsApplication->answers()->create([
            'form_version_id' => $homsApplication->form_version_id,
            'field_key' => 'english_level',
            'answer_json' => ['value' => 'c1_c2'],
        ]);
        $damascusApplication->answers()->create([
            'form_version_id' => $damascusApplication->form_version_id,
            'field_key' => 'motivation',
            'answer_json' => ['value' => 'Hidden Damascus answer'],
        ]);

        app()->setLocale('en');
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertSee(__('recruitment-applicant.application_answers.title'))
            ->assertSee('Why are you interested in this role?')
            ->assertSee('Built case management workflows')
            ->assertSee('C1 - C2')
            ->assertDontSee('Hidden Damascus answer');
    }

    public function test_managers_see_and_endorse_applicants_for_their_own_campaign_requests(): void
    {
        $manager = $this->managerWithPosition($this->homs);
        $ownCampaign = $this->makeCampaignForRequester($manager, $this->homs, 'Manager Campaign');
        $foreignCampaign = $this->makeCampaignIn($this->homs, title: 'Foreign Campaign');
        $ownApplication = $this->applyTo($ownCampaign, $this->makeApplicant('Own Candidate'));
        $this->applyTo($foreignCampaign, $this->makeApplicant('Hidden Candidate'));

        $this->actingAs($manager);

        Livewire::test(ApplicationTable::class)
            ->assertSee('Own Candidate')
            ->assertDontSee('Hidden Candidate')
            ->assertSee(__('recruitment-applicant.endorsement.group_unvoted'));

        Livewire::test(Show::class, ['applicantId' => $ownApplication->applicant_id])
            ->call('endorse', $ownApplication->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recruitment_application_endorsements', [
            'application_id' => $ownApplication->id,
            'user_id' => $manager->id,
            'voter_role' => 'manager',
        ]);
    }

    public function test_campaign_applicants_are_filtered_by_distinct_hr_and_manager_shortlist_votes(): void
    {
        $manager = $this->managerWithPosition($this->homs);
        $hr = $this->hr($this->homs);
        $secondHr = $this->hr($this->homs);
        $campaign = $this->makeCampaignForRequester($manager, $this->homs, 'Vote Campaign');

        $preferred = $this->applyTo($campaign, $this->makeApplicant('Preferred Paula'), ['status' => Status::Shortlisted]);
        $alternative = $this->applyTo($campaign, $this->makeApplicant('Alternative Amir'), ['status' => Status::Shortlisted]);
        $hrOnlyDuplicate = $this->applyTo($campaign, $this->makeApplicant('HR Only Hana'), ['status' => Status::Shortlisted]);
        $unvoted = $this->applyTo($campaign, $this->makeApplicant('No Vote Noor'), ['status' => Status::Shortlisted]);

        Livewire::actingAs($manager)->test(Show::class, ['applicantId' => $preferred->applicant_id])->call('endorse', $preferred->id);
        Livewire::actingAs($hr)->test(Show::class, ['applicantId' => $preferred->applicant_id])->call('endorse', $preferred->id);
        Livewire::actingAs($manager)->test(Show::class, ['applicantId' => $alternative->applicant_id])->call('endorse', $alternative->id);
        Livewire::actingAs($hr)->test(Show::class, ['applicantId' => $hrOnlyDuplicate->applicant_id])->call('endorse', $hrOnlyDuplicate->id);
        Livewire::actingAs($secondHr)->test(Show::class, ['applicantId' => $hrOnlyDuplicate->applicant_id])->call('endorse', $hrOnlyDuplicate->id);

        $this->actingAs($hr);

        Livewire::test(Index::class)
            ->set('activeCampaignId', $campaign->id)
            ->assertSee(__('recruitment-applicant.endorsement.group_preferred'))
            ->assertSee(__('recruitment-applicant.endorsement.group_alternative'))
            ->assertSee(__('recruitment-applicant.endorsement.group_unvoted'));

        Livewire::test(ApplicationTable::class, [
            'campaignFilter' => $campaign->id,
            'endorsementGroup' => 'preferred',
        ])
            ->assertSee('Preferred Paula')
            ->assertSee(__('recruitment-applicant.interviews.schedule'))
            ->assertDontSee('Alternative Amir')
            ->assertDontSee('HR Only Hana')
            ->assertDontSee('No Vote Noor');

        Livewire::test(ApplicationTable::class, [
            'campaignFilter' => $campaign->id,
            'endorsementGroup' => 'alternative',
        ])
            ->assertSee('Alternative Amir')
            ->assertSee('HR Only Hana')
            ->assertDontSee('Preferred Paula')
            ->assertDontSee('No Vote Noor');

        Livewire::test(ApplicationTable::class, [
            'campaignFilter' => $campaign->id,
            'endorsementGroup' => 'unvoted',
        ])
            ->assertSee('No Vote Noor')
            ->assertDontSee('Preferred Paula')
            ->assertDontSee('Alternative Amir')
            ->assertDontSee('HR Only Hana');

        $this->assertSame(0, $unvoted->endorsements()->count());
    }

    // ------------------------------------------------------------------
    // Notes
    // ------------------------------------------------------------------

    public function test_a_note_records_its_actor_and_cannot_target_another_scopes_application(): void
    {
        $applicant = $this->makeApplicant();
        $homsApplication = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $damascusApplication = $this->applyTo($this->makeCampaignIn($this->damascus), $applicant);
        $hr = $this->hr($this->homs);
        $this->actingAs($hr);

        Livewire::test(Notes::class, ['applicantId' => $applicant->id])
            ->set('applicationId', $damascusApplication->id)
            ->set('body', 'Trying the neighbour')
            ->call('addNote')
            ->assertHasErrors('applicationId')
            ->set('applicationId', $homsApplication->id)
            ->set('body', 'Strong portfolio')
            ->call('addNote')
            ->assertHasNoErrors();

        $note = RecruitmentApplicantNote::query()->sole();
        $this->assertSame($hr->id, (int) $note->user_id);
        $this->assertSame($homsApplication->id, (int) $note->application_id);
        $this->assertNotNull($note->created_at);
        $this->assertSame($hr->id, $note->actor->id);

        // Writing a note moved nothing.
        $this->assertSame(Status::Submitted, $homsApplication->fresh()->status);
        $this->assertSame(0, RecruitmentApplicationDecision::query()->count());
    }

    public function test_the_notes_component_has_no_way_to_carry_a_status(): void
    {
        $surface = collect((new ReflectionClass(Notes::class))->getProperties(ReflectionProperty::IS_PUBLIC))
            ->map->getName()
            ->merge(collect((new ReflectionClass(Notes::class))->getMethods())->map->getName())
            ->filter(fn (string $name): bool => str_contains(strtolower($name), 'status'));

        $this->assertSame([], $surface->values()->all());
    }

    public function test_notes_are_immutable_and_cannot_be_deleted_outside_the_service(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $note = app(RecruitmentApplicantNoteService::class)->add($this->hr($this->homs), $applicant, $application, 'Original');

        try {
            $note->forceFill(['body' => 'Rewritten'])->save();
            $this->fail('A note body was edited.');
        } catch (LogicException) {
            // Expected.
        }

        $this->expectException(LogicException::class);
        $note->fresh()->delete();
    }

    public function test_deleting_a_sensitive_note_needs_a_reason_and_leaves_an_audit_entry(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->hr($this->homs);
        $note = app(RecruitmentApplicantNoteService::class)->add($hr, $applicant, $application, 'Medical detail', sensitive: true);
        $this->actingAs($hr);

        $screen = Livewire::test(Notes::class, ['applicantId' => $applicant->id])
            ->call('confirmDelete', $note->id)
            ->call('deleteNote')
            ->assertHasErrors('deletionReason');
        $this->assertFalse($note->fresh()->trashed());

        $screen->set('deletionReason', 'Recorded against the wrong applicant')
            ->call('deleteNote')
            ->assertHasNoErrors()
            ->assertDontSee('Medical detail');

        $deleted = RecruitmentApplicantNote::withTrashed()->findOrFail($note->id);
        $this->assertTrue($deleted->trashed());
        $this->assertSame('Medical detail', $deleted->body, 'The note row is kept for the audit.');
        $this->assertSame($hr->id, (int) $deleted->deleted_by_user_id);

        $audit = Activity::query()
            ->where('log_name', RecruitmentApplicantNoteService::AUDIT_LOG)
            ->where('description', 'recruitment_note.deleted')
            ->sole();
        $this->assertSame($note->id, (int) $audit->subject_id);
        $this->assertSame($hr->id, (int) $audit->causer_id);
        $this->assertSame(hash('sha256', 'Medical detail'), $audit->getExtraProperty('body_sha256'));
        $this->assertStringNotContainsString('Medical detail', json_encode($audit->properties));
    }

    public function test_deleting_notes_needs_its_own_permission(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $hr = $this->without($this->hr($this->homs), ['recruitment-applicants.delete-notes']);
        $note = app(RecruitmentApplicantNoteService::class)->add($hr, $applicant, $application, 'Keep me');
        $this->actingAs($hr);

        Livewire::test(Notes::class, ['applicantId' => $applicant->id])
            ->call('confirmDelete', $note->id)
            ->assertForbidden();

        $this->assertFalse($note->fresh()->trashed());
    }

    // ------------------------------------------------------------------
    // Status changes
    // ------------------------------------------------------------------

    public function test_status_change_is_a_separate_action_recorded_as_a_decision(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs, HiringCampaignStatus::Closed), $applicant);
        $hr = $this->hr($this->homs);
        $this->actingAs($hr);

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Rejected)
            ->set('statusReason', 'Application does not meet the required language level.')
            ->call('changeStatus')
            ->assertHasErrors('statusReasonCode')
            ->set('statusReasonCode', 'other')
            ->call('changeStatus')
            ->assertHasNoErrors();

        $this->assertSame(Status::Rejected, $application->fresh()->status);
        $decision = RecruitmentApplicationDecision::query()->sole();
        $this->assertSame([Status::Submitted, Status::Rejected, $hr->id], [
            $decision->from_status, $decision->to_status, (int) $decision->decided_by_user_id,
        ]);
    }

    public function test_status_change_refuses_stale_screens_and_later_pipeline_states(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        $screen = Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Shortlisted);

        // Someone else moved it while this screen was open.
        DB::table('recruitment_applications')->where('id', $application->id)->update(['status' => Status::Hired]);

        $screen->set('statusReason', 'Review was completed while this screen was open.')
            ->call('changeStatus')->assertHasErrors('statusTarget');
        $this->assertSame(Status::Hired, $application->fresh()->status);
    }

    public function test_status_change_needs_its_own_permission(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->without($this->hr($this->homs), ['recruitment-applicants.change-status']));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginStatusChange', $application->id, Status::Shortlisted)
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // CVs
    // ------------------------------------------------------------------

    public function test_archiving_a_cv_without_a_vacancy_requires_a_reason_note(): void
    {
        // A talent-pool record: no application, so scope comes from its city.
        $applicant = $this->makeApplicant('Pool Paul', $this->homs, 'walk_in');
        $cv = $this->cvFor($applicant);
        $hr = $this->hr($this->homs);
        $this->actingAs($hr);

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginArchive', $cv->id)
            ->call('archiveCv')
            ->assertHasErrors('archiveReason')
            ->set('archiveReason', 'Kept for the next teaching intake')
            ->call('archiveCv')
            ->assertHasNoErrors();

        $cv->refresh();
        $this->assertTrue($cv->isArchived());
        $this->assertSame($hr->id, (int) $cv->archived_by_user_id);
        $this->assertSame(RecruitmentApplicantNote::TYPE_CV_ARCHIVE, $cv->archiveNote->note_type);
        $this->assertSame('Kept for the next teaching intake', $cv->archiveNote->body);
        $this->assertFalse(Gate::forUser($hr)->allows('delete', $cv->archiveNote), 'The justification cannot be deleted.');

        // Another governorate cannot reach the record at all.
        $this->actingAs($this->hr($this->damascus));
        Livewire::test(Show::class, ['applicantId' => $applicant->id])->assertNotFound();
    }

    public function test_a_cv_with_a_vacancy_archives_without_a_reason(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $cv = $this->cvFor($applicant, $application);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->call('beginArchive', $cv->id)
            ->call('archiveCv')
            ->assertHasNoErrors();

        $this->assertTrue($cv->fresh()->isArchived());
        $this->assertNull($cv->fresh()->archive_note_id);
    }

    public function test_cv_download_needs_its_own_permission_and_scope(): void
    {
        $applicant = $this->makeApplicant(city: $this->homs);
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $cv = $this->cvFor($applicant, $application);
        $orphan = $this->cvFor($applicant);

        $hr = $this->hr($this->homs);
        $this->assertTrue(Gate::forUser($hr)->inspect('download', $cv)->allowed());

        // Managing campaigns — or seeing the applicant — is not reading their CV.
        $viewer = $this->without($hr, ['recruitment-applicants.download-cv']);
        $this->assertSame('missing_permission', Gate::forUser($viewer)->inspect('download', $cv)->code());
        $this->actingAs($viewer)
            ->get(route('recruitment.applicant-files.download', $cv->id))
            ->assertForbidden();

        $damascusHr = $this->hr($this->damascus);
        $this->assertSame('out_of_scope', Gate::forUser($damascusHr)->inspect('download', $cv)->code());
        $this->assertSame('out_of_scope', Gate::forUser($damascusHr)->inspect('download', $orphan)->code());
    }

    public function test_a_missing_cv_is_shown_as_missing_not_as_an_error(): void
    {
        $applicant = $this->makeApplicant('No File Nour');
        $this->applyTo($this->makeCampaignIn($this->homs), $applicant);
        $this->actingAs($this->hr($this->homs));

        Livewire::test(ApplicationTable::class)->assertSee(__('recruitment-applicant.cv_availability.missing'));
        Livewire::test(Show::class, ['applicantId' => $applicant->id])
            ->assertOk()
            ->assertSee(__('recruitment-applicant.cv_availability.missing'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Governorate HR holding what the permission migration grants HR roles. */
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

    private function managerWithPosition(City $city): User
    {
        $manager = $this->makeUser($city, 'manager', [
            'recruitment-applicants.view',
            'recruitment-applicants.endorse',
            'recruitment-campaigns.view',
        ]);
        $program = Program::query()->forceCreate([
            'name' => 'Manager Program '.uniqid(),
            'code' => strtoupper(uniqid('MP')),
            'slug' => 'manager-program-'.uniqid(),
            'start_date' => now()->subYear(),
            'is_active' => true,
        ]);
        $department = Department::query()->forceCreate(['name' => 'Department '.uniqid(), 'code' => strtoupper(uniqid('D'))]);
        $jobLevel = JobLevel::query()->forceCreate(['name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 2]);
        $contractType = ContractType::query()->forceCreate(['name_en' => 'Annual', 'name_ar' => 'سنوي']);
        $position = Position::query()->forceCreate([
            'program_id' => $program->id,
            'city_id' => $city->id,
            'job_level_id' => $jobLevel->id,
            'department_id' => $department->id,
            'title_en' => 'Recruitment manager',
            'title_ar' => 'Recruitment manager',
            'max_emp' => 1,
            'exist_emp' => 0,
            'is_active' => true,
        ]);

        Contract::query()->forceCreate([
            'employee_id' => $manager->employee_id,
            'position_id' => $position->id,
            'contract_type_id' => $contractType->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addYear(),
            'currency' => 2,
            'salary_per' => 1,
            'weekly_hours' => 40,
            'is_active' => true,
        ]);

        return $manager->fresh();
    }

    private function makeCampaignForRequester(User $requester, City $city, string $title): \App\Models\HiringCampaign
    {
        $campaign = $this->makeCampaignIn($city, title: $title);
        $campaign->forceFill(['created_by_user_id' => $requester->id])->save();
        $campaign->vacancy->createdFromRequest->forceFill(['requester_user_id' => $requester->id])->save();

        return $campaign->fresh(['vacancy.createdFromRequest']);
    }

    private function without(User $user, array $permissions): User
    {
        Role::findByName($user->myRole(), 'web')->revokePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function cvFor(RecruitmentApplicant $applicant, ?RecruitmentApplication $application = null): RecruitmentApplicantFile
    {
        return RecruitmentApplicantFile::query()->forceCreate([
            'applicant_id' => $applicant->id,
            'application_id' => $application?->id,
            'file_type' => RecruitmentApplicantFile::TYPE_CV,
            'scan_status' => RecruitmentFileScanStatus::Clean,
            'uploaded_at' => now(),
        ]);
    }
}
