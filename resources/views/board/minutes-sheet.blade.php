@php
    /**
     * The board's minutes as a sheet (محضر اجتماع مجلس إدارة), in the order of the board's own paper: the meeting's
     * particulars, who attended and who was absent, the narrative parts as the secretary wrote them, the follow-up
     * table, and who prepared it. The PDF and the page the members read render this same markup.
     *
     * @var \App\Models\BoardMeeting $meeting
     * @var \Illuminate\Support\Collection $present
     * @var \Illuminate\Support\Collection $online
     * @var \Illuminate\Support\Collection $absent
     * @var bool $draft
     */
    $number = str_pad((string) $meeting->number, 3, '0', STR_PAD_LEFT);
    $ordinals = ['أولاً', 'ثانياً', 'ثالثاً', 'رابعاً', 'خامساً', 'سادساً', 'سابعاً', 'ثامناً', 'تاسعاً', 'عاشراً'];
@endphp
<style>
    .bm-sheet { direction: rtl; text-align: right; font-size: 12pt; line-height: 1.7; color: #414042; }
    .bm-sheet h1 { font-size: 17pt; text-align: center; margin: 0 0 4mm; color: #BE1F24; font-weight: 700; }
    .bm-sheet h2 { font-size: 13pt; margin: 7mm 0 2mm; color: #414042; font-weight: 700; }
    .bm-sheet .draft { text-align: center; font-weight: 700; border: 1.5px dashed #939598; padding: 1.5mm; margin-bottom: 4mm; }
    .bm-sheet table { width: 100%; border-collapse: collapse; }
    .bm-sheet .meta td { border: 1px solid #BCBEC0; padding: 1.5mm 3mm; vertical-align: top; }
    .bm-sheet .meta td.k { background: #F3F3F4; font-weight: 700; width: 24%; }
    .bm-sheet .follow th { background: #E6E7E8; border: 1px solid #BCBEC0; padding: 1.5mm 3mm; text-align: right; font-size: 11pt; }
    .bm-sheet .follow td { border: 1px solid #BCBEC0; padding: 1.5mm 3mm; vertical-align: top; }
    .bm-sheet .body { white-space: pre-line; text-align: justify; }
    .bm-sheet section { page-break-inside: auto; }
    .bm-sheet tr { page-break-inside: avoid; }
    .bm-sheet .sign { margin-top: 10mm; font-weight: 700; }
</style>
<div class="bm-sheet" dir="rtl">
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
</div>
