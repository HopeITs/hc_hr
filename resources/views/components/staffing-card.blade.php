@props(['node', 'compact' => false, 'view' => 'staff'])

@php
    $position = $node['position'];
    $openSeats = $node['openSeats'];
    $filled = (int) $position->exist_emp;
    $capacity = (int) $position->max_emp;
    $isRoot = $node['isRoot'];
    $passthrough = $node['passthrough'] ?? false;

    $holders = $position->activeContracts->map(fn ($contract) => $contract->employee)->filter()->values();

    // Blocked actions are not listed. A manager reading the chart wants the one
    // request they can start, and a paragraph explaining the four they cannot
    // turned every card into an argument with itself.
    $available = collect($node['actions'])->filter(fn (array $action): bool => $action['available']);

    $isStaff = $view === 'staff';

    // The organisation chart's box, and it earns the nesting the way that chart
    // does: a manager's reports are drawn inside it, so the box is what makes a
    // subtree's containment visible without a single extra line.
    //
    // Its border carries the one state worth colouring here — a seat standing
    // open. A request already in flight mutes it back down, because the action
    // has been taken and the card says so on its own line.
    $frame = match (true) {
        $isRoot => 'border-secondary-dark',
        $openSeats > 0 && $node['requests']->isEmpty() => 'border-primary',
        default => 'border-neutral-200',
    };

    // Only the positions view fixes a column width: a chart of posts is read by
    // comparing them, and a ragged set of widths is what makes that hard. The
    // staff view is as wide as its people need.
    //
    // It is set on the card's own content and never on the box, because the box
    // now holds this manager's whole subtree: a fixed width there strangled
    // every descendant down to one column, which is why a level of posts came
    // out stacked one under another instead of side by side.
    $width = $isStaff ? '' : ($compact ? 'w-44' : 'w-52');

    // A post with reports draws two things in one box: the post itself and the
    // team beneath it. The post gets its own hairline frame so it reads as the
    // manager of what is below, and the team stays a separate, tinted panel. A
    // post with nobody under it is already a single card and needs no second one.
    $managerFrame = trim((string) $slot) !== ''
        ? 'rounded-control border border-secondary-light/60 bg-white '.($compact ? 'p-2' : 'p-3')
        : '';
@endphp

<div
    x-data="{ menu: false }"
    x-on:keydown.escape="menu = false"
    {{-- No data-no-pan on the box on purpose: the canvas stays draggable from
         anywhere. Only the links and the toggles opt out, so a click on one is a
         click and not the start of a pan. --}}
    class="rounded-surface border bg-white shadow-e1 transition
           {{ $frame }}
           {{ $passthrough ? 'opacity-55 hover:opacity-100' : '' }}
           {{ $compact ? 'p-2' : 'p-3' }}"
>
  <div class="{{ $width }} mx-auto {{ $managerFrame }}">
    {{-- ---------- Seats and standing ---------- --}}
    <div class="flex items-center justify-between gap-2 pb-2">
        @if ($capacity > 0 && $capacity <= 6)
            <div class="flex items-center gap-1" role="img"
                 aria-label="{{ __('recruitment.team_staffing.seats_label', ['filled' => $filled, 'capacity' => $capacity]) }}">
                @for ($seat = 0; $seat < $capacity; $seat++)
                    @if ($seat < $filled)
                        <span class="size-2 rounded-full bg-secondary-medium" aria-hidden="true"></span>
                    @else
                        <span class="size-2 rounded-full border border-dashed border-primary bg-primary/15" aria-hidden="true"></span>
                    @endif
                @endfor
            </div>
        @else
            <span class="text-caption font-bold tabular-nums text-secondary-medium"
                  aria-label="{{ __('recruitment.team_staffing.seats_label', ['filled' => $filled, 'capacity' => $capacity]) }}">
                {{ $filled }}<span class="text-secondary-light">/{{ $capacity }}</span>
            </span>
        @endif

        @if ($isRoot)
            <span class="rounded-inset bg-secondary-dark px-2 py-0.5 text-caption font-bold uppercase tracking-wide text-white">
                {{ __('recruitment.team_staffing.you') }}
            </span>
        @elseif ($openSeats > 0)
            <span class="rounded-inset bg-primary/10 px-2 py-0.5 text-caption font-bold text-primary">
                {{ __('recruitment.team_staffing.open_capacity_count', ['count' => $openSeats]) }}
            </span>
        @endif
    </div>

    {{-- ---------- The body, which is what the two views disagree about ---------- --}}
    @if ($isStaff)
        {{-- One card per person and one per empty seat, exactly as the
             organisation chart draws a post. This view is read to find somebody,
             and "+2 more in this position" is not somebody. They stay together
             as one group inside their post and wrap to the width that post has,
             so a small team is a short row and a big one folds by itself. --}}
        <div class="flex flex-wrap items-start justify-center gap-2">
            @foreach ($holders as $person)
                <x-org-node :key="'holder-'.$person->getKey()"
                            :position="$position" :employee="$person"
                            :is-manager="count($node['children']) > 0" :compact="$compact" />
            @endforeach

            @for ($seat = 0; $seat < max($openSeats, $holders->isEmpty() ? 1 : 0); $seat++)
                <x-org-empty-node :key="'seat-'.$node['id'].'-'.$seat"
                                  :position="$position" :compact="$compact" />
            @endfor
        </div>
    @else
        {{-- The establishment. No photograph: this view is read by comparing
             posts, and a face is the loudest thing on a card that is not about
             faces. --}}
        <div>
            <p class="text-small font-semibold leading-snug text-secondary-dark" dir="auto">
                {{ $position->title }}
            </p>
            <p class="mt-1 truncate text-caption text-secondary-light" dir="auto"
               title="{{ $position->program?->name }}">
                {{ $position->program?->name }}
            </p>
            <p class="text-caption text-secondary-light" dir="auto">
                @if ($holders->isNotEmpty())
                    {{ $holders->first()->name }}@if ($holders->count() > 1)<span> {{ __('recruitment.team_staffing.more_holders', ['count' => $holders->count() - 1]) }}</span>@endif
                @else
                    <span class="font-semibold text-primary">{{ __('general.vacant-position') }}</span>
                @endif
            </p>
        </div>
    @endif

    {{-- ---------- Requests already moving ---------- --}}
    @if ($node['requests']->isNotEmpty())
        <ul class="mt-2 space-y-1">
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
                       class="flex items-center gap-1.5 rounded-inset bg-neutral-50 px-2 py-1 text-caption font-semibold text-secondary-medium transition hover:bg-secondary-soft hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1">
                        <span class="size-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        <span class="truncate">
                            {{ \App\Enums\RecruitmentRequestType::getLocalizedName((int) $request->request_type)['value'] }}
                            <span class="text-secondary-light">·</span> {{ $status['value'] }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- ---------- Starting a request ----------
         The list opens in place rather than as an absolute dropdown: the canvas
         clips its own overflow, so a menu hanging off a card near the edge was
         cut in half. Nothing is rendered at all when there is nothing to start —
         a disabled control that will not say why is worse than no control. --}}
    @if ($available->isNotEmpty())
        <button type="button" x-on:click="menu = ! menu" :aria-expanded="menu" data-no-pan
                class="mt-2 flex w-full items-center justify-between gap-1 rounded-control bg-primary px-2 py-1.5 text-caption font-bold text-white transition hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1">
            <span class="flex min-w-0 items-center gap-1">
                <svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                </svg>
                <span class="truncate">{{ __('recruitment.team_staffing.open_request') }}</span>
            </span>
            <svg class="size-3 shrink-0 transition-transform duration-200" :class="menu ? 'rotate-180' : ''"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <ul x-show="menu" x-cloak x-collapse data-no-pan
            class="mt-1 overflow-hidden rounded-control border border-neutral-200 bg-white">
            @foreach ($available as $type => $action)
                <li wire:key="staffing-action-{{ $node['id'] }}-{{ $type }}">
                    <a href="{{ $action['url'] }}" wire:navigate
                       class="flex items-center gap-2 border-b border-neutral-100 px-2 py-2 text-caption font-semibold text-secondary-dark transition last:border-b-0 hover:bg-secondary-soft hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
                        <span class="size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                        <span class="leading-snug">{{ $action['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

  </div>

    {{-- The reports, drawn inside this box by the node partial and deliberately
         outside the column above: they are as wide as the team is. --}}
    {{ $slot }}
</div>
