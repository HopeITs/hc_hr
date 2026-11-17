@props([
    'contract',
    'forPdf' => false,
])

@php
    use App\Support\ArabicText as AR;
    use App\Services\LeaveRequestPricing;

    /*
     * Study Zone's "Friday is considered a day off…" sentence, printed only
     * when HR ticked it in the composer. A Study Zone position usually has no
     * center of its own, so the day is the weekend its centers in the city
     * keep — the same weekendFor() leave pricing uses, so the contract never
     * names a day off that leave requests then charge for. With no center
     * weekend known at all (the code default), nothing is printed: the
     * sentence would state a day nobody set.
     */
    $days = [];

    if ($contract->show_weekend_clause) {
        [$weekend, $source] = app(LeaveRequestPricing::class)->weekendFor($contract);
        $days = $source === 'default' ? [] : $weekend;
    }

    // Sunday(0)..Saturday(6), Carbon's numbering — as in schedule-clause.
    $dayNamesEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    $namesEn = array_map(fn (int $d) => $dayNamesEn[$d], $days);
    $namesAr = array_map(fn (int $d) => $dayNamesAr[$d], $days);
@endphp

@if ($days !== [])
    @php
        $last = fn (array $names, string $and) => count($names) > 1
            ? implode(', ', array_slice($names, 0, -1)).$and.end($names)
            : $names[0];

        $en = count($days) === 1
            ? "{$namesEn[0]} is considered a day off, unless management deems it necessary, and is not considered overtime."
            : $last($namesEn, ' and ').' are considered days off, unless management deems it necessary, and are not considered overtime.';

        $arRaw = match (count($days)) {
            1 => "يعتبر يوم {$namesAr[0]} يوم عطلة، إلا في حال رأت الإدارة الحاجة لذلك، ولا يعتبر دوام إضافي.",
            2 => "يعتبر يوما {$namesAr[0]} و{$namesAr[1]} يومي عطلة، إلا في حال رأت الإدارة الحاجة لذلك، ولا يعتبر دوام إضافي.",
            default => 'تعتبر أيام '.implode(' و', $namesAr).' أيام عطلة، إلا في حال رأت الإدارة الحاجة لذلك، ولا يعتبر دوام إضافي.',
        };

        $ar = $forPdf ? AR::smartGlyphs($arRaw) : $arRaw;
    @endphp

    <x-contract.dual class="mt-1" :en="$en" :ar="$ar" />
@endif
