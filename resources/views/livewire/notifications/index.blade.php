<x-slot:title>
    {{ __('general.notifications') }}
</x-slot:title>

<div class="w-8/12 mx-auto space-y-4">
    {{-- Page header --}}
    <div class="flex items-center justify-between">
        <h2 class="text-h4 font-semibold text-ink">@lang('general.notifications')</h2>

        @if(! $loadFailed && $hasUnread)
            <x-button variant="link" size="sm" wire:click="markAllAsRead">
                @lang('general.mark-all-read')
            </x-button>
        @endif
    </div>

    {{-- Polite announcement after load, retry and mark-all-read. --}}
    <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
        <span wire:loading wire:target="markAllAsRead,retryLoad,gotoPage,nextPage,previousPage">{{ __('states.notifications-loading') }}</span>
        <span wire:loading.remove wire:target="markAllAsRead,retryLoad,gotoPage,nextPage,previousPage">{{ $loadFailed ? __('general.state-error-title') : trans_choice('states.notifications-summary', $unreadCount) }}</span>
    </p>

    @if($loadFailed)
        <x-ui.load-error />
    @else
    {{-- Card list --}}
    <div class="overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1">
        @forelse ($notifications as $notification)
            @php
                $isUnread = is_null($notification->read_at);
                $safeUrl = $this->safeNotificationUrl($notification->data['url'] ?? null);
                $isMailto = is_string($safeUrl) && str_starts_with($safeUrl, 'mailto:');
            @endphp

            <a
                wire:key="note-{{ $notification->id }}"
                x-data="{ clicked: false }"
                @if($safeUrl)
                    href="{{ $safeUrl }}"
                    @if($isMailto)
                        wire:click="markAsRead('{{ $notification->id }}')"
                    @else
                        wire:click.prevent="openNotification('{{ $notification->id }}')"
                    @endif
                @elseif($isUnread)
                    role="button"
                    tabindex="0"
                    wire:click="markAsRead('{{ $notification->id }}')"
                    wire:keydown.enter="markAsRead('{{ $notification->id }}')"
                @endif
                @if($isUnread) @click="clicked = true" @endif
                x-bind:style="clicked ? 'background-color: transparent' : ''"
                class="group relative flex items-start gap-3 border-b border-line-soft px-4 py-4 transition-colors duration-300 last:border-b-0
                       {{ $isUnread
                            ? 'bg-surface hover:bg-surface cursor-pointer'
                            : 'bg-card hover:bg-hover' }}">

                {{-- Unread accent bar --}}
                <span class="absolute inset-y-0 start-0 w-[3px] transition-all duration-300 {{ $isUnread ? 'bg-primary' : 'bg-transparent' }}"
                      x-bind:style="clicked ? 'background-color: transparent' : ''"></span>

                {{-- Icon bubble --}}
                <span class="mt-1 inline-flex size-9 shrink-0 items-center justify-center rounded-full transition-colors duration-300
                             {{ $isUnread ? 'bg-surface text-ink-2' : 'bg-surface text-ink-3' }}">
                    <x-icons.notification class="size-4" />
                </span>

                {{-- Text --}}
                <div class="min-w-0 flex-1">
                    <p class="text-body font-semibold transition-colors duration-300 text-ink">
                        {{ $notification->data['title'][app()->getLocale()] ?? 'Notification' }}
                    </p>

                    <p class="mt-1 text-small transition-colors duration-300 text-ink-2">
                        {{ ($states[$notification->id]['state'] ?? null) === 'unavailable' ? __('general.notification-target-unavailable') : ($notification->data['body'][app()->getLocale()] ?? '') }}
                    </p>

                    @php($state = $states[$notification->id] ?? null)
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
                        {{ $notification->created_at?->diffForHumans() }}
                    </p>
                </div>

                {{-- Unread dot (fades + shrinks out on read) --}}
                <span class="mt-2 size-2 shrink-0 rounded-full bg-primary transition-all duration-300 {{ $isUnread ? 'opacity-100 scale-100' : 'opacity-0 scale-0' }}"
                      x-bind:style="clicked ? 'opacity: 0; transform: scale(0)' : ''"></span>
            </a>
        @empty
            <div class="flex min-h-72 flex-col items-center justify-center gap-2 px-4 py-16 text-center" data-state="empty">
                <span class="inline-flex size-12 items-center justify-center rounded-full bg-surface text-ink-3" aria-hidden="true">
                    <x-icons.notification class="size-5" />
                </span>
                <h3 class="text-body font-semibold text-ink">@lang('general.no-notifications')</h3>
                <p class="text-small text-ink-2">@lang('states.notifications-empty-help')</p>
            </div>
        @endforelse
    </div>

    {{ $notifications->links() }}
    @endif
</div>
