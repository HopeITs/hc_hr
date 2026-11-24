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
     ])
     {{-- chat.js finds the chat here: its "typing…" signal and the notice check. --}}
     data-chat-conversation="{{ $conversationId }}"
     data-chat-open="{{ ($open || $onPage) ? '1' : '0' }}"
     data-testid="chat-window">
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
                    <span class="min-w-0 flex-1 truncate text-small {{ $summary['unread'] > 0 ? 'font-bold text-ink' : 'font-semibold text-ink' }}"
                          data-chat-when-not-typing="{{ $conversationId }}">{{ $summary['title'] }}</span>
                    <span class="min-w-0 flex-1 truncate text-small font-semibold text-primary" hidden
                          data-chat-when-typing="{{ $conversationId }}">{{ __('chat.ui.typing') }}</span>
                    @if ($summary['unread'] > 0)
                        <span class="min-w-[18px] rounded-full px-1.5 text-center text-caption font-semibold text-white {{ $summary['muted'] ? 'bg-ink-3' : 'bg-primary' }}">{{ $summary['unread'] }}</span>
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
                     {{-- Drop files anywhere on the chat to attach them. --}}
                     x-on:dragover.prevent="dragging = true" x-on:dragleave.self="dragging = false"
                     x-on:drop.prevent="dragging = false; addFiles($event.dataTransfer.files)"
                     :class="dragging && 'ring-2 ring-primary ring-inset'"
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
                        {{-- Status line: typing… beats online, online beats last seen. --}}
                        <p class="truncate text-caption text-primary" hidden data-chat-when-typing="{{ $conversationId }}" data-testid="chat-typing">
                            @if ($isGroup)
                                <span data-chat-typing-name="{{ $conversationId }}" data-template="{{ __('chat.ui.typing_named', ['name' => ':name']) }}"></span>
                            @else
                                {{ __('chat.ui.typing') }}
                            @endif
                        </p>
                        <div data-chat-when-not-typing="{{ $conversationId }}">
                            @if ($isGroup)
                                <p class="truncate text-caption text-ink-3">{{ trans_choice('chat.ui.members', $summary['members'], ['count' => $summary['members']]) }}</p>
                            @elseif (! $summary['other_active'])
                                <p class="truncate text-caption text-ink-3">{{ __('chat.ui.deactivated') }}</p>
                            @else
                                <p class="truncate text-caption text-ink-2" hidden
                                   data-chat-when-online="{{ (int) $summary['other_user_id'] }}" data-testid="chat-status-online">{{ __('chat.ui.online') }}</p>
                                <p class="truncate text-caption text-ink-3"
                                   data-chat-when-offline="{{ (int) $summary['other_user_id'] }}" data-testid="chat-status-last-seen">{{ $summary['other_last_seen'] }}</p>
                            @endif
                        </div>
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
                                    class="rounded-full bg-card px-3 py-1 text-caption font-semibold text-ink-2 hover:text-ink">{{ __('chat.ui.older') }}</button>
                        </div>
                    @endif

                    @php($previous = null)
                    @forelse ($messages as $message)
                        @if (\App\Livewire\Chat\Window::startsNewDay($previous, $message))
                            <div class="my-2 flex justify-center" wire:key="day-{{ $message->id }}">
                                <span class="rounded-full bg-card px-2.5 py-0.5 text-caption text-ink-2">{{ \App\Support\ChatTime::dayLabel($message->created_at) }}</span>
                            </div>
                        @endif

                        @if ($message->isService())
                            <p class="my-1.5 text-center text-caption text-ink-3" wire:key="m-{{ $message->id }}">{{ $directory->serviceText($message) }}</p>
                        @else
                            @php($mine = $message->sender_id === $viewerId)
                            <div class="mb-1 flex {{ $mine ? 'justify-end' : 'justify-start' }}" wire:key="m-{{ $message->id }}" data-testid="chat-message">
                                <div class="max-w-[85%] rounded-[12px] px-2.5 pb-1 pt-1.5 text-small leading-relaxed
                                            {{-- Own messages: dark silver, not brand red (too loud for a whole thread). --}}
                                            {{ $mine ? 'bg-[#5C5D61] text-white' : 'bg-card text-ink' }}">
                                    @if ($isGroup && ! $mine)
                                        <p class="text-caption font-semibold text-primary">{{ $directory->nameOf($message->sender) }}</p>
                                    @endif
                                    @if ($message->isForwarded())
                                        <p class="text-caption font-semibold {{ $mine ? 'text-white/90' : 'text-primary' }}">
                                            {{ __('chat.ui.forwarded_from', ['name' => $directory->nameOf($message->forwardedFrom)]) }}
                                        </p>
                                    @endif
                                    @if ((string) $message->body !== '')
                                        <p class="whitespace-pre-line break-words" dir="auto">{{ $message->body }}</p>
                                    @endif
                                    @foreach ($message->attachments as $attachment)
                                        @php($fileUrl = route('chat.files.show', $attachment->id))
                                        @if ($attachment->thumbnail_path)
                                            {{-- A photo: its preview; click for full size over the page. --}}
                                            <button type="button" x-on:click="lightbox = @js($fileUrl)"
                                                    class="mt-1 block overflow-hidden rounded-[10px]"
                                                    aria-label="{{ __('chat.ui.open_photo') }}" data-testid="chat-photo">
                                                {{-- A set width (and the photo's shape) so the space exists before it
                                                     loads: with no size a lazy image is never "in view" and never loads. --}}
                                                <img src="{{ $fileUrl }}?thumb=1" alt="{{ $attachment->original_name }}" loading="lazy"
                                                     class="block max-h-60 w-60 max-w-full bg-soft object-cover"
                                                     @if ($attachment->width && $attachment->height) style="aspect-ratio: {{ $attachment->width }} / {{ $attachment->height }}" @endif>
                                            </button>
                                        @else
                                            {{-- Any other file: a card that downloads it. --}}
                                            <a href="{{ $fileUrl }}" download
                                               class="mt-1 flex items-center gap-2 rounded-[10px] px-2 py-1.5 {{ $mine ? 'bg-white/12 hover:bg-white/20' : 'bg-soft hover:bg-hover' }}"
                                               aria-label="{{ __('chat.ui.download', ['name' => $attachment->original_name]) }}" data-testid="chat-file">
                                                <svg class="size-6 shrink-0 {{ $mine ? 'text-white' : 'text-primary' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15l3 3 3-3"/></svg>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-[12px] font-semibold" dir="auto">{{ $attachment->original_name }}</span>
                                                    <span class="block text-caption {{ $mine ? 'text-white/75' : 'text-ink-3' }}">{{ \Illuminate\Support\Number::fileSize($attachment->size, precision: 1) }}</span>
                                                </span>
                                            </a>
                                        @endif
                                    @endforeach
                                    <p class="mt-0.5 flex items-center justify-end gap-1 text-caption {{ $mine ? 'text-white/80' : 'text-ink-3' }}">
                                        @if ($message->edited_at)<span>{{ __('chat.ui.edited') }}</span>@endif
                                        <span>{{ \App\Support\ChatTime::time($message->created_at) }}</span>
                                        @if ($mine)
                                            @if ($message->id <= $readUpTo)
                                                {{-- ✓✓ read (by the other person; in a group, by anyone). --}}
                                                <svg class="size-3.5" viewBox="0 0 28 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="{{ __('chat.ui.read') }}" data-testid="chat-read"><path d="M18 6 7 17l-5-5"/><path d="M26 6 15 17l-1.5-1.5"/></svg>
                                            @else
                                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="{{ __('chat.ui.sent') }}" data-testid="chat-sent"><path d="M20 6 9 17l-5-5"/></svg>
                                            @endif
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

                {{-- A photo at full size, over the whole page; Escape or a click closes it. --}}
                <template x-teleport="body">
                    <div x-show="lightbox" x-cloak x-transition.opacity
                         x-on:click="lightbox = null" x-on:keydown.escape.window="lightbox = null"
                         class="fixed inset-0 z-[9800] flex items-center justify-center bg-black/85 p-4"
                         role="dialog" aria-modal="true" aria-label="{{ __('chat.ui.open_photo') }}" data-testid="chat-lightbox">
                        <img :src="lightbox" alt="" class="max-h-full max-w-full rounded-[8px] object-contain" x-on:click.stop>
                        <button type="button" x-on:click="lightbox = null"
                                class="absolute end-4 top-4 grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25"
                                aria-label="{{ __('chat.ui.close_photo') }}">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>

                @if ($canSend)
                    <form x-on:submit.prevent="submit()" class="border-t border-line-soft bg-card px-2 py-1.5"
                          data-upload-url="{{ route('chat.messages.store', $conversationId) }}"
                          data-max-bytes="{{ (int) config('chat.max_file_kb') * 1024 }}"
                          data-max-files="{{ (int) config('chat.max_files_per_message') }}">
                        @error('draft')
                            <p class="mb-1 text-caption text-primary" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- Files chosen to send (before sending): Alpine's own list, so Livewire leaves it alone. --}}
                        <div wire:ignore>
                            <p x-show="uploadError" x-text="uploadError" x-cloak class="mb-1 text-caption text-primary" role="alert"></p>
                            <div x-show="files.length" x-cloak class="mb-1.5 flex flex-col gap-1" data-testid="chat-pending-files">
                                <template x-for="(item, index) in files" :key="item.key">
                                    <div class="flex items-center gap-2 rounded-control bg-soft px-2 py-1.5">
                                        <template x-if="item.preview">
                                            <img :src="item.preview" alt="" class="size-9 shrink-0 rounded object-cover">
                                        </template>
                                        <template x-if="! item.preview">
                                            <span class="grid size-9 shrink-0 place-items-center rounded bg-card text-ink-2" aria-hidden="true">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                            </span>
                                        </template>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-[12px] font-semibold text-ink" dir="auto" x-text="item.name"></span>
                                            <span class="block text-caption text-ink-3" x-text="item.sizeLabel"></span>
                                        </span>
                                        <button type="button" x-on:click="removeFile(index)" x-show="! uploading"
                                                class="grid size-7 shrink-0 place-items-center rounded-full text-ink-3 hover:bg-hover hover:text-ink"
                                                aria-label="{{ __('chat.ui.remove_file') }}" title="{{ __('chat.ui.remove_file') }}">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </template>
                                <div x-show="uploading" x-cloak class="h-1 overflow-hidden rounded-full bg-soft" role="progressbar"
                                     :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('chat.ui.uploading') }}">
                                    <div class="h-full bg-primary transition-[width]" :style="`width: ${progress}%`"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-end gap-1.5">
                            <input type="file" multiple x-ref="fileInput" class="hidden" x-on:change="addFiles($event.target.files); $event.target.value = ''"
                                   data-testid="chat-file-input">
                            <button type="button" x-on:click="$refs.fileInput.click()" x-bind:disabled="uploading"
                                    class="grid size-9 shrink-0 place-items-center rounded-full text-ink-2 hover:bg-hover hover:text-ink disabled:opacity-50"
                                    aria-label="{{ __('chat.ui.attach') }}" title="{{ __('chat.ui.attach') }}" data-testid="chat-attach">
                                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.4 11.6-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
                            </button>
                            <textarea x-ref="composer" wire:model="draft" x-on:keydown="keydown($event)" x-on:paste="pasted($event)"
                                      rows="1" dir="auto" maxlength="{{ config('chat.max_message_length') }}"
                                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                                      {{-- chat.js whispers "typing…" from here, with this name. --}}
                                      data-chat-composer="{{ $directory->nameOf(auth()->user()) }}"
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
