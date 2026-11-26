<?php

namespace App\Support\News;

use App\Models\News;

/** The words the news approval notifications share (HR-040), in both languages. */
final class NewsNotificationText
{
    /** @return array{0: string, 1: string} [en, ar] */
    public static function titles(News $news): array
    {
        $ar = (string) ($news->title_ar ?: $news->title_en);
        $en = (string) ($news->title_en ?: $news->title_ar);

        return [$en, $ar];
    }

    /** @return array{0: string, 1: string} [en, ar] */
    public static function author(News $news): array
    {
        $employee = $news->creator?->profile;

        if ($employee === null) {
            $name = (string) ($news->creator?->name ?? '');

            return [$name, $name];
        }

        $en = trim($employee->first_name_en.' '.$employee->last_name_en);
        $ar = trim($employee->first_name_ar.' '.$employee->last_name_ar);

        return [$en ?: $ar, $ar ?: $en];
    }
}
