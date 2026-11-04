<?php

namespace App\Enums\News;

use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Program;

/**
 * What an item is ABOUT (stored in newsable_type / newsable_id). It never
 * decides who can see the item: that is AudienceType.
 *
 * There is no Project model: a "project" in this app is a program run in a
 * governorate (HolidayProject), so Program is the project-level context.
 */
enum RelatedTo: string
{
    case Organization = 'organization';
    case City = 'city';
    case Center = 'center';
    case Program = 'program';
    case Department = 'department';
    case Employee = 'employee';

    /** @return class-string|null */
    public function modelClass(): ?string
    {
        return match ($this) {
            self::Organization => null,
            self::City => City::class,
            self::Center => Center::class,
            self::Program => Program::class,
            self::Department => Department::class,
            self::Employee => Employee::class,
        };
    }

    public static function fromModelClass(?string $class): self
    {
        foreach (self::cases() as $case) {
            if ($case->modelClass() !== null && $case->modelClass() === $class) {
                return $case;
            }
        }

        return self::Organization;
    }

    public function needsEntity(): bool
    {
        return $this !== self::Organization;
    }

    public function label(): string
    {
        return __('news.related.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::Organization => 'building',
            self::City => 'pin',
            self::Center => 'building',
            self::Program => 'folder',
            self::Department => 'users',
            self::Employee => 'user',
        };
    }
}
