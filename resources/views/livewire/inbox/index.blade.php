{{--
    The work inbox (C3, approval-source-contract.md). One card per item waiting
    on the signed-in user; each action runs through its domain owner and
    reports one of the §4.1 outcomes. Every form is a real <form>, so Enter
    submits (G-B-7). Rows carry no leave reason, pay or national id (§3.2).
--}}
<x-slot:title>
    {{ __('general.nav.work-inbox') }}
</x-slot:title>

<div>
    <div class="mb-4">
        <livewire:layout.breadcrumbs :title="__('general.nav.work-inbox')" :crumbs="[]" />
        <p class="text-small text-ink-2">{{ __('inbox.intro') }}</p>
    </div>

    {{-- Filters with the same counts as the menu badge. --}}
    <div role="group" aria-label="{{ __('inbox.filter.label') }}" class="mb-4 flex flex-wrap gap-2">
        @foreach (['all', 'decide', 'review', 'checklist', 'profile'] as $key)
            @if ($key === 'all' || $counts[$key] > 0 || $filter === $key)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                        data-testid="inbox-filter-{{ $key }}"
                        @class([
                            'inline-flex min-h-11 items-center gap-2 rounded-control border-[1.5px] px-4 text-small font-semibold',
                            'border-ink bg-surface text-ink' => $filter === $key,
                            'border-line bg-card text-ink hover:bg-hover' => $filter !== $key,
                        ])>
                    {{ __("inbox.filter.{$key}") }}
                    <span @class(['rounded-full px-2 text-caption', 'bg-card' => $filter === $key, 'bg-surface' => $filter !== $key])>{{ $counts[$key] }}</span>
                </button>
            @endif
        @endforeach
    </div>

    @if ($failed)
        <div class="rounded-surface border border-line-soft bg-card p-6 text-center" role="alert">
            <p class="text-body text-ink">{{ __('inbox.failed') }}</p>
            <x-button variant="outline" class="mt-3" wire:click="$refresh">{{ __('general.toast.try-again') }}</x-button>
        </div>
    @elseif ($rows->isEmpty())
        <x-center-empty-state data-testid="inbox-empty" :title="__('inbox.empty.title')" :description="__('inbox.empty.body')">
            <x-slot:icon><x-ic name="inbox" class="size-9" /></x-slot:icon>
        </x-center-empty-state>
    @else
        {{-- Batch: only checklist confirmations (C3D-Q4). --}}
        @if ($rows->where('bulk', true)->isNotEmpty())
            <div class="mb-3 flex flex-wrap items-center gap-3 rounded-surface border border-line-soft bg-card px-4 py-2">
                <span class="text-small text-ink-2">{{ __('inbox.batch.hint') }}</span>
                <x-button wire:click="previewBatch" data-testid="inbox-batch" class="ms-auto"
                        :disabled="count($selected) === 0">
                    {{ trans_choice('inbox.batch.button', count($selected), ['count' => count($selected)]) }}
                </x-button>
            </div>
        @endif

        @if ($capped)
            <p class="mb-3 text-small text-ink-2" data-testid="inbox-capped">{{ __('inbox.capped', $capped) }}</p>
        @endif

        <ul class="flex flex-col gap-3" wire:loading.class="opacity-70" wire:target="decide,markSeen,object,confirmItem,finalizeItem,approveProfile,rejectProfile,applyBatch,setFilter">

            @foreach ($rows as $row)
                @php
                    $slug = $row['slug'];
                    $outcome = $outcomes[$slug] ?? null;
                    $subject = $row['subject'];
                @endphp
                <li wire:key="row-{{ $slug }}" id="inbox-{{ $slug }}" data-key="{{ $row['key'] }}" data-testid="inbox-row"
                    x-data x-init="@js($open === $row['key']) && $el.scrollIntoView({ block: 'center' })"
                    @class([
                        'rounded-surface border bg-card p-4 shadow-e1',
                        'border-primary ring-1 ring-primary' => $open === $row['key'],
                        'border-line-soft' => $open !== $row['key'],
                    ])>
                    <div class="flex flex-wrap items-start gap-3">
                        @if ($row['bulk'])
                            <input type="checkbox" value="{{ $row['key'] }}" wire:model.live="selected" class="mt-1 size-5 shrink-0 accent-ink"
                                   aria-label="{{ __('inbox.batch.select', ['name' => $subject?->name]) }}">
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">
                                <bdi>{{ $subject?->name ?? __('general.na') }}</bdi>
                                @if ($row['former'] ?? false)
                                    <x-tag variant="soft" class="ms-1" data-testid="inbox-former">{{ __('inbox.former') }}</x-tag>
                                @endif
                                @if ($showCity && $row['city'])
                                    <span class="text-small font-normal text-ink-2">· <bdi>{{ $row['city'] }}</bdi></span>
                                @endif
                            </p>
                            <p class="text-small text-ink-2">
                                <span class="font-semibold text-ink">{{ __("inbox.action.{$row['action']}") }}</span>
                                @if ($row['source'] === 'leave')
                                    · <bdi>{{ $row['leave_type'] }}</bdi>
                                    · <bdi dir="ltr">{{ $row['type'] === 'hour' ? $row['start_at']?->format('d-m-Y H:i').'–'.$row['end_at']?->format('H:i') : $row['start_at']?->format('d-m-Y').($row['end_at'] && ! $row['start_at']->isSameDay($row['end_at']) ? ' – '.$row['end_at']->format('d-m-Y') : '') }}</bdi>
                                    · <bdi>{{ $row['duration'] }}</bdi>
                                @else
                                    · <bdi>{{ $row['item'] }}</bdi>
                                @endif
                            </p>
                            <p class="text-caption text-ink-3">
                                {{-- A length ("3 days"), not "3 days ago": the sentence around it says since when. --}}
                                {{ __('inbox.waiting-since', ['when' => $row['waiting_since']?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                                {{-- G-C-5: an undecided leave already under way is not "starts soon"; the home block uses the same rule. --}}
                                @if ($startFlag = \App\Support\Dashboard\WaitingOnYou::startFlag($row))
                                    <x-tag class="ms-1" :variant="$startFlag === 'started' ? 'late' : 'rush'">{{ __($startFlag === 'started' ? 'inbox.started' : 'inbox.urgent') }}</x-tag>
                                @endif
                            </p>
                            @if (($row['basis'] ?? null) === 'on_behalf')
                                <p class="mt-1 text-small font-semibold text-ink">{{ __('inbox.basis.on_behalf', ['manager' => $row['managers']]) }}</p>
                            @elseif (($row['basis'] ?? null) === 'no_manager')
                                <p class="mt-1 text-small font-semibold text-ink">{{ __('inbox.basis.no_manager') }}</p>
                            @endif
                            @if (! empty($row['overlap']['members']))
                                <div class="mt-2">@include('livewire.team.partials.overlap-warning', ['overlap' => $row['overlap']])</div>
                            @endif
                        </div>
                        @if ($row['source'] === 'leave')
                            <x-button variant="link" size="sm" class="shrink-0" :href="route('leaves.all', ['leave' => $row['id'], 'mode' => 'details'])">{{ __('inbox.details') }}</x-button>
                        @endif
                    </div>

                    @if ($outcome)
                        <p class="mt-2 rounded-control bg-soft px-3 py-2 text-small text-ink" role="status" data-testid="inbox-outcome">{{ $outcome['message'] }}</p>
                    @endif

                    {{-- Actions --}}
                    <div class="mt-3">
                        @if ($row['action'] === 'decide')
                            {{-- Accept or reject; a rejection needs its reason (DEC-05). Enter submits. --}}
                            <form x-data="{ choice: null }"
                                  x-on:submit.prevent="choice && $wire.decide(@js($row['key']), @js($row['version']), choice)"
                                  class="flex flex-col gap-2">
                                <div role="radiogroup" aria-label="{{ __('inbox.decision.label') }}" class="flex flex-wrap gap-2">
                                    <button type="button" role="radio" :aria-checked="(choice === 1).toString()" x-on:click="choice = 1"
                                            class="hc-choice inline-flex min-h-11 items-center gap-2 px-4 font-semibold text-ink"><x-ic name="check" class="size-4" />{{ __('inbox.decision.accept') }}</button>
                                    <button type="button" role="radio" :aria-checked="(choice === 2).toString()" x-on:click="choice = 2; $nextTick(() => $refs.reason.focus())"
                                            class="hc-choice inline-flex min-h-11 items-center gap-2 px-4 font-semibold text-ink"><x-ic name="x" class="size-4" />{{ __('inbox.decision.reject') }}</button>
                                </div>
                                <div x-show="choice === 2" x-cloak>
                                    <label for="reason-{{ $slug }}" class="text-small font-semibold text-ink">{{ __('general.reject-reason') }} <span class="text-primary">*</span></label>
                                    <input id="reason-{{ $slug }}" x-ref="reason" type="text" maxlength="255" wire:model="reasons.{{ $slug }}"
                                           class="mt-1 h-11 w-full rounded-control border border-line bg-field px-3">
                                    @error('reasons.'.$slug) <p class="mt-1 text-small text-primary">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <x-button type="submit" ::disabled="!choice" data-testid="inbox-decide">{{ __('inbox.decision.save') }}</x-button>
                                </div>
                            </form>
                        @elseif ($row['action'] === 'review')
                            <p class="mb-2 text-small text-ink-2">
                                {{ $row['decision'] }}
                            </p>
                            {{-- Seen by HR with an optional note; objection needs one (C3D-Q5). --}}
                            <form x-data="{ objecting: false }"
                                  x-on:submit.prevent="objecting ? $wire.object(@js($row['key'])) : $wire.markSeen(@js($row['key']))"
                                  class="flex flex-col gap-2">
                                <label for="note-{{ $slug }}" class="text-small font-semibold text-ink">
                                    <span x-show="!objecting">{{ __('inbox.review.note-optional') }}</span>
                                    <span x-show="objecting" x-cloak>{{ __('inbox.review.note-required') }} <span class="text-primary">*</span></span>
                                </label>
                                <input id="note-{{ $slug }}" type="text" maxlength="{{ \App\Services\LeaveRequestReview::NOTE_MAX }}" wire:model="notes.{{ $slug }}"
                                       class="h-11 w-full rounded-control border border-line bg-field px-3">
                                @error('notes.'.$slug) <p class="text-small text-primary">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap gap-2">
                                    <x-button type="submit" x-on:click="objecting = false" data-testid="inbox-seen">{{ __('inbox.review.seen') }}</x-button>
                                    <x-button type="submit" variant="outline" x-on:click="objecting = true" data-testid="inbox-object">{{ __('inbox.review.object') }}</x-button>
                                </div>
                                <p class="text-caption text-ink-2">{{ __('inbox.review.object-help') }}</p>
                            </form>
                        @elseif ($row['source'] === 'profile')
                            {{-- D1: what the employee proposed, so HR approves what it has seen. --}}
                            @include('livewire.inbox.partials.profile-change', ['row' => $row, 'slug' => $slug])
                        @elseif ($row['action'] === 'finalize')
                            <x-button wire:click="finalizeItem({{ \Illuminate\Support\Js::from($row['key']) }})">{{ __('inbox.checklist.finalize') }}</x-button>
                        @else
                            <form wire:submit="confirmItem(@js($row['key']))" class="flex flex-wrap items-end gap-2">
                                <div class="min-w-48 flex-1">
                                    <label for="note-{{ $slug }}" class="text-small font-semibold text-ink">{{ __('inbox.checklist.note') }}</label>
                                    <input id="note-{{ $slug }}" type="text" maxlength="255" wire:model="notes.{{ $slug }}"
                                           class="mt-1 h-11 w-full rounded-control border border-line bg-field px-3">
                                </div>
                                <x-button type="submit">
                                    {{ __("inbox.checklist.{$row['action']}") }}
                                </x-button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
