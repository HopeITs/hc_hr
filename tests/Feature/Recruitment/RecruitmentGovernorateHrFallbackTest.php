<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentScopeType;
use App\Models\City;
use App\Models\RecruitmentWorkflowInstance;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Services\RecruitmentApprovalService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsWorkflowFixtures;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Who reviews a governorate, and that the same answer decides who may approve.
 *
 * A governorate with an HR of its own is reviewed by it. Without one, the
 * request falls back: Aleppo and every other governorate to HR Syria, Tartous
 * to Latakia, Homs Rural to Homs. The person a step is assigned to and the
 * person allowed to decide it come from one resolver, so a request can never
 * appear in somebody's queue and then refuse them.
 */
class RecruitmentGovernorateHrFallbackTest extends TestCase
{
    use BuildsWorkflowFixtures, CreatesHrFixtures, RefreshDatabase;

    private RecruitmentScopeResolver $scopes;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->scopes = app(RecruitmentScopeResolver::class);
    }

    // ---- The rule --------------------------------------------------------

    public function test_aleppo_goes_to_its_own_hr_when_it_has_one(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $this->makeUser($aleppo, 'HR-ALP');
        $this->makeUser($aleppo, 'HR-SYR');

        $this->assertSame('HR-ALP', $this->scopes->governorateHrRole($aleppo->id));
    }

    public function test_aleppo_falls_back_to_hr_syria(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $this->makeUser($aleppo, 'HR-SYR');

        $this->assertSame('HR-SYR', $this->scopes->governorateHrRole($aleppo->id));
    }

    public function test_tartous_goes_to_its_own_hr_when_it_has_one(): void
    {
        $tartous = $this->makeCity('TAR', '006');
        $this->makeUser($tartous, 'HR-TAR');
        $this->makeUser($this->makeCity('LAT', '005'), 'HR-LAT');

        $this->assertSame('HR-TAR', $this->scopes->governorateHrRole($tartous->id));
    }

    public function test_tartous_falls_back_to_hr_latakia(): void
    {
        $tartous = $this->makeCity('TAR', '006');
        $this->makeUser($this->makeCity('LAT', '005'), 'HR-LAT');

        $this->assertSame('HR-LAT', $this->scopes->governorateHrRole($tartous->id));
    }

    public function test_homs_rural_goes_to_its_own_hr_when_it_has_one(): void
    {
        $rural = $this->makeCity('RHO', '008');
        $this->makeUser($rural, 'HR-RHO');
        $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS');

        $this->assertSame('HR-RHO', $this->scopes->governorateHrRole($rural->id));
    }

    public function test_homs_rural_falls_back_to_hr_homs(): void
    {
        $rural = $this->makeCity('RHO', '008');
        $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS');

        $this->assertSame('HR-HMS', $this->scopes->governorateHrRole($rural->id));
    }

    public function test_any_other_governorate_goes_to_its_own_hr_when_it_has_one(): void
    {
        $damascus = $this->makeCity('DMS', '003');
        $this->makeUser($damascus, 'HR-DMS');

        $this->assertSame('HR-DMS', $this->scopes->governorateHrRole($damascus->id));
    }

    public function test_any_other_governorate_falls_back_to_hr_syria(): void
    {
        $damascus = $this->makeCity('DMS', '003');

        $this->assertSame('HR-SYR', $this->scopes->governorateHrRole($damascus->id));
    }

    /** A role nobody active holds is not an HR the governorate has. */
    public function test_a_role_with_no_active_holder_does_not_count(): void
    {
        $tartous = $this->makeCity('TAR', '006');
        $hr = $this->makeUser($tartous, 'HR-TAR');
        $hr->profile->update(['is_active' => false]);

        $this->assertSame('HR-LAT', $this->scopes->governorateHrRole($tartous->id));
    }

    // ---- What each role may hold -----------------------------------------

    public function test_the_scope_is_only_what_falls_back_to_the_role(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $tartous = $this->makeCity('TAR', '006');
        $latakia = $this->makeCity('LAT', '005');
        $city = RecruitmentScopeType::City;

        $hrSyria = $this->makeUser($aleppo, 'HR-SYR');
        $hrLatakia = $this->makeUser($latakia, 'HR-LAT');
        $hrHoms = $this->makeUser($homs, 'HR-HMS');

        // Aleppo has no HR: HR Syria holds it. It does not hold Homs, whose
        // own HR exists, nor Tartous, which falls to Latakia.
        $this->assertTrue($this->scopes->holdsScope($hrSyria, $city, $aleppo->id));
        $this->assertFalse($this->scopes->holdsScope($hrSyria, $city, $homs->id));
        $this->assertFalse($this->scopes->holdsScope($hrSyria, $city, $tartous->id));

        $this->assertTrue($this->scopes->holdsScope($hrLatakia, $city, $tartous->id));
        $this->assertTrue($this->scopes->holdsScope($hrLatakia, $city, $latakia->id));
        $this->assertFalse($this->scopes->holdsScope($hrHoms, $city, $tartous->id));
    }

    public function test_an_hr_of_its_own_takes_the_governorate_from_the_fallback(): void
    {
        $tartous = $this->makeCity('TAR', '006');
        $rural = $this->makeCity('RHO', '008');
        $aleppo = $this->makeCity('ALP', '001');
        $city = RecruitmentScopeType::City;

        $this->makeUser($tartous, 'HR-TAR');
        $this->makeUser($rural, 'HR-RHO');
        $this->makeUser($aleppo, 'HR-ALP');

        $hrLatakia = $this->makeUser($this->makeCity('LAT', '005'), 'HR-LAT');
        $hrHoms = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS');
        $hrSyria = $this->makeUser($aleppo, 'HR-SYR');

        $this->assertFalse($this->scopes->holdsScope($hrLatakia, $city, $tartous->id));
        $this->assertNotContains($tartous->id, $this->scopes->governorateCityIds($hrLatakia));

        $this->assertFalse($this->scopes->holdsScope($hrHoms, $city, $rural->id));
        $this->assertNotContains($rural->id, $this->scopes->governorateCityIds($hrHoms));

        $this->assertFalse($this->scopes->holdsScope($hrSyria, $city, $aleppo->id));
    }

    // ---- Approving -------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function fallbacks(): array
    {
        return [
            'aleppo to hr syria' => ['ALP', 'HR-SYR'],
            'tartous to hr latakia' => ['TAR', 'HR-LAT'],
            'homs rural to hr homs' => ['RHO', 'HR-HMS'],
            'another governorate to hr syria' => ['DMS', 'HR-SYR'],
        ];
    }

    /** Assigned, in the queue, and then able to decide: one answer throughout. */
    #[\PHPUnit\Framework\Attributes\DataProvider('fallbacks')]
    public function test_the_fallback_hr_can_approve_what_it_was_assigned(string $slug, string $role): void
    {
        $fixture = $this->workflow($slug, $role);
        $instance = $fixture['instance'];

        $this->assertSame($fixture['hr']->id, (int) $this->stepAt($instance, 3)->assigned_user_id);

        $approvals = app(RecruitmentApprovalService::class);
        $approvals->approve($fixture['manager'], $this->stepAt($instance, 1), 1);
        $approvals->approve($fixture['finance'], $this->stepAt($instance, 2), 1);
        $approvals->approve($fixture['hr'], $this->stepAt($instance, 3), 1);

        $this->assertNotNull($this->stepAt($instance, 3)->decision, "{$role} could not approve {$slug}");
    }

    public function test_the_governorates_own_hr_can_approve_and_the_fallback_cannot(): void
    {
        $fixture = $this->workflow('ALP', 'HR-ALP');
        $instance = $fixture['instance'];
        $hrSyria = $this->makeUser(City::query()->findOrFail($fixture['request']->scope_city_id), 'HR-SYR', \App\Support\AccessPermissionCatalog::roleGrantableNames());

        $approvals = app(RecruitmentApprovalService::class);
        $approvals->approve($fixture['manager'], $this->stepAt($instance, 1), 1);
        $approvals->approve($fixture['finance'], $this->stepAt($instance, 2), 1);

        $step = $this->stepAt($instance, 3);
        $this->assertSame($fixture['hr']->id, (int) $step->assigned_user_id);

        $this->assertFalse($this->scopes->canActOnStep($hrSyria, $step));

        $approvals->approve($fixture['hr'], $step, 1);
        $this->assertNotNull($this->stepAt($instance, 3)->decision);
    }

    public function test_an_hr_who_is_not_the_resolved_reviewer_cannot_approve(): void
    {
        $fixture = $this->workflow('TAR', 'HR-LAT');
        $instance = $fixture['instance'];

        $approvals = app(RecruitmentApprovalService::class);
        $approvals->approve($fixture['manager'], $this->stepAt($instance, 1), 1);
        $approvals->approve($fixture['finance'], $this->stepAt($instance, 2), 1);

        $city = City::query()->findOrFail($fixture['request']->scope_city_id);
        $others = [
            $this->makeUser($city, 'HR-HMS', \App\Support\AccessPermissionCatalog::roleGrantableNames()),
            $this->makeUser($city, 'HR-SYR', \App\Support\AccessPermissionCatalog::roleGrantableNames()),
        ];

        foreach ($others as $other) {
            $step = $this->stepAt($instance, 3);

            try {
                $approvals->approve($other, $step, 1);
                $this->fail('Only the resolved reviewer decides.');
            } catch (ValidationException|AuthorizationException) {
                $this->assertNull($step->fresh()->decision, 'A refused decision writes nothing.');
            }
        }
    }

    private function stepAt(RecruitmentWorkflowInstance $instance, int $order): RecruitmentWorkflowInstanceStep
    {
        return $instance->steps()
            ->whereHas('workflowStep', fn ($query) => $query->where('step_order', $order))
            ->with(['workflowStep', 'workflowInstance.requestVersion'])
            ->sole();
    }
}
