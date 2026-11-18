{{--
    "Email a list": a menu that opens a new message to the list chosen (nothing is sent from here).

      <x-mail-list-menu :suggested="$lists" :people="$emails" :people-label="…" align="end" :up="true" />

    up: open above the button, for a menu inside a scrolling panel that would clip it.
    suggested: lists to put first (from MailList::suggestionsFor), marked as suggested.
    people:    addresses to offer as one more choice ("these people directly"), when it makes sense.
    Every list of the organisation is below, grouped, with a search.
--}}
@props(['suggested' => null, 'people' => [], 'peopleLabel' => null, 'label' => null, 'align' => 'start', 'up' => false])

@php
    $suggested = collect($suggested);
    $people = array_values(array_filter((array) $people));
    $groups = \App\Models\MailList::menuGroups();
    $peopleHref = $people ? 'mailto:'.implode(',', array_map(fn ($email) => str_replace('%40', '@', rawurlencode($email)), $people)) : null;
    // A link longer than about 2000 characters is refused by the mail program, so a team too big for one is not offered.
    $peopleHref = $peopleHref && strlen($peopleHref) <= 1900 ? $peopleHref : null;
    $item = 'flex flex-col px-3 py-2 text-start transition hover:bg-hover';
@endphp

@if($groups->isNotEmpty() || $peopleHref)
    <div class="relative" x-data="{ open: false, q: '' }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" data-testid="mail-list-menu">
        <x-button variant="outline" size="sm" type="button" :loading="false" x-on:click="open = ! open; if (open) $nextTick(() => $refs.q.focus())" x-bind:aria-expanded="open" aria-haspopup="true">
            <x-icons.envelope class="size-4" /> {{ $label ?? __('mail_lists.email_a_list') }}
            <x-ic name="chevron" class="size-3 rotate-90" />
        </x-button>

        <div x-show="open" x-cloak
             class="absolute z-30 {{ $up ? 'bottom-full mb-1' : 'mt-1' }} w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-surface border border-line-soft bg-card shadow-e3 {{ $align === 'end' ? 'end-0' : 'start-0' }}">
            <div class="border-b border-line-soft p-2">
                <input type="search" x-ref="q" x-model="q" dir="auto" placeholder="{{ __('mail_lists.menu_search') }}" aria-label="{{ __('mail_lists.menu_search') }}"
                       class="block w-full rounded-control border border-line bg-card px-3 py-2 text-small text-ink placeholder:text-ink-3">
            </div>

            <div class="max-h-64 overflow-y-auto py-1">
                @if($peopleHref)
                    <a href="{{ $peopleHref }}" data-testid="mail-people" x-show="! q" class="{{ $item }}">
                        <span class="text-small font-semibold text-ink">{{ $peopleLabel ?? __('mail_lists.menu_people') }}</span>
                        <span class="text-caption text-ink-2 num">{{ trans_choice('mail_lists.menu_people_count', count($people), ['count' => count($people)]) }}</span>
                    </a>
                @endif

                @if($suggested->isNotEmpty())
                    <p class="px-3 pt-2 text-caption font-bold uppercase tracking-wide text-ink-3" x-show="! q">@lang('mail_lists.menu_suggested')</p>
                    @foreach($suggested as $list)
                        <a href="{{ $list->mailto() }}" data-testid="mail-list-suggested" x-show="! q" class="{{ $item }}">
                            <span class="text-small font-semibold text-ink"><bdi>{{ $list->name }}</bdi></span>
                            <span class="text-caption text-ink-2" dir="ltr">{{ $list->address }}</span>
                        </a>
                    @endforeach
                @endif

                @foreach($groups as $category => $lists)
                    <p class="px-3 pt-2 text-caption font-bold uppercase tracking-wide text-ink-3" x-show="! q">@lang('mail_lists.cat.'.$category)</p>
                    @foreach($lists as $list)
                        <a href="{{ $list->mailto() }}" class="{{ $item }}"
                           x-show="! q || @js(\Illuminate\Support\Str::lower($list->name.' '.$list->address)).includes(q.toLowerCase())">
                            <span class="text-small font-semibold text-ink"><bdi>{{ $list->name }}</bdi></span>
                            <span class="text-caption text-ink-2" dir="ltr">{{ $list->address }}</span>
                        </a>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>
@endif
