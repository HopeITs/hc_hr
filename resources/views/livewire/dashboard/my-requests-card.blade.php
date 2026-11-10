{{--
    "My leave requests" tile (MyRequestsCard): the leaves page as a card. The
    viewer's latest requests, each with its type, dates and status (x-status:
    icon, words and fill, never colour), the annual days left, and New request.
    Nothing yet: one quiet line the grid folds away.
--}}
<div class="hc-myreq flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.my-requests') }}</h2>
            @if ($annual !== null)
                <span class="text-small text-ink-2 num">{{ trans_choice('dashboard.leave-balance.days-left', $annual, ['count' => $annual]) }}</span>
            @endif
            @if ($newUrl)
                <x-button size="sm" icon="plus" :href="$newUrl" class="ms-auto shrink-0">{{ __('dashboard.my-requests.new') }}</x-button>
            @endif
        </header>

        @if ($requests->isEmpty())
            <p class="hc-nt-empty" data-tile-empty>{{ __('dashboard.my-requests.none') }}</p>
        @else
            <ul class="hc-list" data-testid="my-requests-list">
                @foreach ($requests as $request)
                    @php
                        $locale = app()->getLocale();
                        $from = $request->start_at->locale($locale);
                        $to = $request->end_at->locale($locale);
                        $period = $request->type === 'hour'
                            ? $from->isoFormat('D MMM').' · '.$from->format('H:i').'–'.$to->format('H:i')
                            : ($from->isSameDay($to) ? $from->isoFormat('D MMM') : $from->isoFormat('D MMM').' – '.$to->isoFormat('D MMM'));
                    @endphp
                    <li>
                        <a href="{{ route('leaves.index', ['leave' => $request->id]) }}" wire:navigate class="hc-list-row">
                            <span class="min-w-0 flex-1">
                                <b class="block truncate">{{ $request->leaveBalance?->leaveType?->name }}</b>
                                <span class="block truncate text-small text-ink-2 num">{{ $period }}</span>
                            </span>
                            <x-status class="shrink-0" :status="\App\Enums\LeaveRequestStatus::of($request->status)" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
