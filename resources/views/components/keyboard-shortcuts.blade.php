{{--
    Keyboard shortcuts (prompt 10). Rendered once per full page by the app layout,
    beside the help panel; behaviour in resources/js/commands.js.

      - "g" then a letter opens a page of the active role's menu
        (App\Support\KeyboardShortcuts): only the letters this role has are
        listed here and handed to the browser. "?" opens this list; so does
        the "Keyboard shortcuts" command in Ctrl+K.
      - Keys are left alone while someone types (fields, selects, editable
        text), with Ctrl/Cmd/Alt, and inside dialogs, menus and list boxes
        (resources/js/shortcuts-guard.js).
      - A modal dialog: focus moves in and is trapped (x-trap.inert); Escape,
        the close button and a click outside close it; focus returns to what
        had it before.
--}}
@php
    $goTo = \App\Support\KeyboardShortcuts::goTo(auth()->user());
    $kbd = 'inline-flex h-7 min-w-7 items-center justify-center rounded-control border-[1.5px] border-line bg-soft px-1.5 font-mono text-caption font-bold text-ink';
    $row = 'flex min-h-11 items-center justify-between gap-3 py-2';
@endphp

<div x-data="keyboardShortcuts(@js(array_map(fn (array $key) => $key['href'], $goTo)))"
     x-on:keydown.window="onKey($event)"
     x-on:shortcuts-open.window="show()"
     {{-- Focus is trapped inside, so this only sees keys pressed in the list. --}}
     x-on:keydown.escape.prevent.stop="close()"
     x-show="open"
     {{-- Opened over a form dialog, this root was hidden by that dialog's x-trap.inert. --}}
     x-effect="open && $el.removeAttribute('aria-hidden')"
     data-keyboard-shortcuts
     class="fixed inset-0 z-[1100] grid place-items-center p-4"
     style="display: none;">

    {{-- Class transitions, not x-transition's inline styles, so reduced motion can switch them off. --}}
    <div x-show="open"
         x-transition:enter="transition-opacity duration-150 motion-reduce:transition-none"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-on:click="close()"
         aria-hidden="true"
         class="absolute inset-0 bg-backdrop"></div>

    <section x-show="open"
             x-trap.inert.noscroll="open"
             x-transition:enter="transition duration-150 ease-out motion-reduce:transition-none"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             role="dialog"
             aria-modal="true"
             aria-labelledby="keyboard-shortcuts-title"
             aria-describedby="keyboard-shortcuts-lead"
             data-testid="keyboard-shortcuts"
             class="relative max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto overscroll-contain rounded-surface border border-line-soft bg-card p-5 shadow-e3"
             data-csb-exclude>

        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="keyboard-shortcuts-title" class="text-h4 font-extrabold text-ink">{{ __('navigation.shortcuts.title') }}</h2>
                <p id="keyboard-shortcuts-lead" class="mt-1 text-small text-ink-2">{{ __('navigation.shortcuts.lead') }}</p>
            </div>
            <button type="button"
                    x-on:click="close()"
                    aria-label="{{ __('navigation.shortcuts.close') }}"
                    class="-me-2 -mt-1 grid size-11 shrink-0 place-items-center rounded-control text-ink-2 transition hover:bg-hover hover:text-ink motion-reduce:transition-none">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </header>

        <h3 class="mt-5 text-th font-bold text-ink-2">{{ __('navigation.shortcuts.anywhere') }}</h3>
        <dl class="divide-y divide-line-soft">
            <div class="{{ $row }}">
                <dt class="text-body text-ink">{{ __('navigation.shortcuts.search') }}</dt>
                <dd class="flex shrink-0 items-center gap-1" dir="ltr"><kbd class="{{ $kbd }}">{{ __('navigation.shortcuts.keys.ctrl') }}</kbd><kbd class="{{ $kbd }}">K</kbd></dd>
            </div>
            <div class="{{ $row }}">
                <dt class="text-body text-ink">{{ __('navigation.shortcuts.sheet') }}</dt>
                <dd class="flex shrink-0 items-center"><kbd class="{{ $kbd }}">?</kbd></dd>
            </div>
            <div class="{{ $row }}">
                <dt class="text-body text-ink">{{ __('navigation.shortcuts.escape') }}</dt>
                <dd class="flex shrink-0 items-center"><kbd class="{{ $kbd }}">{{ __('navigation.shortcuts.keys.esc') }}</kbd></dd>
            </div>
        </dl>

        @if ($goTo !== [])
            <h3 class="mt-5 text-th font-bold text-ink-2">{{ __('navigation.shortcuts.go-to') }}</h3>
            <dl class="divide-y divide-line-soft">
                @foreach ($goTo as $letter => $key)
                    <div class="{{ $row }}" data-go-to="{{ $letter }}" data-go-to-page="{{ $key['id'] }}">
                        <dt class="min-w-0 text-body text-ink">{{ $key['label'] }}</dt>
                        {{-- In reading order: g first, then the letter (right to left in Arabic). --}}
                        <dd class="flex shrink-0 items-center gap-1.5 text-small text-ink-2"><kbd class="{{ $kbd }}">g</kbd>{{ __('navigation.shortcuts.then') }}<kbd class="{{ $kbd }}">{{ $letter }}</kbd></dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
</div>
