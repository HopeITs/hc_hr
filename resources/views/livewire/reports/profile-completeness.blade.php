{{-- Which files are missing what. Presence only: nothing from the files themselves is shown. --}}
<div class="space-y-4">
    <x-slot:title>{{ __('Profile completeness') }}</x-slot:title>

    <x-page-header :title="__('Profile completeness')" :lead="__('Whose file is missing a photo, contact, education or emergency contact.')">
        <x-slot:actions>
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block text-small text-ink-2">
                <span class="mb-1 block">{{ __('City') }}</span>
                <select wire:model.live="cityId" class="w-full rounded-lg border border-line bg-card p-2 text-ink">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($cities as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-small text-ink-2">
                <span class="mb-1 block">{{ __('Missing') }}</span>
                <select wire:model.live="missing" class="w-full rounded-lg border border-line bg-card p-2 text-ink" data-testid="completeness-missing">
                    <option value="">{{ __('Anything') }}</option>
                    @foreach ($items as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="mt-2 text-small text-ink-3">{{ __('People with a contract in force today.') }}</p>
    </x-card>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-card :title="__('Average completeness')"><span class="num text-3xl font-extrabold text-ink" data-testid="completeness-average">{{ $r['averagePercent'] === null ? '-' : $r['averagePercent'].'%' }}</span></x-card>
        <x-card :title="__('Complete files')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="completeness-complete">{{ number_format($r['complete']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ __('of :n people', ['n' => $r['people']]) }}</p>
        </x-card>
        <x-card :title="__('Incomplete files')"><span class="num text-3xl font-extrabold text-ink" data-testid="completeness-incomplete">{{ number_format($r['people'] - $r['complete']) }}</span></x-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('How many have each item')"><x-report.bars :rows="$r['byItem']" /></x-card>
        <x-card :title="__('Average completeness by city (people · %)')">
            <x-report.bars :rows="$r['byCity']" />
        </x-card>
    </div>

    <x-card :title="__('Files to complete')">
        @if ($r['list']->isEmpty())
            <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
        @else
            <ul class="divide-y divide-line-soft" data-testid="completeness-list">
                @foreach ($r['list']->take($limit) as $p)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-small">
                        <span class="text-ink">
                            <a href="{{ route('employees.show', $p['username']) }}" class="underline" wire:navigate>{{ $p['name'] }}</a>
                            <span class="text-ink-3">· {{ $p['city'] }}</span>
                        </span>
                        <span class="text-ink-2"><span class="num">{{ $p['percent'] }}%</span> · {{ implode('، ', $p['missingLabels']) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($r['listTotal'] > $limit)
                <p class="mt-2 text-small text-ink-3">{{ __('Showing :a of :b. The Excel file has them all.', ['a' => $limit, 'b' => $r['listTotal']]) }}</p>
            @endif
        @endif
    </x-card>
</div>
