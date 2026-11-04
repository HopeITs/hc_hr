{{-- Title cell: cover thumbnail, the localized title and a small type tag. --}}
@php($cover = $news->coverUrl())
<div class="flex min-w-56 items-center gap-3">
    @if($cover)
        <img src="{{ $cover }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-control border border-line-soft object-cover">
    @else
        <span class="flex size-12 shrink-0 items-center justify-center rounded-control border border-line-soft bg-soft text-ink-3" aria-hidden="true">
            <x-ic :name="$news->type->icon()" class="size-5" />
        </span>
    @endif
    <span class="flex min-w-0 flex-col items-start gap-1">
        <span class="line-clamp-2 font-semibold text-ink" dir="{{ $news->textDirection() }}">{{ $news->localizedTitle() }}</span>
        <x-tag variant="soft" :icon="$news->type->icon()" :label="$news->type->label()" />
    </span>
</div>
