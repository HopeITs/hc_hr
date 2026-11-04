{{-- Derived state (draft / scheduled / published / expired / archived), plus when an event takes place. --}}
@php($timing = $news->eventTiming())
<span class="flex flex-wrap items-center gap-1.5">
    <x-status :status="$news->state()" />
    @if($timing)
        <x-tag variant="soft" icon="calendar" :label="__('news.timing.'.$timing)" />
    @endif
</span>
