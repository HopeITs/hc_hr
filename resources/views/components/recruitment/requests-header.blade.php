@props(['title', 'description', 'current'])

{{--
    The head of both recruitment request tabs.

    One component rather than a copy per tab: the two headers were written
    separately and drifted into a plain heading on one page and a dark hero on
    the other, so moving between tabs read as moving between applications. The
    tab strip below belongs to the header for the same reason — it is the same
    strip on both pages, and a second hand-written copy is how they diverge
    again.

    `current` is 'requests' or 'team'.
--}}
@php
    $crumbs = $current === 'team'
        ? [['label' => __('recruitment.navigation_section')], ['label' => __('recruitment.navigation_requests'), 'route' => 'recruitment.requests.index'], ['label' => $title]]
        : [['label' => __('recruitment.navigation_section')], ['label' => $title]];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <div class="min-w-0">
            <livewire:layout.breadcrumbs :title="$title" :crumbs="$crumbs" />
            <p class="max-w-2xl text-secondary-medium">{{ $description }}</p>
        </div>

        {{-- The primary action stays here on both tabs, so it never moves as
            the manager switches between the list and the chart. --}}
        <div class="flex flex-wrap items-center gap-3">
            @can('create', \App\Models\RecruitmentRequest::class)
                <x-primary-button :href="route('recruitment.requests.create')" class="!h-7 !py-0 !text-sm !shadow-none">
                    {{ __('recruitment.create') }}
                </x-primary-button>
            @endcan

            {{-- Its own permission, never implied by managing requests (D15).
                 The same weight as the request beside it: both start a hire, and
                 for the HR who holds this permission the direct opening is not
                 the lesser errand. --}}
            @can('directOpen', \App\Models\RecruitmentRequest::class)
                <x-primary-button :href="route('recruitment.requests.direct-open')" class="!h-7 !py-0 !text-sm !shadow-none">
                    {{ __('recruitment.direct_open.title') }}
                </x-primary-button>
            @endcan
        </div>
    </div>

    {{-- Switches page — the list, or the staffing chart — so it is an
         underlined nav of links, kept visually distinct from the pills that
         filter a list in place without leaving the page. --}}
    <nav class="flex gap-1 border-b border-neutral-200" aria-label="{{ __('recruitment.requests') }}">
        @php
            $tabs = [
                'requests' => ['route' => 'recruitment.requests.index', 'label' => __('recruitment.navigation_requests')],
                'team' => ['route' => 'recruitment.requests.team', 'label' => __('recruitment.team_staffing.tab')],
            ];
        @endphp

        @foreach ($tabs as $key => $tab)
            @if ($key === 'team' && ! auth()->user()?->can('create', \App\Models\RecruitmentRequest::class))
                @continue
            @endif

            @if ($key === $current)
                <span aria-current="page"
                      class="border-b-2 border-primary px-4 py-3 text-sm font-semibold text-primary">
                    {{ $tab['label'] }}
                </span>
            @else
                <a href="{{ route($tab['route']) }}" wire:navigate
                   class="border-b-2 border-transparent px-4 py-3 text-sm font-semibold text-secondary-medium transition hover:border-neutral-300 hover:text-secondary-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                    {{ $tab['label'] }}
                </a>
            @endif
        @endforeach
    </nav>
</div>
