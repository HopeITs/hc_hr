{{--
    Why people leave: the termination reason recorded on the contract of each
    person who left. Figures come from App\Services\LeavingReasonsReport.
--}}
@php
    $filterLabels = [
        'department_id' => __('Department'),
        'program_id' => __('Program'),
        'job_level_id' => __('Job level'),
        'position_id' => __('Position'),
        'contract_type_id' => __('Contract type'),
    ];
    $hasFilters = collect($filters)->filter(fn ($v) => filled($v))->isNotEmpty();
    $delta = $r['previousTotal'] === null ? null : $r['total'] - $r['previousTotal'];
@endphp
<div class="space-y-4">
    <x-slot:title>{{ __('Why people leave') }}</x-slot:title>

    <x-page-header :title="__('Why people leave')" :lead="__('The reason recorded on the contract of each person who left. One row per person.')">
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
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="leaving-filters">
            @foreach ($filterLabels as $key => $label)
                <label class="block text-small text-ink-2">
                    <span class="mb-1 block">{{ $label }}</span>
                    <select wire:model.live="filters.{{ $key }}" class="w-full rounded-lg border border-line bg-card p-2 text-ink">
                        <option value="">{{ __('All') }}</option>
                        @foreach ($options[$key] as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
        </div>
        @if ($hasFilters)
            <div class="mt-3"><x-button type="button" size="sm" variant="quiet" wire:click="clearFilters">{{ __('Clear filters') }}</x-button></div>
        @endif
    </x-card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :title="__('People who left')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="leaving-total">{{ number_format($r['total']) }}</span>
            @if ($delta !== null)
                <p class="mt-1 text-small text-ink-2" data-testid="leaving-delta">
                    @if ($delta === 0)
                        {{ __('Same as the previous period') }} (<span class="num">{{ $r['previousTotal'] }}</span>)
                    @else
                        <span class="num" dir="ltr">{{ $delta > 0 ? '+' : '' }}{{ $delta }}</span> {{ __('vs the previous period') }} (<span class="num">{{ $r['previousTotal'] }}</span>)
                    @endif
                </p>
            @endif
        </x-card>
        <x-card :title="__('Turnover rate')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="leaving-turnover">{{ $r['turnoverPercent'] === null ? '-' : $r['turnoverPercent'].'%' }}</span>
            <p class="mt-1 text-small text-ink-2">
                {{ $r['headcount'] === null ? __('Not shown for all time') : __('Of an average team of :n', ['n' => $r['headcount']]) }}
            </p>
        </x-card>
        <x-card :title="__('With a recorded reason')">
            <span class="num text-3xl font-extrabold text-ink">{{ number_format($r['recorded']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':n without a reason', ['n' => $r['missing']]) }}</p>
        </x-card>
        <x-card :title="__('Average time with us (months)')"><span class="num text-3xl font-extrabold text-ink">{{ $r['averageTenureMonths'] ?? '-' }}</span></x-card>
    </div>

    @if ($r['total'] === 0)
        <x-card><p class="text-ink-2">{{ __('Nobody left in this period.') }}</p></x-card>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ([['By category', $r['byCategory']], ['By reason', $r['byReason']], ['Time with us before leaving', $r['byTenure']], ['By city', $r['byCity']]] as [$heading, $rows])
                <x-card :title="__($heading)">
                    @forelse ($rows as $row)
                        <div class="mb-3 last:mb-0">
                            <div class="flex items-baseline justify-between gap-2 text-small">
                                <span class="text-ink">{{ $row['label'] }}</span>
                                <span class="num text-ink-2">{{ $row['count'] }} · {{ $row['percent'] }}%</span>
                            </div>
                            <div class="mt-1 h-2 rounded-full bg-surface"><div class="h-2 rounded-full bg-ink" style="width: {{ $row['percent'] }}%"></div></div>
                        </div>
                    @empty
                        <p class="text-small text-ink-3">{{ __('No reasons recorded yet.') }}</p>
                    @endforelse
                </x-card>
            @endforeach
        </div>

        @php $mMax = max(1, $r['monthly']->max()); @endphp
        <x-card :title="__('By month')">
            <div class="flex h-32 items-end gap-1" dir="ltr">
                @foreach ($r['monthly'] as $month => $count)
                    <div class="group relative flex h-full flex-1 items-end" title="{{ $month }}: {{ $count }}">
                        <div class="w-full rounded-t bg-ink" style="height: {{ $count / $mMax * 100 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-caption text-ink-3 num" dir="ltr">
                <span>{{ $r['monthly']->keys()->first() }}</span><span>{{ $r['monthly']->keys()->last() }}</span>
            </div>
        </x-card>

        @if ($r['missing'] > 0)
            <x-card :title="__('Left without a recorded reason')">
                <p class="mb-2 text-small text-ink-2">{{ __('Add the reason on the contract so these figures are complete.') }}</p>
                <ul class="divide-y divide-line-soft" data-testid="leaving-missing">
                    @foreach ($r['withoutReason'] as $row)
                        <li class="flex items-baseline justify-between gap-2 py-2 text-small">
                            <span class="text-ink">
                                @if ($canEditContracts)
                                    <a href="{{ route('contracts.edit', $row['contract_id']) }}" class="underline" wire:navigate>{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                                @if ($row['note']) <span class="text-ink-3">· {{ $row['note'] }}</span>@endif
                            </span>
                            <span class="num text-ink-2">{{ $row['left_on'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif
    @endif
</div>
