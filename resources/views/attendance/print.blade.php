@php
    use App\Services\Attendance\FormRows;

    $t = fn (string $key, array $replace = []) => __('attendance.form.'.$key, $replace, 'ar');
    // The PDF is drawn by a headless browser that cannot fetch the app's own URLs: fonts go in as data.
    $font = fn (string $file) => $pdf
        ? 'data:font/ttf;base64,'.base64_encode((string) file_get_contents(public_path('fonts/SomarSans/'.$file)))
        : asset('fonts/SomarSans/'.$file);
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.') ?: '0';
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>Monthly TimeSheet {{ $month->format('Y-m') }}</title>
    <style>
        @font-face { font-family: 'Somar'; font-weight: 400; src: url('{{ $font('SomarSans-Regular.ttf') }}'); }
        @font-face { font-family: 'Somar'; font-weight: 700; src: url('{{ $font('SOMARSANS-BOLD.ttf') }}'); }
        @page { size: A4 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Somar', 'Noto Sans Arabic', Arial, sans-serif; color: #2b2a2c; background: #e6e7e8; }
        .bar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #bcbec0; padding: 10px 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; }
        .bar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 0; background: #BE1F24; color: #fff; font-weight: 700; cursor: pointer; }
        .page { position: relative; width: 190mm; min-height: 277mm; margin: 12px auto; background: #fff; padding: 6mm 5mm; display: flex; flex-direction: column; gap: 8px; page-break-after: always; break-after: page; }
        .page:last-of-type { page-break-after: auto; break-after: auto; }
        .head { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #414042; padding-bottom: 8px; }
        .head .org { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; }
        .head .mark { width: 26px; height: 26px; background: #BE1F24; border-radius: 6px; display: block; }
        .head h1 { margin: 0; font-size: 19px; font-weight: 700; direction: ltr; }
        .ids { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px 12px; font-size: 12px; }
        .ids div { display: flex; gap: 6px; border-bottom: 1px dotted #939598; padding-bottom: 2px; }
        .ids b { white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        th { background: #e6e7e8; border: 1px solid #6d6e71; padding: 4px 3px; text-align: center; line-height: 1.25; }
        td { border: 1px solid #939598; padding: 0 3px; height: 6.3mm; text-align: center; white-space: nowrap; }
        td.n { text-align: start; white-space: normal; }
        tr.off td { background: #f3f3f4; color: #6d6e71; }
        tr.todo td { background: #fff; outline: 1px dashed #414042; }
        tr.tot td { background: #e6e7e8; font-weight: 700; height: 8mm; border-color: #6d6e71; }
        .num { direction: ltr; unicode-bidi: plaintext; font-variant-numeric: tabular-nums; }
        .sign { display: flex; gap: 14px; margin-top: auto; font-size: 12px; }
        .sign div { flex: 1; border-top: 1px solid #6d6e71; padding-top: 5px; text-align: center; margin-top: 22px; }
        h2 { margin: 0; font-size: 16px; direction: ltr; text-align: start; }
        .kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .kpi div { border: 1px solid #6d6e71; border-radius: 6px; padding: 9px 6px; text-align: center; }
        .kpi b { display: block; font-size: 21px; direction: ltr; }
        .kpi span { font-size: 11px; }
        .note { font-size: 11px; color: #6d6e71; line-height: 1.6; }
        .draft { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; font-size: 110px; font-weight: 700; color: rgba(65, 64, 66, .09); transform: rotate(-28deg); letter-spacing: 6px; }
        @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; width: auto; min-height: 0; height: 275mm; padding: 0; box-shadow: none; } }
    </style>
</head>
<body>
@unless($pdf)
<div class="bar">
    <div>{{ $sheets->count() }} · {{ $month->format('Y-m') }}@if($draft) · {{ $t('draft') }}@endif</div>
    <button type="button" onclick="window.print()">{{ __('attendance.timesheets.print') }}</button>
</div>
@endunless

@foreach($sheets as $item)
    @php
        $employee = $item['employee'];
        $sheet = $item['sheet'];
        $totals = $sheet['totals'];
        $position = $employee->latestActiveContract()?->position;
        $monthName = $month->locale('ar')->translatedFormat('F');
    @endphp

    {{-- Page 1: the timesheet --}}
    <section class="page" data-testid="timesheet-page">
        @if($draft)<div class="draft">{{ $t('draft') }}</div>@endif
        <div class="head">
            <div class="org"><i class="mark"></i><span>{{ $t('organization') }}</span></div>
            <h1>{{ $t('title') }}</h1>
        </div>
        <div class="ids">
            <div><b>{{ $t('volunteer') }}</b><span><bdi>{{ $employee->name }}</bdi></span></div>
            <div><b>{{ $t('employee-id') }}</b><span class="num">{{ $employee->id }}</span></div>
            <div><b>{{ $t('month-year') }}</b><span>{{ $monthName }} {{ $month->year }}</span></div>
            <div><b>{{ $t('position') }}</b><span>{{ $position?->title_ar ?: $position?->title_en }}</span></div>
            <div><b>{{ $t('department') }}</b><span>{{ $position?->program?->name }}</span></div>
            <div><b>{{ $t('daily-hours') }}</b><span class="num">{{ $fmt($sheet['daily_hours']) }}</span></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:13mm">{{ $t('day') }}</th><th style="width:21mm">{{ $t('date') }}</th><th style="width:34mm">{{ $t('leave-type') }}</th>
                    <th style="width:19mm">{{ $t('leave-hours') }}</th><th style="width:17mm">{{ $t('overtime') }}</th><th style="width:18mm">{{ $t('working-days') }}</th><th>{{ $t('notes') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach(FormRows::for($sheet) as $r)
                    <tr class="{{ $r['off'] ? 'off' : '' }} {{ $r['undecided'] ? 'todo' : '' }}">
                        <td>{{ $r['day'] }}</td>
                        <td class="num">{{ $r['date'] }}</td>
                        <td>{{ $r['type'] }}</td>
                        <td class="num">{{ $fmt($r['leave_hours']) }}</td>
                        <td class="num">{{ $fmt($r['overtime']) }}</td>
                        <td class="num">{{ $r['working_days'] === null ? '?' : $fmt($r['working_days']) }}</td>
                        <td class="n">{{ $r['note'] }}</td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td colspan="2">{{ $t('monthly-total') }}</td>
                    <td>{{ $t('total-leave-hours') }}</td>
                    <td class="num">{{ $fmt($totals['leave_hours']) }}</td>
                    <td class="num">{{ $fmt($totals['overtime_hours']) }}</td>
                    <td class="num">{{ $fmt($totals['working_days']) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="sign"><div>{{ $t('sign-volunteer') }}</div><div>{{ $t('sign-supervisor') }}</div><div>{{ $t('sign-hr') }}</div></div>
    </section>

    {{-- Page 2: the monthly summary and leave balances --}}
    <section class="page" data-testid="summary-page">
        @if($draft)<div class="draft">{{ $t('draft') }}</div>@endif
        <div class="head">
            <div class="org"><i class="mark"></i><span>{{ $t('organization') }}</span></div>
            <h1>{{ $t('summary-title') }}</h1>
        </div>
        <div class="ids">
            <div><b>{{ $t('volunteer') }}</b><span><bdi>{{ $employee->name }}</bdi></span></div>
            <div><b>{{ $t('employee-id') }}</b><span class="num">{{ $employee->id }}</span></div>
            <div><b>{{ $t('month-year') }}</b><span>{{ $monthName }} {{ $month->year }}</span></div>
        </div>

        <div class="kpi">
            <div><b>{{ $fmt($totals['working_days']) }}</b><span>{{ $t('total-working-days') }}</span></div>
            <div><b>{{ $fmt($totals['overtime_hours']) }}</b><span>{{ $t('total-overtime') }}</span></div>
            <div><b>{{ $fmt($totals['overtime_days']) }}</b><span>{{ $t('free-days') }}</span></div>
            <div><b>{{ $fmt($totals['leave_hours']) }}</b><span>{{ $t('leave-consumed') }}</span></div>
        </div>
        @if($totals['overtime_hours'] > 0)
            <div class="note">{{ $t('overtime-note', ['hours' => $fmt($totals['overtime_hours']), 'daily' => $fmt($sheet['daily_hours']), 'days' => $fmt($totals['overtime_days'])]) }}</div>
        @endif

        <h2>Leave balances</h2>
        <table>
            <thead>
                <tr><th>{{ $t('balance-type') }}</th><th>{{ $t('balance-entitled') }}</th><th>{{ $t('balance-month-hours') }}</th><th>{{ $t('balance-month-days') }}</th><th>{{ $t('balance-remaining') }}</th><th>{{ $t('balance-status') }}</th></tr>
            </thead>
            <tbody>
                @foreach($sheet['balances'] as $balance)
                    <tr>
                        <td>{{ $balance['type']?->name_ar ?: $balance['type']?->name_en }}</td>
                        <td class="num">{{ $fmt($balance['entitled_days']) }}</td>
                        <td class="num">{{ $fmt($balance['month_hours']) }}</td>
                        <td class="num">{{ $fmt($balance['month_days']) }}</td>
                        <td>{{ $balance['exceeded'] ? '—' : $t('days-and-hours', ['days' => $balance['remaining_days'], 'hours' => $balance['remaining_rest_hours']]) }}</td>
                        <td>{{ $balance['exceeded'] ? $t('exceeded') : $t('within') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="note">{{ $t('balances-note') }}</div>

        <div class="sign"><div>{{ $t('sign-volunteer') }}</div><div>{{ $t('sign-supervisor') }}</div><div>{{ $t('sign-hr') }}</div></div>
    </section>
@endforeach
</body>
</html>
