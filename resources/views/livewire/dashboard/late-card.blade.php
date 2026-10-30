{{--
    "Past the deadline" tile (LateCard): a red-edged strip with a ringing clock,
    so it is not missed. It folds to fit a small tile (dashboard.css). Nothing
    late: one quiet line, marked data-tile-empty so the grid closes it at the
    bottom. Every name comes from WaitingOnYou (the viewer's own scope).
--}}
@php($locale = app()->getLocale())
<div class="hc-late-tile flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($late === null)
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.late') }}</h2>
            <x-ic name="clock" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>
        <p class="hc-nt-empty" data-tile-empty>{{ __('dashboard.late-tile.none') }}</p>
    @else
        <section class="hc-lateband" role="alert" aria-label="{{ __('dashboard.tiles.late') }}" data-testid="late-band">
            <span class="hc-lateband-ico" aria-hidden="true"><x-ic name="clock" /></span>
            <span class="hc-lateband-text">
                <b>{{ collect($late['parts'])->map(fn ($count, $part) => trans_choice("dashboard.header.late.{$part}", $count, ['count' => $count]))->implode(' · ') }}</b>
                @if ($late['names'] !== [])
                    <span>{{ implode($locale === 'ar' ? '، ' : ', ', $late['names']) }}@if ($late['more']) …@endif</span>
                @endif
            </span>
            <x-button size="sm" :href="$late['oldest']['url']" data-testid="late-open-oldest">
                <x-ic name="arrow" class="shrink-0 rtl:-scale-x-100" /><span class="min-w-0 truncate">{{ __('dashboard.header.late.open') }}</span>
            </x-button>
        </section>
    @endif
</div>
