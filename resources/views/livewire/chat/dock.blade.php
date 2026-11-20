{{--
    The chat dock (App\Livewire\Chat\Dock). Bottom corner on the start side
    (left in English, right in Arabic), just past the sidebar and following it
    as it opens and closes: the "Chats" button sits at the start and tabs line
    up after it, newest nearest. On a phone the bar is not shown; the header
    icon opens the list and an opened chat fills the screen until it is closed.

    sidebarCollapsed comes from the page's own x-data on <body> (layouts/app).
--}}
<div x-data="chatDock(@js('hc-chat-tabs:'.auth()->id()))"
     x-on:chat-tabs-changed.window="save($event.detail.tabs)"
     x-on:keydown.escape.window="if ($wire.launcherOpen) $wire.toggleLauncher()"
     {{-- Livewire leaves the root's own attributes alone, so the display that
          chat.js sets (hidden on the full chats page) survives re-renders. --}}
     wire:ignore.self
     data-testid="chat-dock">

    {{-- The bar, in reading order: launcher, newest tab ... oldest, "+N". It
         starts where the page content starts (sidebar + the content's 2rem
         padding, layouts/app) and rests on the footer's top line while the
         footer is on screen (--chat-dock-lift, chat.js). --}}
    <div class="fixed bottom-[var(--chat-dock-lift,0px)] start-4 z-[45] flex items-end gap-2 transition-[inset-inline-start] duration-(--sidebar-duration) ease-sidebar"
         :class="sidebarCollapsed ? 'md:start-28' : 'md:start-72'">

        {{-- The launcher. --}}
        <button type="button" wire:click="toggleLauncher"
                aria-expanded="{{ $launcherOpen ? 'true' : 'false' }}"
                class="flex h-10 w-32 items-center gap-2 rounded-t-[10px] bg-primary px-3 text-small font-semibold text-white max-md:hidden"
                data-testid="chat-launcher">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.2A8 8 0 1 1 21 12z"/></svg>
            <span class="flex-1 truncate text-start">{{ __('chat.ui.chats') }}</span>
            @if ($unreadChats > 0)
                <span class="min-w-[18px] rounded-full bg-white px-1 text-center text-[11px] font-bold text-primary"
                      aria-label="{{ trans_choice('chat.ui.unread_chats', $unreadChats, ['count' => $unreadChats]) }}">{{ $unreadChats }}</span>
            @endif
        </button>

        {{-- Every tab's window, newest first so it sits beside the launcher.
             Those behind "+N" stay mounted but hidden on desktop. --}}
        @foreach ($visible as $tab)
            <livewire:chat.window :conversation-id="$tab['id']" :open="$tab['open']" :in-bar="true"
                                  :mobile-active="$mobileActiveId === $tab['id']"
                                  :wire:key="'chat-window-'.$tab['id']" />
        @endforeach
        @foreach ($overflow as $tab)
            <livewire:chat.window :conversation-id="$tab['id']" :open="$tab['open']" :in-bar="false"
                                  :mobile-active="$mobileActiveId === $tab['id']"
                                  :wire:key="'chat-window-'.$tab['id']" />
        @endforeach

        @if ($overflow->isNotEmpty())
            <div x-data="{ open: false }" class="relative max-md:hidden" x-on:click.outside="open = false">
                <button type="button" x-on:click="open = ! open"
                        class="flex h-10 min-w-11 items-center justify-center rounded-t-[10px] border border-b-0 border-line bg-card px-3 text-small font-semibold text-ink-2 hover:text-ink"
                        aria-label="{{ __('chat.ui.more_chats', ['count' => $overflow->count()]) }}"
                        data-testid="chat-overflow">
                    +{{ $overflow->count() }}
                    @if ($overflow->sum(fn ($t) => $summaries[$t['id']]['unread'] ?? 0) > 0)
                        <span class="ms-1 size-2 rounded-full bg-primary" aria-hidden="true"></span>
                    @endif
                </button>
                <div x-show="open" x-cloak
                     class="absolute bottom-full start-0 mb-1 w-56 rounded-surface border border-line bg-card p-1 shadow-e3">
                    @foreach ($overflow as $tab)
                        @php($item = $summaries[$tab['id']])
                        <button type="button" wire:click="openConversation({{ $tab['id'] }})" x-on:click="open = false"
                                class="flex w-full items-center gap-2 rounded-control px-2 py-1.5 text-start hover:bg-hover">
                            <x-chat.avatar :item="$item" size="size-7" />
                            <span class="min-w-0 flex-1 truncate text-small text-ink">{{ $item['title'] }}</span>
                            @if ($item['unread'] > 0)
                                <span class="rounded-full px-1.5 text-[11px] font-semibold text-white {{ $item['muted'] ? 'bg-ink-3' : 'bg-primary' }}">{{ $item['unread'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- The chats list / new-chat picker: a panel above the launcher on
         desktop, the whole screen on a phone. --}}
    @if ($launcherOpen)
        <section role="dialog" aria-modal="false" aria-labelledby="chat-launcher-title"
                 class="fixed z-[9600] flex flex-col overflow-hidden border border-line bg-card shadow-e3
                        max-md:inset-0
                        md:bottom-[calc(2.75rem+var(--chat-dock-lift,0px))] md:h-[min(30rem,calc(100dvh-8rem))] md:w-80 md:rounded-surface"
                 :class="sidebarCollapsed ? 'md:start-28' : 'md:start-72'"
                 data-testid="chat-launcher-panel">

            @if ($launcherMode === 'chats')
                <header class="flex items-center gap-2 border-b border-line-soft px-3 py-2">
                    <h2 id="chat-launcher-title" class="flex-1 text-body font-semibold text-ink">{{ __('chat.ui.chats') }}</h2>
                    {{-- The full chats page, like a tab's maximise (no pencil: searching a
                         name already offers colleagues to start a chat with). --}}
                    <button type="button" x-on:click="maximize(@js(route('chat')))"
                            class="grid size-9 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink max-md:hidden"
                            aria-label="{{ __('chat.ui.maximize') }}" title="{{ __('chat.ui.maximize') }}" data-testid="chat-launcher-maximize">
                        <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                    </button>
                    <button type="button" wire:click="toggleLauncher"
                            class="grid size-9 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink"
                            aria-label="{{ __('chat.ui.close') }}" title="{{ __('chat.ui.close') }}">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="px-3 py-2">
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="{{ __('chat.ui.search_chats') }}" aria-label="{{ __('chat.ui.search_chats') }}"
                           class="hc-field h-9 w-full rounded-full text-small">
                </div>

                @include('livewire.chat.partials.list', ['openAction' => 'openConversation', 'startAction' => 'startWith', 'emptyAction' => 'showPeople'])
            @else
                <header class="flex items-center gap-2 border-b border-line-soft px-3 py-2">
                    <button type="button" wire:click="showChats"
                            class="grid size-9 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink"
                            aria-label="{{ __('chat.ui.back') }}" title="{{ __('chat.ui.back') }}">
                        <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </button>
                    <h2 id="chat-launcher-title" class="flex-1 text-body font-semibold text-ink">{{ __('chat.ui.new_chat') }}</h2>
                </header>

                <div class="px-3 py-2">
                    <input type="search" wire:model.live.debounce.300ms="peopleSearch" x-init="$el.focus()"
                           placeholder="{{ __('chat.ui.search_people') }}" aria-label="{{ __('chat.ui.search_people') }}"
                           class="hc-field h-9 w-full rounded-full text-small">
                    @error('people')
                        <p class="mt-1 text-small text-primary" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-1 pb-2" data-csb-exclude>
                    @forelse ($people as $person)
                        <button type="button" wire:click="startWith({{ $person['id'] }})" wire:key="chat-person-{{ $person['id'] }}"
                                class="flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start hover:bg-hover"
                                data-testid="chat-person">
                            <img src="{{ $person['avatar'] }}" alt="" class="size-9 shrink-0 rounded-full object-cover">
                            <span class="min-w-0 flex-1 truncate text-small font-semibold text-ink">{{ $person['name'] }}</span>
                        </button>
                    @empty
                        <p class="px-6 py-10 text-center text-small text-ink-2">{{ __('chat.ui.no_people') }}</p>
                    @endforelse
                </div>
            @endif
        </section>
    @endif
</div>
