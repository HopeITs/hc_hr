{{--
    One chat in the dock (App\Livewire\Chat\Window): a small tab when
    minimised; the conversation when open. On a phone only the latest opened
    chat shows, filling the screen, until it is closed.
--}}
@php($onPage = $mode === 'page')
<div @class([
        'flex min-h-0 flex-1 flex-col' => $onPage,
        'md:hidden' => ! $onPage && ! $inBar,
        'max-md:hidden' => ! $onPage && ! $mobileActive,
     ]) data-testid="chat-window">
    @if ($summary)
        @if (! $open && ! $onPage)
            {{-- The minimised tab: a red line and a count when something is unread. --}}
            <div class="group flex h-10 w-44 items-center gap-2 rounded-t-[10px] border border-b-0 bg-card ps-2 pe-1 max-md:hidden
                        {{ $summary['unread'] > 0 && ! $summary['muted'] ? 'border-primary border-t-[3px]' : 'border-line' }}"
                 data-testid="chat-tab">
                <button type="button" wire:click="$parent.toggle({{ $conversationId }})"
                        class="flex min-w-0 flex-1 items-center gap-2 text-start"
                        aria-label="{{ __('chat.ui.open_chat', ['name' => $summary['title']]) }}">
                    <x-chat.avatar :item="$summary" size="size-6" />
                    <span class="min-w-0 flex-1 truncate text-small {{ $summary['unread'] > 0 ? 'font-bold text-ink' : 'font-semibold text-ink' }}">{{ $summary['title'] }}</span>
                    @if ($summary['unread'] > 0)
                        <span class="min-w-[18px] rounded-full px-1.5 text-center text-[11px] font-semibold text-white {{ $summary['muted'] ? 'bg-ink-3' : 'bg-primary' }}">{{ $summary['unread'] }}</span>
                    @endif
                </button>
                <button type="button" wire:click="$parent.close({{ $conversationId }})"
                        class="grid size-7 shrink-0 place-items-center rounded-full text-ink-3 hover:bg-hover hover:text-ink"
                        aria-label="{{ __('chat.ui.close') }}" title="{{ __('chat.ui.close') }}">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        @else
            <section x-data="chatWindow" @if (! $onPage) role="dialog" aria-modal="false" @endif aria-label="{{ $summary['title'] }}"
                     @class([
                         'flex flex-col overflow-hidden bg-card',
                         'min-h-0 flex-1' => $onPage,
                         'border border-line shadow-e3 max-md:fixed max-md:inset-0 max-md:z-[9550] md:h-[min(26rem,calc(100dvh-7rem))] md:w-[18.5rem] md:rounded-t-[12px] md:border-b-0' => ! $onPage,
                     ])
                     data-testid="chat-open">

                <header class="flex items-center gap-2 border-b border-line-soft bg-card px-2 py-1.5">
                    @unless ($onPage)
                        {{-- Phone: back to the page (the chat stays on the bar). --}}
                        <button type="button" wire:click="$parent.minimize({{ $conversationId }})"
                                class="grid size-9 place-items-center rounded-full text-ink-2 hover:bg-hover md:hidden"
                                aria-label="{{ __('chat.ui.back') }}">
                            <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        </button>
                    @endunless
                    <x-chat.avatar :item="$summary" size="size-8" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-small font-semibold text-ink">{{ $summary['title'] }}</p>
                        @if ($isGroup)
                            <p class="truncate text-[11px] text-ink-3">{{ trans_choice('chat.ui.members', $summary['members'], ['count' => $summary['members']]) }}</p>
                        @elseif (! $summary['other_active'])
                            <p class="truncate text-[11px] text-ink-3">{{ __('chat.ui.deactivated') }}</p>
                        @endif
                    </div>
                    @if ($onPage)
                        {{-- Back to where one was, with this chat as a tab on the dock again. --}}
                        <button type="button" x-on:click="backToDock({{ $conversationId }}, @js(route('dashboard')))"
                                class="grid size-8 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink"
                                aria-label="{{ __('chat.ui.restore') }}" title="{{ __('chat.ui.restore') }}" data-testid="chat-restore">
                            <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/></svg>
                        </button>
                    @else
                        <button type="button" x-on:click="maximize(@js(route('chat', ['c' => $conversationId])))"
                                class="grid size-8 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink max-md:hidden"
                                aria-label="{{ __('chat.ui.maximize') }}" title="{{ __('chat.ui.maximize') }}" data-testid="chat-maximize">
                            <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                        </button>
                        {{-- Minimise and close as one group (like the header's button group). --}}
                        <div class="flex shrink-0 items-center gap-0.5 rounded-[12px] bg-soft p-0.5" role="group" data-testid="chat-window-controls">
                            <button type="button" wire:click="$parent.minimize({{ $conversationId }})"
                                    class="grid size-7 place-items-center rounded-[10px] text-ink-2 hover:bg-card hover:text-ink max-md:hidden"
                                    aria-label="{{ __('chat.ui.minimize') }}" title="{{ __('chat.ui.minimize') }}">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg>
                            </button>
                            <button type="button" wire:click="$parent.close({{ $conversationId }})"
                                    class="grid size-7 place-items-center rounded-[10px] text-ink-2 hover:bg-card hover:text-ink"
                                    aria-label="{{ __('chat.ui.close') }}" title="{{ __('chat.ui.close') }}">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </div>
                    @endif
                </header>

                <div x-ref="scroller" x-on:scroll.passive="onScroll()"
                     class="min-h-0 flex-1 overflow-y-auto bg-soft px-2.5 py-2" data-csb-exclude
                     aria-live="polite" aria-relevant="additions">
                    @if ($hasOlder)
                        <div class="mb-2 flex justify-center">
                            <button type="button" x-on:click="older()"
                                    class="rounded-full bg-card px-3 py-1 text-[11px] font-semibold text-ink-2 hover:text-ink">{{ __('chat.ui.older') }}</button>
                        </div>
                    @endif

                    @php($previous = null)
                    @forelse ($messages as $message)
                        @if (\App\Livewire\Chat\Window::startsNewDay($previous, $message))
                            <div class="my-2 flex justify-center" wire:key="day-{{ $message->id }}">
                                <span class="rounded-full bg-card px-2.5 py-0.5 text-[11px] text-ink-2">{{ \App\Support\ChatTime::dayLabel($message->created_at) }}</span>
                            </div>
                        @endif

                        @if ($message->isService())
                            <p class="my-1.5 text-center text-[11px] text-ink-3" wire:key="m-{{ $message->id }}">{{ $directory->serviceText($message) }}</p>
                        @else
                            @php($mine = $message->sender_id === $viewerId)
                            <div class="mb-1 flex {{ $mine ? 'justify-end' : 'justify-start' }}" wire:key="m-{{ $message->id }}" data-testid="chat-message">
                                <div class="max-w-[85%] rounded-[12px] px-2.5 pb-1 pt-1.5 text-small leading-relaxed
                                            {{-- Own messages: dark silver, not brand red (too loud for a whole thread). --}}
                                            {{ $mine ? 'bg-[#5C5D61] text-white' : 'bg-card text-ink' }}">
                                    @if ($isGroup && ! $mine)
                                        <p class="text-[11px] font-semibold text-primary">{{ $directory->nameOf($message->sender) }}</p>
                                    @endif
                                    @if ($message->isForwarded())
                                        <p class="text-[11px] font-semibold {{ $mine ? 'text-white/90' : 'text-primary' }}">
                                            {{ __('chat.ui.forwarded_from', ['name' => $directory->nameOf($message->forwardedFrom)]) }}
                                        </p>
                                    @endif
                                    @if ((string) $message->body !== '')
                                        <p class="whitespace-pre-line break-words" dir="auto">{{ $message->body }}</p>
                                    @endif
                                    @foreach ($message->attachments as $attachment)
                                        <p class="mt-1 truncate text-[12px] {{ $mine ? 'text-white/90' : 'text-ink-2' }}">📎 {{ $attachment->original_name }}</p>
                                    @endforeach
                                    <p class="mt-0.5 flex items-center justify-end gap-1 text-[10px] {{ $mine ? 'text-white/80' : 'text-ink-3' }}">
                                        @if ($message->edited_at)<span>{{ __('chat.ui.edited') }}</span>@endif
                                        <span>{{ \App\Support\ChatTime::time($message->created_at) }}</span>
                                        @if ($mine)
                                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-label="{{ __('chat.ui.sent') }}"><path d="M20 6 9 17l-5-5"/></svg>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endif
                        @php($previous = $message)
                    @empty
                        <p class="py-10 text-center text-small text-ink-3">{{ __('chat.ui.say_hello') }}</p>
                    @endforelse
                </div>

                @if ($canSend)
                    <form x-on:submit.prevent="submit()" class="border-t border-line-soft bg-card px-2 py-1.5">
                        @error('draft')
                            <p class="mb-1 text-[11px] text-primary" role="alert">{{ $message }}</p>
                        @enderror
                        <div class="flex items-end gap-1.5">
                            <textarea x-ref="composer" wire:model="draft" x-on:keydown="keydown($event)"
                                      rows="1" dir="auto" maxlength="{{ config('chat.max_message_length') }}"
                                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                                      placeholder="{{ __('chat.ui.type_message') }}" aria-label="{{ __('chat.ui.type_message') }}"
                                      class="max-h-[120px] min-h-9 flex-1 resize-none rounded-[18px] border-0 bg-soft px-3 py-2 text-small text-ink focus:ring-1 focus:ring-primary"
                                      data-testid="chat-composer"></textarea>
                            {{-- Phones only: a phone keyboard has no Shift+Enter, so Enter
                                 makes a new line there and this button sends. On desktop
                                 Enter sends (Shift+Enter is a new line). --}}
                            <button type="submit" wire:loading.attr="disabled" wire:target="send"
                                    class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-white disabled:opacity-60 md:hidden"
                                    aria-label="{{ __('chat.ui.send') }}" title="{{ __('chat.ui.send') }}" data-testid="chat-send">
                                <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg>
                            </button>
                        </div>
                    </form>
                @else
                    <p class="border-t border-line-soft bg-card px-3 py-2.5 text-center text-[12px] text-ink-3">{{ __('chat.ui.cannot_reply') }}</p>
                @endif
            </section>
        @endif
    @endif
</div>
