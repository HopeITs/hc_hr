<?php

namespace Tests\Feature\News;

use App\Livewire\News\NewsCreate;
use App\Livewire\News\NewsShow;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\Concerns\CreatesNews;
use Tests\TestCase;

/**
 * Optional free tags replace "Related To": zero or more short labels saying
 * what an item is about. They never decide who sees it (NewsAudienceTest).
 * 2026_11_04_000002_create_news_tags_table turns every old Related To into a
 * tag on the run that creates the table.
 */
class NewsTagsTest extends TestCase
{
    use CreatesHrFixtures, CreatesNews, RefreshDatabase;

    private City $city;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));

        $this->city = $this->makeCity('ALP', '001');
        $this->hr = $this->makeUser($this->city, 'HR-ALP', ['news.view', 'news.create', 'news.update']);
    }

    // ── normalisation ─────────────────────────────────────────────────────

    public function test_sync_tags_normalises_what_it_stores(): void
    {
        $news = $this->makeNews();

        $stored = $news->syncTags([
            '  Study   Zone ',
            'study zone',         // a case-insensitive duplicate: the first spelling wins
            '',
            '   ',
            ['not', 'a', 'tag'],  // not a scalar
            'حلب',
            str_repeat('x', 75),  // cut to 60 characters
        ]);

        $this->assertSame(['Study Zone', 'حلب', str_repeat('x', 60)], $stored);
        $this->assertSame($stored, $news->fresh()->tagNames());
    }

    public function test_sync_tags_keeps_at_most_ten_and_replaces_the_previous_ones(): void
    {
        $news = $this->makeNews();
        $news->syncTags(['Old']);

        $stored = $news->syncTags(array_map(fn (int $i) => 'Tag '.$i, range(1, 12)));

        $this->assertCount(10, $stored);
        $this->assertSame('Tag 10', end($stored));
        $this->assertNotContains('Old', $news->fresh()->tagNames());

        $this->assertSame([], $news->syncTags([]));
        $this->assertSame(0, DB::table('news_tags')->where('news_id', $news->id)->count());
    }

    public function test_tagged_matches_case_insensitively(): void
    {
        $training = $this->makeNews();
        $training->syncTags(['Training', 'Aleppo']);
        $this->makeNews()->syncTags(['Study Zone']);

        $this->assertSame([$training->id], News::query()->tagged('  training ')->pluck('id')->all());
        $this->assertSame([], News::query()->tagged('Damascus')->pluck('id')->all());
    }

    public function test_tags_are_deleted_with_their_item(): void
    {
        $news = $this->makeNews();
        $news->syncTags(['Aleppo']);

        $news->delete();

        $this->assertSame(0, DB::table('news_tags')->count());
    }

    // ── the form ──────────────────────────────────────────────────────────

    public function test_the_form_stores_tags_and_reloads_them_for_editing(): void
    {
        $this->actingAs($this->hr);

        Livewire::test(NewsCreate::class)
            ->set('form.title_ar', 'خبر')
            ->set('form.description_ar', 'نص')
            ->set('form.tags', [' Aleppo ', 'aleppo', 'Study  Zone'])
            ->call('publish')
            ->assertHasNoErrors();

        $news = News::query()->sole();
        $this->assertSame(['Aleppo', 'Study Zone'], $news->tagNames());
        $this->assertNull($news->newsable_type);
        $this->assertNull($news->newsable_id);

        Livewire::test(NewsCreate::class, ['news' => $news])
            ->assertSet('form.tags', ['Aleppo', 'Study Zone'])
            ->set('form.tags', ['Training'])
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(['Training'], $news->fresh()->tagNames());
    }

    public function test_tags_are_optional_but_limited(): void
    {
        $this->actingAs($this->hr);

        Livewire::test(NewsCreate::class)
            ->set('form.title_ar', 'خبر')
            ->set('form.description_ar', 'نص')
            ->set('form.tags', array_map(fn (int $i) => 'Tag '.$i, range(1, 11)))
            ->call('publish')
            ->assertHasErrors(['form.tags'])
            ->set('form.tags', [str_repeat('x', 61)])
            ->call('publish')
            ->assertHasErrors(['form.tags.0'])
            ->set('form.tags', [])
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame([], News::query()->sole()->tagNames());
    }

    public function test_editing_a_legacy_item_keeps_its_related_to_columns(): void
    {
        $this->actingAs($this->hr);
        $news = $this->makeNews(['newsable_type' => City::class, 'newsable_id' => $this->city->id, 'description_ar' => 'نص']);

        Livewire::test(NewsCreate::class, ['news' => $news])
            ->set('form.tags', ['ALP'])
            ->call('publish')
            ->assertHasNoErrors();

        $news->refresh();
        $this->assertSame(City::class, $news->newsable_type);
        $this->assertSame($this->city->id, (int) $news->newsable_id);
        $this->assertSame(['ALP'], $news->tagNames());
    }

    public function test_suggestions_list_used_tags_first_then_active_places(): void
    {
        $closed = $this->makeCity('HMS', '002');
        $closed->forceFill(['is_active' => false])->save();

        $this->makeNews()->syncTags(['Training', 'Aleppo']);
        $this->makeNews()->syncTags(['Training']);

        $suggestions = \App\Livewire\Forms\NewsForm::tagSuggestions();

        $this->assertSame('Training', $suggestions[0]);
        $this->assertContains('Aleppo', $suggestions);
        $this->assertContains('ALP', $suggestions);
        $this->assertNotContains('HMS', $suggestions);
        $this->assertSame(count($suggestions), count(array_unique(array_map('mb_strtolower', $suggestions))));
    }

    // ── display ───────────────────────────────────────────────────────────

    public function test_the_details_page_shows_tags_and_prefers_items_sharing_one(): void
    {
        $this->actingAs($this->hr);

        $news = $this->makeNews(['title_en' => 'Main']);
        $news->syncTags(['Training']);

        $this->makeNews(['title_en' => 'Newer, unrelated', 'published_at' => now()->subMinutes(5)]);
        $sharing = $this->makeNews(['title_en' => 'Older, same tag', 'published_at' => now()->subDays(3)]);
        $sharing->syncTags(['training']);

        $page = Livewire::test(NewsShow::class, ['news' => $news])
            ->assertSee('Training');

        $this->assertSame($sharing->id, $page->viewData('related')->first()->id);
    }

    public function test_an_item_without_a_cover_has_no_image_area(): void
    {
        $this->actingAs($this->hr);
        $news = $this->makeNews();

        Livewire::test(NewsShow::class, ['news' => $news])
            ->assertDontSeeHtml('<img')
            ->assertDontSeeHtml('aspect-[21/9]');

        $title = view('livewire.news.list._title', ['news' => $news])->render();
        $this->assertStringNotContainsString('<img', $title);
        $this->assertStringNotContainsString('size-12', $title);
    }

    // ── migration backfill ────────────────────────────────────────────────

    public function test_the_backfill_turns_each_related_to_into_one_tag(): void
    {
        $center = Center::query()->create([
            'name_en' => 'Study Zone Center', 'name_ar' => 'مركز ستادي زون', 'city_id' => $this->city->id, 'is_active' => true,
            'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
        $program = Program::query()->create(['name' => 'Study Zone', 'code' => 'SZ', 'slug' => 'study-zone', 'is_active' => true]);
        $department = Department::query()->create(['code' => 'TRN', 'name' => 'Training']);
        $employee = $this->makeEmployee($this->city, 'subject', [
            'first_name_ar' => 'رنا', 'last_name_ar' => 'حداد', 'first_name_en' => 'Rana', 'last_name_en' => 'Haddad',
        ]);
        $englishOnly = $this->makeEmployee($this->city, 'english-only', [
            'first_name_ar' => '', 'last_name_ar' => '', 'first_name_en' => 'Sam', 'last_name_en' => 'Lee',
        ]);
        DB::table('cities')->where('id', $this->city->id)->update(['name_ar' => 'حلب', 'name_en' => 'Aleppo']);

        $rows = [
            'city' => $this->legacyRow(City::class, $this->city->id),
            'center' => $this->legacyRow(Center::class, $center->id),
            'program' => $this->legacyRow(Program::class, $program->id),
            'department' => $this->legacyRow(Department::class, $department->id),
            'employee' => $this->legacyRow(Employee::class, $employee->id),
            'english' => $this->legacyRow(Employee::class, $englishOnly->id),
            'gone' => $this->legacyRow(City::class, 999999),
            'none' => $this->legacyRow(null, null),
        ];

        $this->backfill();

        $this->assertSame(['حلب'], $this->tagsOf($rows['city']));
        $this->assertSame(['مركز ستادي زون'], $this->tagsOf($rows['center']));
        $this->assertSame(['Study Zone'], $this->tagsOf($rows['program']));
        $this->assertSame(['Training'], $this->tagsOf($rows['department']));
        $this->assertSame(['رنا حداد'], $this->tagsOf($rows['employee']));
        $this->assertSame(['Sam Lee'], $this->tagsOf($rows['english']));
        $this->assertSame([], $this->tagsOf($rows['gone']));
        $this->assertSame([], $this->tagsOf($rows['none']));

        // newsable_* is kept as it was.
        $this->assertSame(City::class, DB::table('news')->where('id', $rows['city'])->value('newsable_type'));

        // A second run adds nothing.
        $this->backfill();
        $this->assertSame(['حلب'], $this->tagsOf($rows['city']));
    }

    public function test_the_backfill_only_runs_on_the_run_that_creates_the_table(): void
    {
        $id = $this->legacyRow(City::class, $this->city->id);

        // RefreshDatabase already created news_tags: running up() again is a no-op.
        $migration = require database_path('migrations/2026_11_04_000002_create_news_tags_table.php');
        $migration->up();

        $this->assertSame([], $this->tagsOf($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function legacyRow(?string $type, ?int $id): int
    {
        return (int) DB::table('news')->insertGetId([
            'type' => 'news',
            'status' => 'published',
            'audience_type' => 'all',
            'title_ar' => 'خبر قديم',
            'title_en' => 'Legacy item',
            'description_ar' => '',
            'description_en' => '',
            'newsable_type' => $type,
            'newsable_id' => $id,
            'published_at' => '2026-03-01 09:00:00',
            'created_at' => '2026-02-28 12:00:00',
            'updated_at' => '2026-02-28 12:00:00',
        ]);
    }

    /** Run the migration's private backfill() again. */
    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_11_04_000002_create_news_tags_table.php');

        (fn () => $this->backfill())->call($migration);
    }

    /** @return list<string> */
    private function tagsOf(int $newsId): array
    {
        return DB::table('news_tags')->where('news_id', $newsId)->orderBy('id')->pluck('tag')->all();
    }
}
