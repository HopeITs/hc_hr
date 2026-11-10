<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentApplicationStatus as Status;
use App\Enums\RecruitmentFileScanStatus;
use App\Enums\RecruitmentIndicatorOutcome as Outcome;
use App\Livewire\Recruitment\Applicants\ApplicationTable;
use App\Livewire\Recruitment\Applicants\Show;
use App\Models\City;
use App\Models\HiringCampaign;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationDecision;
use App\Models\RecruitmentApplicationIndicatorSnapshot;
use App\Models\RecruitmentApplicationTag;
use App\Models\RecruitmentIndicatorCriteriaVersion;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\RecruitmentTag;
use App\Models\Role;
use App\Models\User;
use App\Services\RecruitmentApplicationFilters;
use App\Services\RecruitmentApplicationFormService;
use App\Services\RecruitmentMatchingIndicators;
use App\Services\RecruitmentTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentAtsTest extends TestCase
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
    // Filters
    // ------------------------------------------------------------------

    public function test_filters_only_narrow_and_never_reach_outside_scope(): void
    {
        $homsCampaign = $this->makeCampaignIn($this->homs, title: 'Homs Role');
        $damascusCampaign = $this->makeCampaignIn($this->damascus, title: 'Damascus Role');
        $this->applyTo($homsCampaign, $this->makeApplicant('Homs Huda'));
        $this->applyTo($damascusCampaign, $this->makeApplicant('Damascus Dima'));
        $hr = $this->hr($this->homs);
        $filters = app(RecruitmentApplicationFilters::class);

        // An id from another governorate narrows to nothing rather than reaching it.
        $this->assertSame(0, $filters->query($hr, ['campaign' => $damascusCampaign->id])->count());
        $this->assertSame(0, $filters->query($hr, ['city' => $this->damascus->id])->count());
        $this->assertSame(1, $filters->query($hr, ['city' => $this->homs->id])->count());
        $this->assertSame([$this->homs->id], array_keys($filters->cityOptions($hr)));
        $this->assertArrayNotHasKey($damascusCampaign->id, $filters->campaignOptions($hr));

        $this->actingAs($hr);
        Livewire::test(ApplicationTable::class)
            ->set('filters.campaign_id', $damascusCampaign->id)
            ->assertDontSee('Damascus Dima')
            ->assertDontSee('Homs Huda');

        Livewire::test(ApplicationTable::class)
            ->set('filters.city', $this->homs->id)
            ->assertSee('Homs Huda')
            ->assertDontSee('Damascus Dima');
    }

    public function test_malformed_filter_values_are_dropped_not_obeyed(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $this->applyTo($campaign, $this->makeApplicant('Plain Pat'));
        $this->applyTo($campaign, $this->makeApplicant('Other Omar'));
        $hr = $this->hr($this->homs);
        $filters = app(RecruitmentApplicationFilters::class);

        $this->assertSame(2, $filters->query($hr, [
            'campaign' => ['x', -4, ['nested']],
            'status' => 'bogus',
            'city' => '1 OR 1=1',
            'tag' => 0,
            'cv' => 'whatever',
            'previous' => 'maybe',
            'submitted_from' => '2026-13-45',
            'submitted_to' => ['array'],
        ])->count());

        // % is matched literally, not as a wildcard.
        $this->assertSame(0, $filters->query($hr, ['search' => '%'])->count());
        $this->assertSame(1, $filters->query($hr, ['search' => 'plain'])->count());
    }

    public function test_tag_cv_and_previous_applicant_filters(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $returning = $this->makeApplicant('Returning Reem');
        $this->applyTo($this->makeCampaignIn($this->homs), $returning, ['submitted_at' => now()->subMonth()]);
        $withCv = $this->applyTo($campaign, $returning);
        $withoutCv = $this->applyTo($campaign, $this->makeApplicant('New Nour'));
        $this->cvFor($withCv);
        $hr = $this->hr($this->homs);
        $tag = app(RecruitmentTagService::class)->create($hr, 'Strong');
        app(RecruitmentTagService::class)->attach($hr, $withoutCv, $tag->id);
        $filters = app(RecruitmentApplicationFilters::class);

        $this->assertSame([$withoutCv->id], $filters->query($hr, ['tag' => $tag->id])->pluck('id')->all());
        $this->assertSame([$withCv->id], $filters->query($hr, ['cv' => 'available'])->pluck('id')->all());
        $this->assertContains($withoutCv->id, $filters->query($hr, ['cv' => 'none'])->pluck('id')->all());
        $this->assertSame([$withCv->id], $filters->query($hr, ['previous' => '1'])->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // Tags
    // ------------------------------------------------------------------

    public function test_applying_a_tag_records_its_actor_and_time_and_is_idempotent(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $first = $this->hr($this->homs);
        $second = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $tag = $tags->create($first, 'Follow up');

        $this->travelTo(now()->subHour());
        $this->assertTrue($tags->attach($first, $application, $tag->id));
        $this->travelBack();

        // Tagging again keeps the original author and date.
        $this->assertFalse($tags->attach($second, $application, $tag->id));

        $assignment = RecruitmentApplicationTag::query()->sole();
        $this->assertSame($first->id, (int) $assignment->created_by_user_id);
        $this->assertTrue($assignment->created_at->lt(now()->subMinutes(30)));
        $this->assertTrue(Activity::query()->where('description', 'recruitment_tag.attached')->where('causer_id', $first->id)->exists());

        $this->assertTrue($tags->detach($second, $application, $tag->id));
        $removal = Activity::query()->where('description', 'recruitment_tag.detached')->sole();
        $this->assertSame($second->id, (int) $removal->causer_id);
        $this->assertSame($first->id, (int) $removal->properties['applied_by_user_id']);
    }

    public function test_bulk_tagging_refuses_an_oversized_or_empty_selection(): void
    {
        config(['recruitment.tags.bulk_limit' => 2]);
        $hr = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $tag = $tags->create($hr, 'Capped');

        foreach ([[1, 2, 3], [], ['nope'], [999999]] as $ids) {
            try {
                $tags->bulkAttach($hr, $ids, $tag->id);
                $this->fail('Expected the selection to be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('applicationIds', $exception->errors());
            }
        }
    }

    public function test_a_deleted_tag_disappears_everywhere_and_comes_back_on_restore(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant('Tagged Tala'));
        $hr = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $tag = $tags->create($hr, 'Seasonal');
        $tags->attach($hr, $application, $tag->id);

        $tags->delete($hr, $tag);

        $this->assertCount(0, $application->fresh()->tags);
        $this->assertSame(1, RecruitmentApplicationTag::query()->count(), 'The assignment is kept, only hidden.');
        $this->assertSame(0, app(RecruitmentApplicationFilters::class)->query($hr, ['tag' => $tag->id])->count());
        $this->assertArrayNotHasKey($tag->id, app(RecruitmentApplicationFilters::class)->tagOptions());

        // A stale form still pointing at it gets a message, not a 500.
        try {
            $tags->attach($hr, $application, $tag->id);
            $this->fail('A deleted tag must not be applied.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tagId', $exception->errors());
        }

        // Re-creating the name points at the deleted tag instead of duplicating it.
        $this->assertValidationError(fn () => $tags->create($hr, 'seasonal'), 'tagName');

        $tags->restore($hr, $tag);
        $restored = $application->fresh()->tags;
        $this->assertSame(['Seasonal'], $restored->pluck('name')->all());
        $this->assertSame($hr->id, (int) $restored->first()->pivot->created_by_user_id);
    }

    public function test_renaming_relabels_every_assignment_and_rejects_clashes(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $hr = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $tag = $tags->create($hr, 'Old name');
        $tags->create($hr, 'Taken');
        $tags->attach($hr, $application, $tag->id);

        $this->assertValidationError(fn () => $tags->rename($hr, $tag, 'TAKEN'), 'tagName');

        $tags->rename($hr, $tag, 'New name', 'dark');
        $this->assertSame(['New name'], $application->fresh()->tags->pluck('name')->all());
        $this->assertSame(
            'Old name',
            Activity::query()->where('description', 'recruitment_tag.renamed')->sole()->properties['before']['name'],
        );
    }

    public function test_invalid_tag_input_is_a_validation_error(): void
    {
        $hr = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());

        foreach (['', '   ', 'x', str_repeat('a', 41), '<script>alert(1)</script>', "tab\x00null", null, ['array']] as $name) {
            $this->assertValidationError(fn () => $tags->create($hr, $name), 'tagName');
        }

        $this->assertValidationError(fn () => $tags->create($hr, 'Fine', 'chartreuse'), 'tagColor');

        foreach (['abc', '-1', 0, null, ['1'], '99999'] as $tagId) {
            $this->assertValidationError(fn () => $tags->attach($hr, $application, $tagId), 'tagId');
        }

        // Arabic names and spacing are normalized, not refused.
        $this->assertSame('مرشح قوي', $tags->create($hr, '  مرشح   قوي ')->name);
        $this->assertSame(0, RecruitmentTag::query()->where('name', 'like', '%script%')->count());
    }

    // ------------------------------------------------------------------
    // Matching indicators
    // ------------------------------------------------------------------

    public function test_indicators_explain_each_comparison_when_the_data_is_complete(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $this->requireOn($campaign, ['required_experience_years' => 3, 'required_qualifications' => 'Bachelor degree in IT or higher']);
        $application = $this->applyTo($campaign, $this->makeApplicant());
        $this->useFullForm($application);
        $this->answer($application, [
            'vacancy_city' => 'homs',
            'experience_years' => '5_plus',
            'education_level' => 'master',
            'experience_field' => 'it',
        ]);
        $this->cvFor($application);

        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application->fresh());

        $this->assertSame(Outcome::Met, $set->get('city_match')->outcome);
        $this->assertSame(Outcome::Met, $set->get('experience')->outcome);
        $this->assertSame(Outcome::Met, $set->get('qualification')->outcome);
        $this->assertSame('bachelor', $set->get('qualification')->params['keyword']);
        $this->assertSame(Outcome::Met, $set->get('industry_experience')->outcome);
        $this->assertSame(Outcome::Met, $set->get('cv_availability')->outcome);
        $this->assertSame(Outcome::Info, $set->get('previous_applicant')->outcome);
        $this->assertFalse($set->get('previous_applicant')->scored);
        $this->assertSame([5, 5], [$set->metCount(), $set->assessedCount()]);

        foreach ($set->indicators as $indicator) {
            $this->assertNotSame('recruitment-applicant.indicators.reasons.'.$indicator->reason, $indicator->explanation());
        }
    }

    public function test_missing_answers_and_older_form_versions_are_not_held_against_anyone(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $this->requireOn($campaign, ['required_experience_years' => 2, 'required_qualifications' => 'Good communication']);

        // The fixture's form version asks for a CV only: nothing else was ever asked.
        $application = $this->applyTo($campaign, $this->makeApplicant());
        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application);

        $this->assertSame(Outcome::Unknown, $set->get('city_match')->outcome);
        $this->assertSame('experience_not_asked', $set->get('experience')->reason);
        $this->assertSame('qualification_unrecognised', $set->get('qualification')->reason);
        $this->assertSame('industry_not_asked', $set->get('industry_experience')->reason);
        $this->assertSame('cv_missing', $set->get('cv_availability')->reason);
        $this->assertSame([0, 1], [$set->metCount(), $set->assessedCount()]);
        $this->assertSame(4, $set->unassessedCount());

        // Same questions on the form, left blank: "not answered", still not a miss.
        $this->useFullForm($application);
        $this->answer($application, ['experience_years' => '', 'education_level' => null]);
        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application->fresh());
        $this->assertSame('experience_not_answered', $set->get('experience')->reason);
        $this->assertFalse($set->get('experience')->isAssessed());

        // A range straddling the requirement is flagged for review, not decided.
        $this->answer($application, ['experience_years' => '1_3']);
        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application->fresh());
        $this->assertSame(Outcome::Partial, $set->get('experience')->outcome);
    }

    public function test_no_requirement_and_no_vacancy_city_are_not_applicable(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $campaign->vacancy->createdFromRequest->forceFill(['scope_city_id' => null])->save();
        $application = $this->applyTo($campaign, $this->makeApplicant());

        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application);

        $this->assertSame(Outcome::NotApplicable, $set->get('city_match')->outcome);
        $this->assertSame(Outcome::NotApplicable, $set->get('experience')->outcome);
        $this->assertSame(Outcome::NotApplicable, $set->get('qualification')->outcome);
    }

    public function test_a_saved_score_always_carries_its_criteria_version(): void
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $this->cvFor($application);
        $indicators = app(RecruitmentMatchingIndicators::class);

        $snapshot = $indicators->snapshot($application, $this->hr($this->homs));
        $version = $indicators->criteriaVersion();

        $this->assertSame($version, $snapshot->criteria_version);
        $this->assertSame(1, $snapshot->met_count);
        $this->assertSame(
            $indicators->criteriaDefinition(),
            RecruitmentIndicatorCriteriaVersion::query()->where('version', $version)->sole()->definition_json,
        );

        // Changing a rule changes the version; the old definition is kept.
        config(['recruitment.indicators.experience_ranges.5_plus' => [6, null]]);
        $this->assertNotSame($version, $indicators->criteriaVersion());

        Artisan::call('recruitment:indicators:snapshot', ['--stale' => true]);
        $this->assertSame($indicators->criteriaVersion(), $snapshot->fresh()->criteria_version);
        $this->assertSame(2, RecruitmentIndicatorCriteriaVersion::query()->count());
        $this->assertSame(1, RecruitmentApplicationIndicatorSnapshot::query()->count());
    }

    public function test_nothing_in_the_ats_layer_changes_an_application_status(): void
    {
        $campaign = $this->makeCampaignIn($this->homs);
        $this->requireOn($campaign, ['required_experience_years' => 10]);
        $application = $this->applyTo($campaign, $this->makeApplicant('Unmoved Umar'));
        $this->useFullForm($application);
        $this->answer($application, ['experience_years' => 'less_than_1', 'vacancy_city' => 'damascus']);
        $hr = $this->hr($this->homs);
        $tags = app(RecruitmentTagService::class);
        $tag = $tags->create($hr, 'Reject pile');

        $indicators = app(RecruitmentMatchingIndicators::class);
        $this->assertSame(Outcome::NotMet, $indicators->evaluate($application->fresh())->get('experience')->outcome);
        $indicators->snapshot($application->fresh());
        Artisan::call('recruitment:indicators:snapshot');
        $tags->attach($hr, $application, $tag->id);
        $tags->bulkAttach($hr, [$application->id], $tag->id);
        $tags->delete($hr, $tag);
        app(RecruitmentApplicationFilters::class)->query($hr, ['tag' => $tag->id, 'status' => Status::Rejected])->get();

        $this->actingAs($hr);
        Livewire::test(Show::class, ['applicantId' => $application->applicant_id])
            ->assertSee(__('recruitment-applicant.indicators.title'))
            ->assertSee(__('recruitment-applicant.indicators.disclaimer'));
        Livewire::test(ApplicationTable::class)->assertSee('Unmoved Umar');

        $this->assertSame(Status::Submitted, $application->fresh()->status);
        $this->assertSame(0, RecruitmentApplicationDecision::query()->count());
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

    private function requireOn(HiringCampaign $campaign, array $detail): void
    {
        $request = $campaign->vacancy->createdFromRequest;
        $version = RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $request->requester_user_id,
            'submitted_at' => now(),
        ]);

        RecruitmentRequestPositionDetail::query()->forceCreate(['request_version_id' => $version->id] + $detail);
    }

    private function useFullForm(RecruitmentApplication $application): void
    {
        $application->formVersion->forceFill([
            'fields_json' => array_values(app(RecruitmentApplicationFormService::class)->defaultFields()),
        ])->save();
    }

    private function answer(RecruitmentApplication $application, array $answers): void
    {
        foreach ($answers as $key => $value) {
            $application->answers()->updateOrCreate(
                ['field_key' => $key],
                ['form_version_id' => $application->form_version_id, 'answer_json' => ['value' => $value]],
            );
        }
    }

    private function cvFor(RecruitmentApplication $application): RecruitmentApplicantFile
    {
        return RecruitmentApplicantFile::query()->forceCreate([
            'applicant_id' => $application->applicant_id,
            'application_id' => $application->id,
            'file_type' => RecruitmentApplicantFile::TYPE_CV,
            'scan_status' => RecruitmentFileScanStatus::Clean,
            'uploaded_at' => now(),
        ]);
    }

    private function assertValidationError(callable $action, string $field): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }

        $this->fail("Expected a validation error on {$field}.");
    }
}
