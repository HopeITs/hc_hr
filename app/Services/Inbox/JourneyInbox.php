<?php

namespace App\Services\Inbox;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The employee-journey sources of the work inbox (DEC-09 increment 2). Each
 * one is a JourneyInboxSource; WorkInbox merges what they list into its own
 * rows under the "journey" action and counts them under counts['journey'].
 *
 * To add a source (renewal, exit): write the class and add it to SOURCES
 * (docs/ux/journey-inbox-implementation.md §3). Nothing else changes.
 */
final class JourneyInbox
{
    /** @var list<class-string<JourneyInboxSource>> */
    public const SOURCES = [
        OnboardingInboxSource::class,
    ];

    /** @return list<JourneyInboxSource> */
    public function sources(): array
    {
        return array_map(fn (string $class) => app($class), self::SOURCES);
    }

    /** Whether any journey source would list something for this user's active role. */
    public function hasSources(User $user): bool
    {
        return collect($this->sources())->contains(fn (JourneyInboxSource $source) => $source->visibleTo($user));
    }

    /**
     * Every source's rows (each capped at $perSource) and the exact total.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: int}
     */
    public function collect(User $user, int $perSource): array
    {
        $rows = collect();
        $total = 0;

        foreach ($this->sources() as $source) {
            [$listed, $count] = $source->collect($user, $perSource);
            $rows = $rows->merge($listed);
            $total += $count;
        }

        return [$rows, $total];
    }
}
