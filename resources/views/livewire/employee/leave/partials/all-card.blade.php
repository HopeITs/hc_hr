{{--
    Phone card for one request in All leaves (B4-D §6, G-B-4). The name opens
    what a row click opens: the decision while pending, the read-only details
    once decided (G-B-5). Receives $item (LeaveRequest), $link and $table.
--}}
@php
    $name = $item->leaveBalance?->contract?->employee?->name ?? __('general.na');
    $decided = $item->status !== \App\Enums\LeaveRequestStatus::Pending;
@endphp
<div class="flex min-w-0 flex-col gap-2" data-card="{{ $item->getKey() }}">
    <button type="button"
        x-on:click="{{ $table->rowOpenAction($item) }}"
        aria-haspopup="dialog"
        class="block min-h-11 w-full min-w-0 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        <span class="block break-words font-semibold text-black"><bdi>{{ $name }}</bdi></span>
        @include('livewire.employee.leave.partials.card-summary', ['item' => $item])
    </button>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="flex flex-wrap items-center gap-2">
            @include('livewire.employee.leave.partials.card-status', ['item' => $item])
            @if($decided && $table->showsHrReview())
                <span class="inline-flex items-center gap-1.5 text-small text-secondary-medium">
                    <x-table.hr-review :reviewed="$item->hr_approved" :model-id="$item->getKey()" />
                    {{ __('general.seen-by-hr') }}
                </span>
            @endif
        </span>
        <span class="flex items-center">{!! $table->leaveActions($item) !!}</span>
    </div>
</div>
