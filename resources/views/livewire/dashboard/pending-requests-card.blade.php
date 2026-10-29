<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <x-dashboard-cards.pending-requests-card :pending-requests="$pendingRequests" />
    @endif
</div>
