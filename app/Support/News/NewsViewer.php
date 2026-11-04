<?php

namespace App\Support\News;

use App\Enums\News\AudienceType;
use App\Models\Employee;
use App\Models\User;

/**
 * The organizational coordinates audience rules are matched against: the
 * employee, their city, and the center, program and department of their
 * latest contract's position. Resolved once per request per employee.
 */
final class NewsViewer
{
    /** @param array<string, list<int>> $targets keyed by AudienceType value */
    private function __construct(private array $targets) {}

    public static function from(self|Employee|User|null $viewer): self
    {
        if ($viewer instanceof self) {
            return $viewer;
        }

        $employee = $viewer instanceof User ? $viewer->profile : $viewer;

        if (! $employee instanceof Employee) {
            return new self([]);
        }

        $position = $employee->latestActiveContract()?->position;

        $ids = fn (?int ...$values) => array_values(array_unique(array_filter(array_map('intval', $values))));

        return new self([
            AudienceType::Employees->value => $ids($employee->getKey()),
            AudienceType::Cities->value => $ids($employee->city_id, $position?->city_id, $position?->center?->city_id),
            AudienceType::Centers->value => $ids($position?->center_id),
            AudienceType::Programs->value => $ids($position?->program_id),
            AudienceType::Departments->value => $ids($position?->department_id),
        ]);
    }

    /** @return array<string, list<int>> */
    public function targets(): array
    {
        return $this->targets;
    }
}
