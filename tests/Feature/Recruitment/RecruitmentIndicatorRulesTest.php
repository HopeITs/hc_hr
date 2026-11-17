<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentIndicatorOutcome as Outcome;
use App\Livewire\Recruitment\Indicators\Rules;
use App\Models\City;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentIndicatorSetting;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Services\RecruitmentApplicationFormService;
use App\Services\RecruitmentIndicatorRules;
use App\Services\RecruitmentMatchingIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsRecruitmentApplications;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/** HR-editable matching rules: what is stored, what it changes, who may change it. */
class RecruitmentIndicatorRulesTest extends TestCase
{
    use BuildsRecruitmentApplications, CreatesHrFixtures, RefreshDatabase;

    private const PERMISSION = 'recruitment-indicators.manage';

    private City $homs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class]);
        $this->homs = $this->makeCity('HMS', '002');
    }

    public function test_nothing_saved_means_the_config_rules_apply_unchanged(): void
    {
        $rules = app(RecruitmentIndicatorRules::class);

        $this->assertFalse($rules->hasOverrides());
        $this->assertSame(config('recruitment.indicators.experience_ranges'), $rules->get('experience_ranges'));
        $this->assertNull($rules->get('default_required_experience_years'));
        $this->assertTrue($rules->isEnabled('experience'));
    }

    public function test_saving_changes_the_criteria_version_and_keeps_the_old_definition_explainable(): void
    {
        $indicators = app(RecruitmentMatchingIndicators::class);
        $before = $indicators->criteriaVersion();

        app(RecruitmentIndicatorRules::class)->save($this->manager(), $this->input(['default_required_experience_years' => 3]));

        $this->assertNotSame($before, $indicators->criteriaVersion());
        $this->assertSame(3, $indicators->criteriaDefinition()['rules']['default_required_experience_years']);
    }

    public function test_the_saved_minimums_fill_in_where_a_request_names_none(): void
    {
        $application = $this->applicationAnswering(['experience_years' => '1_3', 'education_level' => 'institute']);
        $indicators = app(RecruitmentMatchingIndicators::class);

        $set = $indicators->evaluate($application);
        $this->assertSame(Outcome::NotApplicable, $set->get('experience')->outcome);
        $this->assertSame(Outcome::NotApplicable, $set->get('qualification')->outcome);

        app(RecruitmentIndicatorRules::class)->save($this->manager(), $this->input([
            'default_required_experience_years' => 3,
            'default_required_qualification' => 'university',
        ]));

        $set = $indicators->evaluate($application->fresh());
        // 1-3 years straddles a 3-year minimum; an institute diploma is below a university degree.
        $this->assertSame(Outcome::Partial, $set->get('experience')->outcome);
        $this->assertSame(Outcome::NotMet, $set->get('qualification')->outcome);
        $this->assertSame('qualification_below_default', $set->get('qualification')->reason);
        $this->assertNotSame('recruitment-applicant.indicators.reasons.qualification_below_default', $set->get('qualification')->explanation());
    }

    public function test_a_request_that_names_its_own_requirement_beats_the_saved_minimum(): void
    {
        $application = $this->applicationAnswering(['experience_years' => '5_plus', 'education_level' => 'master']);
        $this->requireOn($application, ['required_experience_years' => 1, 'required_qualifications' => 'Bachelor degree']);

        app(RecruitmentIndicatorRules::class)->save($this->manager(), $this->input([
            'default_required_experience_years' => 10,
            'default_required_qualification' => 'doctorate',
        ]));

        $set = app(RecruitmentMatchingIndicators::class)->evaluate($application->fresh());
        $this->assertSame(Outcome::Met, $set->get('experience')->outcome);
        $this->assertSame('qualification_met', $set->get('qualification')->reason);
    }

    public function test_an_edited_range_and_edited_keywords_change_the_outcome(): void
    {
        $application = $this->applicationAnswering(['experience_years' => '3_5', 'education_level' => 'university']);
        $this->requireOn($application, ['required_experience_years' => 4, 'required_qualifications' => 'Applied diploma']);
        $indicators = app(RecruitmentMatchingIndicators::class);

        $this->assertSame(Outcome::Partial, $indicators->evaluate($application->fresh())->get('experience')->outcome);
        $this->assertSame(Outcome::Met, $indicators->evaluate($application->fresh())->get('qualification')->outcome);

        // "diploma" now means a master's, and 3-5 years is read as 5 years exactly.
        app(RecruitmentIndicatorRules::class)->save($this->manager(), $this->input([
            'experience_ranges' => ['3_5' => ['min' => 5, 'max' => 5]],
            'qualification_keywords' => ['institute' => ['institute'], 'master' => ['master', 'diploma']],
        ]));

        $set = $indicators->evaluate($application->fresh());
        $this->assertSame(Outcome::Met, $set->get('experience')->outcome);
        $this->assertSame(Outcome::NotMet, $set->get('qualification')->outcome);
    }

    public function test_a_switched_off_criterion_leaves_the_list_and_the_count(): void
    {
        $application = $this->applicationAnswering(['experience_years' => '1_3']);
        $indicators = app(RecruitmentMatchingIndicators::class);
        $this->assertNotNull($indicators->evaluate($application)->get('cv_availability'));

        app(RecruitmentIndicatorRules::class)->save($this->manager(), $this->input(['disabled' => ['cv_availability', 'previous_applicant']]));

        $set = $indicators->evaluate($application->fresh());
        $this->assertNull($set->get('cv_availability'));
        $this->assertNull($set->get('previous_applicant'));
        $this->assertCount(4, $set->indicators);
    }

    public function test_reset_returns_to_the_defaults_and_is_audited(): void
    {
        $manager = $this->manager();
        $rules = app(RecruitmentIndicatorRules::class);
        $rules->save($manager, $this->input(['default_required_experience_years' => 3]));
        $this->assertTrue($rules->hasOverrides());

        $rules->reset($manager);

        $this->assertFalse($rules->hasOverrides());
        $this->assertSame(0, RecruitmentIndicatorSetting::query()->count());
        $this->assertNull($rules->get('default_required_experience_years'));
        $this->assertSame(
            ['recruitment_indicator_rules.updated', 'recruitment_indicator_rules.reset'],
            Activity::query()->where('log_name', RecruitmentIndicatorRules::AUDIT_LOG)->orderBy('id')->pluck('description')->all(),
        );
    }

    public function test_bad_input_is_refused_and_nothing_is_saved(): void
    {
        $manager = $this->manager();
        $rules = app(RecruitmentIndicatorRules::class);

        foreach ([
            'default_required_experience_years' => ['default_required_experience_years' => 99],
            'default_required_qualification' => ['default_required_qualification' => 'wizard'],
            'disabled' => ['disabled' => ['made_up']],
            'experience_ranges.1_3' => ['experience_ranges' => ['1_3' => ['min' => 4, 'max' => 2]]],
            'qualification_keywords.master' => ['qualification_keywords' => ['master' => [str_repeat('x', 61)]]],
        ] as $field => $bad) {
            try {
                $rules->save($manager, $this->input($bad));
                $this->fail("{$field} should have been refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertSame(0, RecruitmentIndicatorSetting::query()->count());
    }

    public function test_only_the_manage_permission_opens_the_page_and_its_actions(): void
    {
        $this->actingAs($this->makeUser($this->homs, 'HR-HMS', ['recruitment-applicants.view']));
        Livewire::test(Rules::class)->assertForbidden();
        $this->get(route('recruitment.indicator-rules'))->assertForbidden();

        $this->actingAs($this->manager());
        $this->get(route('recruitment.indicator-rules'))->assertOk();
    }

    public function test_the_page_saves_reports_field_errors_and_resets(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(Rules::class)
            ->assertSet('defaultYears', '')
            ->set('defaultYears', '4')
            ->set('defaultLevel', 'university')
            ->set('enabled.cv_availability', false)
            ->set('ranges.5_plus.min', '6')
            ->set('keywords.master', 'master, MSc، ماجستير')
            ->call('save')
            ->assertHasNoErrors();

        $rules = app(RecruitmentIndicatorRules::class);
        $this->assertSame(4, $rules->get('default_required_experience_years'));
        $this->assertSame('university', $rules->get('default_required_qualification'));
        $this->assertFalse($rules->isEnabled('cv_availability'));
        $this->assertSame([6, null], $rules->get('experience_ranges.5_plus'));
        $this->assertSame(['master', 'MSc', 'ماجستير'], $rules->get('qualification_keywords.master'));

        Livewire::test(Rules::class)
            ->assertSet('defaultYears', '4')
            ->assertSet('enabled.cv_availability', false)
            ->assertSet('keywords.master', 'master, MSc, ماجستير')
            ->set('ranges.1_3.min', '5')
            ->set('ranges.1_3.max', '2')
            ->set('defaultYears', '500')
            ->call('save')
            ->assertHasErrors(['ranges.1_3', 'defaultYears']);

        Livewire::test(Rules::class)->call('resetToDefaults')->assertSet('defaultYears', '');
        $this->assertFalse(app(RecruitmentIndicatorRules::class)->hasOverrides());
    }

    // ------------------------------------------------------------------

    /** A user who holds the permission through their active role. */
    private function manager(): User
    {
        return $this->makeUser($this->homs, 'HR-HMS', [self::PERMISSION]);
    }

    /** The full input shape, with the parts a test cares about replaced. */
    private function input(array $replace = []): array
    {
        return $replace + [
            'disabled' => [],
            'default_required_experience_years' => null,
            'default_required_qualification' => null,
            'experience_ranges' => [],
            'qualification_keywords' => [],
        ];
    }

    /** An application whose vacancy names no requirement, with the full form answered. */
    private function applicationAnswering(array $answers): RecruitmentApplication
    {
        $application = $this->applyTo($this->makeCampaignIn($this->homs), $this->makeApplicant());
        $application->formVersion->forceFill([
            'fields_json' => array_values(app(RecruitmentApplicationFormService::class)->defaultFields()),
        ])->save();

        foreach ($answers as $key => $value) {
            $application->answers()->updateOrCreate(
                ['field_key' => $key],
                ['form_version_id' => $application->form_version_id, 'answer_json' => ['value' => $value]],
            );
        }

        return $application->fresh();
    }

    private function requireOn(RecruitmentApplication $application, array $detail): void
    {
        $request = $application->campaign->vacancy->createdFromRequest;
        $version = RecruitmentRequestVersion::query()->forceCreate([
            'request_id' => $request->id,
            'version_no' => 1,
            'snapshot_json' => [],
            'created_by_user_id' => $request->requester_user_id,
            'submitted_at' => now(),
        ]);

        RecruitmentRequestPositionDetail::query()->forceCreate(['request_version_id' => $version->id] + $detail);
    }
}
