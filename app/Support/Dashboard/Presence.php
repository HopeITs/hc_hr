<?php

namespace App\Support\Dashboard;

use App\Models\Employee;
use App\Models\User;
use App\Services\TeamCoverage;
use App\Support\DirectoryAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Who is online, for the big clock (Dashboard\ClockPresence).
 *
 * Writing: every authenticated request stamps users.last_seen_at
 * (RecordPresence middleware), at most once a minute per account, through the
 * query builder (no model events, no activity log, no updated_at bump).
 *
 * Reading: only the people the viewer already sees on the dashboard's team
 * tiles (who is out, birthdays): TeamCoverage on the team calendar's default
 * scope for the active role. That is their own reports or colleagues for
 * staff and managers, their city for regional HR, everyone for HR-SYR and
 * leadership. No new rule: a person outside that set is never read, so the
 * card cannot reveal them, not even as a count.
 */
class Presence
{
    /** Seen this recently counts as online. */
    public const ONLINE_MINUTES = 5;

    /** The stamp is written at most this often per account. */
    public const WRITE_EVERY_SECONDS = 60;

    /** Stamps $user as seen now, unless it already was in the last minute. Returns whether it wrote. */
    public function touch(User $user, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());
        $threshold = $now->subSeconds(self::WRITE_EVERY_SECONDS);
        $seen = $user->getAttribute('last_seen_at');

        // The row the guard already loaded answers most requests without a query.
        if ($seen !== null && $this->parse($seen)?->greaterThan($threshold)) {
            return false;
        }

        // The same guard in the WHERE, so two requests racing each other write once.
        $written = $user->newQuery()->whereKey($user->getKey())->toBase()
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $threshold))
            ->update(['last_seen_at' => $now]) > 0;

        $user->setRawAttributes(array_merge($user->getAttributes(), ['last_seen_at' => $now->toDateTimeString()]), true);

        return $written;
    }

    /**
     * The people in $viewer's scope who are online now (the viewer left out),
     * by name, and how many are in that scope at all.
     *
     * @return array{people: Collection<int, array{id: int, name: string, avatar: string}>, total: int}
     */
    public function onlineFor(User $viewer, ?CarbonInterface $now = null): array
    {
        $coverage = app(TeamCoverage::class);
        $ids = array_values(array_diff(
            $coverage->memberIds($viewer, $coverage->defaultScope($viewer)),
            [(int) $viewer->employee_id],
        ));

        if ($ids === []) {
            return ['people' => collect(), 'total' => 0];
        }

        $since = CarbonImmutable::instance($now ?? now())->subMinutes(self::ONLINE_MINUTES);

        $online = User::query()->toBase()
            ->whereIn('employee_id', $ids)
            ->where('last_seen_at', '>=', $since)
            ->distinct()
            ->pluck('employee_id')
            ->all();

        $people = $online === [] ? collect() : Employee::query()
            ->select(DirectoryAccess::COLUMNS)
            ->whereIn('id', $online)
            ->where('is_active', true)
            ->with('media')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => (int) $e->id,
                'name' => (string) $e->name,
                'avatar' => $e->avatar_url,
            ])
            ->sortBy('name')
            ->values();

        return ['people' => $people, 'total' => count($ids)];
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        try {
            return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
