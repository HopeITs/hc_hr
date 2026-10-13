@php
    $locale = app()->getLocale();
    $titleField = 'title_' . $locale;
@endphp

<div class="relative w-full h-full bg-white rounded-surface shadow-[0px_0px_25px_-14px_rgba(0,_0,_0,_0.30)] overflow-hidden flex flex-col">

    <!-- Header (IDENTICAL to No Leaves) -->
    <div class="flex items-center gap-2 px-6 pt-6 pb-4">
        <h2 class="text-h4 font-semibold text-secondary-medium">
            @lang('general.soon-ending-contracts')
        </h2>

        <span class="text-secondary-light sm:text-h4 md:text-body font-medium">
            {{ $contracts->count() }}
        </span>

    </div>

    <!-- Scrollable Content (IDENTICAL to No Leaves) -->
    <div class="relative px-6 pb-6 pt-2 space-y-3 overflow-y-auto flex-grow
                scrollbar-thin scrollbar-thumb-[var(--color-neutral-300)]
                scrollbar-track-transparent
                hover:scrollbar-thumb-[var(--color-secondary-medium)]
                max-h-[440px]">

        @forelse ($contracts as $contract)
            <div class="flex items-center justify-between py-4 border-b border-[var(--color-neutral-200)] last:border-0">

                <!-- LEFT: Avatar + Name -->
                <div class="flex items-center gap-3">

                    <div class="flex items-center justify-center h-11 w-11 flex-shrink-0 rounded-full overflow-hidden
                                bg-[var(--color-primary)] text-white font-semibold uppercase shadow-e1">

                        <img
                            src="{{ $contract->employee->avatar_url }}"
                            alt="{{ $contract->employee->name }}"
                            class="h-full w-full object-cover"
                        >
                    </div>


                    <div class="min-w-0">
                        <p class="text-body font-semibold text-[var(--color-neutral-800)] truncate">
                            {{ $contract->employee_name }}
                        </p>

                        <p class="text-small text-[var(--color-neutral-500)] truncate">
                            {{ $contract->position->$titleField }}
                        </p>
                    </div>
                </div>

                <!-- RIGHT: End Date (only difference) -->
                <div class="flex flex-col items-end">
                    <p class="text-body font-semibold text-primary whitespace-nowrap">
                        {{ \Carbon\Carbon::parse($contract->end_date)->format('d/m/Y') }}
                    </p>
                </div>

            </div>
        @empty
            <div class="flex h-full flex-col items-center justify-center text-center text-[10.5px] text-[var(--color-neutral-500)]">
                @lang('general.no_active_contracts_ending_soon')
            </div>
        @endforelse
    </div>
</div>
