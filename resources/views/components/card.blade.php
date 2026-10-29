{{--
    Card: card fill, 1px line-soft border, 12px radius, shadow-sm, 18px/800 title.
    title: optional heading; slot `icon` sits at its end. The body is the slot.
--}}
@props(['title' => null])
<div {{$attributes->merge([
    'class' => 'flex flex-col justify-between rounded-surface border border-line-soft bg-card p-5 shadow-e1'
])}}
>
    @isset($title)
        <div class="mb-3 flex items-start justify-between gap-3">
            <h2 class="line-clamp-2 text-start text-h4 font-extrabold text-ink">{{$title}}</h2>
            {{$icon ?? ''}}
        </div>
    @endisset
    <div class="text-body text-ink-2">
        {{$slot}}
    </div>
</div>
