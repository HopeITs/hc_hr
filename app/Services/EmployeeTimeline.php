<?php

namespace App\Services;

use App\Enums\BadgeType;
use App\Models\Badge;
use App\Models\Contract;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * The profile timeline (D1; profile-contract.md §4, Q1 = B): career events
 * and badges, each as far as the viewer's tiers allow. Nothing else.
 *
 * Built from rows the caller has already loaded for the viewer's tiers, so an
 * event the viewer may not see is never read here, and leaves no trace in the
 * count or the payload. Events are plain arrays: no model, no badge notes, and
 * no pay unless $withPay (F tier).
 *
 *   contract.started   joined, new contract or renewal (R)
 *   position.changed   the new contract is on another position (R)
 *   contract.ended     ended or terminated, with the reason (R; Q3 = A)
 *   badge.reward / badge.warning   (B, or the owner: Q2 = C)
 *   badge.salary       salary increase (B with F only; never the owner as such)
 */
final class EmployeeTimeline
{
    /**
     * @param  Collection<int, Contract>  $contracts  career history the viewer may see (R), newest first
     * @param  Collection<int, Badge>  $badges  badges the viewer may see
     * @return array<int, array<string, mixed>> newest first
     */
    public function events(Collection $contracts, Collection $badges, bool $withPay): array
    {
        $events = [];
        $previous = null;

        foreach ($contracts->sortBy('start_date')->values() as $contract) {
            if (! $contract->start_date) {
                continue;
            }

            $moved = $previous && $previous->position_id !== $contract->position_id;
            $events[] = [
                'kind' => $previous === null ? 'contract.joined' : ($moved ? 'position.changed' : 'contract.started'),
                'date' => $contract->start_date->toDateString(),
                'title' => $contract->position?->title,
                'detail' => $contract->contractType?->name,
                'pay' => $withPay && $contract->salary ? ['amount' => $contract->salary, 'currency' => $contract->currency] : null,
            ];

            $ended = $contract->terminated_at ?? ($contract->end_date && $contract->end_date->isPast() ? $contract->end_date : null);

            if ($ended) {
                $events[] = [
                    'kind' => 'contract.ended',
                    'date' => $ended->toDateString(),
                    'title' => $contract->position?->title,
                    'detail' => $contract->terminationReason?->title,
                    'pay' => null,
                ];
            }

            $previous = $contract;
        }

        foreach ($badges as $badge) {
            $kind = match ((int) $badge->type) {
                BadgeType::Reward => 'badge.reward',
                BadgeType::Warning => 'badge.warning',
                BadgeType::Salary_Increase => 'badge.salary',
                default => null,
            };

            // A salary increase is pay (F); without F it is not an event at all.
            if ($kind === null || ($kind === 'badge.salary' && ! $withPay) || ! $badge->date) {
                continue;
            }

            $events[] = [
                'kind' => $kind,
                'date' => $badge->date->toDateString(),
                'title' => BadgeType::getLocalizedName((int) $badge->type),
                'detail' => $badge->reason,
                'pay' => $kind === 'badge.salary' ? ['amount' => $badge->new_salary, 'currency' => $badge->currency] : null,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return $events;
    }

    /** Badges the owner may see on their own profile (Q2 = C): rewards and warnings, no pay, no notes. */
    public function ownBadges(Employee $employee): Collection
    {
        return $employee->badges()
            ->whereIn('type', [BadgeType::Reward, BadgeType::Warning])
            ->latest('date')
            ->get(['id', 'employee_id', 'type', 'date', 'reason']);
    }
}
