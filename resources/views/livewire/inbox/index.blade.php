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
        <p class="text-small text-secondary-medium">{{ __('inbox.intro') }}</p>
    </div>

    {{-- Filters with the same counts as the menu badge. --}}
    <div role="group" aria-label="{{ __('inbox.filter.label') }}" class="mb-4 flex flex-wrap gap-2">
        @foreach (['all', 'decide', 'review', 'checklist'] as $key)
            @if ($key === 'all' || $counts[$key] > 0 || $filter === $key)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                        data-testid="inbox-filter-{{ $key }}"
                        @class([
                            'inline-flex min-h-11 items-center gap-2 rounded-control border px-4 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'border-primary bg-primary text-white' => $filter === $key,
                            'border-neutral-300 bg-white text-neutral-800 hover:bg-neutral-50' => $filter !== $key,
                        ])>
                    {{ __("inbox.filter.{$key}") }}
                    <span @class(['rounded-full px-2 text-caption', 'bg-white/25' => $filter === $key, 'bg-neutral-100' => $filter !== $key])>{{ $counts[$key] }}</span>
                </button>
            @endif
        @endforeach
    </div>

    @if ($failed)
        <div class="rounded-surface border border-[var(--line-hairline)] bg-white p-6 text-center" role="alert">
            <p class="text-body text-neutral-800">{{ __('inbox.failed') }}</p>
            <button type="button" wire:click="$refresh" class="mt-3 min-h-11 rounded-control border border-primary px-4 font-semibold text-primary">{{ __('general.toast.try-again') }}</button>
        </div>
    @elseif ($rows->isEmpty())
        <div class="rounded-surface border border-[var(--line-hairline)] bg-white p-8 text-center" data-testid="inbox-empty">
            <p class="text-h4 font-semibold text-neutral-900">{{ __('inbox.empty.title') }}</p>
            <p class="mt-1 text-body text-secondary-medium">{{ __('inbox.empty.body') }}</p>
        </div>
    @else
        {{-- Batch: only checklist confirmations (C3D-Q4). --}}
        @if ($rows->where('bulk', true)->isNotEmpty())
            <div class="mb-3 flex flex-wrap items-center gap-3 rounded-surface border border-[var(--line-hairline)] bg-white px-4 py-2">
                <span class="text-small text-secondary-medium">{{ __('inbox.batch.hint') }}</span>
                <button type="button" wire:click="previewBatch" data-testid="inbox-batch"
                        @disabled(count($selected) === 0)
                        class="ms-auto min-h-11 rounded-control bg-primary px-4 font-semibold text-white disabled:opacity-50">
                    {{ trans_choice('inbox.batch.button', count($selected), ['count' => count($selected)]) }}
                </button>
            </div>
        @endif

        <ul class="flex flex-col gap-3" wire:loading.class="opacity-70" wire:target="decide,markSeen,object,confirmItem,finalizeItem,applyBatch,setFilter">
            @foreach ($rows as $row)
                @php
                    $slug = $row['slug'];
                    $outcome = $outcomes[$slug] ?? null;
                    $subject = $row['subject'];
                @endphp
                <li wire:key="row-{{ $slug }}" id="inbox-{{ $slug }}" data-key="{{ $row['key'] }}" data-testid="inbox-row"
                    x-data x-init="@js($open === $row['key']) && $el.scrollIntoView({ block: 'center' })"
                    @class([
                        'rounded-surface border bg-white p-4 shadow-e1',
                        'border-primary ring-2 ring-primary/20' => $open === $row['key'],
                        'border-[var(--line-hairline)]' => $open !== $row['key'],
                    ])>
                    <div class="flex flex-wrap items-start gap-3">
                        @if ($row['bulk'])
                            <input type="checkbox" value="{{ $row['key'] }}" wire:model.live="selected" class="mt-1 size-5 shrink-0 accent-[var(--color-primary)]"
                                   aria-label="{{ __('inbox.batch.select', ['name' => $subject?->name]) }}">
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-neutral-900">
                                <bdi>{{ $subject?->name ?? __('general.na') }}</bdi>
                                @if ($showCity && $row['city'])
                                    <span class="text-small font-normal text-secondary-medium">· <bdi>{{ $row['city'] }}</bdi></span>
                                @endif
                            </p>
                            <p class="text-small text-secondary-medium">
                                <span class="font-semibold text-secondary-dark">{{ __("inbox.action.{$row['action']}") }}</span>
                                @if ($row['source'] === 'leave')
                                    · <bdi>{{ $row['leave_type'] }}</bdi>
                                    · <bdi dir="ltr">{{ $row['type'] === 'hour' ? $row['start_at']?->format('d-m-Y H:i').'–'.$row['end_at']?->format('H:i') : $row['start_at']?->format('d-m-Y').($row['end_at'] && ! $row['start_at']->isSameDay($row['end_at']) ? ' – '.$row['end_at']->format('d-m-Y') : '') }}</bdi>
                                    · <bdi>{{ $row['duration'] }}</bdi>
                                @else
                                    · <bdi>{{ $row['item'] }}</bdi>
                                @endif
                            </p>
                            <p class="text-caption text-secondary-light">
                                {{-- A length ("3 days"), not "3 days ago": the sentence around it says since when. --}}
                                {{ __('inbox.waiting-since', ['when' => $row['waiting_since']?->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                                @if ($row['urgent'])
                                    <span class="ms-1 rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200">{{ __('inbox.urgent') }}</span>
                                @endif
                            </p>
                            @if (($row['basis'] ?? null) === 'on_behalf')
                                <p class="mt-1 text-small text-primary">{{ __('inbox.basis.on_behalf', ['manager' => $row['managers']]) }}</p>
                            @elseif (($row['basis'] ?? null) === 'no_manager')
                                <p class="mt-1 text-small text-primary">{{ __('inbox.basis.no_manager') }}</p>
                            @endif
                        </div>
                        @if ($row['source'] === 'leave')
                            <a href="{{ route('leaves.all', ['leave' => $row['id'], 'mode' => 'details']) }}" wire:navigate
                               class="min-h-11 shrink-0 content-center rounded-control px-3 text-small font-semibold text-primary hover:bg-neutral-50">{{ __('inbox.details') }}</a>
                        @endif
                    </div>

                    @if ($outcome)
                        <p class="mt-2 rounded-control bg-neutral-50 px-3 py-2 text-small text-neutral-800" role="status" data-testid="inbox-outcome">{{ $outcome['message'] }}</p>
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
                                            :class="choice === 1 ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-neutral-300 bg-white text-neutral-800'"
                                            class="min-h-11 rounded-control border px-4 font-semibold">{{ __('inbox.decision.accept') }}</button>
                                    <button type="button" role="radio" :aria-checked="(choice === 2).toString()" x-on:click="choice = 2; $nextTick(() => $refs.reason.focus())"
                                            :class="choice === 2 ? 'border-primary bg-[#fbeaea] text-primary' : 'border-neutral-300 bg-white text-neutral-800'"
                                            class="min-h-11 rounded-control border px-4 font-semibold">{{ __('inbox.decision.reject') }}</button>
                                </div>
                                <div x-show="choice === 2" x-cloak>
                                    <label for="reason-{{ $slug }}" class="text-small font-semibold text-neutral-800">{{ __('general.reject-reason') }} <span class="text-primary">*</span></label>
                                    <input id="reason-{{ $slug }}" x-ref="reason" type="text" maxlength="255" wire:model="reasons.{{ $slug }}"
                                           class="mt-1 h-11 w-full rounded-control border border-neutral-300 px-3 focus:border-primary focus:outline-none focus:ring-2 focus:ring-secondary-light">
                                    @error('reasons.'.$slug) <p class="mt-1 text-small text-primary">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <button type="submit" :disabled="!choice" data-testid="inbox-decide"
                                            class="min-h-11 rounded-control bg-primary px-5 font-semibold text-white disabled:opacity-50">{{ __('inbox.decision.save') }}</button>
                                </div>
                            </form>
                        @elseif ($row['action'] === 'review')
                            <p class="mb-2 text-small text-secondary-medium">
                                {{ __($row['status'] === \App\Enums\LeaveRequestStatus::Accepted ? 'inbox.review.accepted' : 'inbox.review.rejected') }}
                            </p>
                            {{-- Seen by HR with an optional note; objection needs one (C3D-Q5). --}}
                            <form x-data="{ objecting: false }"
                                  x-on:submit.prevent="objecting ? $wire.object(@js($row['key'])) : $wire.markSeen(@js($row['key']))"
                                  class="flex flex-col gap-2">
                                <label for="note-{{ $slug }}" class="text-small font-semibold text-neutral-800">
                                    <span x-show="!objecting">{{ __('inbox.review.note-optional') }}</span>
                                    <span x-show="objecting" x-cloak>{{ __('inbox.review.note-required') }} <span class="text-primary">*</span></span>
                                </label>
                                <input id="note-{{ $slug }}" type="text" maxlength="{{ \App\Services\LeaveRequestReview::NOTE_MAX }}" wire:model="notes.{{ $slug }}"
                                       class="h-11 w-full rounded-control border border-neutral-300 px-3 focus:border-primary focus:outline-none focus:ring-2 focus:ring-secondary-light">
                                @error('notes.'.$slug) <p class="text-small text-primary">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" x-on:click="objecting = false" data-testid="inbox-seen"
                                            class="min-h-11 rounded-control bg-primary px-5 font-semibold text-white">{{ __('inbox.review.seen') }}</button>
                                    <button type="submit" x-on:click="objecting = true" data-testid="inbox-object"
                                            class="min-h-11 rounded-control border border-primary bg-white px-5 font-semibold text-primary">{{ __('inbox.review.object') }}</button>
                                </div>
                                <p class="text-caption text-secondary-medium">{{ __('inbox.review.object-help') }}</p>
                            </form>
                        @elseif ($row['action'] === 'finalize')
                            <button type="button" wire:click="finalizeItem(@js($row['key']))"
                                    class="min-h-11 rounded-control bg-primary px-5 font-semibold text-white">{{ __('inbox.checklist.finalize') }}</button>
                        @else
                            <form wire:submit="confirmItem(@js($row['key']))" class="flex flex-wrap items-end gap-2">
                                <div class="min-w-48 flex-1">
                                    <label for="note-{{ $slug }}" class="text-small font-semibold text-neutral-800">{{ __('inbox.checklist.note') }}</label>
                                    <input id="note-{{ $slug }}" type="text" maxlength="255" wire:model="notes.{{ $slug }}"
                                           class="mt-1 h-11 w-full rounded-control border border-neutral-300 px-3 focus:border-primary focus:outline-none focus:ring-2 focus:ring-secondary-light">
                                </div>
                                <button type="submit" class="min-h-11 rounded-control bg-primary px-5 font-semibold text-white">
                                    {{ __("inbox.checklist.{$row['action']}") }}
                                </button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
