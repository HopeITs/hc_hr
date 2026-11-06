<?php

namespace App\Enums\News;

use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Program;

/**
 * Who may see an item. Enforced in SQL by News::scopeVisibleTo(), never by
 * the browser. The selected ids live in news_audience_targets.
 */
enum AudienceType: string
{
    case All = 'all';
    case Cities = 'cities';
    case Centers = 'centers';
    case Programs = 'programs';
    case Departments = 'departments';
    case Employees = 'employees';

    /** @return class-string|null */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::All => null,
            self::Cities => City::class,
            self::Centers => Center::class,
            self::Programs => Program::class,
            self::Departments => Department::class,
            self::Employees => Employee::class,
        };
    }

    public function needsTargets(): bool
    {
        return $this !== self::All;
    }

    public function label(): string
    {
        return __('news.audience.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::All => 'users',
            self::Cities => 'pin',
            self::Centers => 'building',
            self::Programs => 'folder',
            self::Departments => 'users',
            self::Employees => 'user',
        };
    }
}
