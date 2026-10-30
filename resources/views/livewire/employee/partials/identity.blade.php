{{-- Employee list identity cell: photo, name (opens the read-only drawer) and position on a second line. --}}
@php($position = $employee->primaryContract()?->position?->title)
<div class="flex min-w-0 items-center gap-3">
    <img src="{{ $employee->avatar_url }}" alt="" loading="lazy" class="size-[38px] max-w-none shrink-0 rounded-full border border-line-soft bg-card object-cover" />
    <div class="min-w-0">
        <button type="button"
            x-on:click.stop="$dispatch('employee-drawer', { id: {{ (int) $employee->getKey() }} })"
            aria-haspopup="dialog"
            data-employee-open="{{ (int) $employee->getKey() }}"
            aria-label="{{ __('general.emp-open-details', ['name' => $employee->name]) }}"
            class="block max-w-full truncate text-start font-semibold text-ink hover:underline"><bdi>{{ $employee->name }}</bdi></button>
        @if($position)
            <span class="block truncate text-small font-normal text-ink-2"><bdi>{{ $position }}</bdi></span>
        @endif
    </div>
</div>
