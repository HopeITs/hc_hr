<div>
    {{-- Header --}}
    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-line-soft bg-soft/90 px-4 py-3 backdrop-blur">
        <span class="text-body font-semibold text-ink">
            @lang('general.notifications')
        </span>

        @if($hasUnread && ! $loadFailed)
            <x-button variant="link" size="sm" wire:click="markAllAsRead">
                @lang('general.mark-all-read')
            </x-button>
        @endif
    </div>

    {{-- Announces loading and the unread total politely; the panel keeps its height while it refreshes. --}}
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
        <span wire:loading wire:target="refreshList,markAllAsRead,retryLoad">{{ __('states.notifications-loading') }}</span>
        <span wire:loading.remove wire:target="refreshList,markAllAsRead,retryLoad">{{ $loadFailed ? __('general.state-error-title') : trans_choice('states.notifications-summary', $unreadCount) }}</span>
    </p>

    {{-- List --}}
    @if($loadFailed)
        <x-ui.load-error class="m-3 min-h-40" />
    @endif
    @forelse($notifications as $note)
        @php
            $isUnread = is_null($note->read_at);
            $safeUrl = $this->safeNotificationUrl($note->data['url'] ?? null);
            $isMailto = is_string($safeUrl) && str_starts_with($safeUrl, 'mailto:');
        @endphp

        <a
            wire:key="note-{{ $note->id }}"
            x-data="{ clicked: false }"
            @if($safeUrl)
                href="{{ $safeUrl }}"
                @if($isMailto)
                    wire:click="markAsRead('{{ $note->id }}')"
                @else
                    wire:click.prevent="openNotification('{{ $note->id }}')"
                @endif
            @elseif($isUnread)
                role="button"
                tabindex="0"
                wire:click="markAsRead('{{ $note->id }}')"
                wire:keydown.enter="markAsRead('{{ $note->id }}')"
            @endif
            @if($isUnread) @click="clicked = true" @endif
            x-bind:style="clicked ? 'background-color: transparent' : ''"
            class="notif-row group relative flex items-start gap-3 border-b border-line-soft px-4 py-3 transition-colors duration-300 last:border-b-0
                   {{ $isUnread
                        ? 'bg-surface hover:bg-surface cursor-pointer'
                        : 'bg-card hover:bg-hover' }}">

            {{-- Unread accent bar --}}
            <span class="absolute inset-y-0 start-0 w-[3px] transition-all duration-300 {{ $isUnread ? 'bg-primary' : 'bg-transparent' }}"
                  x-bind:style="clicked ? 'background-color: transparent' : ''"></span>

            {{-- Text --}}
            <div class="min-w-0 flex-1">
                <p class="truncate text-body font-semibold transition-colors duration-300
                          text-ink">
                    {{ $note->data['title'][app()->getLocale()] ?? 'Notification' }}
                </p>

                <p class="mt-1 line-clamp-2 text-small transition-colors duration-300
                          text-ink-2">
                    {{ ($states[$note->id]['state'] ?? null) === 'unavailable' ? __('general.notification-target-unavailable') : ($note->data['body'][app()->getLocale()] ?? '') }}
                </p>

                    @php($state = $states[$note->id] ?? null)
                    @if($state && $state['state'] !== 'info')
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-caption">
                            @if($state['state'] === 'awaiting')
                                <x-status tone="attn" icon="alert" :label="__('general.notification-state.awaiting')" />
                            @elseif($state['state'] === 'handled')
                                <x-status tone="done" icon="check" :label="__('general.notification-state.handled')" />
                            @else
                                <x-status tone="stop" icon="ban" :label="__('general.notification-state.unavailable')" />
                            @endif
                            @if($state['status'])
                                <span class="text-ink-2">@lang('general.notification-state.status', ['status' => $state['status']])</span>
                            @endif
                        </p>
                        @if($state['next_step'] && $state['state'] !== 'handled')
                            <p class="mt-1 text-caption text-ink-2">{{ $state['next_step'] }}</p>
                        @endif
                    @endif

                <p class="mt-1 text-caption text-ink-3">
                    {{ $note->created_at?->diffForHumans() }}
                </p>
            </div>

            {{-- Unread dot (fades + shrinks out on read) --}}
            <span class="mt-2 size-2 shrink-0 rounded-full bg-primary transition-all duration-300 {{ $isUnread ? 'opacity-100 scale-100' : 'opacity-0 scale-0' }}"
                  x-bind:style="clicked ? 'opacity: 0; transform: scale(0)' : ''"></span>
        </a>
    @empty
        @unless($loadFailed)
            <div class="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center" data-state="empty">
                <span class="inline-flex size-11 items-center justify-center rounded-full bg-surface text-ink-3" aria-hidden="true">
                    <x-icons.notification class="size-5" />
                </span>
                <span class="text-body font-semibold text-ink">@lang('general.no-notifications')</span>
                <span class="text-small text-ink-2">@lang('states.notifications-empty-help')</span>
            </div>
        @endunless
    @endforelse
</div>
