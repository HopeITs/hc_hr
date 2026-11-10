{{--
    Phone card for one of the user's own requests (B4-D §6, G-B-4): leave type
    first, "Seen by HR" inside the status line. The card opens what a row click
    opens. Receives $item (LeaveRequest), $link and $table.
--}}
<div class="flex min-w-0 flex-col gap-2" data-card="{{ $item->getKey() }}">
    <button type="button"
        x-on:click="{{ $table->rowOpenAction($item) }}"
        aria-haspopup="dialog"
        class="block min-h-11 w-full min-w-0 text-start">
        @include('livewire.employee.leave.partials.card-summary', ['item' => $item])
    </button>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="flex flex-wrap items-center gap-2">
            @include('livewire.employee.leave.partials.card-status', ['item' => $item])
            @if($item->hr_approved)
                <span class="text-small text-ink-2">{{ __('general.seen-by-hr') }}</span>
            @endif
        </span>
        <span class="flex items-center">{!! $table->leaveActions($item) !!}</span>
    </div>
</div>
