<div class="h-full">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0" />
    @else
        <x-dashboard-cards.news-card :news-items="$newsItems" />
    @endif
</div>
