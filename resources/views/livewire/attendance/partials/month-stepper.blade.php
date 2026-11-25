{{-- The month, one step back or forward. Shared by the list and the person's calendar. --}}
<div class="flex items-center rounded-control border border-line bg-card" role="group" aria-label="{{ __('attendance.timesheets.month') }}">
    <button type="button" wire:click="shiftMonth(-1)" aria-label="{{ __('attendance.timesheets.prev-month') }}"
            class="grid size-11 place-items-center rounded-control text-ink hover:bg-hover">
        <x-ic name="chevron" class="size-4 ltr:rotate-180" />
    </button>
    <label class="relative">
        <span class="sr-only">{{ __('attendance.timesheets.month') }}</span>
        <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}" class="h-11 w-40 border-0 bg-transparent px-1 text-center text-body font-extrabold text-ink num focus:ring-0">
    </label>
    <button type="button" wire:click="shiftMonth(1)" aria-label="{{ __('attendance.timesheets.next-month') }}" @disabled($month >= now()->format('Y-m'))
            class="grid size-11 place-items-center rounded-control text-ink hover:bg-hover disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent">
        <x-ic name="chevron" class="size-4 rtl:rotate-180" />
    </button>
</div>
