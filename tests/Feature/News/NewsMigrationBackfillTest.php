<?php

namespace Tests\Feature\News;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Models\Center;
use App\Models\City;
use App\Models\Employee;
use App\Models\News;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * 2026_11_04_000001_redesign_news_as_content: existing rows keep exactly the
 * visibility they had (their newsable target becomes their audience and
 * stays their Related To), get a type from their old event_date, and a
 * Publish At.
 *
 * RefreshDatabase has already run the migration on an empty table, so the
 * legacy rows are inserted here in the old shape and the migration's own
 * backfill() is run again over them.
 */
class NewsMigrationBackfillTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    }

    public function test_a_row_targeted_at_a_city_gets_that_city_as_audience_and_keeps_it_as_related_to(): void
    {
        $alp = $this->makeCity('ALP', '001');
        $id = $this->legacyRow([
            'newsable_type' => City::class,
            'newsable_id' => $alp->id,
            'event_date' => '2026-03-01',
            'published_at' => '2026-03-01 09:00:00',
        ]);

        $this->backfill();
        $news = News::query()->findOrFail($id);

        $this->assertSame(AudienceType::Cities, $news->audience_type);
        $this->assertSame([$alp->id], $this->targetIds($id));
        $this->assertSame(City::class, $news->newsable_type);
        $this->assertSame($alp->id, (int) $news->newsable_id);
        $this->assertSame(NewsStatus::Published, $news->status);
    }

    public function test_every_old_newsable_kind_maps_to_its_audience(): void
    {
        $city = $this->makeCity('DMS', '003');
        $employee = $this->makeEmployee($city, 'legacy-target');
        $center = Center::query()->create([
            'name_en' => 'Legacy Center', 'name_ar' => 'مركز', 'city_id' => $city->id, 'is_active' => true,
            'weekends' => 'friday', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);

        $cases = [
            [Employee::class, $employee->id, AudienceType::Employees],
            [Center::class, $center->id, AudienceType::Centers],
            ['App\\Models\\Program', 7, AudienceType::Programs],
            ['App\\Models\\Department', 9, AudienceType::Departments],
        ];

        $ids = [];

        foreach ($cases as [$type, $targetId]) {
            $ids[] = $this->legacyRow(['newsable_type' => $type, 'newsable_id' => $targetId]);
        }

        $this->backfill();

        foreach ($cases as $i => [$type, $targetId, $audience]) {
            $news = News::query()->findOrFail($ids[$i]);

            $this->assertSame($audience, $news->audience_type, "{$type} should map to {$audience->value}");
            $this->assertSame([$targetId], $this->targetIds($ids[$i]));
            $this->assertSame($type, $news->newsable_type);
        }
    }

    public function test_a_row_without_a_newsable_is_for_everyone(): void
    {
        $id = $this->legacyRow(['newsable_type' => null, 'newsable_id' => null]);

        $this->backfill();

        $this->assertSame(AudienceType::All, News::query()->findOrFail($id)->audience_type);
        $this->assertSame([], $this->targetIds($id));
    }

    public function test_an_event_date_after_publication_makes_an_event(): void
    {
        $id = $this->legacyRow(['event_date' => '2026-03-10', 'published_at' => '2026-03-01 09:00:00']);

        $this->backfill();
        $news = News::query()->findOrFail($id);

        $this->assertSame(NewsType::Event, $news->type);
        $this->assertSame('2026-03-10 00:00:00', $news->event_starts_at?->toDateTimeString());
        $this->assertNull($news->event_ends_at);
        $this->assertSame('2026-03-10', $news->event_date?->toDateString());
    }

    public function test_an_event_date_on_the_publication_day_makes_a_news_item(): void
    {
        $id = $this->legacyRow(['event_date' => '2026-03-01', 'published_at' => '2026-03-01 09:00:00']);

        $this->backfill();
        $news = News::query()->findOrFail($id);

        $this->assertSame(NewsType::News, $news->type);
        $this->assertNull($news->event_starts_at);
        // event_date is not dropped, only no longer read.
        $this->assertSame('2026-03-01', $news->event_date?->toDateString());
    }

    public function test_an_event_date_before_publication_makes_a_news_item(): void
    {
        $id = $this->legacyRow(['event_date' => '2026-02-20', 'published_at' => '2026-03-01 09:00:00']);

        $this->backfill();
        $news = News::query()->findOrFail($id);

        $this->assertSame(NewsType::News, $news->type);
        $this->assertNull($news->event_starts_at);
    }

    public function test_a_missing_publish_at_falls_back_to_created_at(): void
    {
        $id = $this->legacyRow([
            'published_at' => null,
            'created_at' => '2026-01-15 08:30:00',
            'event_date' => '2026-01-20',
        ]);

        $this->backfill();
        $news = News::query()->findOrFail($id);

        $this->assertSame('2026-01-15 08:30:00', $news->published_at?->toDateTimeString());
        // The event is compared against that fallback date.
        $this->assertSame(NewsType::Event, $news->type);
    }

    public function test_a_backfilled_row_is_released_and_keeps_its_old_visibility(): void
    {
        $alp = $this->makeCity('ALP', '001');
        $dms = $this->makeCity('DMS', '003');
        $id = $this->legacyRow(['newsable_type' => City::class, 'newsable_id' => $alp->id, 'published_at' => '2026-10-01 09:00:00']);

        $this->backfill();

        $this->assertTrue(News::query()->whereKey($id)->released()->exists());
        $this->assertTrue(News::query()->findOrFail($id)->isVisibleTo($this->makeEmployee($alp, 'aleppine')));
        $this->assertFalse(News::query()->findOrFail($id)->isVisibleTo($this->makeEmployee($dms, 'damascene')));
    }

    public function test_running_the_backfill_twice_does_not_duplicate_targets(): void
    {
        $alp = $this->makeCity('ALP', '001');
        $id = $this->legacyRow(['newsable_type' => City::class, 'newsable_id' => $alp->id]);

        $this->backfill();
        $this->backfill();

        $this->assertSame([$alp->id], $this->targetIds($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Insert a row the way the pre-redesign app stored it; returns its id. */
    private function legacyRow(array $attributes = []): int
    {
        return (int) DB::table('news')->insertGetId(array_merge([
            'title_ar' => 'خبر قديم',
            'title_en' => 'Legacy item',
            'description_ar' => '',
            'description_en' => '',
            'newsable_type' => null,
            'newsable_id' => null,
            'event_date' => '2026-03-01',
            'published_at' => '2026-03-01 09:00:00',
            'created_at' => '2026-02-28 12:00:00',
            'updated_at' => '2026-02-28 12:00:00',
        ], $attributes));
    }

    /** Run the migration's private backfill() again. */
    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_11_04_000001_redesign_news_as_content.php');

        (fn () => $this->backfill())->call($migration);
    }

    /** @return list<int> */
    private function targetIds(int $newsId): array
    {
        return DB::table('news_audience_targets')->where('news_id', $newsId)->orderBy('target_id')
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
    }
}
