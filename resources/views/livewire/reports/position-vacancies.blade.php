{{-- Position capacity against people in post (positions.max_emp / exist_emp). --}}
@php
    $filterLabels = ['city_id' => __('City'), 'department_id' => __('Department'), 'program_id' => __('Program')];
    $hasFilters = collect($filters)->filter(fn ($v) => filled($v) && $v !== false)->isNotEmpty();
    $states = ['all' => __('All'), 'vacant' => __('With a vacancy'), 'empty' => __('Nobody in post'), 'full' => __('Full'), 'over' => __('Over capacity')];
@endphp
<div class="space-y-4">
    <x-slot:title>{{ __('Position vacancies') }}</x-slot:title>

    <x-page-header :title="__('Position vacancies')" :lead="__('Capacity against the people in post, by city and department, with the critical positions.')">
        <x-slot:actions>
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="vacancy-filters">
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
            <label class="block text-small text-ink-2">
                <span class="mb-1 block">{{ __('Show') }}</span>
                <select wire:model.live="filters.state" class="w-full rounded-lg border border-line bg-card p-2 text-ink">
                    @foreach ($states as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-end gap-2 pb-2 text-small text-ink-2">
                <input type="checkbox" wire:model.live="filters.critical" class="size-4 rounded border-line">
                {{ __('Critical positions only') }}
            </label>
        </div>
        @if ($hasFilters)
            <div class="mt-3"><x-button type="button" size="sm" variant="quiet" wire:click="clearFilters">{{ __('Clear filters') }}</x-button></div>
        @endif
    </x-card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :title="__('Positions')"><span class="num text-3xl font-extrabold text-ink" data-testid="vacancy-positions">{{ number_format($r['positions']) }}</span></x-card>
        <x-card :title="__('Vacant seats')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="vacancy-seats">{{ number_format($r['vacantSeats']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':a in post of :b', ['a' => $r['filled'], 'b' => $r['capacity']]) }}</p>
        </x-card>
        <x-card :title="__('Filled')"><span class="num text-3xl font-extrabold text-ink" data-testid="vacancy-fill">{{ $r['fillPercent'] === null ? '-' : $r['fillPercent'].'%' }}</span></x-card>
        <x-card :title="__('Critical positions with a vacancy')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="vacancy-critical">{{ $r['criticalVacant'] }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __(':a with nobody in post · :b over capacity', ['a' => $r['emptyPositions'], 'b' => $r['overPositions']]) }}</p>
        </x-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['By city', $r['byCity']], ['By department', $r['byDepartment']]] as [$heading, $groups])
            <x-card :title="__($heading)">
                @forelse ($groups as $g)
                    <div class="mb-3 last:mb-0">
                        <div class="flex items-baseline justify-between gap-2 text-small">
                            <span class="text-ink">{{ $g['label'] }}</span>
                            <span class="num text-ink-2">{{ $g['filled'] }} / {{ $g['capacity'] }} · {{ __(':n vacant', ['n' => $g['vacant']]) }}</span>
                        </div>
                        <div class="mt-1 h-2 rounded-full bg-surface"><div class="h-2 rounded-full bg-ink" style="width: {{ min(100, $g['percent'] ?? 0) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
                @endforelse
            </x-card>
        @endforeach
    </div>

    <x-card :title="__('Positions')">
        @if ($r['list']->isEmpty())
            <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-small" data-testid="vacancy-list">
                    <thead class="text-ink-2">
                        <tr>
                            <th scope="col" class="py-2 text-start">{{ __('Position') }}</th>
                            <th scope="col" class="py-2 text-start">{{ __('City') }}</th>
                            <th scope="col" class="py-2 text-start">{{ __('Department') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('In post') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Capacity') }}</th>
                            <th scope="col" class="py-2 text-end">{{ __('Vacant') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line-soft">
                        @foreach ($r['list']->take($limit) as $p)
                            <tr>
                                <td class="py-2 text-ink">{{ $p['title'] }} @if ($p['critical'])<x-tag variant="act">{{ __('Critical') }}</x-tag>@endif</td>
                                <td class="py-2 text-ink-2">{{ $p['city'] }}</td>
                                <td class="py-2 text-ink-2">{{ $p['department'] }}</td>
                                <td class="num py-2 text-end">{{ $p['filled'] }}</td>
                                <td class="num py-2 text-end">{{ $p['capacity'] }}</td>
                                <td class="num py-2 text-end">{{ $p['over'] > 0 ? '+'.$p['over'].' '.__('over') : $p['vacant'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($r['listTotal'] > $limit)
                <p class="mt-2 text-small text-ink-3">{{ __('Showing :a of :b. The Excel file has them all.', ['a' => $limit, 'b' => $r['listTotal']]) }}</p>
            @endif
        @endif
    </x-card>
</div>
