@php
    $locale = app()->getLocale();
    $titleField = 'title_' . $locale;
@endphp

<div class="relative flex h-full min-h-64 w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">

    <!-- Header (IDENTICAL to No Leaves) -->
    <div class="flex items-center gap-2 px-6 pt-6 pb-4">
        <h2 class="text-h4 font-extrabold text-ink">
            @lang('general.soon-ending-contracts')
        </h2>

        <span class="num text-ink-2 sm:text-h4 md:text-body font-medium">
            @unless ($loadFailed){{ $contracts->count() }}@endunless
        </span>

    </div>

    <!-- Scrollable Content (IDENTICAL to No Leaves) -->
    <div class="relative px-6 pb-6 pt-2 space-y-3 overflow-y-auto flex-grow
                scrollbar-thin scrollbar-thumb-[var(--color-line)]
                scrollbar-track-transparent
                hover:scrollbar-thumb-[var(--color-ink-2)]
                max-h-[440px]">

        @if ($loadFailed)
            <x-ui.load-error class="min-h-40" />
        @else
        @forelse ($contracts as $contract)
            <div class="flex items-center justify-between py-4 border-b border-line-soft last:border-0">

                <!-- LEFT: Avatar + Name -->
                <div class="flex items-center gap-3">

                    <div class="flex items-center justify-center h-11 w-11 flex-shrink-0 rounded-full overflow-hidden
                                bg-surface text-ink font-semibold uppercase shadow-e1">

                        <img
                            src="{{ $contract->employee_photo }}"
                            alt=""
                            class="h-full w-full object-cover"
                        >
                    </div>


                    <div class="min-w-0">
                        <p class="text-body font-semibold text-ink truncate">
                            {{ $contract->employee_name }}
                        </p>

                        <p class="text-small text-ink-2 truncate">
                            {{ $contract->position?->$titleField }}
                        </p>
                    </div>
                </div>

                <!-- RIGHT: End Date (only difference) -->
                <div class="flex flex-col items-end">
                    <p class="text-body font-semibold text-primary whitespace-nowrap">
                        <bdi>{{ \Carbon\Carbon::parse($contract->end_date)->format('d/m/Y') }}</bdi>
                    </p>
                </div>

            </div>
        @empty
            <x-center-empty-state variant="empty" :title="__('dashboard.no-contracts-ending-soon')" :description="''" class="min-h-40! border-0 shadow-none" />
        @endforelse
        @endif
    </div>
</div>
