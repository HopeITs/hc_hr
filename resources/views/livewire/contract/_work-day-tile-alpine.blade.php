@php
    /**
     * _work-day-tile drawn by Alpine, for the pickers where days are tapped and
     * must change at once (the composer, the "Change days off" dialog). Same
     * classes, icons and words; inside an x-for over `d` with d.name,
     * d.short, d.closed and d.working.
     */
    $tileWords = [
        'work' => __('contracts.work-days.tile.work'),
        'off' => __('contracts.work-days.tile.off'),
        'closed' => __('contracts.work-days.tile.closed'),
    ];
@endphp

<span class="flex min-h-16 w-full min-w-0 flex-col items-center justify-center gap-1 rounded-control border px-0 py-2 text-center sm:px-1"
      :class="d.closed ? 'border-dashed border-line bg-soft text-ink-3' : (d.working ? 'border-ink bg-surface text-ink' : 'border-ink bg-card text-ink')"
      :title="d.name + ' — ' + (d.closed ? @js($tileWords['closed']) : (d.working ? @js($tileWords['work']) : @js($tileWords['off'])))">
    <span class="text-caption leading-tight font-semibold tracking-tight sm:text-small sm:tracking-normal">
        <span aria-hidden="true" x-text="d.short"></span>
        <span class="sr-only" x-text="d.name"></span>
    </span>
    <span class="inline-flex" x-show="d.working && !d.closed"><x-ic name="check" class="size-4" /></span>
    <span class="inline-flex" x-show="!d.working && !d.closed"><x-ic name="home" class="size-4" /></span>
    <span class="inline-flex" x-show="d.closed"><x-ic name="building" class="size-4" /></span>
    <span class="text-caption leading-none"
          x-text="d.closed ? @js($tileWords['closed']) : (d.working ? @js($tileWords['work']) : @js($tileWords['off']))"></span>
</span>
