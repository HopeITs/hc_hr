@php
    /**
     * One day of the week on the working-days card and in its dialog.
     *
     * @var array{day: int, name: string, short: string, working: bool, closed: bool} $d
     * @var bool $full  full day names from xl up (the card); the dialog is too
     *                  narrow for seven of them, so it keeps the short ones
     *
     * The state is said three ways, never by colour: the fill (worked =
     * surface, day off = card, center closed = dashed soft), an icon (check /
     * home / building) and a word under it.
     */
    $full ??= false;
    [$icon, $word] = match (true) {
        $d['closed'] => ['building', __('contracts.work-days.tile.closed')],
        $d['working'] => ['check', __('contracts.work-days.tile.work')],
        default => ['home', __('contracts.work-days.tile.off')],
    };
@endphp

<span @class([
    'flex min-h-16 w-full min-w-0 flex-col items-center justify-center gap-1 rounded-control border px-0 py-2 text-center sm:px-1',
    'border-dashed border-line bg-soft text-ink-3' => $d['closed'],
    'border-ink bg-surface text-ink' => $d['working'],
    'border-ink bg-card text-ink' => ! $d['closed'] && ! $d['working'],
]) title="{{ $d['name'] }} — {{ $word }}">
    <span class="text-caption leading-tight font-semibold tracking-tight sm:text-small sm:tracking-normal">
        <span @class(['xl:hidden' => $full]) aria-hidden="true">{{ $d['short'] }}</span>
        @if($full)
            <span class="hidden xl:inline" aria-hidden="true">{{ $d['name'] }}</span>
        @endif
        <span class="sr-only">{{ $d['name'] }}</span>
    </span>
    <x-ic :name="$icon" class="size-4" />
    <span class="text-caption leading-none">{{ $word }}</span>
</span>
