{{--
    One item's actions: "View" and a "More" menu with what its CURRENT state
    allows (NewsTable re-authorizes and re-checks the state on every call).
    $context is "row" (View + More) or "card" (More only: the card links to
    the item). Same WAI-ARIA menu button as the contracts list; `fixed` so the
    table's horizontal scroll cannot clip it.

    Receives $news, $context, $state (NewsState), $canManage, $viewUrl.
--}}
@php
    use App\Enums\News\NewsState;

    $id = (int) $news->getKey();
    $menuId = "news-menu-{$context}-{$id}";
    $title = $news->localizedTitle();
    $itemClass = 'flex min-h-11 w-full items-center gap-2 px-4 text-start text-small text-ink hover:bg-hover focus-visible:bg-surface focus-visible:-outline-offset-2';
@endphp
<div class="flex items-center justify-end gap-1"
    x-data="{
        open: false, top: 0, left: 0,
        items() { return [...this.$refs.menu.querySelectorAll('[role=menuitem]')]; },
        show() { this.open = true; this.$nextTick(() => { this.place(); this.items()[0]?.focus(); }); },
        hide(refocus = true) { if (! this.open) return; this.open = false; if (refocus) this.$refs.trigger.focus(); },
        place() {
            const r = this.$refs.trigger.getBoundingClientRect();
            const w = this.$refs.menu.offsetWidth, h = this.$refs.menu.offsetHeight;
            const rtl = document.documentElement.dir === 'rtl';
            this.left = Math.max(8, Math.min(rtl ? r.left : r.right - w, window.innerWidth - w - 8));
            this.top = r.bottom + h + 8 > window.innerHeight ? Math.max(8, r.top - h - 4) : r.bottom + 4;
        },
        move(step) { const all = this.items(); all[(all.indexOf(document.activeElement) + step + all.length) % all.length]?.focus(); },
    }"
    x-on:click.outside="hide(false)"
    x-on:scroll.window.capture="hide(false)"
    x-on:resize.window="hide(false)">
    @if($context === 'row' && $viewUrl)
        <a href="{{ $viewUrl }}" wire:navigate
            class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-small font-semibold text-ink hover:bg-hover"
            aria-label="{{ __('news.list.actions.view_named', ['title' => $title]) }}">
            <x-ic name="eye" class="size-4" />
            <span>{{ __('news.list.actions.view') }}</span>
        </a>
    @endif

    @if($canManage)
        <button type="button" x-ref="trigger"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink hover:bg-hover"
            aria-haspopup="menu" aria-controls="{{ $menuId }}" x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ __('news.list.actions.more', ['title' => $title]) }}"
            x-on:click.stop="open ? hide() : show()"
            x-on:keydown.arrow-down.prevent="show()">
            <x-ic name="more" class="size-5" />
        </button>

        <div id="{{ $menuId }}" role="menu" x-ref="menu" x-show="open" x-cloak
            aria-label="{{ __('news.list.actions.more', ['title' => $title]) }}"
            class="fixed z-40 min-w-52 overflow-hidden rounded-surface border border-line-soft bg-card py-1 text-start shadow-e2"
            x-bind:style="`top:${top}px;left:${left}px`"
            x-on:keydown.escape.prevent.stop="hide()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.home.prevent="items()[0]?.focus()"
            x-on:keydown.end.prevent="items().at(-1)?.focus()"
            x-on:keydown.tab="hide(false)">
            <a role="menuitem" href="{{ route('news.edit', ['news' => $id]) }}" wire:navigate class="{{ $itemClass }}">
                <x-ic name="pen" class="size-4" />{{ __('news.list.actions.edit') }}
            </a>
            <a role="menuitem" href="{{ route('news.preview', ['news' => $id]) }}" wire:navigate class="{{ $itemClass }}">
                <x-ic name="eye" class="size-4" />{{ __('news.list.actions.preview') }}
            </a>
            @if(in_array($state, [NewsState::Draft, NewsState::Scheduled], true))
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    wire:click="confirmPublishNow({{ $id }})" x-on:click="hide(false)">
                    <x-ic name="send" class="size-4" />{{ __('news.list.actions.publish_now') }}
                </button>
            @endif
            @if($state === NewsState::Draft)
                <a role="menuitem" href="{{ route('news.edit', ['news' => $id, 'mode' => 'schedule']) }}" wire:navigate class="{{ $itemClass }}">
                    <x-ic name="clock" class="size-4" />{{ __('news.list.actions.schedule') }}
                </a>
            @endif
            @if($state === NewsState::Scheduled)
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    wire:click="confirmUnpublish({{ $id }})" x-on:click="hide(false)">
                    <x-ic name="return" class="size-4" />{{ __('news.list.actions.unpublish') }}
                </button>
            @endif
            @if(in_array($state, [NewsState::Published, NewsState::Expired], true))
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    wire:click="confirmArchive({{ $id }})" x-on:click="hide(false)">
                    <x-ic name="folder" class="size-4" />{{ __('news.list.actions.archive') }}
                </button>
            @endif
            @if($state === NewsState::Archived)
                <button type="button" role="menuitem" class="{{ $itemClass }}"
                    wire:click="restore({{ $id }})" x-on:click="hide(false)">
                    <x-ic name="reset" class="size-4" />{{ __('news.list.actions.restore') }}
                </button>
            @endif
            <div role="separator" class="my-1 border-t border-line-soft"></div>
            <button type="button" role="menuitem" class="{{ $itemClass }}"
                wire:click="confirmDelete({{ $id }})" x-on:click="hide(false)">
                <x-ic name="trash" class="size-4" />{{ __('news.list.actions.delete') }}
            </button>
        </div>
    @endif
</div>
