@props([
    'format',
    'city',
    'program',
    'jobTitle',
    'id' => null,
])

@php
    $isPost = $format === 'post';
    $viewBox = $isPost ? '0 0 1000 1000' : '0 0 1080 1920';
    $exportWidth = 2160;
    $exportHeight = $isPost ? 2160 : 3840;
    $asset = fn (string $name): string => asset("assets/recruitment/hope-center/{$name}.svg");

    // The text block sits bottom-left with the arrow at the right end of the
    // same rows (the arrow's foot on the job title's baseline), so every line
    // is fitted to the column left of the arrow using the font's real widths.
    // A line keeps its template size while it fits and shrinks when it does
    // not. The project name and job title may be long: below their floor size
    // they break onto two balanced lines, and everything above moves up so
    // the title stays on the arrow. Text is never stretched, only (in the
    // extreme case) condensed to the column.
    $column = $isPost ? 545.0 : 660.0;
    $clean = fn ($text): string => trim(preg_replace('/\s+/u', ' ', (string) $text));
    $measure = fn (string $line, int $weight): float => \App\Support\Recruitment\SomarSansMetrics::width($line, $weight);

    $layout = function (string $text, int $weight, float $base, float $min, bool $mayWrap) use ($column, $measure): array {
        $lines = [$text];
        $size = min($base, $column / max(0.01, $measure($text, $weight)));

        if ($size < $min && $mayWrap && str_contains($text, ' ')) {
            $words = explode(' ', $text);
            $best = null;
            for ($i = 1; $i < count($words); $i++) {
                $pair = [implode(' ', array_slice($words, 0, $i)), implode(' ', array_slice($words, $i))];
                $widest = max($measure($pair[0], $weight), $measure($pair[1], $weight));
                if ($best === null || $widest < $best[0]) {
                    $best = [$widest, $pair];
                }
            }
            $lines = $best[1];
            $size = min($base, $column / max(0.01, $best[0]));
        }

        $size = round(max($min, $size), 2);

        return [
            'size' => $size,
            'lineHeight' => round($size * 1.2, 2),
            'lines' => array_map(fn (string $line): array => [
                'text' => $line,
                // Only ever condenses: set when the line would overrun.
                'condense' => $measure($line, $weight) * $size > $column,
            ], $lines),
        ];
    };

    $cityText = mb_strtoupper($clean($city));
    $cityLayout = $layout($cityText, 900, $isPost ? 28.48 : 34.559, $isPost ? 20.0 : 24.0, false);
    $programLayout = $layout(mb_strtoupper($clean($program)), 400, $isPost ? 28.588 : 34.69, $isPost ? 22.0 : 26.0, true);
    $titleLayout = $layout($clean($jobTitle), 700, $isPost ? 40.0 : 47.0, $isPost ? 30.0 : 35.0, true);

    // Extra lines push the rows above them upwards; the title's last line
    // never moves, so it always sits level with the arrow.
    $titleExtra = (count($titleLayout['lines']) - 1) * $titleLayout['lineHeight'];
    $programExtra = (count($programLayout['lines']) - 1) * $programLayout['lineHeight'];
    $aboveTitle = $titleExtra;
    $aboveProgram = $titleExtra + $programExtra;
@endphp

<svg
    @if ($id) id="{{ $id }}" @endif
    {{ $attributes->class('hc-job-post-svg') }}
    xmlns="http://www.w3.org/2000/svg"
    viewBox="{{ $viewBox }}"
    width="{{ $isPost ? 1000 : 1080 }}"
    height="{{ $isPost ? 1000 : 1920 }}"
    data-export-width="{{ $exportWidth }}"
    data-export-height="{{ $exportHeight }}"
    data-export-format="{{ $format }}"
    role="img"
    aria-label="{{ __('recruitment.job_post.preview_aria', ['format' => $format]) }}"
    style="direction:ltr"
>
    <rect width="100%" height="100%" fill="#be1f24" />

    @if ($isPost)
        <image href="{{ $asset('logo') }}" x="182.031" y="122.85" width="332" height="111" />
        <image href="{{ $asset('hiring') }}" x="182" y="333" width="645.651" height="307.14" />
        <image href="{{ $asset('arrow2') }}" x="750" y="808" width="65.026" height="65.849" />
        <image href="{{ $asset('loc') }}" x="182" y="{{ 750.85 - $aboveProgram }}" width="13.472" height="20.051" />
        <text direction="ltr" text-anchor="start" x="203" y="{{ 771.85 - $aboveProgram }}" font-family="SomarSans" font-weight="900" font-size="{{ $cityLayout['size'] }}" @if ($cityLayout['lines'][0]['condense']) textLength="{{ $column - 21 }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff">{{ $cityText }}</text>
        @foreach ($programLayout['lines'] as $index => $line)
            <text direction="ltr" text-anchor="start" x="182" y="{{ 819.85 - $aboveProgram + $index * $programLayout['lineHeight'] }}" font-family="SomarSans" font-weight="400" font-size="{{ $programLayout['size'] }}" @if ($line['condense']) textLength="{{ $column }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff" data-program-text>{{ $line['text'] }}</text>
        @endforeach
        @foreach ($titleLayout['lines'] as $index => $line)
            <text direction="ltr" text-anchor="start" x="182" y="{{ 875.85 - $aboveTitle + $index * $titleLayout['lineHeight'] }}" font-family="SomarSans" font-weight="700" font-size="{{ $titleLayout['size'] }}" @if ($line['condense']) textLength="{{ $column }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff" data-job-title-text>{{ $line['text'] }}</text>
        @endforeach
    @else
        <image href="{{ $asset('logo') }}" x="152.5" y="231.385" width="480.133" height="164.738" />
        <image href="{{ $asset('hiring') }}" x="152.5" y="699" width="775.281" height="364.503" />
        <image href="{{ $asset('arrow') }}" x="840" y="1550" width="85.598" height="86.61" />
        <image href="{{ $asset('line') }}" x="152.5" y="1682" width="775.281" height="9.677" preserveAspectRatio="none" />
        <image href="{{ $asset('loc') }}" x="152.5" y="{{ 1480.6 - $aboveProgram }}" width="16.347" height="24.33" />
        <text direction="ltr" text-anchor="start" x="180" y="{{ 1506.6 - $aboveProgram }}" font-family="SomarSans" font-weight="900" font-size="{{ $cityLayout['size'] }}" @if ($cityLayout['lines'][0]['condense']) textLength="{{ $column - 27.5 }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff">{{ $cityText }}</text>
        @foreach ($programLayout['lines'] as $index => $line)
            <text direction="ltr" text-anchor="start" x="152.5" y="{{ 1565.6 - $aboveProgram + $index * $programLayout['lineHeight'] }}" font-family="SomarSans" font-weight="400" font-size="{{ $programLayout['size'] }}" @if ($line['condense']) textLength="{{ $column }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff" data-program-text>{{ $line['text'] }}</text>
        @endforeach
        @foreach ($titleLayout['lines'] as $index => $line)
            <text direction="ltr" text-anchor="start" x="152.5" y="{{ 1631.6 - $aboveTitle + $index * $titleLayout['lineHeight'] }}" font-family="SomarSans" font-weight="700" font-size="{{ $titleLayout['size'] }}" @if ($line['condense']) textLength="{{ $column }}" lengthAdjust="spacingAndGlyphs" @endif fill="#fff" data-job-title-text>{{ $line['text'] }}</text>
        @endforeach
    @endif
</svg>
