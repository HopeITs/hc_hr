@php($compact = $density === 'compact')

<x-slot:title>{{ __('recruitment.team_staffing.title') }}</x-slot:title>

<div class="space-y-6 pb-8">
    <x-recruitment.requests-header
        current="team"
        :title="__('recruitment.team_staffing.title')"
        :description="__('recruitment.team_staffing.description')" />

    <section class="isolate overflow-hidden rounded-surface border border-neutral-200 bg-white shadow-e1">
        @if ($root)
            {{-- ---------- Toolbar ----------
                 Two rows on purpose. The top row decides what the chart is
                 about — which face, how much of it, how dense — and the bottom
                 row only moves the camera. Mixing them put "show me the posts
                 that are short" beside "zoom in", which are not the same kind
                 of decision. --}}
            <div class="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 backdrop-blur">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                    {{-- The two faces of the same tree. A segmented control
                         rather than tabs: it changes what the cards say, not
                         which page you are on, and the page already has a tab
                         strip above that does the other thing. --}}
                    <div class="flex items-center rounded-control border border-neutral-200 bg-neutral-50 p-0.5"
                         role="group" aria-label="{{ __('recruitment.team_staffing.view_label') }}">
                        @foreach (['staff', 'positions'] as $mode)
                            <button type="button" wire:click="setView('{{ $mode }}')"
                                    aria-pressed="{{ $view === $mode ? 'true' : 'false' }}"
                                    class="rounded-inset px-3 py-1.5 text-small font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1
                                           {{ $view === $mode
                                               ? 'bg-white text-secondary-dark shadow-e1'
                                               : 'text-secondary-medium hover:text-secondary-dark' }}">
                                {{ __('recruitment.team_staffing.views.'.$mode) }}
                            </button>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Goes solid while on, so the chart never silently
                             omits cards without saying it is doing so. --}}
                        <button type="button" wire:click="toggleVacantOnly"
                                aria-pressed="{{ $vacantOnly ? 'true' : 'false' }}"
                                class="flex items-center gap-2 rounded-control border px-3 py-1.5 text-small font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1
                                       {{ $vacantOnly
                                           ? 'border-primary bg-primary text-white hover:bg-primary-hover'
                                           : 'border-neutral-200 bg-white text-secondary-medium hover:border-secondary-light hover:bg-secondary-soft hover:text-primary' }}">
                            {{ __('recruitment.team_staffing.vacant_only') }}
                            @if ($summary['vacancies'] > 0)
                                <span class="flex min-w-5 items-center justify-center rounded-full px-1 text-caption font-bold tabular-nums
                                             {{ $vacantOnly ? 'bg-white text-primary' : 'bg-primary text-white' }}">
                                    {{ $summary['vacancies'] }}
                                </span>
                            @endif
                        </button>

                        <button type="button" wire:click="toggleDensity"
                                class="rounded-control border border-neutral-200 bg-white px-3 py-1.5 text-small font-semibold text-secondary-medium transition hover:border-secondary-light hover:bg-secondary-soft hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1">
                            {{ $compact ? __('general.comfortable-view') : __('general.compact-view') }}
                        </button>
                    </div>
                </div>

                {{-- ---------- Reading ---------- --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-100 px-4 py-2">
                    {{-- The establishment in one line. Not a row of tiles: these
                         are four numbers a manager glances at, not the subject
                         of the page. --}}
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-secondary-light">
                        @foreach ([
                            ['value' => $summary['positions'], 'label' => __('recruitment.team_staffing.positions'), 'accent' => false],
                            ['value' => $summary['capacity'], 'label' => __('recruitment.team_staffing.approved_headcount'), 'accent' => false],
                            ['value' => $summary['filled'], 'label' => __('recruitment.team_staffing.filled_headcount'), 'accent' => false],
                            ['value' => $summary['vacancies'], 'label' => __('recruitment.team_staffing.open_capacity'), 'accent' => true],
                        ] as $index => $stat)
                            @if ($index > 0)
                                <span aria-hidden="true" class="text-neutral-300">·</span>
                            @endif
                            <span class="{{ $stat['accent'] && $stat['value'] > 0 ? 'font-semibold text-primary' : '' }}">
                                <span class="font-semibold tabular-nums {{ $stat['accent'] && $stat['value'] > 0 ? '' : 'text-secondary-dark' }}">{{ number_format($stat['value']) }}</span>
                                {{ $stat['label'] }}
                            </span>
                        @endforeach

                        @if ($vacantOnly && $hidden > 0)
                            <span aria-hidden="true" class="text-neutral-300">·</span>
                            <span class="font-medium">{{ __('recruitment.team_staffing.hidden_positions', ['count' => $hidden]) }}</span>
                        @endif
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ([
                            ['action' => 'expandAll', 'label' => __('general.expand-all'), 'dispatch' => 'staffing-expand-all'],
                            ['action' => 'collapseAll', 'label' => __('general.collapse-all'), 'dispatch' => 'staffing-collapse-all'],
                        ] as $control)
                            <button type="button" wire:click="{{ $control['action'] }}"
                                    @if ($control['dispatch']) x-on:click="$dispatch('{{ $control['dispatch'] }}')" @endif
                                    class="rounded-control border border-neutral-200 bg-white px-2.5 py-1.5 text-caption font-semibold text-secondary-medium transition hover:border-secondary-light hover:bg-secondary-soft hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1">
                                {{ $control['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ---------- Canvas ---------- --}}
            <div class="relative">
                <div wire:loading.flex wire:target="toggleVacantOnly,toggleDensity,setView,expandAll,collapseAll"
                     class="absolute inset-0 z-30 items-center justify-center bg-white/75 backdrop-blur-[1px]">
                    <div class="flex items-center gap-2 rounded-surface border border-neutral-200 bg-white px-4 py-3 text-body font-semibold text-secondary-medium shadow-e3">
                        <x-icons.spinner class="size-5 animate-spin text-primary" />
                        {{ __('general.updating-chart') }}
                    </div>
                </div>

                <div class="p-4 sm:p-6">
                    <div class="flex flex-col items-center">
                        @include('livewire.recruitment.requests.partials.team-staffing-node', [
                            'node' => $root,
                            'compact' => $compact,
                            'view' => $view,
                        ])
                    </div>
                </div>
            </div>

            <p class="border-t border-neutral-200 bg-neutral-50 px-4 py-3 text-caption leading-relaxed text-secondary-light">
                {{ __('recruitment.team_staffing.tree_help') }}
            </p>
        @else
            <div class="px-6 py-16 text-center">
                <span class="mx-auto flex size-14 items-center justify-center rounded-surface bg-secondary-soft text-primary">
                    <x-icons.code-fork class="size-7" />
                </span>
                <h2 class="mt-4 text-h4 font-semibold text-secondary-dark">
                    {{ __('recruitment.team_staffing.no_position') }}
                </h2>
                <p class="mx-auto mt-2 max-w-sm text-body leading-relaxed text-secondary-light">
                    {{ __('recruitment.team_staffing.no_position_help') }}
                </p>
            </div>
        @endif
    </section>

    {{-- The people view uses the organisation chart's own cards, so clicking one
         opens the same employee profile it opens there. --}}
    <livewire:position.profile-drawer />
</div>
