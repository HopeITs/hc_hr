@props(['position', 'employee' => null, 'isManager' => false, 'managerType' => 'direct', 'compact' => false])

@php
    $org = orgCardTokens($compact);

    $employee ??= $position->activeContracts->first()?->employee;
    $center = $position->center?->name;
    $city = $position->city?->slug ?? $position->center?->city?->slug;
    $cityName = $position->city?->name ?? $position->center?->city?->name;

    $modifiers = collect([
        'org-id-card',
        $org['modifier'],
        // $isManager ? 'org-id-card--manager' : null,
        $employee->is_board_member ? 'org-id-card--board' : null,
    ])
        ->filter()
        ->implode(' ');

    // Long single words (e.g. surnames) can't wrap inside the fixed card width,
    // so shrink the name font a little when either line is long.
    $longestNameLine = max(mb_strlen((string) $employee->first_name), mb_strlen((string) $employee->last_name));
    $nameClass = $longestNameLine > 9 ? 'org-id-card__name org-id-card__name--sm' : 'org-id-card__name';
@endphp

<button type="button" wire:click="$dispatch('open-profile', { employeeId: {{ $employee->id }} })"
    data-tour="org.node"
    aria-label="{{ __('general.view-profile-of', ['name' => $employee->name]) }}"
    {{ $attributes->merge(['class' => $modifiers]) }}>


    <div class="org-id-card__photo">
        <div class="org-id-card__bar" aria-hidden="true"></div>
        <img class="org-id-card__avatar" src="{{ $employee->avatar_url }}" alt="" loading="lazy">
    </div>

    <div class="org-id-card__info">
        <div>
            <div class="{{ $nameClass }}">{{ $employee->first_name }}</div>
            <div class="{{ $nameClass }}">{{ $employee->last_name }}</div>
        </div>
        <div class="mt-2 org-id-card__position">{{ $position->title }}</div>
        <div class="flex flex-col items-start justify-start mt-3 ">


            <span class="text-caption uppercase">{{ $position->program?->name }}</span>
            <span class="text-ink-2 font-semibold text-caption uppercase"
                @if ($cityName) title="{{ $cityName }}" @endif>{{ $cityName }}</span>

        </div>
    </div>
</button>
