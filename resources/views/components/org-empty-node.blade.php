@props(['position', 'compact' => false])

@php
    $org = orgCardTokens($compact);
    $city = $position->city?->slug ?? $position->center?->city?->slug;
    $cityName = $position->city?->name ?? $position->center?->city?->name;
@endphp

<div {{ $attributes->merge(['class' => 'org-id-card org-id-card--vacant '.$org['modifier']]) }}>

    <div class="org-id-card__photo">
        <div class="org-id-card__bar org-id-card__bar--vacant" aria-hidden="true"></div>
        <img class="org-id-card__avatar"
            src="{{ asset('images/default-avatar.png') }}"
            alt=""
            loading="lazy"
            aria-hidden="true">
    </div>

    <div class="org-id-card__info">
        <div>
            <div class="org-id-card__name org-id-card__name--sm">@lang('general.vacant-position')</div>
        </div>
        <div class="mt-2 org-id-card__position">{{ $position->title }}</div>
        <div class="flex flex-col items-start justify-start mt-3">
            <span class="text-caption uppercase">{{ $position->program?->name }}</span>
            <span class="text-ink-2 font-semibold text-caption uppercase"
                @if ($cityName) title="{{ $cityName }}" @endif>{{ $cityName }}</span>
        </div>
    </div>
</div>
