<?php

if (! function_exists('ar_digits')) {
    function ar_digits($value): string
    {
        $s = (string) ($value ?? '');

        return strtr($s, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }
}

if (! function_exists('en_digits')) {
    function en_digits($value): string
    {
        $s = (string) ($value ?? '');

        return strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}

if (! function_exists('esc')) {
    function esc($value): string
    {
        return e($value ?? '');
    }
}
if (! function_exists('transColumn')) {
    function transColumn(string $base, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $base.'_'.$locale;
    }
}

if (! function_exists('role_label')) {
    /**
     * Display name for a stored role name. The stored name stays the identifier
     * (permissions and switching use it); only what is shown is translated.
     * City-scoped HR roles keep their city code ("HR-ALP" -> "HR · ALP").
     * Custom roles without a label are shown as stored.
     */
    function role_label(?string $role): string
    {
        if ($role === null || $role === '') {
            return '';
        }

        if (preg_match('/^HR-([A-Z]+)$/', $role, $m)) {
            return __('general.role-labels.hr-city', ['city' => $m[1]]);
        }

        $key = 'general.role-labels.'.$role;

        return Illuminate\Support\Facades\Lang::has($key) ? __($key) : $role;
    }
}

if (! function_exists('join_counts')) {
    /**
     * Joins counts such as "3 Days" and "2 Hours" the way the locale does:
     * a space in English, «و» before each following part in Arabic.
     *
     * @param  array<int, string>  $parts
     */
    function join_counts(array $parts): string
    {
        return array_reduce(
            array_values(array_filter($parts, fn ($part) => $part !== '')),
            fn (?string $carry, string $part) => $carry === null ? $part : __('general.count.and', ['first' => $carry, 'second' => $part]),
        ) ?? '';
    }
}

if (! function_exists('formatDuration')) {
    function formatDuration($days, $hours)
    {
        // Accept decimal hours
        $days = (int) $days;

        // Split hours into whole hours + fractional minutes
        $wholeHours = (int) $hours;
        $minutes = (int) round(($hours - $wholeHours) * 60);

        // Handle 60 minutes → +1 hour
        if ($minutes >= 60) {
            $wholeHours += intdiv($minutes, 60);
            $minutes = $minutes % 60;
        }

        // Now normalize hours into days
        if ($wholeHours >= 24) {
            $days += intdiv($wholeHours, 24);
            $wholeHours = $wholeHours % 24;
        }

        $parts = [];

        if ($days > 0) {
            $parts[] = trans_choice('general.count.days', $days, ['count' => '<strong class="text-secondary-dark">'.$days.'</strong>']);
        }

        if ($wholeHours > 0) {
            $parts[] = trans_choice('general.count.hours', $wholeHours, ['count' => '<strong class="text-secondary-dark">'.$wholeHours.'</strong>']);
        }

        if ($minutes > 0) {
            $parts[] = trans_choice('general.count.minutes', $minutes, ['count' => '<strong class="text-secondary-dark">'.$minutes.'</strong>']);
        }

        return join_counts($parts) ?: trans_choice('general.count.days', 0);
    }

}

if (! function_exists('orgCardTokens')) {
    /** Org-chart card size modifier (layout in app.css `.org-id-card`). */
    function orgCardTokens(bool $compact = false): array
    {
        return [
            'modifier' => $compact ? 'org-id-card--compact' : 'org-id-card--comfortable',
        ];
    }
}
