<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestType;
use App\Models\RecruitmentWorkflowDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A database brought up by migrations alone must still be able to send a
 * recruitment request: hr_db had no approval workflows and every request
 * stopped at the draft (P1 run, 2026-11-27).
 */
class RecruitmentWorkflowMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_one_active_workflow_per_request_type(): void
    {
        User::factory()->create();
        RecruitmentWorkflowDefinition::query()->delete();

        $this->migration()->up();

        $types = RecruitmentWorkflowDefinition::query()->where('is_active', true)->pluck('request_type')->map(fn ($t) => (int) $t)->sort()->values()->all();

        $this->assertSame([
            RecruitmentRequestType::FillVacancy,
            RecruitmentRequestType::IncreaseHeadcount,
            RecruitmentRequestType::DecreaseHeadcount,
            RecruitmentRequestType::CreatePosition,
            RecruitmentRequestType::ClosePosition,
            RecruitmentRequestType::CloseVacancy,
        ], $types);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        User::factory()->create();

        $this->migration()->up();
        $count = RecruitmentWorkflowDefinition::query()->count();
        $this->migration()->up();

        $this->assertSame($count, RecruitmentWorkflowDefinition::query()->count());
    }

    public function test_a_database_without_users_is_left_for_the_seeder(): void
    {
        User::query()->delete();
        RecruitmentWorkflowDefinition::query()->delete();

        $this->migration()->up();

        $this->assertSame(0, RecruitmentWorkflowDefinition::query()->count());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_12_05_000001_load_recruitment_approval_workflows.php');
    }
}
