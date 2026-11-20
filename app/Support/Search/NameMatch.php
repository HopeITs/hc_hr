<?php

namespace App\Support\Search;

use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;

/**
 * "The name contains every word typed", in Arabic or English whatever the interface language
 * (Arabic letters folded as SearchText does, in SQL too). One rule for the people, the contracts
 * and the leave requests, so the same typing finds the same person everywhere.
 */
final class NameMatch
{
    /** Restrict an employees query to those whose names hold every word. */
    public static function employees(Builder $employees, string $term): Builder
    {
        foreach (SearchText::words($term) as $word) {
            $employees->where(fn (Builder $any) => $any
                ->where('first_name_en', 'like', "%{$word}%")
                ->orWhere('last_name_en', 'like', "%{$word}%")
                ->orWhereRaw(SearchText::foldedColumn('first_name_ar').' like ?', ["%{$word}%"])
                ->orWhereRaw(SearchText::foldedColumn('last_name_ar').' like ?', ["%{$word}%"]));
        }

        return $employees;
    }

    /** "contains this text" for LIKE, with the characters LIKE treats as wildcards taken out. */
    public static function like(string $text): string
    {
        return '%'.str_replace(['%', '_', '\\'], '', trim($text)).'%';
    }
}
