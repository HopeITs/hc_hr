@php
    /**
     * The board's minutes (محضر اجتماع مجلس إدارة), Arabic RTL on A4, in the order of the board's own sheet:
     * the meeting's particulars, who attended and who was absent, the narrative parts as the secretary wrote them,
     * the follow-up table, and who prepared it.
     *
     * @var \App\Models\BoardMeeting $meeting
     * @var \Illuminate\Support\Collection $present
     * @var \Illuminate\Support\Collection $online
     * @var \Illuminate\Support\Collection $absent
     * @var bool $draft
     */
    $inlineAssets ??= false;
    $font = function (string $path) use ($inlineAssets) {
        if (! $inlineAssets || ! is_file(public_path($path))) {
            return asset($path);
        }

        return 'data:font/truetype;charset=utf-8;base64,'.base64_encode(file_get_contents(public_path($path)));
    };
    $number = str_pad((string) $meeting->number, 3, '0', STR_PAD_LEFT);
    $ordinals = ['أولاً', 'ثانياً', 'ثالثاً', 'رابعاً', 'خامساً', 'سادساً', 'سابعاً', 'ثامناً', 'تاسعاً', 'عاشراً'];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $fileName ?? 'board-minutes' }}</title>
    <style>
        @font-face { font-family: 'somar'; src: url('{{ $font('fonts/SomarSans/SomarSans-Regular.ttf') }}') format('truetype'); font-weight: 400; }
        @font-face { font-family: 'somar'; src: url('{{ $font('fonts/SomarSans/SOMARSANS-BOLD.ttf') }}') format('truetype'); font-weight: 700; }
        @font-face { font-family: 'NotoNaskhArabic'; src: url('{{ $font('fonts/NotoNaskh/NotoNaskhArabic-Regular.ttf') }}') format('truetype'); font-weight: 400; }
        @font-face { font-family: 'NotoNaskhArabic'; src: url('{{ $font('fonts/NotoNaskh/NotoNaskhArabic-Bold.ttf') }}') format('truetype'); font-weight: 700; }
        @page { size: A4 portrait; margin: 16mm 14mm 18mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'somar', 'NotoNaskhArabic', 'DejaVu Sans', sans-serif; font-size: 12pt; line-height: 1.7; color: #414042; margin: 0; }
        h1 { font-size: 17pt; text-align: center; margin: 0 0 4mm; color: #BE1F24; }
        h2 { font-size: 13pt; margin: 7mm 0 2mm; color: #414042; }
        .draft { text-align: center; font-weight: 700; border: 1.5px dashed #939598; padding: 1.5mm; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { border: 1px solid #BCBEC0; padding: 1.5mm 3mm; vertical-align: top; }
        .meta td.k { background: #F3F3F4; font-weight: 700; width: 24%; }
        .follow th { background: #E6E7E8; border: 1px solid #BCBEC0; padding: 1.5mm 3mm; text-align: right; font-size: 11pt; }
        .follow td { border: 1px solid #BCBEC0; padding: 1.5mm 3mm; vertical-align: top; }
        .body { white-space: pre-line; text-align: justify; }
        section { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        .sign { margin-top: 10mm; font-weight: 700; }
    </style>
</head>
<body>
    @if($draft)<div class="draft">مسودة · لم يُنشر المحضر بعد</div>@endif

    <h1>محضر اجتماع مجلس الإدارة</h1>

    <table class="meta">
        <tr>
            <td class="k">رقم الاجتماع</td><td>{{ $number }}</td>
            <td class="k">التاريخ</td><td>{{ $meeting->starts_at->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="k">التوقيت</td>
            <td>من الساعة {{ $meeting->starts_at->format('H:i') }}@if($meeting->ends_at) حتى الساعة {{ $meeting->ends_at->format('H:i') }}@endif</td>
            <td class="k">المكان</td>
            <td>{{ $meeting->location }}@if($meeting->room) - الغرفة {{ $meeting->room }}@endif</td>
        </tr>
        @if($meeting->led_by || $meeting->purpose)
            <tr>
                <td class="k">المسؤول عن الاجتماع</td><td>{{ $meeting->led_by }}</td>
                <td class="k">الهدف</td><td>{{ $meeting->purpose }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">الحضور</td>
            <td colspan="3">
                {{ $present->implode(' - ') ?: '—' }}
                @if($online->isNotEmpty())<br><small>عبر الإنترنت: {{ $online->implode(' - ') }}</small>@endif
            </td>
        </tr>
        <tr>
            <td class="k">الغياب</td>
            <td colspan="3">{{ $absent->implode(' - ') ?: '—' }}</td>
        </tr>
    </table>

    @foreach($meeting->sections as $i => $section)
        <section>
            <h2>{{ $ordinals[$i] ?? ($i + 1).'.' }}: {{ $section->title }}</h2>
            <div class="body">{{ $section->body }}</div>
        </section>
    @endforeach

    @if($meeting->followUps->isNotEmpty())
        <section>
            <h2>جدول متابعة التكليفات والملفات</h2>
            <table class="follow">
                <thead><tr><th>الموضوع</th><th>الشخص أو الجهة المعنية</th><th>المطلوب</th></tr></thead>
                <tbody>
                    @foreach($meeting->followUps as $row)
                        <tr><td>{{ $row->topic }}</td><td>{{ $row->party }}</td><td>{{ $row->required }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <p class="sign">أعدّ هذا المحضر: {{ $meeting->prepared_by ?: '—' }}</p>
</body>
</html>
