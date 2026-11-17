<?php

namespace Tests\Feature\Recruitment;

use App\Enums\WorkflowStepKey;
use App\Models\Department;
use App\Models\JobLevel;
use App\Models\Program;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestPositionDetail;
use App\Models\RecruitmentRequestVersion;
use App\Models\User;
use App\Services\RecruitmentRequestChangeClassifier as Classifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-1: how far back an edit sends the workflow.
 *
 * Moving a position to another programme is not the same as rewording its
 * duties, and the difference is measured in how many people have to decide
 * again.
 */
class RecruitmentChangeClassifierTest extends TestCase
{
    use RefreshDatabase;

    private Classifier $classifier;

    private RecruitmentRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = app(Classifier::class);
        $this->request = RecruitmentRequest::query()->create([
            'request_number' => 'RR-'.uniqid(),
            'requester_user_id' => User::factory()->create()->id,
            'request_type' => \App\Enums\RecruitmentRequestType::FillVacancy,
            'status' => \App\Enums\RecruitmentRequestStatus::UnderReview,
        ]);
    }

    /** An unchanged resubmission invalidates nothing. */
    public function test_identical_versions_are_no_change(): void
    {
        $one = $this->version(1, ['main_duties' => 'Same']);
        $two = $this->version(2, ['main_duties' => 'Same']);

        $this->assertSame(Classifier::NOTHING, $this->classifier->classify($one, $two));
        $this->assertSame([], $this->classifier->changes($one, $two));
    }

    /** The first version has nothing before it to invalidate. */
    public function test_the_first_version_invalidates_nothing(): void
    {
        $this->assertSame(Classifier::NOTHING, $this->classifier->classify(null, $this->version(1)));
    }

    public function test_rewording_resumes_where_the_workflow_stopped(): void
    {
        $one = $this->version(1, ['main_duties' => 'Answer the phone']);
        $two = $this->version(2, ['main_duties' => 'Answer the phone and the door']);

        $this->assertSame(Classifier::DESCRIPTIVE, $this->classifier->classify($one, $two));
        $this->assertNull($this->classifier->restartsFrom(Classifier::DESCRIPTIVE));
    }

    public function test_changing_the_money_sends_it_back_to_finance(): void
    {
        $one = $this->version(1, ['salary_amount' => 500]);
        $two = $this->version(2, ['salary_amount' => 900]);

        $this->assertSame(Classifier::FINANCIAL, $this->classifier->classify($one, $two));
        $this->assertSame(WorkflowStepKey::FinanceApproval, $this->classifier->restartsFrom(Classifier::FINANCIAL));
    }

    public function test_moving_the_position_starts_the_workflow_over(): void
    {
        $other = Program::query()->create([
            'name' => 'Other', 'code' => 'OTH', 'slug' => 'other',
            'start_date' => now(), 'is_active' => true,
        ]);

        $one = $this->version(1);
        $two = $this->version(2, ['program_id' => $other->id]);

        $this->assertSame(Classifier::SUBSTANTIVE, $this->classifier->classify($one, $two));
        $this->assertSame(WorkflowStepKey::ManagerApproval, $this->classifier->restartsFrom(Classifier::SUBSTANTIVE));
    }

    /** When one edit touches two categories, the severer one decides. */
    public function test_the_severest_category_wins(): void
    {
        $other = Department::query()->create(['name' => 'Other', 'code' => 'OTH']);

        $one = $this->version(1, ['salary_amount' => 500, 'main_duties' => 'One']);
        $two = $this->version(2, [
            'salary_amount' => 900,
            'main_duties' => 'Two',
            'department_id' => $other->id,
        ]);

        $this->assertSame(Classifier::SUBSTANTIVE, $this->classifier->classify($one, $two));
    }

    /**
     * Headcount is compared on the stored columns, so the derived amounts the
     * request types talk about cannot slip past — there is no additional_count
     * column for the comparison to miss.
     */
    public function test_headcount_is_compared_on_the_stored_columns(): void
    {
        $one = $this->version(1, ['current_headcount' => 5, 'requested_headcount' => 6]);
        $two = $this->version(2, ['current_headcount' => 5, 'requested_headcount' => 8]);

        $changes = $this->classifier->changes($one, $two);

        $this->assertSame(Classifier::SUBSTANTIVE, $changes['requested_headcount']['category']);
        $this->assertArrayNotHasKey('current_headcount', $changes);
    }

    /**
     * RV-1b needs the old and new value of everything that moved, not just the
     * fact that something did.
     */
    public function test_every_change_is_reported_with_its_old_and_new_value(): void
    {
        $one = $this->version(1, ['salary_amount' => 500, 'priority' => 'normal']);
        $two = $this->version(2, ['salary_amount' => 900, 'priority' => 'urgent']);

        $changes = $this->classifier->changes($one, $two);

        $this->assertEqualsCanonicalizing(['salary_amount', 'priority'], array_keys($changes));
        $this->assertSame(500, (int) $changes['salary_amount']['from']);
        $this->assertSame(900, (int) $changes['salary_amount']['to']);
        $this->assertSame('normal', $changes['priority']['from']);
        $this->assertSame('urgent', $changes['priority']['to']);
    }

    /**
     * Fields RV-1 calls substantive that live on the request rather than on the
     * details row are read from the version snapshot.
     */
    public function test_request_level_fields_are_compared_from_the_snapshot(): void
    {
        $one = $this->version(1, [], ['city_id' => 1]);
        $two = $this->version(2, [], ['city_id' => 2]);

        $changes = $this->classifier->changes($one, $two);

        $this->assertSame(Classifier::SUBSTANTIVE, $changes['city_id']['category']);
        $this->assertSame(Classifier::SUBSTANTIVE, $this->classifier->classify($one, $two));
    }

    /** Clearing a field is a change; it is not the same as setting it to zero. */
    public function test_clearing_a_field_is_a_change(): void
    {
        $one = $this->version(1, ['weekly_hours' => 40]);
        $two = $this->version(2, ['weekly_hours' => null]);

        $this->assertArrayHasKey('weekly_hours', $this->classifier->changes($one, $two));
    }

    private function version(int $number, array $detail = [], array $snapshot = []): RecruitmentRequestVersion
    {
        $version = RecruitmentRequestVersion::query()->create([
            'request_id' => $this->request->id,
            'version_no' => $number,
            'snapshot_json' => $snapshot,
            'created_by_user_id' => $this->request->requester_user_id,
            'submitted_at' => now(),
        ]);

        RecruitmentRequestPositionDetail::query()->create(array_merge([
            'request_version_id' => $version->id,
            'program_id' => Program::query()->firstOrCreate(
                ['slug' => 'base'],
                ['name' => 'Base', 'code' => 'BAS', 'start_date' => now(), 'is_active' => true],
            )->id,
            'department_id' => Department::query()->firstOrCreate(['code' => 'BAS'], ['name' => 'Base'])->id,
            'job_level_id' => JobLevel::query()->firstOrCreate(
                ['grade' => 6],
                ['name_en' => 'Team', 'name_ar' => 'فريق'],
            )->id,
            'salary_amount' => 500,
            'main_duties' => 'Duties',
        ], $detail));

        return $version->load('positionDetail');
    }
}
