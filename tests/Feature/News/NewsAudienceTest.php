<?php

namespace Tests\Feature\News;

use App\Enums\News\AudienceType;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\News;
use App\Models\NewsAudienceTarget;
use App\Models\Position;
use App\Models\Program;
use App\Models\User;
use App\Services\News\NewsFeed;
use App\Support\News\NewsViewer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Audience decides who may see an item, in SQL. Tags are only what the item
 * is about and never restrict visibility.
 *
 * Two viewers sit on deliberately disjoint coordinates: a Damascus employee
 * (city, center, program, department of their contract's position) and an
 * Aleppo employee on another center, program and department.
 */
class NewsAudienceTest extends TestCase
{
    use CreatesContractFixtures, CreatesNews, RefreshDatabase;

    private City $dms;

    private City $alp;

    private User $dmsUser;

    private Employee $alpEmployee;

    private Position $dmsPosition;

    private Position $alpPosition;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

        $this->dms = $this->makeCity('DMS', '003');
        $this->alp = $this->makeCity('ALP', '001');

        $this->dmsUser = $this->makeUser($this->dms, 'employee');
        $this->dmsPosition = $this->makePosition($this->dms);
        $this->makeContractFor($this->dmsUser->profile, ['position_id' => $this->dmsPosition->id]);

        $otherProgram = Program::query()->create(['name' => 'Other Program', 'code' => 'OTH', 'slug' => 'other-program', 'is_active' => true]);
        $otherDepartment = Department::query()->create(['code' => 'FIN', 'name' => 'Finance']);

        $this->alpEmployee = $this->makeEmployee($this->alp, 'aleppo-employee');
        $this->alpPosition = $this->makePosition($this->alp, [
            'program_id' => $otherProgram->id,
            'department_id' => $otherDepartment->id,
        ]);
        $this->makeContractFor($this->alpEmployee, ['position_id' => $this->alpPosition->id]);
    }

    public function test_the_fixture_viewers_really_are_disjoint(): void
    {
        $dms = NewsViewer::from($this->dmsUser->fresh())->targets();
        $alp = NewsViewer::from($this->alpEmployee->fresh())->targets();

        $this->assertSame([$this->dms->id], $dms[AudienceType::Cities->value]);
        $this->assertSame([$this->dmsPosition->center_id], $dms[AudienceType::Centers->value]);
        $this->assertSame([$this->dmsPosition->program_id], $dms[AudienceType::Programs->value]);
        $this->assertSame([$this->dmsPosition->department_id], $dms[AudienceType::Departments->value]);
        $this->assertSame([$this->dmsUser->employee_id], $dms[AudienceType::Employees->value]);

        foreach ($dms as $type => $ids) {
            $this->assertSame([], array_values(array_intersect($ids, $alp[$type])), "Viewers share a {$type} target");
        }
    }

    public function test_audience_all_reaches_everyone_including_a_user_without_an_employee(): void
    {
        $news = $this->makeNews();
        $noEmployee = User::factory()->create(['employee_id' => null]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
        $this->assertTrue($news->isVisibleTo($noEmployee->fresh()));
        $this->assertTrue($news->isVisibleTo(null));
    }

    public function test_a_user_without_an_employee_sees_only_organization_wide_items(): void
    {
        $all = $this->makeNews();
        $this->makeNewsFor(AudienceType::Cities, [$this->dms->id, $this->alp->id]);
        $this->makeNewsFor(AudienceType::Employees, [$this->dmsUser->employee_id]);
        $noEmployee = User::factory()->create(['employee_id' => null]);

        $this->assertSame([$all->id], News::query()->visibleTo($noEmployee->fresh())->pluck('id')->all());
        $this->assertSame([$all->id], News::query()->visibleTo(null)->pluck('id')->all());
    }

    public function test_a_city_audience_reaches_only_that_city(): void
    {
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()));
    }

    public function test_a_city_audience_also_matches_an_employee_without_a_contract_by_their_own_city(): void
    {
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->alp->id]);
        $newcomer = $this->makeEmployee($this->alp, 'aleppo-newcomer');

        $this->assertTrue($news->isVisibleTo($newcomer->fresh()));
        $this->assertFalse($news->isVisibleTo($this->dmsUser->fresh()));
    }

    public function test_a_center_audience_reaches_only_that_center(): void
    {
        $news = $this->makeNewsFor(AudienceType::Centers, [$this->dmsPosition->center_id]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()));
    }

    public function test_a_program_audience_reaches_only_that_program(): void
    {
        $news = $this->makeNewsFor(AudienceType::Programs, [$this->alpPosition->program_id]);

        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
        $this->assertFalse($news->isVisibleTo($this->dmsUser->fresh()));
    }

    public function test_a_department_audience_reaches_only_that_department(): void
    {
        $news = $this->makeNewsFor(AudienceType::Departments, [$this->dmsPosition->department_id]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()));
    }

    public function test_an_employee_audience_reaches_only_those_employees(): void
    {
        $news = $this->makeNewsFor(AudienceType::Employees, [$this->alpEmployee->id]);

        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
        $this->assertFalse($news->isVisibleTo($this->dmsUser->fresh()));
    }

    public function test_target_ids_are_matched_within_the_audience_type_only(): void
    {
        // The viewer's city id stored as, say, a department target (the kind
        // is the parent's audience_type) must not match: ids never leak
        // across kinds.
        // The Aleppo viewer: its center id (3rd center or later) differs from
        // its city id (2), so there is always at least one pair to try.
        $targets = NewsViewer::from($this->alpEmployee->fresh())->targets();
        $checked = 0;

        foreach ($targets as $kind => $ids) {
            foreach ($targets as $otherKind => $otherIds) {
                if ($kind === $otherKind) {
                    continue;
                }

                foreach (array_diff($otherIds, $ids) as $foreignId) {
                    $news = $this->makeNewsFor(AudienceType::from($kind), [$foreignId]);

                    $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()), "A {$otherKind} id leaked into a {$kind} audience");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_an_audience_with_no_targets_reaches_nobody(): void
    {
        $news = $this->makeNewsFor(AudienceType::Cities, []);

        $this->assertFalse($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()));
        $this->assertFalse($news->isVisibleTo(null));
    }

    public function test_visibility_is_the_same_for_a_user_and_their_employee(): void
    {
        $this->makeNews();
        $this->makeNewsFor(AudienceType::Centers, [$this->dmsPosition->center_id]);
        $this->makeNewsFor(AudienceType::Programs, [$this->alpPosition->program_id]);

        $this->assertSame(
            News::query()->visibleTo($this->dmsUser->fresh())->orderBy('id')->pluck('id')->all(),
            News::query()->visibleTo($this->dmsUser->profile()->first())->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_tags_never_restrict_visibility(): void
    {
        $news = $this->makeNews();
        $news->syncTags([$this->alp->name_en, 'Study Zone']);

        $this->assertSame(AudienceType::All, $news->audience_type);
        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
    }

    public function test_audience_decides_even_when_a_tag_names_another_city(): void
    {
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id]);
        $news->syncTags([$this->alp->name_en]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertFalse($news->isVisibleTo($this->alpEmployee->fresh()));
        // The tag is kept as the subject.
        $this->assertSame([$this->alp->name_en], $news->fresh()->tagNames());
    }

    public function test_a_legacy_related_to_never_restricts_visibility(): void
    {
        // Old rows keep newsable_* (no longer written or read by the app).
        $news = $this->makeNews([
            'newsable_type' => City::class,
            'newsable_id' => $this->alp->id,
        ]);

        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
    }

    public function test_the_feed_applies_the_audience(): void
    {
        $all = $this->makeNews();
        $mine = $this->makeNewsFor(AudienceType::Centers, [$this->dmsPosition->center_id]);
        $this->makeNewsFor(AudienceType::Cities, [$this->alp->id]);

        $latest = app(NewsFeed::class)->latestNews($this->dmsUser->fresh());

        $this->assertEqualsCanonicalizing([$all->id, $mine->id], $latest->pluck('id')->all());
    }

    public function test_sync_audience_replaces_the_targets(): void
    {
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id, $this->alp->id]);

        $this->assertSame(AudienceType::Cities, $news->audience_type);
        $this->assertEqualsCanonicalizing([$this->dms->id, $this->alp->id], $this->targetIds($news));

        $news->syncAudience(AudienceType::Centers, [$this->alpPosition->center_id, $this->alpPosition->center_id, '0']);
        $news->refresh();

        $this->assertSame(AudienceType::Centers, $news->audience_type);
        // Duplicates collapse and empty ids are dropped.
        $this->assertSame([$this->alpPosition->center_id], $this->targetIds($news));
        $this->assertFalse($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertTrue($news->isVisibleTo($this->alpEmployee->fresh()));
        $this->assertEqualsCanonicalizing([$this->alpPosition->center_id], $news->audienceEntities()->pluck('id')->all());

        $news->syncAudience(AudienceType::All, [$this->dms->id]);
        $news->refresh();

        $this->assertSame(AudienceType::All, $news->audience_type);
        $this->assertSame([], $this->targetIds($news));
        $this->assertSame(0, NewsAudienceTarget::query()->where('news_id', $news->id)->count());
        $this->assertTrue($news->isVisibleTo($this->dmsUser->fresh()));
        $this->assertTrue($news->audienceEntities()->isEmpty());
    }

    public function test_sync_audience_leaves_other_items_targets_alone(): void
    {
        $first = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id]);
        $second = $this->makeNewsFor(AudienceType::Cities, [$this->alp->id]);

        $first->syncAudience(AudienceType::Employees, [$this->alpEmployee->id]);

        $this->assertSame([$this->alp->id], $this->targetIds($second));
    }

    /** @return list<int> */
    private function targetIds(News $news): array
    {
        return NewsAudienceTarget::query()->where('news_id', $news->id)->orderBy('target_id')
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
    }
}
