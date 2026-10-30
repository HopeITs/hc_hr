@props(['manager', 'managerType' => 'direct', 'compact' => false, 'collapsed' => [], 'trunk' => 'bg-line', 'branchBorder' => 'border-line', 'topLevel' => false])

@php
    $teamRel = $managerType.'Team';
    $team = $manager->{$teamRel};
    $pt = $topLevel ? 'pt-6' : ($compact ? 'pt-4' : 'pt-4');
    $stemH = $topLevel ? 'h-6' : ($compact ? 'h-4' : 'h-5');
@endphp

<section
    x-data="{ open: @js(!($collapsed[$manager->id] ?? true)) }"
    x-on:expand-all.window="open = true"
    x-on:collapse-all.window="open = false"
    class="relative flex flex-col items-center {{ $pt }}"
>
    <span class="absolute start-1/2 top-0 {{ $stemH }} w-px {{ $trunk }}"></span>

    <div class="rounded-surface border border-line-soft bg-card {{ $compact ? 'p-2' : 'p-3' }} shadow-e1">
        <div class="flex flex-wrap justify-center gap-2">
            @forelse($manager->activeContracts as $contract)
                <x-org-node
                    :position="$manager"
                    :employee="$contract->employee"
                    :is-manager="$team->isNotEmpty()"
                    :manager-type="$managerType"
                    :compact="$compact"
                />
            @empty
                <x-org-empty-node :position="$manager" :compact="$compact"/>
            @endforelse
        </div>

        @if($team->isNotEmpty())
            <button type="button"
                    wire:click="toggleCollapse({{ $manager->id }})"
                    x-on:click="open = !open"
                    class="mt-2 flex w-full items-center justify-between gap-2 rounded-[0.5cqw] bg-surface px-2 py-2 text-caption font-bold text-ink-2 transition hover:bg-surface hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                    :aria-expanded="open"
                    :title="open ? @js(__('general.collapse')) : @js(__('general.expand'))">
                <span>{{ \App\Models\Position::headcount($team) }} @lang('general.direct-reports')</span>
                <svg class="size-4 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>
        @else
            <div class="mt-2 rounded-[0.5cqw] bg-surface px-2 py-2 text-center text-caption font-bold text-ink-2">
                0 @lang('general.direct-reports')
            </div>
        @endif

        @if($team->isNotEmpty())
            <div x-cloak x-show="open" x-collapse>
                <div class="relative mt-4 flex items-start justify-center {{ $compact ? 'gap-2' : 'gap-4' }} border-t {{ $branchBorder }}">
                    <x-org-section :team="$team" :managerType="$managerType" :compact="$compact" :collapsed="$collapsed"/>
                </div>
            </div>
        @endif
    </div>
</section>
