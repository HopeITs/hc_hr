<?php

namespace App\Enums;

/**
 * Where a job offer stands (Phase 2 Task 9).
 *
 * One offer per application; a revision is a new version of the same offer.
 *
 *   Draft     — prepared, not yet given to the candidate; editable in place.
 *   Sent      — the current version is with the candidate, until expires_at.
 *   Revised   — a new version is being prepared (after a change, a refusal,
 *               an expiry or a cancellation); editable until it is sent.
 *   Accepted  — the candidate accepted the current version. Final: it hands
 *               over to the hiring conversion (Task 10), exactly once.
 *   Rejected  — the candidate refused. Can be revised, or HR moves on.
 *   Expired   — nobody answered by expires_at. Can be revised.
 *   Cancelled — HR withdrew it. Can be revised (a new version).
 */
final class RecruitmentJobOfferStatus
{
    public const Draft = 'draft';

    public const Sent = 'sent';

    public const Revised = 'revised';

    public const Accepted = 'accepted';

    public const Rejected = 'rejected';

    public const Expired = 'expired';

    public const Cancelled = 'cancelled';

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        self::Draft => [self::Sent, self::Cancelled],
        self::Sent => [self::Accepted, self::Rejected, self::Expired, self::Cancelled, self::Revised],
        self::Revised => [self::Sent, self::Cancelled],
        self::Rejected => [self::Revised],
        self::Expired => [self::Revised],
        self::Cancelled => [self::Revised],
    ];

    /** @return array<int, string> */
    public static function values(): array
    {
        return [self::Draft, self::Sent, self::Revised, self::Accepted, self::Rejected, self::Expired, self::Cancelled];
    }

    /** Statuses whose current version is a draft that can still be edited. */
    public static function editable(): array
    {
        return [self::Draft, self::Revised];
    }

    /**
     * An accepted offer takes a seat on the vacancy until the hiring
     * conversion (Task 10) fills it. Sent offers do not: two candidates can
     * be offered one seat, and the first to accept takes it.
     */
    public static function takingSeat(): array
    {
        return [self::Accepted];
    }

    public static function canMove(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function classes(?string $status): string
    {
        return match ($status) {
            self::Draft, self::Revised => 'bg-neutral-100 text-secondary-dark',
            self::Sent => 'bg-white text-primary ring-1 ring-inset ring-primary',
            self::Accepted => 'bg-primary text-white',
            self::Rejected, self::Expired => 'bg-secondary-dark text-white',
            default => 'bg-neutral-100 text-secondary-dark',
        };
    }
}
