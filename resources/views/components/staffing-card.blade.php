@props(['node', 'compact' => false, 'view' => 'staff'])

@php
    $position = $node['position'];
    $openSeats = $node['openSeats'];
    $filled = (int) $position->exist_emp;
    $capacity = (int) $position->max_emp;
    $isRoot = $node['isRoot'];
    $passthrough = $node['passthrough'] ?? false;
    $inFlight = $node['requests']->isNotEmpty();
    $isStaff = $view === 'staff';

    $holders = $position->activeContracts->map(fn ($contract) => $contract->employee)->filter()->values();

    // Blocked actions are not listed. A manager reading the chart wants the one
    // request they can start, not a paragraph about the ones they cannot.
    $available = collect($node['actions'])->filter(fn (array $action): bool => $action['available']);

    // Empty seats: one per open seat, and one for a post nobody holds even when
    // its capacity says otherwise. Up to three are drawn one by one (a gap in a
    // team is read beside the people in it); more than that is one chip with a
    // count, because four identical "Vacant" rows say less than "×4".
    $emptySeats = max($openSeats, $holders->isEmpty() ? 1 : 0);
    $vacancyChips = $emptySeats <= 3 ? $emptySeats : 1;

    // The card decides its own width so the canvas measures it once. Staff view:
    // chips are a fixed size (w-48 compact / w-56), laid out 1 column for one
    // chip, 2 for two or more: columns × chip + gaps (0.5rem)
    // + padding (p-3) + the 1px border on each side.
    $chips = $holders->count() + $vacancyChips;
    // Never three: a wide card is what spreads the whole tree sideways, and a
    // taller one only costs a little height.
    $cols = $chips <= 1 ? 1 : 2;
    $width = $isStaff
        ? ($compact
            ? [1 => 'w-[calc(13.5rem+2px)]', 2 => 'w-[calc(26rem+2px)]', 3 => 'w-[calc(38.5rem+2px)]'][$cols]
            : [1 => 'w-[calc(15.5rem+2px)]', 2 => 'w-[calc(30rem+2px)]', 3 => 'w-[calc(44.5rem+2px)]'][$cols])
        : ($compact ? 'w-56' : 'w-60');

    $seatsLabel = __('recruitment.team_staffing.seats_label', ['filled' => $filled, 'capacity' => $capacity]);
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink';
@endphp

<div
    x-data="{ menu: false }"
    x-on:keydown.escape="menu = false"
    {{-- No data-no-pan on the box: the canvas stays draggable from anywhere.
         Only links and toggles opt out, so a click on one is not a pan. --}}
    @class([
        'shrink-0 rounded-surface border bg-card p-3 transition-opacity duration-150 motion-reduce:transition-none',
        $width,
        $isRoot ? 'border-ink' : 'border-line-soft',
        'opacity-60 hover:opacity-100' => $passthrough,
    ])
>
    {{-- ---------- Header: the post, its seats, and the one thing to notice ---------- --}}
    <div class="flex items-start justify-between gap-3">
        <p class="line-clamp-2 min-w-0 text-small font-semibold leading-snug text-ink" dir="auto" title="{{ $position->title }}">
            {{ $position->title }}
        </p>

        @if (! $isStaff && $capacity > 0 && $capacity <= 6)
            {{-- Positions view: the seats as dots (filled = solid, open = dashed). --}}
            <span class="mt-1 flex shrink-0 items-center gap-1" role="img" aria-label="{{ $seatsLabel }}">
                @for ($seat = 0; $seat < $capacity; $seat++)
                    @if ($seat < $filled)
                        <span class="size-2 rounded-full bg-ink-2" aria-hidden="true"></span>
                    @else
                        <span class="size-2 rounded-full border-[1.5px] border-dashed border-ink-3" aria-hidden="true"></span>
                    @endif
                @endfor
            </span>
        @else
            <span class="shrink-0 text-caption font-semibold leading-5 tabular-nums text-ink-2" aria-label="{{ $seatsLabel }}">
                {{ $filled }}<span class="text-ink-3">/{{ $capacity }}</span>
            </span>
        @endif
    </div>

    @if ($isRoot || $openSeats > 0)
        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
            @if ($isRoot)
                {{-- Where the viewer sits: an ink outline, never red. --}}
                <span class="inline-flex items-center rounded-inset border border-ink px-1.5 text-caption font-semibold leading-5 text-ink">
                    {{ __('recruitment.team_staffing.you') }}
                </span>
            @endif
            @if ($openSeats > 0)
                {{-- Red only while the seat waits on the viewer; once a request is
                     moving, the list below says so and the tag steps back. --}}
                <x-tag :variant="$inFlight ? 'soft' : 'act'" :icon="$inFlight ? 'clock' : null"
                       :label="__('recruitment.team_staffing.open_capacity_count', ['count' => $openSeats])" />
            @endif
        </div>
    @endif

    {{-- ---------- Body: people (staff view) or the post (positions view) ---------- --}}
    @if ($isStaff)
        {{-- Fixed-size chips in a fixed-width card wrap into even columns. --}}
        <div class="mt-3 flex flex-wrap items-start justify-center gap-2">
            @foreach ($holders as $person)
                <x-staffing-person wire:key="staffing-holder-{{ $node['id'] }}-{{ $person->getKey() }}"
                                   :employee="$person" :position="$position" :compact="$compact" />
            @endforeach

            @for ($seat = 0; $seat < $vacancyChips; $seat++)
                <x-staffing-vacancy wire:key="staffing-seat-{{ $node['id'] }}-{{ $seat }}"
                                    :position="$position" :compact="$compact"
                                    :count="$vacancyChips === 1 ? $emptySeats : 1" />
            @endfor
        </div>
    @else
        {{-- The establishment, read by comparing posts: no photograph. --}}
        <div class="mt-1 min-w-0">
            @if ($position->program?->name)
                <p class="truncate text-caption text-ink-3" dir="auto" title="{{ $position->program?->name }}">
                    {{ $position->program?->name }}
                </p>
            @endif
            <p class="mt-2 flex min-w-0 items-center gap-1.5 text-caption" dir="auto">
                @if ($holders->isNotEmpty())
                    <x-ic name="user" class="size-3.5 shrink-0 text-ink-3" />
                    <span class="truncate text-ink-2">{{ $holders->first()->name }}@if ($holders->count() > 1)<span> {{ __('recruitment.team_staffing.more_holders', ['count' => $holders->count() - 1]) }}</span>@endif</span>
                @else
                    <span class="size-3 shrink-0 rounded-full border-[1.5px] border-dashed border-ink-3" aria-hidden="true"></span>
                    <span class="font-semibold text-ink-2">{{ __('general.vacant-position') }}</span>
                @endif
            </p>
        </div>
    @endif

    {{-- ---------- Requests already moving ---------- --}}
    @if ($inFlight)
        <ul class="mt-3 space-y-1">
            @foreach ($node['requests'] as $request)
                @php($status = \App\Enums\RecruitmentRequestStatus::getLocalizedName((int) $request->status))
                {{-- Literal classes: the colour name comes from the enum, and a
                     class built by interpolation is never seen by the scanner. --}}
                @php($dot = match ($status['color']) {
                    'primary' => 'bg-primary',
                    'secondary-medium' => 'bg-secondary-medium',
                    default => 'bg-secondary-light',
                })
                <li wire:key="staffing-request-{{ $request->id }}">
                    <a href="{{ route('recruitment.requests.show', ['requestId' => $request->id]) }}" wire:navigate
                       data-no-pan
                       class="flex items-center gap-1.5 rounded-inset bg-soft px-2 py-1 text-caption font-semibold text-ink-2 transition-colors hover:bg-surface hover:text-ink {{ $focus }}">
                        <span class="size-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        <span class="truncate">
                            {{ \App\Enums\RecruitmentRequestType::getLocalizedName((int) $request->request_type)['value'] }}
                            <span class="text-ink-3">·</span> {{ $status['value'] }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- ---------- Starting a request: the card's one strong red element ----------
         The list opens in place rather than as an absolute dropdown: the canvas
         clips its own overflow. Nothing is rendered when there is nothing to start. --}}
    @if ($available->isNotEmpty())
        <button type="button" x-on:click="menu = ! menu" :aria-expanded="menu" data-no-pan
                class="mt-3 flex min-h-9 w-full cursor-pointer items-center justify-between gap-1 rounded-control bg-primary px-2.5 text-caption font-bold text-on-brand transition-colors hover:bg-primary-hover {{ $focus }}">
            <span class="flex min-w-0 items-center gap-1.5">
                <x-ic name="plus" class="size-3.5 shrink-0" />
                <span class="truncate">{{ __('recruitment.team_staffing.open_request') }}</span>
            </span>
            <svg class="size-3.5 shrink-0 transition-transform duration-200 motion-reduce:transition-none" :class="menu ? 'rotate-180' : ''"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <ul x-show="menu" x-cloak x-collapse data-no-pan
            class="mt-1 overflow-hidden rounded-control border border-line-soft bg-card">
            @foreach ($available as $type => $action)
                <li wire:key="staffing-action-{{ $node['id'] }}-{{ $type }}" class="border-b border-line-soft last:border-b-0">
                    <a href="{{ $action['url'] }}" wire:navigate
                       class="flex items-center gap-2 px-2 py-2 text-caption font-semibold text-ink transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ink">
                        <x-ic name="arrow" class="size-3.5 shrink-0 text-ink-3 rtl:-scale-x-100" />
                        <span class="leading-snug">{{ $action['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    {{ $slot }}
</div>
