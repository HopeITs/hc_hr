@php
    $daily = $r['daily']->values();
    $dMax = max(1, $daily->max('actions'));
    $n = max(1, $daily->count() - 1);
    $pt = fn ($d, $i) => round($i / $n * 600, 1).','.round(100 - $d['actions'] / $dMax * 96, 1);
    $points = $daily->map($pt)->implode(' ');
    $area = '0,100 '.$points.' 600,100';
    $hours = $r['hours'];
    $hMax = max(1, $hours->max('actions'));
    $peakHour = $hours->sortByDesc('actions')->first();
    $cityTotal = max(1, $r['cities']->sum('actions'));
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

    {{-- Daily trend: area + line, y-axis ticks, hover for the exact day. --}}
    <x-card :title="__('Daily activity')">
        <div class="flex gap-2" dir="ltr">
            <div class="flex h-40 flex-col justify-between text-end text-caption text-ink-3 num">
                <span>{{ $dMax }}</span><span>{{ round($dMax / 2) }}</span><span>0</span>
            </div>
            <div class="relative h-40 flex-1">
                <svg viewBox="0 0 600 100" preserveAspectRatio="none" class="absolute inset-0 h-full w-full text-ink" role="img" aria-label="{{ __('Daily activity') }}">
                    @foreach ([4, 52, 100] as $y)
                        <line x1="0" y1="{{ $y }}" x2="600" y2="{{ $y }}" stroke="currentColor" stroke-opacity=".12" vector-effect="non-scaling-stroke" @if ($y !== 100) stroke-dasharray="3 4" @endif />
                    @endforeach
                    <polygon points="{{ $area }}" fill="currentColor" fill-opacity=".08" />
                    <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                <div class="absolute inset-0 flex">
                    @foreach ($daily as $d)
                        <div class="group relative h-full flex-1">
                            <div class="absolute inset-y-0 start-1/2 hidden w-px bg-ink/30 group-hover:block"></div>
                            <div class="absolute start-1/2 hidden size-2.5 -translate-x-1/2 rounded-full bg-ink ring-2 ring-card group-hover:block"
                                 style="top: calc({{ 100 - $d['actions'] / $dMax * 96 }}% - 5px)"></div>
                            <div class="pointer-events-none absolute -top-1 start-1/2 z-10 hidden -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-ink px-2 py-1 text-caption text-on-ink group-hover:block">
                                <span class="num">{{ $d['date'] }}</span> · <b class="num">{{ $d['actions'] }}</b>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="mt-1 flex justify-between ps-8 text-caption text-ink-3" dir="ltr">
            <span class="num">{{ $daily->first()['date'] }}</span>
            <span class="num">{{ $daily->last()['date'] }}</span>
        </div>
    </x-card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('Top 5 most active users')">
            @php $uMax = max(1, collect($r['topUsers'])->max('actions') ?? 1); @endphp
            @forelse ($r['topUsers'] as $i => $row)
                <div class="mb-3 last:mb-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-ink"><span class="num text-ink-3">{{ $i + 1 }}.</span> {{ $row['name'] }}</span>
                        <span class="num font-extrabold text-ink">{{ number_format($row['actions']) }}</span>
                    </div>
                    <div class="mt-1 h-2 rounded-full bg-surface">
                        <div class="h-2 rounded-full bg-ink" style="width: {{ round($row['actions'] / $uMax * 100) }}%"></div>
                    </div>
                    @if ($row['last'])
                        <div class="mt-0.5 text-caption text-ink-3">{{ __('Last action') }}: {{ \Illuminate\Support\Carbon::parse($row['last'])->diffForHumans() }}</div>
                    @endif
                </div>
            @empty
                <p class="text-small text-ink-3">{{ __('No activity in this period.') }}</p>
            @endforelse
        </x-card>

        <x-card :title="__('Activity by city')">
            @php $cMax = max(1, $r['cities']->max('actions') ?? 1); @endphp
            @forelse ($r['cities'] as $c)
                <div class="mb-3 last:mb-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="truncate text-ink">{{ $c['name'] }}</span>
                        <span class="num text-small text-ink-2">
                            {{ __(':n users', ['n' => $c['users']]) }} ·
                            <b class="font-extrabold text-ink">{{ number_format($c['actions']) }}</b>
                            <span class="text-ink-3">({{ round($c['actions'] / $cityTotal * 100) }}%)</span>
                        </span>
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

    {{-- Hours: the busiest hour is solid ink, the rest softer; hover shows the count. --}}
    <x-card :title="__('Busiest hours of the day')">
        <div class="flex gap-2" dir="ltr">
            <div class="flex h-36 flex-col justify-between text-end text-caption text-ink-3 num">
                <span>{{ $hMax }}</span><span>0</span>
            </div>
            <div class="flex h-36 flex-1 items-end gap-0.5 border-b border-line-soft">
                @foreach ($hours as $h)
                    @php $isPeak = $peakHour && $h['hour'] === $peakHour['hour'] && $h['actions'] > 0; @endphp
                    <div class="group relative flex h-full flex-1 items-end">
                        <div class="w-full rounded-t {{ $isPeak ? 'bg-ink' : 'bg-ink/35' }} group-hover:bg-ink"
                             style="height: {{ $h['actions'] ? max(3, round($h['actions'] / $hMax * 100)) : 0 }}%"></div>
                        <div class="pointer-events-none absolute start-1/2 top-0 z-10 hidden -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-ink px-2 py-1 text-caption text-on-ink group-hover:block">
                            <span class="num">{{ sprintf('%02d:00', $h['hour']) }}</span> · <b class="num">{{ $h['actions'] }}</b>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="mt-1 flex justify-between ps-8 text-caption text-ink-3" dir="ltr">
            @foreach ([0, 3, 6, 9, 12, 15, 18, 21] as $t)
                <span class="num">{{ sprintf('%02d', $t) }}</span>
            @endforeach
            <span class="num">23</span>
        </div>
        @if ($peakHour && $peakHour['actions'] > 0)
            <p class="mt-2 text-small text-ink-2">{{ __('Peak hour') }}: <b class="num text-ink">{{ sprintf('%02d:00', $peakHour['hour']) }}</b> ({{ number_format($peakHour['actions']) }})</p>
        @endif
    </x-card>

    {{-- Collapsed by default: the list can be long. --}}
    <x-card>
        <details class="group">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                <span class="text-h4 font-extrabold text-ink">
                    {{ __('Users with no activity') }}
                    <span class="num ms-1 text-ink-3">({{ $r['inactive']->count() }})</span>
                </span>
                <span class="text-ink-3 transition group-open:rotate-180" aria-hidden="true">▾</span>
            </summary>
            <div class="mt-3">
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
            </div>
        </details>
    </x-card>
</div>
