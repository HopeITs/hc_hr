@props([
    'contract',
    'forPdf' => false,
])

@php
    use App\Enums\ScheduleMode;
    use App\Support\ArabicText as AR;
    use Carbon\Carbon;

    /*
     * Every print page used to define its own $Glyphs/$Digits pair and build
     * this clause's wording inline — six near-identical, already-stale copies
     * (see docs/superpowers/specs/2026-09-15-contract-composer-restructure.md
     * Phase 5). This component is the one place that wording lives now, data
     * -driven from the same schedule_mode, flex_ and show_ columns the
     * composer itself writes, instead of the old hardcoded "N working hours
     * per week, 8 hours per day" prose that never reflected what was chosen.
     *
     * Arabic shaping (forPdf only — the browser shapes Arabic natively, only
     * DomPDF needs ArPHP's presentation-form glyphs) is applied once to each
     * fully assembled phrase, never to a lone connective letter on its own —
     * splitting "ومن" ("and from") into "و" + "من" and shaping them
     * separately would each get shaped in isolation and print disconnected.
     */
    $glyphs = fn (string $s): string => $forPdf ? AR::smartGlyphs($s) : $s;
    // Digit conversion is Arabic-column-only — the English column always
    // stays Latin numerals, matching every pre-existing print page (their
    // own $Digits() was likewise only ever applied on the :ar side). Feeding
    // Arabic-Indic digits into the LTR-styled English column previously
    // rendered as blank glyphs in the PDF (no visible number at all).
    $digitsAr = fn (int|float|string $n): string => $forPdf ? ar_digits((string) $n) : (string) $n;
    $boldTime = fn (string $v): string => '<strong>'.$v.'</strong>';

    // Sunday(0)..Saturday(6) — Carbon's own day-of-week numbering, matching
    // work_day_from/to and the composer's own bilingual preview
    // (contract-composer.js DAY_NAMES_EN/AR), which this mirrors server-side.
    $dayNamesEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    $hoursBetween = function (?string $from, ?string $to): float {
        if (! $from || ! $to) {
            return 0.0;
        }

        $minutes = Carbon::parse($from)->diffInMinutes(Carbon::parse($to), false);

        return $minutes > 0 ? round($minutes / 60, 2) : 0.0;
    };

    $to12Hour = fn (?string $value): string => $value ? Carbon::parse($value)->format('g:i A') : '';
    // Arabic column: «9:00 ص» / «5:00 م» rather than Latin AM/PM (A4 decision,
    // 2026-10-21). Mirrors to12Hour(value, true) in contract-composer.js.
    $to12HourAr = fn (?string $value): string => $value
        ? Carbon::parse($value)->format('g:i').' '.(Carbon::parse($value)->hour < 12 ? 'ص' : 'م')
        : '';

    $rows = [];

    if ((int) $contract->schedule_mode === ScheduleMode::Flexible) {
        $fragmentsEn = [];
        $fragmentsAr = [];

        if ($contract->flex_hours_per_week) {
            $fragmentsEn[] = ((string) $contract->flex_hours_per_week).' hours per week';
            $fragmentsAr[] = $glyphs($digitsAr($contract->flex_hours_per_week).' ساعة أسبوعيًا');
        }

        if ($contract->flex_days_per_week) {
            $fragmentsEn[] = ((string) $contract->flex_days_per_week).' days per week';
            // Arabic counts 3-10 take the plural (أيام); 1-2 stay singular.
            $dayWordAr = (int) $contract->flex_days_per_week >= 3 ? 'أيام' : 'يوم';
            $fragmentsAr[] = $glyphs($digitsAr($contract->flex_days_per_week).' '.$dayWordAr.' أسبوعيًا');
        }

        if ($contract->flex_hours_per_month) {
            $fragmentsEn[] = ((string) $contract->flex_hours_per_month).' hours per month';
            $fragmentsAr[] = $glyphs($digitsAr($contract->flex_hours_per_month).' ساعة شهريًا');
        }

        if ($contract->flex_varies_by_requirements) {
            $fragmentsEn[] = 'hours vary according to the requirements of the job';
            $fragmentsAr[] = $glyphs('الساعات تتغير بحسب متطلبات العمل');
        }

        if ($contract->flex_coordinated_with_manager) {
            $fragmentsEn[] = 'working days and hours are coordinated with the direct manager';
            $fragmentsAr[] = $glyphs('تُحدَّد أيام وساعات العمل بالتنسيق مع المدير المباشر');
        }

        if (filled($contract->flex_custom_en)) {
            $fragmentsEn[] = rtrim($contract->flex_custom_en, '.');
        }

        if (filled($contract->flex_custom_ar)) {
            $fragmentsAr[] = $glyphs(rtrim($contract->flex_custom_ar, '.'));
        }

        if ($fragmentsEn || $fragmentsAr) {
            $rows[] = [
                'en' => $fragmentsEn ? ucfirst(implode(', ', $fragmentsEn)).'.' : '—',
                'ar' => $fragmentsAr ? implode($glyphs('، '), $fragmentsAr).'.' : '—',
            ];
        }
    } else {
        $clausesEn = [];
        $clausesAr = [];

        if ($contract->show_hours_per_week) {
            $clausesEn[] = '<strong>'.((int) $contract->weekly_hours).' working hours</strong> per week';
            $clausesAr[] = '<strong>'.$glyphs($digitsAr((int) $contract->weekly_hours).' ساعة').'</strong> '.$glyphs('عمل أسبوعيًا');
        }

        if ($contract->show_hours_per_day) {
            $dailyHours = $hoursBetween($contract->work_hours_from, $contract->work_hours_to)
                + $hoursBetween($contract->work_hours_2_from, $contract->work_hours_2_to);

            $clausesEn[] = '<strong>'.$dailyHours.' hours</strong> per day';
            $clausesAr[] = '<strong>'.$glyphs($digitsAr($dailyHours).' ساعات').'</strong> '.$glyphs('يوميًا');
        }

        if ($contract->show_days_per_week && $contract->work_day_from !== null && $contract->work_day_to !== null) {
            $daysCount = (int) $contract->work_day_to - (int) $contract->work_day_from + 1;

            $clausesEn[] = '<strong>'.$daysCount.' days</strong> per week';
            $clausesAr[] = '<strong>'.$glyphs($digitsAr($daysCount).' أيام').'</strong> '.$glyphs('أسبوعيًا');
        }

        if ($clausesEn || $clausesAr) {
            $rows[] = [
                'en' => $clausesEn ? implode(', ', $clausesEn).'.' : '—',
                'ar' => $clausesAr ? implode($glyphs('، '), $clausesAr).'.' : '—',
            ];
        }

        $hasRange = $contract->show_day_range && $contract->work_day_from !== null && $contract->work_day_to !== null;

        $rangeEn = '';
        $rangeArRaw = '';

        if ($hasRange) {
            $fromEn = $dayNamesEn[(int) $contract->work_day_from] ?? '';
            $toEn = $dayNamesEn[(int) $contract->work_day_to] ?? '';
            $fromAr = $dayNamesAr[(int) $contract->work_day_from] ?? '';
            $toAr = $dayNamesAr[(int) $contract->work_day_to] ?? '';

            $rangeEn = $fromEn === $toEn ? $fromEn : "{$fromEn} to {$toEn}";
            $rangeArRaw = $fromAr === $toAr ? $fromAr : "من {$fromAr} إلى {$toAr}";
        }

        $fromTime = $boldTime($to12Hour($contract->work_hours_from));
        $toTime = $boldTime($to12Hour($contract->work_hours_to));
        $fromTimeAr = $boldTime($to12HourAr($contract->work_hours_from));
        $toTimeAr = $boldTime($to12HourAr($contract->work_hours_to));

        $officeHoursEn = 'Standard office hours are'
            .($hasRange ? " {$rangeEn} and" : '')
            ." from {$fromTime} to {$toTime}";

        // "ومن" ("and from") has to stay one fused word (no space between
        // "و" and "من"), both as a matter of correct Arabic and so a single
        // glyphs() call shapes it as one connected unit (see the note
        // above) — so it is written as one literal below, never assembled
        // from a trailing "و" plus a separately-started "من ...".
        $officeHoursArRaw = $hasRange
            ? "ساعات الدوام الرسمية هي {$rangeArRaw} ومن الساعة {$fromTimeAr} حتى الساعة {$toTimeAr}"
            : "ساعات الدوام الرسمية هي من الساعة {$fromTimeAr} حتى الساعة {$toTimeAr}";

        if (filled($contract->work_hours_2_from) && filled($contract->work_hours_2_to)) {
            $from2Time = $boldTime($to12Hour($contract->work_hours_2_from));
            $to2Time = $boldTime($to12Hour($contract->work_hours_2_to));
            $from2TimeAr = $boldTime($to12HourAr($contract->work_hours_2_from));
            $to2TimeAr = $boldTime($to12HourAr($contract->work_hours_2_to));

            $officeHoursEn .= " and from {$from2Time} to {$to2Time}";
            $officeHoursArRaw .= " ومن الساعة {$from2TimeAr} حتى الساعة {$to2TimeAr}";
        }

        $rows[] = [
            'en' => $officeHoursEn.'.',
            'ar' => $glyphs($officeHoursArRaw).'.',
        ];
    }
@endphp

@foreach ($rows as $row)
    <x-contract.dual class="mt-1" :en="$row['en']" :ar="$row['ar']" />
@endforeach
