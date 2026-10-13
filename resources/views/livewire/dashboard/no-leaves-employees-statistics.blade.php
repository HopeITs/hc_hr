@php
    $locale = app()->getLocale();
    $titleField = 'title_' . $locale;
@endphp

<div class="relative w-full h-full bg-white rounded-surface shadow-[0px_0px_25px_-14px_rgba(0,_0,_0,_0.30)] overflow-hidden flex flex-col">

    <div class="flex items-center gap-2 px-6 pt-6 pb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium">
            @lang('general.no-leaves-employees')
        </h2>
        <span class="text-secondary-light sm:text-h4 md:text-body font-medium">
                {{ $noLeavesEmployees->count() }}
            </span>
    </div>

    <div class="relative px-6 pb-6 pt-2 space-y-3 overflow-y-auto flex-grow
                scrollbar-thin scrollbar-thumb-[var(--color-neutral-300)] scrollbar-track-transparent
                hover:scrollbar-thumb-[var(--color-secondary-medium)] max-h-[440px] ">

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
                            {{ $emp->latestActiveContract()->position->title . ' - ' .$emp->department->name }}
                        </p>
                    </div>
                </div>


            </div>
        @empty
            <div class="text-center p-4 text-body text-[var(--color-neutral-500)]">
                No active contracts are set to end in the next 30 days.
            </div>
        @endforelse
    </div>
</div>
