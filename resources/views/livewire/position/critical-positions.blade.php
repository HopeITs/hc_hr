<x-slot:title>{{ __('critical-positions.title') }}</x-slot:title>

<div>
    <x-page-header :title="__('critical-positions.title')" :lead="__('critical-positions.lead')" />

    @if ($rows->isEmpty())
        <x-center-empty-state :title="__('critical-positions.none-title')" :description="__('critical-positions.none-help')" />
    @else
        <div class="overflow-hidden border rounded-surface border-line-soft bg-card" data-testid="critical-positions-list">
            <ul class="divide-y divide-line-soft">
                @foreach ($rows as $row)
                    @php($position = $row['position'])
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="critical-{{ $position->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $position->title }}</p>
                            <p class="text-small text-ink-2">
                                {{ ($position->city ?? $position->center?->city)?->name }}
                                @if ($position->activeContracts->isNotEmpty())
                                    · {{ $position->activeContracts->map(fn ($c) => $c->employee?->name)->filter()->implode(', ') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($row['needs'])
                                <x-tag variant="act" :label="__('critical-positions.needs-replacement')" />
                            @endif
                            <x-button variant="outline" size="sm" :href="route('positions.replacements', $position)">
                                @lang('critical-positions.suggested-replacements')
                            </x-button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
