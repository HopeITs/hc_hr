@php
    $locale = app()->getLocale();
    $titleField = 'title_' . $locale;
@endphp

<div class="relative flex h-full min-h-64 w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">

    <div class="flex items-center gap-2 px-6 pt-6 pb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium">
            @lang('general.no-leaves-employees')
        </h2>
        <span class="text-secondary-light sm:text-h4 md:text-body font-medium">
                @unless ($loadFailed){{ $noLeavesEmployees->count() }}@endunless
            </span>
    </div>

    <div class="relative px-6 pb-6 pt-2 space-y-3 overflow-y-auto flex-grow
                scrollbar-thin scrollbar-thumb-[var(--color-neutral-300)] scrollbar-track-transparent
                hover:scrollbar-thumb-[var(--color-secondary-medium)] max-h-[440px] ">

        @if ($loadFailed)
            <x-ui.load-error class="min-h-40" />
        @else
        @forelse ($noLeavesEmployees as $emp)
            <div class="flex items-center justify-between py-4 border-b border-[var(--color-neutral-200)] last:border-0">

                <div class="flex items-center gap-3">

                    <div class="flex items-center justify-center h-11 w-11 flex-shrink-0 rounded-full overflow-hidden
                                bg-[var(--color-primary)] text-white font-semibold uppercase shadow-e1">


                            <img src="{{ $emp->avatar_url }}"
                                 alt="{{ $emp->name }}"
                                 class="h-full w-full object-cover">


                    </div>

                    <div class="min-w-0">
                        <p class="text-body font-semibold text-[var(--color-neutral-800)] truncate">
                            {{ $emp->name }}
                        </p>

                        <p class="text-small text-[var(--color-neutral-500)] truncate">
                            {{ $emp->latestActiveContract()?->position?->title . ' - ' . $emp->department?->name }}
                        </p>
                    </div>
                </div>


            </div>
        @empty
            <x-center-empty-state variant="empty" :title="__('dashboard.no-leaves-employees-empty')" :description="''" class="min-h-40! border-0 shadow-none" />
        @endforelse
        @endif
    </div>
</div>
