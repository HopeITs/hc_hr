<?php

namespace App\Enums;

/** How a termination reason groups in the "Why people leave" report. */
enum LeavingCategory: string
{
    case Voluntary = 'voluntary';
    case Involuntary = 'involuntary';
    case NaturalEnd = 'natural_end';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Voluntary => __('Voluntary (by their own choice)'),
            self::Involuntary => __('Involuntary (decided by the organisation)'),
            self::NaturalEnd => __('Contract ran its course'),
            self::Other => __('Other'),
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
