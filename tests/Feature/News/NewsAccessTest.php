<?php

namespace Tests\Feature\News;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Models\City;
use App\Models\News;
use App\Support\News\NewsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Who may manage and who may read an item (NewsAccess), and the details /
 * preview pages that enforce it: a reader gets a 404 for anything not
 * released to them, so the existence of a draft or of another audience's
 * item is never disclosed.
 */
class NewsAccessTest extends TestCase
{
    use CreatesContractFixtures, CreatesNews, RefreshDatabase;

    private City $dms;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

        $this->dms = $this->makeCity('DMS', '003');
        $this->alp = $this->makeCity('ALP', '001');

        // The permissions exist (as in production) but are granted to no
        // role here: Spatie throws for a permission name that does not exist.
        foreach (['view', 'create', 'update', 'delete'] as $ability) {
            Permission::findOrCreate('news.'.$ability, 'web');
        }
    }

    // ── NewsAccess ───────────────────────────────────────────────────────

    public function test_hr_super_admin_and_general_manager_manage_every_item(): void
    {
        $author = $this->makeUser($this->dms, 'manager');
        $news = $this->makeNews(['created_by' => $author->id, 'status' => NewsStatus::Draft]);

        foreach (['HR-SYR', 'HR-DMS', 'super-admin', 'general-manager'] as $role) {
            $user = $this->makeUser($this->dms, $role);

            $this->assertTrue(NewsAccess::canManageAll($user), "{$role} should manage all");
            $this->assertTrue(NewsAccess::canCreate($user), "{$role} should create");
            $this->assertTrue(NewsAccess::canManage($user, $news), "{$role} should manage another author's item");
            $this->assertTrue(NewsAccess::canView($user, $news), "{$role} should view a draft it manages");
        }
    }

    public function test_a_manager_creates_and_manages_only_their_own_items(): void
    {
        $manager = $this->makeUser($this->dms, 'manager');
        $otherManager = $this->makeUser($this->dms, 'manager');
        $own = $this->makeNews(['created_by' => $manager->id, 'status' => NewsStatus::Draft]);
        $others = $this->makeNews(['created_by' => $otherManager->id, 'status' => NewsStatus::Draft]);
        $orphan = $this->makeNews(['created_by' => null]);

        $this->assertFalse(NewsAccess::canManageAll($manager));
        $this->assertTrue(NewsAccess::canCreate($manager));
        $this->assertTrue(NewsAccess::canManage($manager, $own));
        $this->assertFalse(NewsAccess::canManage($manager, $others));
        $this->assertFalse(NewsAccess::canManage($manager, $orphan));
        // Their own draft is viewable (preview); another manager's is not.
        $this->assertTrue(NewsAccess::canView($manager, $own));
        $this->assertFalse(NewsAccess::canView($manager, $others));
    }

    public function test_an_employee_manages_nothing(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $news = $this->makeNews(['created_by' => $employee->id]);

        $this->assertFalse(NewsAccess::canManageAll($employee));
        $this->assertFalse(NewsAccess::canCreate($employee));
        // Even an item recorded as theirs.
        $this->assertFalse(NewsAccess::canManage($employee, $news));
    }

    public function test_nobody_signed_out_manages_or_views_anything(): void
    {
        $news = $this->makeNews();

        $this->assertFalse(NewsAccess::canManageAll(null));
        $this->assertFalse(NewsAccess::canCreate(null));
        $this->assertFalse(NewsAccess::canManage(null, $news));
        $this->assertFalse(NewsAccess::canView(null, $news));
    }

    public function test_the_active_role_decides_not_an_inactive_one(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $employee = $this->assignInactiveRole($employee, 'HR-DMS');

        $this->assertFalse(NewsAccess::canManageAll($employee));
        $this->assertFalse(NewsAccess::canCreate($employee));
    }

    public function test_an_employee_views_only_released_items_in_their_audience(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');

        $forAll = $this->makeNews();
        $forMyCity = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id]);
        $forOtherCity = $this->makeNewsFor(AudienceType::Cities, [$this->alp->id]);
        $draft = $this->makeNews(['status' => NewsStatus::Draft]);
        $scheduled = $this->makeNews(['published_at' => now()->addDay()]);
        $archived = $this->makeNews(['status' => NewsStatus::Archived]);
        $expired = $this->makeNews(['published_at' => now()->subDays(5), 'display_until' => now()->subDay()]);

        $this->assertTrue(NewsAccess::canView($employee, $forAll));
        $this->assertTrue(NewsAccess::canView($employee, $forMyCity));
        $this->assertFalse(NewsAccess::canView($employee, $forOtherCity));
        $this->assertFalse(NewsAccess::canView($employee, $draft));
        $this->assertFalse(NewsAccess::canView($employee, $scheduled));
        $this->assertFalse(NewsAccess::canView($employee, $archived));
        // Expired items leave the feeds but stay readable (past events, archive).
        $this->assertTrue(NewsAccess::canView($employee, $expired));
    }

    public function test_a_draft_for_everyone_is_not_viewable_by_an_employee(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $draft = $this->makeNews(['status' => NewsStatus::Draft, 'published_at' => now()->subDay()]);

        $this->assertSame(AudienceType::All, $draft->audience_type);
        $this->assertFalse(NewsAccess::canView($employee, $draft));
    }

    // ── HTTP: details page and preview ───────────────────────────────────

    public function test_an_employee_in_the_audience_opens_the_details_page(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id], ['title_en' => 'Damascus open day']);

        $this->actingAs($employee)->get(route('news.show', $news))->assertOk();
    }

    public function test_an_item_for_everyone_opens_for_any_employee(): void
    {
        $employee = $this->makeUser($this->alp, 'employee');
        $news = $this->makeNews();

        $this->actingAs($employee)->get(route('news.show', $news))->assertOk();
    }

    public function test_an_employee_outside_the_audience_gets_a_404(): void
    {
        $employee = $this->makeUser($this->alp, 'employee');
        $news = $this->makeNewsFor(AudienceType::Cities, [$this->dms->id]);

        $this->actingAs($employee)->get(route('news.show', $news))->assertNotFound();
    }

    public function test_a_draft_gets_a_404_on_the_details_page(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $draft = $this->makeNews(['status' => NewsStatus::Draft]);

        $this->actingAs($employee)->get(route('news.show', $draft))->assertNotFound();
    }

    public function test_a_scheduled_item_gets_a_404_on_the_details_page(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $scheduled = $this->makeNews(['published_at' => now()->addDay()]);

        $this->actingAs($employee)->get(route('news.show', $scheduled))->assertNotFound();
    }

    public function test_the_details_page_needs_a_signed_in_user(): void
    {
        $news = $this->makeNews();

        $this->get(route('news.show', $news))->assertRedirect(route('login'));
    }

    public function test_hr_previews_a_draft(): void
    {
        $hr = $this->makeUser($this->dms, 'HR-SYR');
        $draft = $this->makeNews(['status' => NewsStatus::Draft, 'title_en' => 'Draft headline']);

        $this->actingAs($hr)->get(route('news.preview', $draft))->assertOk();
    }

    public function test_a_plain_employee_cannot_open_the_preview(): void
    {
        $employee = $this->makeUser($this->dms, 'employee');
        $news = $this->makeNews();

        $this->actingAs($employee)->get(route('news.preview', $news))->assertForbidden();
    }
}
