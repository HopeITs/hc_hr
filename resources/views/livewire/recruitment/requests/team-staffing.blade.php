@php
    $compact = $density === 'compact';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $card = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $eyebrow = 'text-caption font-semibold ltr:uppercase ltr:tracking-wider text-secondary-medium';
    $segment = fn (bool $on) => 'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-inset px-3 text-small transition duration-150 motion-reduce:transition-none sm:min-h-9 '.$focus.' '
        .($on ? 'bg-white font-semibold text-secondary-dark shadow-e1' : 'text-secondary-medium hover:text-secondary-dark');
    $fillPct = $summary['capacity'] > 0 ? (int) round($summary['filled'] / $summary['capacity'] * 100) : 0;
@endphp

<x-slot:title>{{ __('recruitment.team_staffing.title') }}</x-slot:title>

<div class="space-y-6 pb-8">
    <x-recruitment.requests-header
        current="team"
        :title="__('recruitment.team_staffing.title')" />

    @if ($root)
        {{-- The establishment in four numbers. Red appears only when seats stand open: the one figure this page exists to find. --}}
        <section aria-label="{{ __('recruitment.team_staffing.summary') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="{{ $card }} px-5 py-4">
                <p class="{{ $eyebrow }}">{{ __('recruitment.team_staffing.positions') }}</p>
                <p class="mt-2 text-h2 font-semibold tabular-nums text-secondary-dark">{{ number_format($summary['positions']) }}</p>
            </div>
            <div class="{{ $card }} px-5 py-4">
                <p class="{{ $eyebrow }}">{{ __('recruitment.team_staffing.approved_headcount') }}</p>
                <p class="mt-2 text-h2 font-semibold tabular-nums text-secondary-dark">{{ number_format($summary['capacity']) }}</p>
            </div>
            <div class="{{ $card }} px-5 py-4">
                <p class="{{ $eyebrow }}">{{ __('recruitment.team_staffing.filled_headcount') }}</p>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="text-h2 font-semibold tabular-nums text-secondary-dark">{{ number_format($summary['filled']) }}</span>
                    <span class="text-small tabular-nums text-secondary-medium">{{ $fillPct }}%</span>
                </p>
                <span aria-hidden="true" class="mt-2 block h-1.5 overflow-hidden rounded-full bg-neutral-100">
                    <span class="block h-full rounded-full bg-secondary-dark" style="width: {{ min(100, max(0, $fillPct)) }}%"></span>
                </span>
            </div>
            {{-- Doubles as the shortcut into "Unfilled only". --}}
            <button type="button" wire:click="toggleVacantOnly" aria-pressed="{{ $vacantOnly ? 'true' : 'false' }}"
                    @disabled($summary['vacancies'] === 0 && ! $vacantOnly)
                    @class([
                        'rounded-surface border px-5 py-4 text-start shadow-e1 transition duration-150 motion-reduce:transition-none disabled:cursor-default',
                        $focus,
                        'border-neutral-300 bg-selection' => $vacantOnly,
                        'cursor-pointer border-neutral-200 bg-white hover:border-neutral-300' => ! $vacantOnly,
                    ])>
                <span class="{{ $eyebrow }} block">{{ __('recruitment.team_staffing.open_capacity') }}</span>
                <span class="mt-2 flex items-center gap-2 text-h2 font-semibold tabular-nums {{ $summary['vacancies'] > 0 ? 'text-primary' : 'text-secondary-light' }}">
                    @if ($summary['vacancies'] > 0)<span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>@endif
                    {{ number_format($summary['vacancies']) }}
                </span>
                @if ($summary['vacancies'] > 0)
                    <span class="mt-1 block text-caption text-secondary-medium">{{ $vacantOnly ? __('recruitment.team_staffing.show_all') : __('recruitment.team_staffing.show_unfilled') }}</span>
                @endif
            </button>
        </section>

        <section class="isolate {{ $card }}">
            {{-- One toolbar row: what the cards show and how much of the tree, then the camera on the far side. --}}
            <div class="sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3 rounded-t-surface border-b border-neutral-100 bg-white/95 px-4 py-2.5 backdrop-blur">
                <div class="flex flex-wrap items-center gap-3">
                    <div role="group" aria-label="{{ __('recruitment.team_staffing.view_label') }}" class="inline-flex rounded-control bg-neutral-100 p-1">
                        @foreach (['staff' => 'users', 'positions' => 'squares-2x2'] as $mode => $icon)
                            <button type="button" wire:click="setView('{{ $mode }}')" aria-pressed="{{ $view === $mode ? 'true' : 'false' }}" class="{{ $segment($view === $mode) }}">
                                <flux:icon :name="$icon" variant="micro" class="size-4" aria-hidden="true" />
                                {{ __('recruitment.team_staffing.views.'.$mode) }}
                            </button>
                        @endforeach
                    </div>

                    {{-- A real switch: it is on/off, not an action, and it stays grey so it does not compete with open seats. --}}
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control px-2 text-small font-medium text-secondary-dark sm:min-h-9 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
                        <input type="checkbox" role="switch" class="peer sr-only" wire:click="toggleVacantOnly" @checked($vacantOnly)>
                        <span aria-hidden="true" class="relative h-5 w-9 shrink-0 rounded-full bg-neutral-300 transition-colors duration-150 peer-checked:bg-secondary-dark motion-reduce:transition-none
                                     after:absolute after:top-0.5 after:start-0.5 after:size-4 after:rounded-full after:bg-white after:shadow-e1 after:transition-all after:duration-150 peer-checked:after:start-4.5 motion-reduce:after:transition-none"></span>
                        {{ __('recruitment.team_staffing.vacant_only') }}
                        @if ($vacantOnly && $hidden > 0)
                            <span class="text-caption font-normal text-secondary-medium">· {{ __('recruitment.team_staffing.hidden_positions', ['count' => $hidden]) }}</span>
                        @endif
                    </label>
                </div>

                <div class="flex items-center gap-1">
                    <button type="button" wire:click="expandAll" class="{{ $segment(false) }} border border-neutral-200 bg-white" title="{{ __('general.expand-all') }}">
                        {{ __('general.expand-all') }}
                    </button>
                    <button type="button" wire:click="collapseAll" class="{{ $segment(false) }} border border-neutral-200 bg-white" title="{{ __('general.collapse-all') }}">
                        {{ __('general.collapse-all') }}
                    </button>
                </div>
            </div>

            <div class="relative">
                <div wire:loading.flex wire:target="toggleVacantOnly,setView,expandAll,collapseAll,toggle"
                     class="absolute inset-0 z-30 items-center justify-center bg-white/75 backdrop-blur-[1px]">
                    <div class="flex items-center gap-2 rounded-control bg-white px-4 py-3 text-small font-semibold text-secondary-dark shadow-e2">
                        <x-icons.spinner class="size-5 animate-spin text-secondary-medium" />
                        {{ __('general.updating-chart') }}
                    </div>
                </div>

                <div class="overflow-x-auto p-4 sm:p-8">
                    <div class="flex min-w-fit flex-col items-center">
                        @include('livewire.recruitment.requests.partials.team-staffing-node', [
                            'node' => $root,
                            'compact' => $compact,
                            'view' => $view,
                        ])
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-b-surface border-t border-neutral-100 bg-neutral-50 px-4 py-3 text-caption text-secondary-medium">
                <p class="flex items-start gap-2 max-w-2xl">
                    <flux:icon.information-circle variant="micro" class="mt-px size-4 shrink-0" aria-hidden="true" />
                    {{ __('recruitment.team_staffing.tree_help') }}
                </p>
                {{-- Key for the seat dots. --}}
                <p class="flex items-center gap-4" aria-hidden="true">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-secondary-dark"></span>{{ __('recruitment.team_staffing.capacity') }}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full border border-dashed border-primary"></span>{{ __('recruitment.team_staffing.open_capacity') }}</span>
                </p>
            </div>
        </section>
    @else
        <section class="{{ $card }} px-6 py-16 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full border border-neutral-200 bg-neutral-50 text-secondary-dark" aria-hidden="true">
                <x-icons.code-fork class="size-6" />
            </span>
            <h2 class="mt-4 text-h4 font-semibold text-secondary-dark">{{ __('recruitment.team_staffing.no_position') }}</h2>
            <p class="mx-auto mt-2 max-w-sm text-body text-secondary-medium text-pretty">{{ __('recruitment.team_staffing.no_position_help') }}</p>
        </section>
    @endif

    <livewire:position.profile-drawer />
</div>
