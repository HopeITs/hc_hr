<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <x-dashboard-cards.upcoming-events-card :upcoming-events="$upcomingEvents" />
    @endif
</div>
