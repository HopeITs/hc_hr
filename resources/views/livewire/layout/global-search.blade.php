{{--
    Header search (C2): one box for pages, people and (prompt 10) commands, also
    opened with Ctrl/Cmd+K. A WAI-ARIA combobox: arrows move through the results,
    Enter opens the highlighted one, Escape closes. Below xl (1280px) the box hides
    behind a search button and opens as a panel under the header (full width on phones).
    A command carries data-action; resources/js/commands.js runs it.
--}}
@php
    $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
    $options = collect($pages)->map(fn ($page) => ['href' => route($page['route']), 'type' => 'page'])
        ->merge($people->map(fn ($person) => ['href' => route('employees.show', $person->username), 'type' => 'person']))
        ->merge(collect($commands)->map(fn ($command) => ['href' => $command['href'], 'type' => 'command']))
        ->values();
@endphp
<div class="relative shrink-0 xl:min-w-44 xl:shrink xl:flex-1 xl:max-w-md"
     x-data="{
         open: false,
         mobile: false,
         active: -1,
         count() { return this.$refs.list ? this.$refs.list.querySelectorAll('[role=option]').length : 0; },
         move(step) {
             const n = this.count();
             if (!n) return;
             this.open = true;
             this.active = (this.active + step + n) % n;
             this.$refs.list.querySelectorAll('[role=option]')[this.active]?.scrollIntoView({ block: 'nearest' });
         },
         choose() {
             const option = this.$refs.list?.querySelectorAll('[role=option]')[this.active];
             if (!option) return;
             {{-- Prompt 10: a command acts in the page (resources/js/commands.js); a page or person opens. --}}
             if (option.dataset.action) {
                 this.close();
                 window.dispatchEvent(new CustomEvent('hopehr-command', { detail: { action: option.dataset.action, href: option.dataset.href ?? null, returns: 'returns' in option.dataset } }));
                 return;
             }
             Livewire.navigate(option.dataset.href);
         },
         {{-- Prompt 9: an empty box shows recent pages and people, fetched the first time it opens. --}}
         recent() { if (!this.$wire.recents) this.$wire.showRecents(); },
         focus() { this.mobile = true; this.open = true; this.recent(); this.$nextTick(() => this.$refs.input.focus()); },
         close() { this.open = false; this.active = -1; this.mobile = false; },
     }"
     x-on:keydown.window.ctrl.k.prevent="focus()"
     x-on:keydown.window.meta.k.prevent="focus()"
     x-on:click.outside="close()"
     data-tour="header.search">

    {{-- Below xl: a button that opens the panel; the full box needs room the header lacks once the sidebar is beside it. --}}
    <button type="button" class="grid size-11 place-items-center rounded-[14px] text-secondary-light hover:text-secondary-dark xl:hidden"
            x-on:click="focus()" aria-label="{{ __('general.global-search.label') }}" data-testid="search-open">
        <x-icons.search class="size-5" />
    </button>

    <div class="max-xl:fixed max-sm:inset-x-2 sm:max-xl:end-4 sm:max-xl:w-[28rem] max-xl:top-[4.25rem] max-xl:z-50 max-xl:rounded-surface max-xl:bg-white max-xl:p-2 max-xl:shadow-e3"
         :class="mobile ? '' : 'max-xl:hidden'">
        <label for="global-search-input" class="sr-only">{{ __('general.global-search.label') }}</label>
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-secondary-light" aria-hidden="true">
                <x-icons.search class="size-4" />
            </span>
            <input id="global-search-input" x-ref="input" type="search" autocomplete="off" spellcheck="false"
                   wire:model.live.debounce.300ms="q"
                   role="combobox" aria-autocomplete="list" aria-controls="global-search-list"
                   :aria-expanded="open.toString()"
                   :aria-activedescendant="active >= 0 ? 'global-search-option-' + active : null"
                   placeholder="{{ __('general.global-search.placeholder') }}" data-typewriter="{{ json_encode(__('examples.global_search'), JSON_UNESCAPED_UNICODE) }}"
                   class="h-11 w-full rounded-[14px] border border-neutral-300 bg-[#f6f6f6] ps-9 pe-3 xl:pe-14 text-body text-neutral-900 placeholder-neutral-400 focus:border-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-secondary-light sm:h-10"
                   x-on:focus="open = true; recent()"
                   x-on:input="open = true; active = -1"
                   x-on:keydown.arrow-down.prevent="move(1)"
                   x-on:keydown.arrow-up.prevent="move(-1)"
                   x-on:keydown.enter.prevent="active < 0 ? move(1) : null; choose()"
                   x-on:keydown.escape.prevent="$wire.q ? $wire.set('q', '') : close()" />
            <kbd class="pointer-events-none absolute inset-y-0 end-3 my-auto hidden h-6 items-center rounded border border-neutral-300 px-1.5 text-caption text-secondary-light xl:flex" aria-hidden="true">Ctrl K</kbd>
        </div>

        <div x-show="open && ($wire.q.trim().length > 0 || $wire.recents)" x-cloak
             class="absolute start-0 top-full z-50 mt-2 w-[min(28rem,calc(100vw-2rem))] max-h-[min(70vh,28rem)] max-xl:w-auto overflow-y-auto rounded-surface border border-[var(--line-hairline)] bg-white p-1.5 shadow-e3 max-xl:static max-xl:mt-2 max-xl:shadow-none">

            {{-- Polite status for screen readers and the visible state line. --}}
            <p class="px-3 py-2 text-small text-secondary-medium" role="status" aria-live="polite">
                <span wire:loading wire:target="q,showRecents">{{ __('general.global-search.loading') }}</span>
                <span wire:loading.remove wire:target="q,showRecents">
                    @if ($showingRecents && $options->isEmpty())
                        {{ __('navigation.recent.empty') }}
                    @elseif ($showingRecents)
                        {{ trans_choice('navigation.recent.count', $options->count(), ['count' => $options->count()]) }}
                    @elseif ($tooShort)
                        {{ __('general.global-search.too-short', ['count' => \App\Livewire\Layout\GlobalSearch::MIN_LENGTH]) }}
                    @elseif ($throttled)
                        {{ __('general.global-search.throttled') }}
                    @elseif ($failed)
                        {{ __('general.global-search.failed') }}
                    @elseif ($term !== '' && $options->isEmpty())
                        {{ __('general.global-search.empty', ['term' => $term]) }}
                    @elseif ($options->isNotEmpty())
                        {{ trans_choice('general.global-search.count', $options->count(), ['count' => $options->count()]) }}
                    @endif
                </span>
            </p>

            <ul id="global-search-list" x-ref="list" role="listbox" aria-label="{{ __('general.global-search.label') }}" wire:loading.class="opacity-60" wire:target="q,showRecents">
                @php($i = 0)
                @if (count($pages))
                    <li role="presentation" class="px-3 pt-2 pb-1 text-caption font-semibold text-secondary-light">{{ $showingRecents ? __('navigation.recent.pages') : __('general.global-search.pages') }}</li>
                    @foreach ($pages as $page)
                        <li id="global-search-option-{{ $i }}" role="option" data-href="{{ route($page['route']) }}" data-testid="{{ $showingRecents ? 'recent-page' : 'search-page' }}"
                            :aria-selected="(active === {{ $i }}).toString()"
                            :class="active === {{ $i }} ? 'bg-secondary-soft' : ''"
                            x-on:mouseenter="active = {{ $i }}"
                            x-on:click="active = {{ $i }}; choose()"
                            class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-control px-3 py-2">
                            <span class="font-semibold text-neutral-900">{{ $page['label'] }}</span>
                            @if ($page['area'] !== '')
                                <span class="text-small text-secondary-medium">{{ $page['area'] }}</span>
                            @endif
                        </li>
                        @php($i++)
                    @endforeach
                @endif

                @if ($people->isNotEmpty())
                    <li role="presentation" class="px-3 pt-2 pb-1 text-caption font-semibold text-secondary-light">{{ $showingRecents ? __('navigation.recent.people') : __('general.global-search.people') }}</li>
                    @foreach ($people as $person)
                        @php($position = $person->contracts->first()?->position)
                        <li id="global-search-option-{{ $i }}" role="option" data-href="{{ route('employees.show', $person->username) }}" data-testid="{{ $showingRecents ? 'recent-person' : 'search-person' }}"
                            :aria-selected="(active === {{ $i }}).toString()"
                            :class="active === {{ $i }} ? 'bg-secondary-soft' : ''"
                            x-on:mouseenter="active = {{ $i }}"
                            x-on:click="active = {{ $i }}; choose()"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-control px-3 py-2">
                            <span class="min-w-0">
                                <span class="block font-semibold text-neutral-900">
                                    <bdi>{{ $person->name }}</bdi>
                                    @if (! $person->is_active && $person->was_employed)
                                        <span class="ms-1 text-caption font-normal text-secondary-medium">({{ __('general.global-search.former') }})</span>
                                    @endif
                                </span>
                                {{-- The other script's name, the position and the city tell namesakes apart. --}}
                                <span class="block text-small text-secondary-medium">
                                    <bdi>{{ trim($person->{'first_name_'.$other}.' '.$person->{'last_name_'.$other}) }}</bdi>
                                    @if ($position) · <bdi>{{ $position->{'title_'.app()->getLocale()} ?? $position->title_en }}</bdi>@endif
                                    @if ($person->city) · <bdi>{{ $person->city->name }}</bdi>@endif
                                </span>
                            </span>
                        </li>
                        @php($i++)
                    @endforeach
                @endif

                {{-- Prompt 10: what the role may start, and commands for the page itself (CommandPalette). --}}
                @if (count($commands))
                    <li role="presentation" class="px-3 pt-2 pb-1 text-caption font-semibold text-secondary-light">{{ __('navigation.commands.title') }}</li>
                    @foreach ($commands as $command)
                        <li id="global-search-option-{{ $i }}" role="option" data-testid="search-command" data-command="{{ $command['id'] }}"
                            data-action="{{ $command['action'] }}" @if ($command['href']) data-href="{{ $command['href'] }}" @endif @if ($command['returns']) data-returns @endif
                            :aria-selected="(active === {{ $i }}).toString()"
                            :class="active === {{ $i }} ? 'bg-secondary-soft' : ''"
                            x-on:mouseenter="active = {{ $i }}"
                            x-on:click="active = {{ $i }}; choose()"
                            class="flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-control px-3 py-2">
                            <span class="font-semibold text-neutral-900">{{ $command['label'] }}</span>
                            @if ($command['hint'])
                                <span class="text-small text-secondary-medium" @if ($command['hint_lang']) lang="{{ $command['hint_lang'] }}" dir="{{ $command['hint_lang'] === 'ar' ? 'rtl' : 'ltr' }}" @endif>{{ $command['hint'] }}</span>
                            @endif
                        </li>
                        @php($i++)
                    @endforeach
                @endif
            </ul>
        </div>
    </div>
</div>
