{{-- Leave: annual leave still owed on contracts in force, and leave taken in the period. --}}
@php $d = fn ($v) => $v === null ? '-' : $v; @endphp
<div class="space-y-4">
    <x-slot:title>{{ __('Leave balances and usage') }}</x-slot:title>

    <x-page-header :title="__('Leave balances and usage')" :lead="__('Annual leave still owed, who is not taking leave, what was taken, and how long requests wait.')">
        <x-slot:actions>
            @foreach ($periods as $p)
                <x-button type="button" size="sm" :variant="$r['days'] === $p ? 'primary' : 'outline'" wire:click="$set('days', {{ $p }})">
                    {{ $p === 0 ? __('All time') : __(':n days', ['n' => $p]) }}
                </x-button>
            @endforeach
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <label class="block max-w-xs text-small text-ink-2">
            <span class="mb-1 block">{{ __('Department') }}</span>
            <select wire:model.live="departmentId" class="w-full rounded-lg border border-line bg-card p-2 text-ink" data-testid="leave-department">
                <option value="">{{ __('All') }}</option>
                @foreach ($departments as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <p class="mt-2 text-small text-ink-3">{{ __('Balances are as of today. The period below applies to the leave requests.') }}</p>
    </x-card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :title="__('Annual leave owed (days)')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="leave-owed">{{ $r['owedDays'] }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __('Across :n people', ['n' => $r['people']]) }} · {{ __('average :n', ['n' => $d($r['averageOwedDays'])]) }}</p>
        </x-card>
        <x-card :title="__('Not taking leave')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="leave-idle">{{ $r['idleCount'] }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __('No leave taken after :n days of service', ['n' => \App\Services\Reports\LeaveReport::IDLE_AFTER_DAYS]) }}</p>
        </x-card>
        <x-card :title="__('Leave taken in the period (days)')"><span class="num text-3xl font-extrabold text-ink" data-testid="leave-taken">{{ $r['takenDays'] }}</span></x-card>
        <x-card :title="__('Days to answer a request')">
            <span class="num text-3xl font-extrabold text-ink">{{ $d($r['daysToAnswer']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':a waiting · :b for over 3 days', ['a' => $r['pending'], 'b' => $r['pendingOverThree']]) }}</p>
        </x-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('Owed by department (days)')">
            @forelse ($r['byDepartment'] as $row)
                <div class="flex items-baseline justify-between gap-2 border-b border-line-soft py-2 text-small last:border-0">
                    <span class="text-ink">{{ $row['label'] }} <span class="text-ink-3">· {{ __(':n people', ['n' => $row['people']]) }}</span></span>
                    <span class="num text-ink-2">{{ $row['owed'] }} <span class="text-ink-3">({{ __('avg :n', ['n' => $row['average']]) }})</span></span>
                </div>
            @empty
                <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
            @endforelse
        </x-card>

        <x-card :title="__('Leave taken by type (days)')">
            <x-report.bars :rows="$r['byType']" />
        </x-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['Most leave owed', $r['mostOwed']], ['Not taking leave', $r['idle']]] as [$heading, $people])
            <x-card :title="__($heading)">
                @forelse ($people as $p)
                    <div class="flex items-baseline justify-between gap-2 border-b border-line-soft py-2 text-small last:border-0">
                        <span class="text-ink">
                            @if ($p['username'])
                                <a href="{{ route('employees.show', $p['username']) }}" class="underline" wire:navigate>{{ $p['name'] }}</a>
                            @else
                                {{ $p['name'] }}
                            @endif
                            <span class="text-ink-3">· {{ $p['department'] }} · {{ $p['city'] }}</span>
                        </span>
                        <span class="num text-ink-2">{{ $p['remaining'] }}</span>
                    </div>
                @empty
                    <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
                @endforelse
            </x-card>
        @endforeach
    </div>

    @if ($r['byMonth']->isNotEmpty())
        @php $mMax = max(0.0001, $r['byMonth']->max()); @endphp
        <x-card :title="__('Leave taken by month (days)')">
            <div class="flex h-32 items-end gap-1" dir="ltr">
                @foreach ($r['byMonth'] as $month => $value)
                    <div class="flex h-full flex-1 items-end" title="{{ $month }}: {{ $value }}">
                        <div class="w-full rounded-t bg-ink" style="height: {{ $value / $mMax * 100 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-caption text-ink-3 num" dir="ltr">
                <span>{{ $r['byMonth']->keys()->first() }}</span><span>{{ $r['byMonth']->keys()->last() }}</span>
            </div>
        </x-card>
    @endif
</div>
