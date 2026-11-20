<?php

namespace App\Services\CriticalPositions;

use App\Models\City;
use App\Models\Contract;
use App\Models\Position;
use App\Models\User;
use App\Notifications\CriticalPositionNeedsReplacement;
use App\Services\NotificationDeliveryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Critical positions that need a replacement (meeting 19/11, track 6), and the
 * one daily job that tells HR of the position's city and its direct manager.
 *
 * A critical, active position needs a replacement when:
 *  - vacant: nobody holds it and nobody ever did;
 *  - contract_ended: nobody holds it now and the last holder's contract has ended;
 *  - poor_evaluation: switched off (config/critical-positions.php) because
 *    evaluations carry no rating to compare.
 *
 * Each reason is told once per position and episode: the ledger day is the end
 * date of the last contract (or the position's creation date when vacant), so
 * a position that is filled and later empties again is told again.
 */
class CriticalPositionAlerts
{
    public const LEDGER_PREFIX = 'critical-position:';

    public const VACANT = 'vacant';

    public const CONTRACT_ENDED = 'contract_ended';

    /** @return list<string> */
    public function reasonsFor(Position $position): array
    {
        if (! $position->is_critical || ! $position->is_active) {
            return [];
        }

        if (Contract::query()->activeNow()->where('position_id', $position->getKey())->exists()) {
            return [];
        }

        return [$this->lastEnd($position) === null ? self::VACANT : self::CONTRACT_ENDED];
    }

    public function needsReplacement(Position $position): bool
    {
        return $this->reasonsFor($position) !== [];
    }

    /** @return Collection<int, Position> critical positions needing a replacement, optionally inside a city scope */
    public function positionsNeedingReplacement(?array $cityIds = null): Collection
    {
        return Position::query()
            ->where('is_critical', true)
            ->where('is_active', true)
            ->when($cityIds !== null, fn (Builder $q) => $q->inCity($cityIds))
            ->whereDoesntHave('contracts', fn (Builder $q) => $q->activeNow())
            ->with(['city', 'center.city', 'jobLevel'])
            ->orderBy('id')
            ->get();
    }

    /** Send the alerts that were not sent yet. Returns how many notifications went out. */
    public function run(NotificationDeliveryLedger $ledger): int
    {
        $sent = 0;

        foreach ($this->positionsNeedingReplacement() as $position) {
            foreach ($this->reasonsFor($position) as $reason) {
                $day = $this->lastEnd($position) ?? CarbonImmutable::parse($position->created_at ?? now());

                foreach ($this->recipients($position) as $user) {
                    $kind = self::LEDGER_PREFIX.$reason;

                    if (! $ledger->claim($kind, $user->id, [$position->id], $day)) {
                        continue;
                    }

                    try {
                        $user->notify(new CriticalPositionNeedsReplacement($position, $reason));
                        $ledger->markDelivered($kind, $user->id, [$position->id], $day);
                        $sent++;
                    } catch (Throwable $e) {
                        $ledger->release($kind, $user->id, [$position->id], $day);
                        report($e);
                    }
                }
            }
        }

        return $sent;
    }

    /**
     * HR roles of the position's city (HR-SYR when the city has none) and the
     * people holding the position it reports to.
     *
     * @return Collection<int, User>
     */
    public function recipients(Position $position): Collection
    {
        $position->loadMissing(['city', 'center.city']);
        $slug = ($position->city ?? $position->center?->city)?->slug;

        $hr = $this->withRoles($slug ? City::hrRolesForSlug($slug) : []);

        if ($hr->isEmpty()) {
            $hr = $this->withRoles(['HR-'.City::NATIONAL_SLUG]);
        }

        $managerEmployeeIds = $position->direct_manager_id === null ? [] : Contract::query()
            ->activeNow()
            ->where('position_id', $position->direct_manager_id)
            ->pluck('employee_id')
            ->all();

        $managers = $managerEmployeeIds === [] ? collect() : User::query()
            ->whereIn('employee_id', $managerEmployeeIds)
            ->whereHas('profile', fn (Builder $q) => $q->where('is_active', true))
            ->get();

        return $hr->concat($managers)->unique('id')->values();
    }

    private function lastEnd(Position $position): ?CarbonImmutable
    {
        $end = DB::table('contracts')->where('position_id', $position->getKey())->max('end_date');

        return $end === null ? null : CarbonImmutable::parse($end);
    }

    /** @param  array<int, string>  $roles */
    private function withRoles(array $roles): Collection
    {
        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $q) => $q->where('is_active', true))
            ->get();
    }
}
