{{--
    Phone card for one item: the card links to the item (details, or preview
    for an unreleased one); the More menu sits beside it.
    Receives $item (News), $link and $table (the component).
--}}
@php($eventRange = \App\Livewire\News\NewsTable::eventRange($item))
<div class="flex items-start gap-2" data-card="{{ $item->getKey() }}">
    <div class="min-w-0 flex-1">
        @if($link)
            <a href="{{ $link }}" wire:navigate class="block min-h-11">
                @include('livewire.news.list._title', ['news' => $item])
            </a>
        @else
            @include('livewire.news.list._title', ['news' => $item])
        @endif
        <div class="mt-2 flex flex-col gap-1 text-small text-ink-2">
            <span>@include('livewire.news.list._status', ['news' => $item])</span>
            <span dir="auto">{{ $item->audienceSummary() }}</span>
            @if($item->isEvent())
                <span class="flex items-center gap-1.5"><x-ic name="calendar" class="size-4" /><bdi class="num">{{ $eventRange }}</bdi></span>
            @elseif($item->published_at)
                <span class="num">{{ $item->published_at->translatedFormat('j M Y, H:i') }}</span>
            @endif
        </div>
    </div>
    {!! $table->rowActions($item, 'card') !!}
</div>
