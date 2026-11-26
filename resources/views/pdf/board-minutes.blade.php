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
        body { font-family: 'somar', 'NotoNaskhArabic', 'DejaVu Sans', sans-serif; margin: 0; }
    </style>
</head>
<body>
    @include('board.minutes-sheet')
</body>
</html>
