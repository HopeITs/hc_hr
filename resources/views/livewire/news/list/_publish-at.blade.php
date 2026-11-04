{{-- Publish At: the date, and a "scheduled" hint while it is still ahead. --}}
@if($news->published_at)
    <span class="flex flex-col">
        <span class="num whitespace-nowrap">{{ $news->published_at->translatedFormat('j M Y, H:i') }}</span>
        @if($news->published_at->isFuture() && $news->status === \App\Enums\News\NewsStatus::Published)
            <span class="text-small text-ink-3">{{ __('news.list.scheduled_hint', ['when' => $news->published_at->diffForHumans()]) }}</span>
        @endif
    </span>
@else
    <span class="text-ink-3">{{ __('news.list.not_set') }}</span>
@endif
