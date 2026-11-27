<?php

namespace Tests\Feature;

use App\Models\PolicyDocument;
use App\Models\RecruitmentWorkflowDefinition;
use Database\Seeders\ScenarioDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The demo data the live scenario runs need on hr_db (T10): workflows, one
 * campaign, two required policies. The campaign needs a free vacancy, which
 * this database does not have, so it is skipped here; it was checked on hr_db.
 */
class ScenarioDemoSeederTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
    }

    public function test_it_seeds_the_workflows_and_two_required_policies_once_and_gives_hr_syria_their_role_back(): void
    {
        $city = $this->makeCity('SYR', '000');
        $hr = $this->makeUser($city, 'HR-ALP');
        $this->assignInactiveRole($hr, 'HR-SYR', ['recruitment-campaigns.manage']);

        $this->seed(ScenarioDemoSeeder::class);
        $this->seed(ScenarioDemoSeeder::class);

        $this->assertSame(6, RecruitmentWorkflowDefinition::query()->where('is_active', true)->count());

        $policies = PolicyDocument::query()->orderBy('id')->get();
        $this->assertSame(['code-of-conduct', 'media-guideline'], $policies->pluck('title')->all());
        $this->assertSame([PolicyDocument::KIND_POLICY, PolicyDocument::KIND_REVIEW], $policies->pluck('onboarding_kind')->all());
        $this->assertTrue($policies->every(fn ($p) => $p->required_for_onboarding && $p->read_status === PolicyDocument::READY));
        $this->assertStringContainsString('سياسة قواعد السلوك', $policies->first()->content);

        $this->assertSame('HR-ALP', $hr->fresh()->myRole());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        (new ScenarioDemoSeeder)->run();
    }
}
