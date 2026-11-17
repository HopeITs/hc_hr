@php
    // The establishment in four numbers, four equal tiles. Every tile is
    // neutral; the only red is a small dot beside the unfilled count while
    // seats stand open.
    $tile = 'flex h-full min-h-24 flex-col justify-between gap-3 rounded-surface border bg-card px-4 py-3';
    $label = 'text-small font-medium leading-snug text-ink-2';
    $figure = 'text-h3 font-bold leading-none tabular-nums';

    $approved = (int) $summary['capacity'];
    $filledCount = (int) $summary['filled'];
    $open = (int) $summary['vacancies'];
    $pct = $approved > 0 ? min(100, max(0, (int) round($filledCount / $approved * 100))) : 0;
@endphp

<section aria-label="{{ __('recruitment.team_staffing.summary') }}" class="grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="{{ $tile }} border-line-soft">
        <p class="{{ $label }}">{{ __('recruitment.team_staffing.positions') }}</p>
        <p class="{{ $figure }} text-ink">{{ number_format($summary['positions']) }}</p>
    </div>

    <div class="{{ $tile }} border-line-soft">
        <p class="{{ $label }}">{{ __('recruitment.team_staffing.approved_headcount') }}</p>
        <p class="{{ $figure }} text-ink">{{ number_format($approved) }}</p>
    </div>

    <div class="{{ $tile }} border-line-soft">
        <p class="{{ $label }}">{{ __('recruitment.team_staffing.filled_headcount') }}</p>
        <div>
            <p class="flex items-baseline justify-between gap-2">
                <span class="{{ $figure }} text-ink">{{ number_format($filledCount) }}</span>
                <span class="text-caption font-semibold tabular-nums text-ink-2">{{ $pct }}%</span>
            </p>
            {{-- Filled against approved: the track is the approved headcount, the ink fill what is held. --}}
            <div role="meter" aria-valuemin="0" aria-valuemax="{{ $approved }}" aria-valuenow="{{ min($filledCount, $approved) }}"
                 aria-valuetext="{{ __('recruitment.team_staffing.seats_label', ['filled' => $filledCount, 'capacity' => $approved]) }} ({{ $pct }}%)"
                 aria-label="{{ __('recruitment.team_staffing.filled_headcount') }}"
                 class="mt-2 h-1 overflow-hidden rounded-full bg-surface">
                <span class="block h-full rounded-full bg-ink-2 transition-[width] duration-300 motion-reduce:transition-none" style="width: {{ $pct }}%"></span>
            </div>
        </div>
    </div>

    {{-- Doubles as the shortcut into "Unfilled only". Pressed = ink outline,
         like every toggle; the red is the dot alone. --}}
    <button type="button" wire:click="toggleVacantOnly" aria-pressed="{{ $vacantOnly ? 'true' : 'false' }}"
            @disabled($open === 0 && ! $vacantOnly)
            @class([
                $tile,
                'text-start transition-colors duration-150 motion-reduce:transition-none disabled:cursor-default',
                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink',
                'border-ink ring-1 ring-ink' => $vacantOnly,
                'cursor-pointer border-line-soft hover:border-line hover:bg-soft' => ! $vacantOnly && $open > 0,
                'border-line-soft' => ! $vacantOnly && $open === 0,
            ])>
        <span class="{{ $label }} flex items-center justify-between gap-2">
            {{ __('recruitment.team_staffing.open_capacity') }}
            @if ($vacantOnly)
                <x-ic name="check" class="size-4 shrink-0 text-ink" />
            @endif
        </span>
        <span class="flex items-end justify-between gap-2">
            <span @class([$figure, 'flex items-center gap-2', 'text-ink' => $open > 0, 'text-ink-3' => $open === 0])>
                @if ($open > 0)<span class="size-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>@endif
                {{ number_format($open) }}
            </span>
            @if ($open > 0 || $vacantOnly)
                <span class="inline-flex items-center gap-1 text-caption font-semibold text-ink-2">
                    {{ $vacantOnly ? __('recruitment.team_staffing.show_all') : __('recruitment.team_staffing.show_unfilled') }}
                    <x-ic name="chevron" class="size-3 shrink-0 rtl:-scale-x-100" />
                </span>
            @endif
        </span>
    </button>
</section>
