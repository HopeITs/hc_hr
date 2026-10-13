<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestStatus as Status;
use App\Enums\RecruitmentRequestType as Type;
use App\Livewire\Recruitment\Requests\Details;
use App\Models\RecruitmentRequest;
use App\Services\RecruitmentRequestDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsRecruitmentRequests;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A refused submission has to say why.
 *
 * Every rule the submission service enforces raises a message keyed to a form
 * field — data.position_id, data.salary_amount. The details screen is not that
 * form and renders none of those keys, so each refusal went into the error bag
 * and nowhere else: the requester pressed Submit and nothing happened at all,
 * with no message and no change of state. Silence is the defect these tests
 * guard against, so they assert on what reaches the screen, not on the throw.
 */
class RecruitmentSubmitRefusalTest extends TestCase
{
    use BuildsRecruitmentRequests, CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_a_duplicate_on_the_same_position_is_reported_with_the_blocking_number(): void
    {
        ['requester' => $requester, 'draft' => $draft, 'position' => $position] = $this->draftOnAPosition();

        // The request already holding that position, under a number of its own.
        RecruitmentRequest::query()->create([
            'request_number' => 'HS-007',
            'requester_user_id' => $requester->getKey(),
            'request_type' => Type::FillVacancy,
            'mode' => \App\Enums\RecruitmentRequestMode::Normal,
            'status' => Status::UnderReview,
            'scope_city_id' => $draft->scope_city_id,
            'target_position_id' => $position->getKey(),
            'submitted_at' => now(),
        ]);

        $screen = Livewire::actingAs($requester)
            ->test(Details::class, ['requestId' => $draft->getKey()])
            ->call('submit');

        // The refusal names the request in the way, so the requester knows
        // which one to cancel or finish.
        $this->assertStringContainsString('HS-007', $this->toastText($screen));

        // And it is a refusal, not a warning after the fact.
        $this->assertSame(Status::Draft, (int) $draft->fresh()->status);
    }

    /** The draft stays a draft, and the reason is on screen rather than in a log. */
    public function test_the_refusal_is_visible_and_not_only_thrown(): void
    {
        ['requester' => $requester, 'draft' => $draft, 'position' => $position] = $this->draftOnAPosition();

        RecruitmentRequest::query()->create([
            'request_number' => 'HS-008',
            'requester_user_id' => $requester->getKey(),
            'request_type' => Type::FillVacancy,
            'mode' => \App\Enums\RecruitmentRequestMode::Normal,
            'status' => Status::UnderReview,
            'scope_city_id' => $draft->scope_city_id,
            'target_position_id' => $position->getKey(),
            'submitted_at' => now(),
        ]);

        $screen = Livewire::actingAs($requester)
            ->test(Details::class, ['requestId' => $draft->getKey()])
            ->call('submit');

        // Something was said at all — the defect was saying nothing.
        $this->assertNotSame('', $this->toastText($screen));

        // And it was said as a refusal rather than as a success.
        $this->assertSame('error', $this->toastIcon($screen));
    }

    /**
     * The toast ships as a Livewire `js` effect rather than an event, so what it
     * says has to be read back out of the emitted script.
     */
    private function toastScript($screen): string
    {
        return collect($screen->effects['xjs'] ?? [])->pluck('expression')->implode("\n");
    }

    private function toastText($screen): string
    {
        preg_match('/"title":"(.*?)"/', $this->toastScript($screen), $matches);

        return $matches[1] ?? '';
    }

    private function toastIcon($screen): ?string
    {
        preg_match('/"icon":"(\w+)"/', $this->toastScript($screen), $matches);

        return $matches[1] ?? null;
    }

    /**
     * A requester with a post beneath them, and a full payload aimed at it.
     *
     * A request is raised for a post in one's own tree rather than for one's own
     * chair, so the fixture needs two positions and a contract seating the
     * requester on the upper one.
     *
     * @return array{requester: \App\Models\User, draft: RecruitmentRequest, position: \App\Models\Position}
     */
    private function draftOnAPosition(): array
    {
        $city = $this->makeCity('SYR', '000');
        $requester = $this->makeUser($city, 'manager');
        $requester->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(
            'recruitment-requests.submit-without-manager', 'web',
        ));

        $root = \App\Models\Position::query()->create([
            'city_id' => $city->id,
            'program_id' => \App\Models\Program::query()->create([
                'name' => 'Finance', 'code' => 'FIN', 'slug' => 'finance',
                'start_date' => now(), 'is_active' => true,
            ])->id,
            'department_id' => \App\Models\Department::query()->create(['name' => 'Finance', 'code' => 'FIN'])->id,
            'job_level_id' => \App\Models\JobLevel::query()->create([
                'name_en' => 'Manager', 'name_ar' => 'مدير', 'grade' => 5,
            ])->id,
            'title_en' => 'Manager', 'title_ar' => 'مدير',
            'is_active' => true, 'max_emp' => 5, 'exist_emp' => 0,
        ]);

        \App\Models\Contract::query()->create([
            'employee_id' => $requester->employee_id, 'position_id' => $root->id,
            'contract_type_id' => $this->recruitmentContractTypeId(),
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
            'currency' => 2, 'salary_per' => 1, 'weekly_hours' => 40, 'is_active' => true,
        ]);

        $target = $root->replicate();
        $target->fill(['direct_manager_id' => $root->id, 'title_en' => 'Analyst', 'exist_emp' => 0])->save();

        $payload = ['request_type' => Type::FillVacancy, 'position_id' => $target->id]
            + $this->recruitmentFormDefaults();

        $draft = app(RecruitmentRequestDraftService::class)->create($requester, $payload);

        return ['requester' => $requester, 'draft' => $draft->fresh(), 'position' => $target];
    }
}
