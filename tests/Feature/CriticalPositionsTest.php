<?php

namespace Tests\Feature;

use App\Livewire\Position\PositionEdit;
use App\Models\City;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Education;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\Position;
use App\Models\User;
use App\Notifications\CriticalPositionNeedsReplacement;
use App\Services\CriticalPositions\CriticalPositionAlerts;
use App\Services\CriticalPositions\InternalCandidateRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Critical positions (meeting 19/11, track 6): the tag, when a critical position
 * "needs a replacement", the daily alert, and the suggested-replacements panel
 * (inside the organization by rules, and old CVs by the recruitment module's
 * own scope and privacy rules). Nothing here calls an outside service.
 */
class CriticalPositionsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const VIEW = 'positions.view-replacements';

    private const MARK = 'positions.mark-critical';

    private City $alp;

    private City $dms;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo('2026-06-01 10:00:00');
        $this->alp = $this->makeCity('ALP', '001');
        $this->dms = $this->makeCity('DMS', '004');
    }

    // ── The tag and who sets it ──────────────────────────────────────

    public function test_hr_of_the_city_marks_and_clears_the_tag(): void
    {
        $position = $this->makePosition($this->alp);
        $hr = $this->makeUser($this->alp, 'HR-ALP', ['positions.update', self::MARK]);

        Livewire::actingAs($hr)->test(PositionEdit::class, ['position' => $position])->call('toggleCritical');
        $this->assertTrue($position->fresh()->is_critical);

        Livewire::actingAs($hr)->test(PositionEdit::class, ['position' => $position])->call('toggleCritical');
        $this->assertFalse($position->fresh()->is_critical);
    }

    public function test_the_tag_cannot_be_set_without_the_permission_or_from_another_city(): void
    {
        $position = $this->makePosition($this->alp);
        $editor = $this->makeUser($this->alp, 'HR-ALP', ['positions.update']);
        $otherCityHr = $this->makeUser($this->dms, 'HR-DMS', ['positions.update', self::MARK]);

        $this->assertFalse(Gate::forUser($editor)->allows('markCritical', $position));
        $this->assertFalse(Gate::forUser($otherCityHr)->allows('markCritical', $position));

        // Without the permission the Livewire call is refused too, not only hidden.
        Livewire::actingAs($editor)->test(PositionEdit::class, ['position' => $position])
            ->call('toggleCritical')->assertForbidden();
        $this->assertFalse($position->fresh()->is_critical);
    }

    public function test_the_permission_migration_gives_the_tag_to_hr_and_the_panel_to_hr_and_the_general_manager_only(): void
    {
        $migration = require database_path('migrations/2026_11_24_000002_grant_critical_position_permissions.php');
        foreach (['HR-ALP', 'HR-SYR', 'super-admin', 'general-manager', 'manager', 'employee'] as $role) {
            \App\Models\Role::findOrCreate($role, 'web');
        }
        $migration->up();

        $has = fn (string $role, string $permission): bool => \App\Models\Role::findByName($role, 'web')->hasPermissionTo($permission);

        foreach (['HR-ALP', 'HR-SYR', 'super-admin'] as $role) {
            $this->assertTrue($has($role, self::MARK), $role);
            $this->assertTrue($has($role, self::VIEW), $role);
        }
        $this->assertFalse($has('general-manager', self::MARK));
        $this->assertTrue($has('general-manager', self::VIEW));

        foreach (['manager', 'employee'] as $role) {
            $this->assertFalse($has($role, self::MARK), $role);
            $this->assertFalse($has($role, self::VIEW), $role);
        }
    }

    // ── Needs a replacement ──────────────────────────────────────────

    public function test_a_critical_position_needs_a_replacement_when_vacant_or_its_holder_contract_ended(): void
    {
        $alerts = app(CriticalPositionAlerts::class);

        $vacant = $this->makePosition($this->alp, ['is_critical' => true]);
        $ended = $this->makePosition($this->alp, ['is_critical' => true]);
        $filled = $this->makePosition($this->alp, ['is_critical' => true]);
        $notCritical = $this->makePosition($this->alp);
        $inactive = $this->makePosition($this->alp, ['is_critical' => true, 'is_active' => false]);

        $this->hold($ended, 'old', ['start_date' => '2025-01-01', 'end_date' => '2026-05-01']);
        $this->hold($filled, 'current');

        $this->assertSame(['vacant'], $alerts->reasonsFor($vacant));
        $this->assertSame(['contract_ended'], $alerts->reasonsFor($ended));
        $this->assertSame([], $alerts->reasonsFor($filled));
        $this->assertSame([], $alerts->reasonsFor($notCritical));
        $this->assertSame([], $alerts->reasonsFor($inactive));

        $this->assertEqualsCanonicalizing(
            [$vacant->id, $ended->id],
            $alerts->positionsNeedingReplacement()->pluck('id')->all(),
        );
    }

    public function test_a_terminated_holder_leaves_the_position_needing_a_replacement(): void
    {
        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $this->hold($position, 'leaver', ['is_active' => false, 'terminated_at' => '2026-05-20']);

        $this->assertSame(['contract_ended'], app(CriticalPositionAlerts::class)->reasonsFor($position));
    }

    // ── The daily alert ──────────────────────────────────────────────

    public function test_the_alert_goes_to_hr_of_the_city_and_the_direct_manager_once(): void
    {
        Notification::fake();

        $managerPosition = $this->makePosition($this->alp, ['title_en' => 'Manager']);
        $managerEmployee = $this->hold($managerPosition, 'boss');
        $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
        $position = $this->makePosition($this->alp, ['is_critical' => true, 'direct_manager_id' => $managerPosition->id]);

        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);
        $otherCityHr = $this->makeUser($this->dms, 'HR-DMS', [self::VIEW]);

        $this->artisan('positions:notify-critical')->assertSuccessful();

        Notification::assertSentTo($hr, CriticalPositionNeedsReplacement::class, fn ($n) => $n->position->is($position) && $n->reason === 'vacant');
        Notification::assertSentTo($manager, CriticalPositionNeedsReplacement::class);
        Notification::assertNotSentTo($otherCityHr, CriticalPositionNeedsReplacement::class);

        $this->artisan('positions:notify-critical')->assertSuccessful();
        Notification::assertSentToTimes($hr, CriticalPositionNeedsReplacement::class, 1);
        Notification::assertSentToTimes($manager, CriticalPositionNeedsReplacement::class, 1);
    }

    public function test_a_filled_or_untagged_position_sends_nothing(): void
    {
        Notification::fake();
        $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);
        $this->hold($this->makePosition($this->alp, ['is_critical' => true]), 'holder');
        $this->makePosition($this->alp);

        $this->artisan('positions:notify-critical')->assertSuccessful();

        Notification::assertNothingSent();
    }

    // ── Suggested replacements inside the organization ───────────────

    public function test_internal_suggestions_are_ranked_and_each_one_explains_itself(): void
    {
        $target = $this->makePosition($this->alp, [
            'is_critical' => true,
            'title_en' => 'Finance Manager',
            'qualifications' => 'Accounting and finance degree',
        ]);

        $strong = $this->hold($this->makePosition($this->alp, ['title_en' => 'Finance Officer']), 'strong', ['start_date' => '2022-01-01']);
        Education::query()->forceCreate(['employee_id' => $strong->id, 'name' => 'Aleppo University', 'specialization' => 'Accounting finance']);

        $otherDepartment = Department::query()->create(['code' => 'OPS', 'name' => 'Operations']);
        $farLevel = JobLevel::query()->create(['grade' => 7, 'name_en' => 'Aide', 'name_ar' => 'مساعد']);
        $weak = $this->hold($this->makePosition($this->dms, [
            'title_en' => 'Driver', 'job_level_id' => $farLevel->id, 'department_id' => $otherDepartment->id,
        ]), 'weak', ['start_date' => '2026-03-01']);

        $viewer = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);
        $list = app(InternalCandidateRanker::class)->suggest($target, $viewer, 10);

        $this->assertSame([$strong->id], array_map(fn ($s) => $s->id, $list));
        $this->assertGreaterThan(60, $list[0]->score);

        $reasons = array_column($list[0]->reasons, 'key');
        foreach (['level', 'title', 'background', 'service', 'department', 'same-city'] as $key) {
            $this->assertContains('critical-positions.reasons.'.$key, $reasons);
        }
        $this->assertNotEmpty($list[0]->reasonTexts());
        $this->assertNotContains($weak->id, array_map(fn ($s) => $s->id, $list));
    }

    public function test_someone_already_holding_a_critical_position_is_never_suggested(): void
    {
        $target = $this->makePosition($this->alp, ['is_critical' => true, 'title_en' => 'Finance Manager']);
        $busy = $this->hold($this->makePosition($this->alp, ['is_critical' => true, 'title_en' => 'Finance Manager']), 'busy');
        $free = $this->hold($this->makePosition($this->alp, ['title_en' => 'Finance Manager']), 'free');

        $viewer = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);
        $ids = array_map(fn ($s) => $s->id, app(InternalCandidateRanker::class)->suggest($target, $viewer, 10));

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($busy->id, $ids);
    }

    public function test_hr_of_a_city_is_not_offered_volunteers_of_another_city(): void
    {
        $target = $this->makePosition($this->alp, ['is_critical' => true, 'title_en' => 'Finance Manager']);
        $faraway = $this->hold($this->makePosition($this->dms, ['title_en' => 'Finance Manager']), 'faraway');

        $alpHr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);
        $national = $this->makeUser($this->alp, 'HR-SYR', [self::VIEW]);

        $this->assertSame([], app(InternalCandidateRanker::class)->suggest($target, $alpHr, 10));

        $nationalIds = array_map(fn ($s) => $s->id, app(InternalCandidateRanker::class)->suggest($target, $national, 10));
        $this->assertSame([$faraway->id], $nationalIds);
    }

    // ── The panel: who may open it ───────────────────────────────────

    public function test_the_panel_opens_for_hr_of_the_city_the_general_manager_and_administrators(): void
    {
        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $url = route('positions.replacements', $position);

        foreach ([
            $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]),
            $this->makeUser($this->alp, 'HR-SYR', [self::VIEW]),
            $this->makeUser($this->alp, 'general-manager', [self::VIEW]),
            $this->makeUser($this->alp, 'super-admin', [self::VIEW]),
        ] as $user) {
            $this->actingAs($user)->get($url)->assertOk()->assertSee(__('critical-positions.cv-empty'));
        }
    }

    public function test_the_panel_is_closed_to_managers_employees_the_candidate_and_other_cities(): void
    {
        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $url = route('positions.replacements', $position);

        // Even a manager or employee handed the permission by hand stays out.
        $manager = $this->makeUser($this->alp, 'manager', [self::VIEW]);
        $candidate = $this->makeUser($this->alp, 'employee', [self::VIEW]);
        $withoutPermission = $this->makeUser($this->alp, 'HR-ALP');
        $otherCity = $this->makeUser($this->dms, 'HR-DMS', [self::VIEW]);

        foreach ([$manager, $candidate, $withoutPermission, $otherCity] as $user) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_the_panel_exists_only_for_a_critical_position(): void
    {
        $position = $this->makePosition($this->alp);
        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);

        $this->actingAs($hr)->get(route('positions.replacements', $position))->assertForbidden();
    }

    public function test_the_critical_list_shows_only_the_scope_of_the_viewer_and_flags_vacancies(): void
    {
        $mine = $this->makePosition($this->alp, ['is_critical' => true, 'title_en' => 'Aleppo Director']);
        $theirs = $this->makePosition($this->dms, ['is_critical' => true, 'title_en' => 'Damascus Director']);
        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);

        $this->actingAs($hr)->get(route('positions.critical'))
            ->assertOk()
            ->assertSee('Aleppo Director')
            ->assertDontSee('Damascus Director')
            ->assertSee(__('critical-positions.needs-replacement'));

        $this->assertNotNull($theirs);
        $this->assertNotNull($mine);
    }

    // ── Old CVs and privacy ──────────────────────────────────────────

    public function test_the_cv_source_is_empty_and_says_so_when_there_are_no_applicants(): void
    {
        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);

        $this->actingAs($hr)->get(route('positions.replacements', $position))
            ->assertOk()
            ->assertSee(__('critical-positions.cv-empty'));
    }

    public function test_the_cv_source_returns_nothing_to_someone_who_may_not_see_applicants(): void
    {
        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);

        $this->assertSame([], app(\App\Services\CriticalPositions\PastApplicantsSource::class)->suggest($position, $hr, 10));
    }

    // ── Nothing leaves the system ────────────────────────────────────

    public function test_no_outside_request_is_made_while_building_the_panel_or_sending_alerts(): void
    {
        Http::fake();
        Notification::fake();

        $position = $this->makePosition($this->alp, ['is_critical' => true]);
        $this->hold($this->makePosition($this->alp), 'someone');
        $hr = $this->makeUser($this->alp, 'HR-ALP', [self::VIEW]);

        $this->actingAs($hr)->get(route('positions.replacements', $position))->assertOk();
        $this->artisan('positions:notify-critical')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** An employee of the position's city holding it under a contract running now. */
    private function hold(Position $position, string $username, array $contract = []): Employee
    {
        $employee = $this->makeEmployee($position->city ?? $this->alp, $username.'-'.uniqid());
        $employee->update(['city_id' => $position->city_id]);

        $this->makeContractFor($employee->refresh(), array_merge(['position_id' => $position->id], $contract));

        return $employee;
    }
}
