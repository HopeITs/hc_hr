{{--
    The staffing chart on a phone: an indented outline instead of a scaled canvas.

    Same flat list as the canvas ($flat, depth-first, managers first, collapsed
    branches left out), so a branch opened here is open on the canvas too. Each
    row: the post, filled / capacity, open seats, the people in it as full-width
    chips (a tap opens the profile drawer, as on the canvas), one chip for its
    vacant seats (with a count), the requests already moving and the request it
    can start.
--}}
<ul class="divide-y divide-line-soft sm:hidden" aria-label="{{ __('recruitment.team_staffing.canvas.outline_label') }}">
    @foreach ($flat as $entry)
        @php
            $node = $entry['node'];
            $position = $node['position'];
            $positionId = $node['id'];
            $reports = count($node['children']);
            $waiting = $node['subtreeOpenSeats'] - $node['openSeats'];
            $holders = $position->activeContracts->map(fn ($contract) => $contract->employee)->filter()->values();
            $vacant = max($node['openSeats'], $holders->isEmpty() ? 1 : 0);
            $available = collect($node['actions'])->filter(fn (array $action): bool => $action['available']);
            $indent = min($entry['depth'], 6);
            $seats = __('recruitment.team_staffing.seats_label', ['filled' => (int) $position->exist_emp, 'capacity' => (int) $position->max_emp]);
        @endphp

        <li wire:key="staffing-row-{{ $positionId }}"
            @class(['py-3 pe-4', 'opacity-60' => $node['passthrough'] ?? false])
            style="padding-inline-start: {{ 1 + $indent * 1.25 }}rem">
            <div @class(['border-s-2 ps-3', 'border-ink' => $node['isRoot'], 'border-line-soft' => ! $node['isRoot']])>
                {{-- ---------- Header: the post, and the toggle for its reports ---------- --}}
                @if ($reports > 0)
                    <button type="button" wire:click="toggle({{ $positionId }})"
                            aria-expanded="{{ $entry['expanded'] ? 'true' : 'false' }}"
                            class="flex min-h-11 w-full cursor-pointer items-start gap-2 rounded-control text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink">
                        <flux:icon.chevron-right variant="micro"
                            @class(['mt-1 size-4 shrink-0 text-ink-2 transition-transform motion-reduce:transition-none', 'rotate-90' => $entry['expanded'], 'rtl:-scale-x-100' => ! $entry['expanded']])
                            aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold leading-snug text-ink" dir="auto">{{ $position->title }}</span>
                            <span class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-ink-2">
                                <span class="tabular-nums">{{ $seats }}</span>
                                <span>{{ trans_choice('recruitment.team_staffing.reports_count', $reports, ['count' => $reports]) }}</span>
                                @if ($waiting > 0)
                                    <span class="inline-flex items-center gap-1 tabular-nums">
                                        <span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>{{ __('recruitment.team_staffing.open_seats_below', ['count' => $waiting]) }}
                                    </span>
                                @endif
                            </span>
                        </span>
                    </button>
                @else
                    <div class="flex min-h-11 items-start gap-2">
                        <span class="mt-1 size-4 shrink-0" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold leading-snug text-ink" dir="auto">{{ $position->title }}</span>
                            <span class="mt-0.5 block text-caption tabular-nums text-ink-2">{{ $seats }}</span>
                        </span>
                    </div>
                @endif

                <div class="ms-6 mt-1 space-y-2">
                    @if ($node['isRoot'] || $node['openSeats'] > 0)
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($node['isRoot'])
                                <span class="inline-flex items-center rounded-inset border border-ink px-1.5 text-caption font-semibold leading-5 text-ink">
                                    {{ __('recruitment.team_staffing.you') }}
                                </span>
                            @endif
                            @if ($node['openSeats'] > 0)
                                <x-tag variant="soft">{{ __('recruitment.team_staffing.open_capacity_count', ['count' => $node['openSeats']]) }}</x-tag>
                            @endif
                        </div>
                    @endif

                    {{-- ---------- People, then one chip for the empty seats ---------- --}}
                    <ul class="space-y-1.5">
                        @foreach ($holders as $person)
                            <li wire:key="staffing-row-{{ $positionId }}-holder-{{ $person->getKey() }}">
                                <x-staffing-person :employee="$person" :position="$position" fluid />
                            </li>
                        @endforeach
                        @if ($vacant > 0)
                            {{-- One chip with a count: on a phone, three identical rows only push the next post down. --}}
                            <li wire:key="staffing-row-{{ $positionId }}-seats">
                                <x-staffing-vacancy :position="$position" :count="$vacant" fluid
                                                    :label="__('recruitment.team_staffing.canvas.vacant_seat')" />
                            </li>
                        @endif
                    </ul>

                    {{-- ---------- Requests already moving ---------- --}}
                    @if ($node['requests']->isNotEmpty())
                        <ul class="space-y-1" aria-label="{{ __('recruitment.team_staffing.canvas.requests') }}">
                            @foreach ($node['requests'] as $request)
                                <li wire:key="staffing-row-request-{{ $request->id }}">
                                    <a href="{{ route('recruitment.requests.show', ['requestId' => $request->id]) }}" wire:navigate
                                       class="flex min-h-11 items-center gap-1.5 rounded-control bg-soft px-3 text-caption font-semibold text-ink-2 transition-colors hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink">
                                        <span class="truncate">
                                            {{ \App\Enums\RecruitmentRequestType::getLocalizedName((int) $request->request_type)['value'] }}
                                            <span class="text-ink-3">·</span> {{ \App\Enums\RecruitmentRequestStatus::getLocalizedName((int) $request->status)['value'] }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- ---------- Starting a request ---------- --}}
                    @if ($available->isNotEmpty())
                        <div x-data="{ open: false }" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                    class="flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-control bg-primary px-3 text-small font-semibold text-on-brand transition-colors hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink">
                                <span class="flex items-center gap-1.5">
                                    <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />
                                    {{ __('recruitment.team_staffing.open_request') }}
                                </span>
                                <flux:icon.chevron-down variant="micro" class="size-4 transition-transform motion-reduce:transition-none" ::class="open ? 'rotate-180' : ''" aria-hidden="true" />
                            </button>
                            <ul x-show="open" x-cloak x-collapse class="mt-1 overflow-hidden rounded-control border border-line-soft bg-card">
                                @foreach ($available as $type => $action)
                                    <li wire:key="staffing-row-action-{{ $positionId }}-{{ $type }}" class="border-b border-line-soft last:border-b-0">
                                        <a href="{{ $action['url'] }}" wire:navigate
                                           class="flex min-h-11 items-center gap-2 px-3 text-small font-semibold text-ink transition-colors hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ink">
                                            <x-ic name="arrow" class="size-3.5 shrink-0 text-ink-3 rtl:-scale-x-100" />
                                            <span class="leading-snug">{{ $action['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </li>
    @endforeach
</ul>
