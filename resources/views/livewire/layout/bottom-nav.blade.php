{{--
    Phone bottom navigation (D2). Labels are the menu's own names (C0 §8);
    the current page is marked with aria-current and a bar, not colour alone.
    Hidden from md up, behind dialogs (z-30 < modal z-50), and, by app.css,
    while a field has focus or a page shows its own sticky save bar.
--}}
<nav aria-label="{{ __('general.nav.bottom') }}" data-testid="bottom-nav"
     {{-- Also by focus events, not only the CSS :focus rule: the keyboard can
          be up while the document reports no focus (embedded views, iOS). --}}
     x-data="{ typing: false, isField: (el) => el?.matches?.('input:not([type=checkbox],[type=radio],[type=file],[type=button],[type=submit]), textarea, select, [contenteditable=true]') }"
     x-on:focusin.window="typing = isField($event.target)"
     x-on:focusout.window="typing = false"
     x-bind:data-typing="typing"
     class="app-bottom-nav fixed inset-x-0 bottom-0 z-30 border-t border-neutral-200 bg-white/95 backdrop-blur md:hidden"
     style="padding-bottom: env(safe-area-inset-bottom, 0px)">
    <ul class="mx-auto flex max-w-lg items-stretch justify-around">
        @foreach ($items as $item)
            @php($active = in_array($currentRoute, $item['active'], true))
            <li class="flex-1" wire:key="bottom-{{ $item['key'] }}">
                <a href="{{ route($item['route']) }}" wire:navigate data-testid="bottom-nav-{{ $item['key'] }}"
                   @if ($active) aria-current="page" @endif
                   @class([
                       'relative flex min-h-14 flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-[11px] font-semibold leading-tight focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                       'text-primary' => $active,
                       'text-secondary-medium hover:text-secondary-dark' => ! $active,
                   ])>
                    @if ($active)
                        <span class="absolute inset-x-4 top-0 h-0.5 rounded-full bg-primary" aria-hidden="true"></span>
                    @endif
                    <span class="relative">
                        <x-dynamic-component :component="'icons.'.$item['icon']" class="size-5" aria-hidden="true" />
                        @if (($item['count'] ?? 0) > 0)
                            <span class="absolute -end-2.5 -top-1.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 text-white" data-testid="bottom-nav-count">
                                {{ $item['count'] > 99 ? '99+' : $item['count'] }}
                            </span>
                        @endif
                    </span>
                    <span class="max-w-full truncate">{{ $item['label'] }}</span>
                    @if (($item['count'] ?? 0) > 0)
                        <span class="sr-only">{{ __('general.nav.work-inbox-count', ['count' => $item['count']]) }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
