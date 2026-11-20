@php
    $daily = $r['daily'];
    $dMax = max(1, $daily->max('actions'));
    $n = max(1, $daily->count() - 1);
    $points = $daily->values()->map(fn ($d, $i) => round($i / $n * 600, 1).','.round(110 - $d['actions'] / $dMax * 100, 1))->implode(' ');
    $hMax = max(1, $r['hours']->max('actions'));
    $inactiveShown = $r['inactive']->take($r['inactiveLimit']);
@endphp
<div class="mb-6 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-h4 font-extrabold text-ink">{{ __('Activity report') }}</h2>
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($periods as $p)
                <x-button type="button" size="sm" :variant="$r['days'] === $p ? 'primary' : 'outline'" wire:click="$set('days', {{ $p }})">
                    {{ __(':n days', ['n' => $p]) }}
                </x-button>
            @endforeach
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card :title="__('Total actions')"><span class="num text-3xl font-extrabold text-ink">{{ number_format($r['total']) }}</span></x-card>
        <x-card :title="__('Active users')"><span class="num text-3xl font-extrabold text-ink">{{ number_format($r['activeUsers']) }}</span></x-card>
        <x-card :title="__('Users with no activity')"><span class="num text-3xl font-extrabold text-ink">{{ number_format($r['inactive']->count()) }}</span></x-card>
    </div>

    <x-card :title="__('Daily activity')">
        <svg viewBox="0 0 600 120" preserveAspectRatio="none" class="h-32 w-full text-ink" role="img" aria-label="{{ __('Daily activity') }}">
            <line x1="0" y1="110" x2="600" y2="110" stroke="currentColor" stroke-opacity=".2" />
            <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
        </svg>
        <div class="mt-1 flex justify-between text-caption text-ink-3">
            <span class="num">{{ $daily->first()['date'] }}</span>
            <span>{{ __('Peak') }}: <span class="num">{{ $dMax }}</span></span>
            <span class="num">{{ $daily->last()['date'] }}</span>
        </div>
    </x-card>

    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ([
            ['title' => __('Top 5 most active users'), 'rows' => $r['topUsers']],
            ['title' => __('Top 5 most used modules'), 'rows' => $r['topModules']],
            ['title' => __('Top 5 actions'), 'rows' => $r['topEvents']],
        ] as $block)
            @php $max = max(1, collect($block['rows'])->max('actions') ?? 1); @endphp
            <x-card :title="$block['title']">
                @forelse ($block['rows'] as $i => $row)
                    <div class="mb-3 last:mb-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-ink"><span class="num text-ink-3">{{ $i + 1 }}.</span> {{ $row['name'] }}</span>
                            <span class="num font-extrabold text-ink">{{ number_format($row['actions']) }}</span>
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-surface">
                            <div class="h-2 rounded-full bg-ink" style="width: {{ round($row['actions'] / $max * 100) }}%"></div>
                        </div>
                        @if ($row['last'])
                            <div class="mt-0.5 text-caption text-ink-3">{{ __('Last action') }}: {{ \Illuminate\Support\Carbon::parse($row['last'])->diffForHumans() }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-small text-ink-3">{{ __('No activity in this period.') }}</p>
                @endforelse
            </x-card>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('Busiest hours of the day')">
            <div class="flex h-32 items-end gap-0.5" dir="ltr">
                @foreach ($r['hours'] as $h)
                    <div class="flex-1 rounded-t bg-ink" title="{{ sprintf('%02d:00', $h['hour']) }} — {{ $h['actions'] }}"
                         style="height: {{ $h['actions'] ? max(3, round($h['actions'] / $hMax * 100)) : 0 }}%"></div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-caption text-ink-3" dir="ltr">
                <span class="num">00</span><span class="num">06</span><span class="num">12</span><span class="num">18</span><span class="num">23</span>
            </div>
        </x-card>

        <x-card :title="__('Activity by city')">
            @php $cMax = max(1, $r['cities']->max('actions') ?? 1); @endphp
            @forelse ($r['cities'] as $c)
                <div class="mb-3 last:mb-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-ink">{{ $c['name'] }}</span>
                        <span class="num text-small text-ink-2">{{ __(':n users', ['n' => $c['users']]) }} · <b class="font-extrabold text-ink">{{ number_format($c['actions']) }}</b></span>
                    </div>
                    <div class="mt-1 h-2 rounded-full bg-surface">
                        <div class="h-2 rounded-full bg-ink" style="width: {{ round($c['actions'] / $cMax * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-small text-ink-3">{{ __('No activity in this period.') }}</p>
            @endforelse
        </x-card>
    </div>

    <x-card :title="__('Users with no activity')">
        @forelse ($inactiveShown as $u)
            <div class="flex items-baseline justify-between gap-2 border-b border-line-soft py-1.5 last:border-0">
                <span class="truncate text-ink">{{ $u['name'] }}</span>
                <span class="text-caption text-ink-3">{{ __('Last seen') }}: {{ $u['last_seen'] ? $u['last_seen']->diffForHumans() : __('Never') }}</span>
            </div>
        @empty
            <p class="text-small text-ink-3">{{ __('Every user was active in this period.') }}</p>
        @endforelse
        @if ($r['inactive']->count() > $inactiveShown->count())
            <p class="mt-2 text-caption text-ink-3">{{ __('Showing :n of :total. The full list is in the Excel export.', ['n' => $inactiveShown->count(), 'total' => $r['inactive']->count()]) }}</p>
        @endif
    </x-card>
</div>
