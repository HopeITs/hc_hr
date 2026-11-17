@php
    $copied = __('mail_lists.copied');
    $copyIcon = '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h8"/></svg>';
@endphp

<div x-data="{
        copy(text) {
            navigator.clipboard?.writeText(text).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: @js($copied) } })));
        },
     }"
     x-on:keydown.escape.window="$wire.closeList()">
    <x-slot:title>{{ __('mail_lists.title') }}</x-slot:title>

    <x-page-header :title="__('mail_lists.title')" :lead="__('mail_lists.lead')">
        @if($canManage)
            <x-slot:actions>
                <x-button variant="outline" size="sm" :href="route('mail-lists.check')">
                    <x-ic name="check-circle" class="size-4" /> @lang('mail_lists.check.open')
                </x-button>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- Only the person who holds the control panel sees this: who was added here and still has to be added there. --}}
    @if($canConfirm)
        <section class="mb-6 rounded-surface border border-ink bg-card" data-testid="mail-lists-pending" x-data="{ open: {{ $pending->isNotEmpty() ? 'true' : 'false' }} }">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start">
                <span class="flex min-w-0 items-center gap-2">
                    <x-ic name="clock" class="size-5 shrink-0 text-ink" />
                    <span class="min-w-0">
                        <span class="block font-bold text-ink">@lang('mail_lists.pending_title')</span>
                        <span class="block text-small text-ink-2">@lang('mail_lists.pending_help')</span>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="rounded-full bg-ink px-2.5 py-0.5 text-small font-bold text-on-ink num">{{ $pending->count() }}</span>
                    <x-ic name="chevron" class="size-4 text-ink-2 transition" x-bind:class="open && 'rotate-90'" />
                </span>
            </button>

            <div x-show="open" x-cloak class="divide-y divide-line-soft border-t border-line-soft">
                @forelse($pending as $person)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="pending-{{ $person['email'] }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink"><bdi>{{ $person['name'] ?? $person['email'] }}</bdi></p>
                            @if($person['name'])
                                <p class="text-small text-ink-2" dir="ltr">{{ $person['email'] }}</p>
                            @endif
                            <p class="mt-1 flex flex-wrap items-center gap-1 text-small text-ink-2">
                                @lang('mail_lists.pending_lists'):
                                @foreach($person['lists'] as $address)
                                    <code class="rounded bg-soft px-1.5 py-0.5" dir="ltr">{{ $address }}</code>
                                @endforeach
                            </p>
                        </div>
                        <x-button size="sm" variant="outline" wire:click="confirmEmail('{{ $person['email'] }}')">
                            <x-ic name="check" class="size-4" /> @lang('mail_lists.pending_done')
                        </x-button>
                    </div>
                @empty
                    <p class="px-4 py-3 text-body text-ink-2">@lang('mail_lists.pending_none')</p>
                @endforelse
            </div>
        </section>
    @endif

    {{-- The same person, the other way round: left the organisation, still in the groups at the control panel. --}}
    @if($canConfirm && $leaving->isNotEmpty())
        <section class="mb-6 rounded-surface border border-line bg-card" data-testid="mail-lists-leaving" x-data="{ open: true }">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open"
                    class="flex w-full items-center justify-between gap-3 px-4 py-3 text-start">
                <span class="flex min-w-0 items-center gap-2">
                    <x-ic name="user" class="size-5 shrink-0 text-ink" />
                    <span class="min-w-0">
                        <span class="block font-bold text-ink">@lang('mail_lists.leaving_title')</span>
                        <span class="block text-small text-ink-2">@lang('mail_lists.leaving_help')</span>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="rounded-full bg-ink px-2.5 py-0.5 text-small font-bold text-on-ink num">{{ $leaving->count() }}</span>
                    <x-ic name="chevron" class="size-4 text-ink-2 transition" x-bind:class="open && 'rotate-90'" />
                </span>
            </button>

            <div x-show="open" x-cloak class="divide-y divide-line-soft border-t border-line-soft">
                @foreach($leaving as $person)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="leaving-{{ $person['email'] }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink"><bdi>{{ $person['name'] ?? $person['email'] }}</bdi></p>
                            @if($person['name'])
                                <p class="text-small text-ink-2" dir="ltr">{{ $person['email'] }}</p>
                            @endif
                            <p class="mt-1 flex flex-wrap items-center gap-1 text-small text-ink-2">
                                @lang('mail_lists.leaving_lists'):
                                @foreach($person['lists'] as $address)
                                    <code class="rounded bg-soft px-1.5 py-0.5" dir="ltr">{{ $address }}</code>
                                @endforeach
                            </p>
                        </div>
                        <x-button size="sm" variant="outline" wire:click="confirmRemovalEmail('{{ $person['email'] }}')">
                            <x-ic name="check" class="size-4" /> @lang('mail_lists.leaving_done')
                        </x-button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($total === 0 && $rows->isEmpty() && $search === '' && $category === '')
        <x-center-empty-state variant="empty" :title="__('mail_lists.empty')" :description="__('mail_lists.empty_help')" />
    @else
        {{-- Find a list, or the lists a person is on; narrow by group. --}}
        <div class="mb-5 space-y-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-0 flex-1 basis-72">
                    <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3">
                        <x-ic name="search" class="size-4" />
                    </span>
                    <input type="search" wire:model.live.debounce.250ms="search" dir="auto"
                           placeholder="{{ __('mail_lists.search_placeholder') }}" aria-label="{{ __('mail_lists.search_placeholder') }}"
                           class="block w-full rounded-control border border-line bg-card py-2.5 ps-10 pe-3 text-body text-ink placeholder:text-ink-3">
                </div>
                <p class="text-small text-ink-2 num" role="status">
                    {{ __('mail_lists.summary', ['lists' => $rows->count(), 'people' => $total]) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('mail_lists.groups') }}">
                <button type="button" wire:click="setCategory('')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}"
                        @class(['inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-small font-semibold transition',
                            'border-ink bg-ink text-on-ink' => $category === '',
                            'border-line bg-card text-ink hover:bg-hover' => $category !== ''])>
                    @lang('mail_lists.cat.all')
                </button>
                @foreach($categories as $key => $count)
                    <button type="button" wire:click="setCategory('{{ $key }}')" wire:key="cat-{{ $key }}" aria-pressed="{{ $category === $key ? 'true' : 'false' }}"
                            @class(['inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3 text-small font-semibold transition',
                                'border-ink bg-ink text-on-ink' => $category === $key,
                                'border-line bg-card text-ink hover:bg-hover' => $category !== $key])>
                        @lang('mail_lists.cat.'.$key)
                        <span class="num opacity-70">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @if($rows->isEmpty())
            <x-center-empty-state variant="empty" :title="__('mail_lists.no_results')" :description="__('mail_lists.no_results_help')" />
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="mail-lists">
                @foreach($rows as $row)
                    @php($list = $row['list'])
                    <article wire:key="list-{{ $list->id }}" class="flex flex-col rounded-surface border border-line-soft bg-card p-4 shadow-e1">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <x-tag variant="soft" :label="__('mail_lists.cat.'.$row['category'])" />
                                <h2 class="mt-2 truncate text-h4 font-bold text-ink"><bdi>{{ $list->name }}</bdi></h2>
                            </div>
                            <span class="shrink-0 text-end">
                                <span class="block text-h3 font-extrabold leading-none text-ink num">{{ $row['members']->count() }}</span>
                                <span class="text-caption text-ink-2">{{ trans_choice('mail_lists.people', $row['members']->count()) }}</span>
                            </span>
                        </div>

                        {{-- The list's own address, one tap to copy. --}}
                        <div class="mt-2 flex items-center gap-1">
                            <p class="min-w-0 truncate text-small text-ink-2" dir="ltr">{{ $list->address }}</p>
                            <button type="button" x-on:click="copy(@js($list->address))" title="{{ __('mail_lists.copy_address') }}" aria-label="{{ __('mail_lists.copy_address') }}"
                                    class="grid size-8 shrink-0 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink">{!! $copyIcon !!}</button>
                        </div>

                        {{-- Who matched the search, when the list itself did not. --}}
                        @if(! empty($row['matches']) && $row['matches']->isNotEmpty())
                            <p class="mt-2 rounded-control bg-soft px-2 py-1.5 text-small text-ink">
                                <span class="text-ink-2">@lang('mail_lists.matches'):</span>
                                @foreach($row['matches'] as $match)
                                    <bdi class="font-semibold">{{ $match['name'] ?? $match['email'] }}</bdi>@if(! $loop->last)،@endif
                                @endforeach
                            </p>
                        @endif

                        <div class="mt-4 flex flex-wrap items-center gap-2 pt-1">
                            {{-- Opens a new message to the list's own address; the mail provider distributes it. --}}
                            <x-button size="sm" :href="'mailto:'.$list->address" :wire:navigate="false">
                                <x-icons.envelope class="size-4" /> @lang('mail_lists.email_list')
                            </x-button>
                            <x-button size="sm" variant="outline" wire:click="openList({{ $list->id }})">
                                <x-ic name="users" class="size-4" /> @lang('mail_lists.members_button')
                            </x-button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    @endif

    {{-- The members of one list, in a side panel: search, copy, and (HR) add / remove. --}}
    @if($open)
        @php($list = $open['list'])
        <div class="fixed inset-0 z-50" data-testid="mail-list-panel" role="dialog" aria-modal="true" aria-labelledby="ml-panel-title">
            <div class="absolute inset-0 bg-backdrop backdrop-blur-[1px]" wire:click="closeList"></div>

            <div class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] min-h-0 flex-col rounded-t-3xl bg-card shadow-e3 sm:inset-y-0 sm:end-0 sm:start-auto sm:h-dvh sm:max-h-none sm:w-[28rem] sm:rounded-none"
                 x-data="{ q: '' }" x-trap.noscroll="true">
                <header class="shrink-0 border-b border-line-soft px-5 pb-4 pt-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 id="ml-panel-title" class="truncate text-h3 font-extrabold text-ink"><bdi>{{ $list->name }}</bdi></h2>
                            <p class="truncate text-small text-ink-2" dir="ltr">{{ $list->address }}</p>
                            <p class="mt-1 text-small text-ink-2 num">{{ trans_choice('mail_lists.members', $open['members']->count(), ['count' => $open['members']->count()]) }}</p>
                        </div>
                        <button type="button" wire:click="closeList" aria-label="{{ __('mail_lists.close') }}"
                                class="-me-2 grid size-11 shrink-0 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink">
                            <x-ic name="x" class="size-5" />
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-button size="sm" :href="'mailto:'.$list->address" :wire:navigate="false">
                            <x-icons.envelope class="size-4" /> @lang('mail_lists.email_list')
                        </x-button>
                        <x-button size="sm" variant="outline" :loading="false" x-on:click="copy({{ \Illuminate\Support\Js::from($list->address) }})">{!! $copyIcon !!} @lang('mail_lists.copy_address')</x-button>
                    </div>

                    <div class="relative mt-3">
                        <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3"><x-ic name="search" class="size-4" /></span>
                        <input type="search" x-model="q" dir="auto" placeholder="{{ __('mail_lists.member_search') }}" aria-label="{{ __('mail_lists.member_search') }}"
                               class="block w-full rounded-control border border-line bg-card py-2 ps-10 pe-3 text-body text-ink placeholder:text-ink-3">
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-2">
                    <ul class="divide-y divide-line-soft">
                        @foreach($open['members'] as $member)
                            @php($label = $member['external'] && ! $canManage ? __('mail_lists.external') : ($member['name'] ?? $member['email']))
                            <li wire:key="member-{{ $member['id'] }}" class="flex items-center gap-3 py-2.5"
                                x-show="! q || @js(\Illuminate\Support\Str::lower($label.' '.($member['external'] && ! $canManage ? '' : $member['email']))).includes(q.toLowerCase())">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface text-small font-bold text-ink-2" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($label, 0, 1)) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold text-ink"><bdi>{{ $label }}</bdi></span>
                                    @if($member['name'] && ! ($member['external'] && ! $canManage))
                                        <span class="block truncate text-caption text-ink-2" dir="ltr">{{ $member['email'] }}</span>
                                    @endif
                                    <span class="flex flex-wrap gap-1">
                                        @if($canConfirm && $member['pending'])
                                            <x-tag variant="rush" icon="clock" :label="__('mail_lists.pending_tag')" />
                                        @endif
                                        @if($canConfirm && $member['leaving'])
                                            <x-tag variant="soft" icon="user" :label="__('mail_lists.leaving_tag')" />
                                        @endif
                                        @if($canManage && $member['former'])
                                            <x-tag variant="soft" :label="__('mail_lists.former')" />
                                        @elseif($canManage && ! $member['account'])
                                            <x-tag variant="soft" :label="__('mail_lists.no_account')" />
                                        @endif
                                    </span>
                                </span>

                                <span class="flex shrink-0 items-center gap-1">
                                    @unless($member['external'] && ! $canManage)
                                        <button type="button" x-on:click="copy(@js($member['email']))" title="{{ __('mail_lists.copy_email') }}" aria-label="{{ __('mail_lists.copy_email') }}"
                                                class="grid size-8 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink">{!! $copyIcon !!}</button>
                                    @endunless
                                    @if($canConfirm && $member['pending'])
                                        <button type="button" wire:click="confirmMember({{ $member['id'] }})" title="{{ __('mail_lists.pending_done') }}" aria-label="{{ __('mail_lists.pending_done') }}"
                                                class="grid size-8 place-items-center rounded-control text-ink transition hover:bg-hover"><x-ic name="check" class="size-4" /></button>
                                    @endif
                                    @if($canConfirm && $member['leaving'])
                                        <button type="button" wire:click="confirmRemoval({{ $member['id'] }})" title="{{ __('mail_lists.leaving_done') }}" aria-label="{{ __('mail_lists.leaving_done') }}"
                                                class="grid size-8 place-items-center rounded-control text-ink transition hover:bg-hover"><x-ic name="check" class="size-4" /></button>
                                    @endif
                                    @if($canManage)
                                        <button type="button" wire:click="removeMember({{ $member['id'] }})" wire:confirm="{{ __('mail_lists.remove_confirm') }}" title="{{ __('mail_lists.remove') }}" aria-label="{{ __('mail_lists.remove') }}"
                                                class="grid size-8 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-primary"><x-ic name="trash" class="size-4" /></button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if($canManage)
                    <footer class="shrink-0 space-y-3 border-t border-line-soft bg-card px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                        <form wire:submit.prevent="addMember({{ $list->id }})" class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <x-forms.input type="email" dir="ltr" wire:model="newEmail.{{ $list->id }}" placeholder="name@hcsyria.org" aria-label="{{ __('mail_lists.add_member') }}" />
                                @error("newEmail.{$list->id}") <x-error-span>{{ $message }}</x-error-span> @enderror
                                <p class="mt-1 text-caption text-ink-2">@lang('mail_lists.add_hint')</p>
                            </div>
                            <x-button type="submit" size="sm"><x-ic name="plus" class="size-4" /> @lang('mail_lists.add')</x-button>
                        </form>
                        <button type="button" wire:click="deleteList({{ $list->id }})" wire:confirm="{{ __('mail_lists.delete_confirm') }}"
                                class="text-small underline text-ink-2 hover:text-primary">@lang('mail_lists.delete_list')</button>
                    </footer>
                @endif
            </div>
        </div>
    @endif
</div>
