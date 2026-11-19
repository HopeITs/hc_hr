<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Entitlement;
use App\Models\JobLevel;
use App\Models\MailList;
use App\Models\Position;
use App\Models\PositionEntitlement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The Permissions Library catalog (meeting 19/11, track 5), from
 * database/data/entitlement-library.php: what each of the organisation's
 * positions needs outside the system, matched on the position's title,
 * department, program, center and city, plus job-level defaults for the
 * managers.
 *
 * Run by hand (php artisan db:seed --class=EntitlementLibrarySeeder), then
 * `php artisan entitlements:backfill` to give the defaults to people already
 * in post. Safe to run again: an item is found by its English name (so a
 * name HR translated or reworded in Arabic is kept), links are only added,
 * and an item HR stopped using is left stopped.
 */
class EntitlementLibrarySeeder extends Seeder
{
    /** @var array{items: int, links: int} */
    public array $created = ['items' => 0, 'links' => 0];

    public function run(): void
    {
        $catalog = require database_path('data/entitlement-library.php');

        $positions = Position::query()
            ->with(['department:id,code', 'program:id,name', 'center:id,name_en,city_id'])
            ->get(['id', 'title_en', 'department_id', 'program_id', 'center_id', 'city_id']);
        $cities = City::query()->get(['id', 'slug', 'name_en', 'name_ar']);
        $levels = JobLevel::query()->pluck('id', 'name_en');
        $lists = MailList::query()->pluck('id', 'address');

        foreach ($catalog['items'] as $spec) {
            $entitlement = $this->item($spec['en'], $spec['ar'], $spec['category'], $lists[$spec['list'] ?? ''] ?? null, $spec['description'] ?? null);

            $this->linkPositions($entitlement, $this->matching($positions, $cities, $spec));

            foreach ($spec['levels'] ?? [] as $level) {
                if (isset($levels[$level])) {
                    $this->link($entitlement, ['job_level_id' => $levels[$level]]);
                }
            }
        }

        // One staff group per city that has positions.
        $byCity = $positions->groupBy(fn (Position $p) => $this->cityOf($p));
        foreach ($cities as $city) {
            $inCity = $byCity->get($city->id);
            if ($inCity === null || $inCity->isEmpty()) {
                continue;
            }

            $entitlement = $this->item(
                str_replace(':city', trim($city->name_en), $catalog['city_groups']['en']),
                str_replace(':city', trim($city->name_ar), $catalog['city_groups']['ar']),
                'telegram',
            );
            $this->linkPositions($entitlement, $inCity);
        }
    }

    /**
     * The positions an item is a default of: any of its titles, departments,
     * programs or centers, narrowed by its city, only_titles and except_departments.
     *
     * @param  Collection<int, Position>  $positions
     * @param  Collection<int, City>  $cities
     * @return Collection<int, Position>
     */
    private function matching(Collection $positions, Collection $cities, array $spec): Collection
    {
        $titles = $spec['titles'] ?? [];
        $departments = $spec['departments'] ?? [];
        $programs = array_map('mb_strtolower', $spec['programs'] ?? []);
        $centers = $spec['centers'] ?? [];

        if ($titles === [] && $departments === [] && $programs === [] && $centers === []) {
            return collect();
        }

        $cityId = isset($spec['city']) ? $cities->firstWhere('slug', $spec['city'])?->id : null;
        if (isset($spec['city']) && $cityId === null) {
            return collect();
        }

        return $positions->filter(function (Position $p) use ($titles, $departments, $programs, $centers, $cityId, $spec): bool {
            $hit = $this->titleMatches($p, $titles)
                || in_array($p->department?->code, $departments, true)
                || ($p->program !== null && in_array(mb_strtolower(trim($p->program->name)), $programs, true))
                || collect($centers)->contains(fn (string $word) => $p->center !== null && stripos($p->center->name_en, $word) !== false);

            return $hit
                && ($cityId === null || $this->cityOf($p) === $cityId)
                && (! isset($spec['only_titles']) || $this->titleMatches($p, $spec['only_titles']))
                && ! in_array($p->department?->code, $spec['except_departments'] ?? [], true);
        });
    }

    /** @param array<int, string> $patterns */
    private function titleMatches(Position $position, array $patterns): bool
    {
        $title = trim((string) $position->title_en);

        foreach ($patterns as $pattern) {
            if (preg_match('/'.str_replace('/', '\/', $pattern).'/iu', $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The same rule as Position::scopeInCity: the position's own city, else its center's. */
    private function cityOf(Position $position): ?int
    {
        return $position->city_id ?? $position->center?->city_id;
    }

    /** @param Collection<int, Position> $positions */
    private function linkPositions(Entitlement $entitlement, Collection $positions): void
    {
        foreach ($positions as $position) {
            $this->link($entitlement, ['position_id' => $position->id]);
        }
    }

    private function link(Entitlement $entitlement, array $target): void
    {
        $link = PositionEntitlement::query()->firstOrCreate(['entitlement_id' => $entitlement->id, ...$target]);

        if ($link->wasRecentlyCreated) {
            $this->created['links']++;
        }
    }

    private function item(string $en, string $ar, string $category, ?int $mailListId = null, ?string $description = null): Entitlement
    {
        $item = Entitlement::query()->firstOrCreate(
            ['name_en' => $en],
            ['name_ar' => $ar, 'category' => $category, 'mail_list_id' => $mailListId, 'description' => $description, 'is_active' => true],
        );

        if ($item->wasRecentlyCreated) {
            $this->created['items']++;
        }

        return $item;
    }
}
