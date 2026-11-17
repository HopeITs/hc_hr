<?php

namespace App\Services;

/**
 * Immutable result of resolving one workflow step.
 *
 * A null user is intentional: mandatory steps remain active and unassigned
 * when the data does not identify exactly one approver. They are never
 * silently skipped or assigned to an arbitrary candidate.
 */
final readonly class RecruitmentApproverAssignment
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $role,
        public ?int $scopeType,
        public ?int $scopeId,
        public ?int $userId,
        public array $meta = [],
    ) {}

    public function isAssigned(): bool
    {
        return $this->userId !== null;
    }
}
