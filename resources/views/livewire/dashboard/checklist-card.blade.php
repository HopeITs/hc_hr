<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <x-dashboard-cards.checklist-items-card :delivered-items="$deliveredItems" />
    @endif
</div>
