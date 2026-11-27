<?php

namespace Tests\Feature\News;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Livewire\News\NewsCreate;
use App\Livewire\News\NewsShow;
use App\Livewire\News\NewsTable;
use App\Models\City;
use App\Models\Employee;
use App\Models\News;
use App\Models\User;
use App\Notifications\NewsApprovalDecided;
use App\Notifications\NewsApprovalRequested;
use App\Notifications\NewsPublishedByManager;
use App\Support\News\NewsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * HR-040: a manager publishes to their own team (direct reports, as
 * Contract::scopeVisibleTo); anything wider waits for the HR of the author's
 * city, who approves (publishes) or rejects with a reason. HR publishes directly.
 */
class NewsApprovalTest extends TestCase
{
    use CreatesContractFixtures, CreatesNews, RefreshDatabase;

    private const PERMS = ['news.view', 'news.create', 'news.update', 'news.delete'];

    private City $dms;

    private City $alp;

    private User $manager;

    private Employee $report;

    private User $reportUser;

    private User $outsiderUser;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

        foreach (self::PERMS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->dms = $this->makeCity('DMS', '003');
        $this->alp = $this->makeCity('ALP', '001');

        // The manager holds a position; the report's position reports to it.
        $managerEmployee = $this->makeEmployee($this->dms, 'the-manager');
        $managerContract = $this->makeContractFor($managerEmployee);
        $this->manager = $this->makeUser($this->dms, 'manager', self::PERMS, $managerEmployee);

        $this->report = $this->makeEmployee($this->dms, 'the-report');
        $reportPosition = $this->makePosition($this->dms, ['direct_manager_id' => $managerContract->position_id]);
        $this->makeContractFor($this->report, ['position_id' => $reportPosition->id]);
        $this->reportUser = $this->makeUser($this->dms, 'employee', [], $this->report);

        // Same city, but not in the manager's team.
        $outsider = $this->makeEmployee($this->dms, 'the-outsider');
        $this->makeContractFor($outsider);
        $this->outsiderUser = $this->makeUser($this->dms, 'employee', [], $outsider);

        $this->hr = $this->makeUser($this->dms, 'HR-DMS', self::PERMS);
    }

    // ── The team and the default audience ────────────────────────────────

    public function test_the_managers_team_is_their_direct_reports_and_themselves(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->report->id, $this->manager->employee_id],
            NewsAccess::teamEmployeeIds($this->manager),
        );
        $this->assertSame([], NewsAccess::teamEmployeeIds($this->hr));
    }

    public function test_a_managers_default_audience_is_their_team(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(NewsCreate::class)
            ->assertSet('form.audience_type', AudienceType::Employees->value);

        $this->assertEqualsCanonicalizing(
            [(string) $this->report->id, (string) $this->manager->employee_id],
            $component->get('form.audience_ids'),
        );
    }

    public function test_hr_keeps_the_whole_organisation_as_default(): void
    {
        $this->actingAs($this->hr);

        Livewire::test(NewsCreate::class)
            ->assertSet('form.audience_type', AudienceType::All->value);
    }

    // ── Publishing ───────────────────────────────────────────────────────

    public function test_a_team_post_publishes_and_tells_the_city_hr(): void
    {
        Notification::fake();
        $this->actingAs($this->manager);

        $this->fillContent(Livewire::test(NewsCreate::class))
            ->call('publish')
            ->assertHasNoErrors();

        $news = News::query()->sole();
        $this->assertSame(NewsStatus::Published, $news->status);
        $this->assertTrue(NewsAccess::canView($this->reportUser, $news));
        $this->assertFalse(NewsAccess::canView($this->outsiderUser, $news));

        Notification::assertSentTo($this->hr, NewsPublishedByManager::class);
        Notification::assertNotSentTo($this->hr, NewsApprovalRequested::class);
    }

    public function test_a_wide_post_waits_for_hr_and_employees_cannot_see_it(): void
    {
        Notification::fake();
        $this->actingAs($this->manager);

        $this->fillContent(Livewire::test(NewsCreate::class))
            ->set('form.audience_type', AudienceType::All->value)
            ->set('form.audience_ids', [])
            ->assertSee(__('news.form.needs_approval_hint'))
            ->call('publish')
            ->assertHasNoErrors();

        $news = News::query()->sole();
        $this->assertSame(NewsStatus::Pending, $news->status);
        $this->assertSame(NewsState::Pending, $news->state());
        $this->assertFalse(NewsAccess::canView($this->reportUser, $news));
        $this->assertFalse(NewsAccess::canView($this->outsiderUser, $news));
        $this->assertFalse(News::query()->live()->whereKey($news->id)->exists());

        Notification::assertSentTo($this->hr, NewsApprovalRequested::class);
        Notification::assertNotSentTo($this->hr, NewsPublishedByManager::class);
    }

    public function test_a_forged_list_with_someone_outside_the_team_does_not_publish(): void
    {
        Notification::fake();
        $this->actingAs($this->manager);

        $this->fillContent(Livewire::test(NewsCreate::class))
            ->set('form.audience_type', AudienceType::Employees->value)
            ->set('form.audience_ids', [(string) $this->report->id, (string) $this->outsiderUser->employee_id])
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(NewsStatus::Pending, News::query()->sole()->status);
    }

    public function test_a_forged_wide_audience_never_publishes_through_any_path(): void
    {
        Notification::fake();
        $this->actingAs($this->manager);

        // Other audience types than "employees" always wait, whatever ids are sent.
        $this->fillContent(Livewire::test(NewsCreate::class))
            ->set('form.audience_type', AudienceType::Cities->value)
            ->set('form.audience_ids', [(string) $this->dms->id])
            ->call('publish');

        $this->assertSame(NewsStatus::Pending, News::query()->sole()->status);

        // "Publish now" on a wide draft from the list.
        $draft = $this->makeNews([
            'status' => NewsStatus::Draft,
            'published_at' => null,
            'created_by' => $this->manager->id,
            'audience_type' => AudienceType::All,
        ]);

        Livewire::test(NewsTable::class)->call('publishNow', ['id' => $draft->id]);

        $this->assertSame(NewsStatus::Pending, $draft->fresh()->status);
        $this->assertFalse(NewsAccess::canView($this->reportUser, $draft->fresh()));

        // A manager cannot approve their own (or anyone's) item.
        $this->assertFalse(NewsAccess::canApprove($this->manager, $draft->fresh()));
        Livewire::test(NewsShow::class, ['news' => $draft->fresh()])
            ->call('approve')
            ->assertForbidden();
        $this->assertSame(NewsStatus::Pending, $draft->fresh()->status);
    }

    public function test_hr_still_publishes_to_everyone_directly(): void
    {
        Notification::fake();
        $this->actingAs($this->hr);

        $this->fillContent(Livewire::test(NewsCreate::class))
            ->set('form.audience_type', AudienceType::All->value)
            ->assertDontSee(__('news.form.needs_approval_hint'))
            ->call('publish')
            ->assertHasNoErrors();

        $news = News::query()->sole();
        $this->assertSame(NewsStatus::Published, $news->status);
        $this->assertTrue(NewsAccess::canView($this->outsiderUser, $news));
        Notification::assertNothingSent();
    }

    // ── HR's review ──────────────────────────────────────────────────────

    public function test_hr_approves_and_the_post_becomes_visible_and_the_manager_is_told(): void
    {
        Notification::fake();
        $news = $this->pendingFromManager(['published_at' => null]);
        $this->actingAs($this->hr);

        Livewire::test(NewsShow::class, ['news' => $news])
            ->assertSee(__('news.review.title'))
            ->call('approve')
            ->assertHasNoErrors();

        $news->refresh();
        $this->assertSame(NewsStatus::Published, $news->status);
        $this->assertSame($this->hr->id, (int) $news->reviewed_by);
        $this->assertNotNull($news->reviewed_at);
        $this->assertTrue(NewsAccess::canView($this->outsiderUser, $news));

        Notification::assertSentTo($this->manager, NewsApprovalDecided::class,
            fn (NewsApprovalDecided $n) => $n->approved === true);
    }

    public function test_approving_keeps_a_scheduled_time_in_the_future(): void
    {
        $at = now()->addDays(2)->startOfMinute();
        $news = $this->pendingFromManager(['published_at' => $at]);
        $this->actingAs($this->hr);

        Livewire::test(NewsShow::class, ['news' => $news])->call('approve');

        $news->refresh();
        $this->assertSame(NewsState::Scheduled, $news->state());
        $this->assertTrue($news->published_at->equalTo($at));
    }

    public function test_hr_can_approve_from_the_list(): void
    {
        Notification::fake();
        $news = $this->pendingFromManager();
        $this->actingAs($this->hr);

        Livewire::test(NewsTable::class)->call('approve', ['id' => $news->id]);

        $this->assertSame(NewsStatus::Published, $news->fresh()->status);
    }

    public function test_hr_rejects_with_a_reason_and_the_manager_is_told_why(): void
    {
        Notification::fake();
        $news = $this->pendingFromManager();
        $this->actingAs($this->hr);

        $component = Livewire::test(NewsShow::class, ['news' => $news])
            ->call('reject')
            ->assertHasErrors(['reviewNote' => 'required']);

        $this->assertSame(NewsStatus::Pending, $news->fresh()->status);

        $component->set('reviewNote', 'Please limit it to your team.')
            ->call('reject')
            ->assertHasNoErrors();

        $news->refresh();
        $this->assertSame(NewsStatus::Rejected, $news->status);
        $this->assertSame(NewsState::Rejected, $news->state());
        $this->assertSame('Please limit it to your team.', $news->review_note);
        $this->assertFalse(NewsAccess::canView($this->outsiderUser, $news));

        Notification::assertSentTo($this->manager, NewsApprovalDecided::class,
            function (NewsApprovalDecided $n) {
                $data = $n->toArray($this->manager);

                return $n->approved === false
                    && $data['comment'] === 'Please limit it to your team.'
                    && str_contains($data['body']['en'], 'Please limit it to your team.');
            });
    }

    public function test_only_the_authors_city_hr_or_head_office_approve(): void
    {
        $news = $this->pendingFromManager();

        $this->assertTrue(NewsAccess::canApprove($this->hr, $news));
        $this->assertTrue(NewsAccess::canApprove($this->makeUser($this->dms, 'HR-SYR', self::PERMS), $news));
        $this->assertTrue(NewsAccess::canApprove($this->makeUser($this->dms, 'super-admin'), $news));

        $otherCityHr = $this->makeUser($this->alp, 'HR-ALP', self::PERMS);
        $this->assertFalse(NewsAccess::canApprove($otherCityHr, $news));
        $this->assertFalse(NewsAccess::canApprove($this->reportUser, $news));

        // Nothing to approve once it is no longer pending.
        $this->assertFalse(NewsAccess::canApprove($this->hr, $this->makeNews(['created_by' => $this->manager->id])));
    }

    public function test_the_pending_and_rejected_states_have_their_own_tone_icon_and_label(): void
    {
        $pairs = array_map(fn (NewsState $s) => $s->tone()->value.'|'.$s->icon(), NewsState::cases());
        $this->assertSame(count($pairs), count(array_unique($pairs)));

        foreach (['en', 'ar'] as $locale) {
            foreach ([NewsState::Pending, NewsState::Rejected] as $state) {
                $key = 'news.states.'.$state->value;
                $this->assertNotSame($key, __($key, [], $locale));
            }
            $this->assertNotSame('news.list.tabs.pending', __('news.list.tabs.pending', [], $locale));
        }
    }

    private function fillContent($component)
    {
        return $component
            ->set('form.title_ar', 'خبر الفريق')
            ->set('form.title_en', 'Team news')
            ->set('form.description_ar', 'نص');
    }

    private function pendingFromManager(array $attributes = []): News
    {
        return $this->makeNews(array_merge([
            'status' => NewsStatus::Pending,
            'created_by' => $this->manager->id,
            'audience_type' => AudienceType::All,
        ], $attributes));
    }
}
