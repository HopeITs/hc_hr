@props(['rows', 'empty' => null])
{{-- A list of horizontal bars: each row is ['label', 'count', 'percent'] (percent drives the bar). --}}
@forelse ($rows as $row)
    <div class="mb-3 last:mb-0">
        <div class="flex items-baseline justify-between gap-2 text-small">
            <span class="text-ink">{{ $row['label'] }}</span>
            <span class="num text-ink-2">{{ $row['count'] }}@isset($row['percent']) · {{ $row['percent'] }}%@endisset</span>
        </div>
        <div class="mt-1 h-2 rounded-full bg-surface"><div class="h-2 rounded-full bg-ink" style="width: {{ min(100, max(0, $row['percent'] ?? 0)) }}%"></div></div>
    </div>
@empty
    <p class="text-small text-ink-3">{{ $empty ?? __('Nothing to show for this selection.') }}</p>
@endforelse
