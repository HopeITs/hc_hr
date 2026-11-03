{{--
    Phone card for one of the user's own requests (B4-D §6, G-B-4): leave type
    as the title, then dates · duration, then the status line ("Seen by HR"
    inside it) and the actions. The card opens what a row click opens.
    Receives $item (LeaveRequest), $link and $table.
--}}
<div class="flex min-w-0 flex-col gap-2" data-card="{{ $item->getKey() }}">
    <button type="button"
        x-on:click="{{ $table->rowOpenAction($item) }}"
        aria-haspopup="dialog"
        class="block min-h-11 w-full min-w-0 text-start">
        <span class="block break-words font-semibold text-ink"><bdi>{{ $item->leaveBalance?->leaveType?->{transColumn('name')} ?? __('general.na') }}</bdi></span>
        @include('livewire.employee.leave.partials.card-summary', ['item' => $item, 'withType' => false])
    </button>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="flex min-w-0 flex-wrap items-center gap-2">
            @include('livewire.employee.leave.partials.card-status', ['item' => $item])
            @if($item->hr_approved)
                <span class="text-small text-ink-2">{{ __('general.seen-by-hr') }}</span>
            @endif
        </span>
        {{-- Each icon button is 44px on phones; the gap keeps neighbouring taps apart. --}}
        <span class="ms-auto flex shrink-0 items-center gap-1">{!! $table->leaveActions($item) !!}</span>
    </div>
</div>
