<x-slot:title>
    {{ __('general.notifications') }}
</x-slot:title>

<div class="w-8/12 mx-auto space-y-4">
    {{-- Page header --}}
    <div class="flex items-center justify-between">
        <h2 class="text-h4 font-semibold text-neutral-800">@lang('general.notifications')</h2>

        @if(! $loadFailed && $unreadCount > 0)
            <button
                type="button"
                wire:click="markAllAsRead"
                class="text-body font-medium text-secondary-medium transition hover:text-neutral-900 hover:underline">
                @lang('general.mark-all-read')
            </button>
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
    <div class="overflow-hidden rounded-surface border border-neutral-100 bg-white shadow-[0_25px_80px_-40px_rgba(88,89,91,0.35)] ring-1 ring-black/5">
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
                class="group relative flex items-start gap-3 border-b border-neutral-100 px-4 py-4 transition-colors duration-300 last:border-b-0
                       {{ $isUnread
                            ? 'bg-neutral-100 hover:bg-neutral-200 cursor-pointer'
                            : 'bg-white hover:bg-neutral-50' }}">

                {{-- Unread accent bar --}}
                <span class="absolute inset-y-0 start-0 w-[3px] transition-all duration-300 {{ $isUnread ? 'bg-primary' : 'bg-transparent' }}"
                      x-bind:style="clicked ? 'background-color: transparent' : ''"></span>

                {{-- Icon bubble --}}
                <span class="mt-1 inline-flex size-9 shrink-0 items-center justify-center rounded-full transition-colors duration-300
                             {{ $isUnread ? 'bg-neutral-200 text-secondary-medium' : 'bg-neutral-100 text-secondary-light' }}">
                    <x-icons.notification class="size-4" />
                </span>

                {{-- Text --}}
                <div class="min-w-0 flex-1">
                    <p class="text-body font-semibold transition-colors duration-300 {{ $isUnread ? 'text-neutral-900' : 'text-neutral-800' }}">
                        {{ $notification->data['title'][app()->getLocale()] ?? 'Notification' }}
                    </p>

                    <p class="mt-1 text-small transition-colors duration-300 {{ $isUnread ? 'text-secondary-medium' : 'text-secondary-medium' }}">
                        {{ ($states[$notification->id]['state'] ?? null) === 'unavailable' ? __('general.notification-target-unavailable') : ($notification->data['body'][app()->getLocale()] ?? '') }}
                    </p>

                    @php($state = $states[$notification->id] ?? null)
                    @if($state && $state['state'] !== 'info')
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-caption">
                            @if($state['state'] === 'awaiting')
                                <span class="rounded-full bg-neutral-100 px-2 py-0.5 font-semibold text-primary">@lang('general.notification-state.awaiting')</span>
                            @elseif($state['state'] === 'handled')
                                <span class="rounded-full bg-neutral-100 px-2 py-0.5 font-semibold text-secondary-medium">@lang('general.notification-state.handled')</span>
                            @else
                                <span class="rounded-full bg-neutral-100 px-2 py-0.5 font-semibold text-secondary-medium">@lang('general.notification-state.unavailable')</span>
                            @endif
                            @if($state['status'])
                                <span class="text-secondary-medium">@lang('general.notification-state.status', ['status' => $state['status']])</span>
                            @endif
                        </p>
                        @if($state['next_step'] && $state['state'] !== 'handled')
                            <p class="mt-1 text-caption text-secondary-medium">{{ $state['next_step'] }}</p>
                        @endif
                    @endif

                    <p class="mt-1 text-caption text-secondary-light">
                        {{ $notification->created_at?->diffForHumans() }}
                    </p>
                </div>

                {{-- Unread dot (fades + shrinks out on read) --}}
                <span class="mt-2 size-2 shrink-0 rounded-full bg-primary transition-all duration-300 {{ $isUnread ? 'opacity-100 scale-100' : 'opacity-0 scale-0' }}"
                      x-bind:style="clicked ? 'opacity: 0; transform: scale(0)' : ''"></span>
            </a>
        @empty
            <div class="flex min-h-72 flex-col items-center justify-center gap-2 px-4 py-16 text-center" data-state="empty">
                <span class="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-secondary-light" aria-hidden="true">
                    <x-icons.notification class="size-5" />
                </span>
                <h3 class="text-body font-semibold text-neutral-800">@lang('general.no-notifications')</h3>
                <p class="text-small text-secondary-light">@lang('states.notifications-empty-help')</p>
            </div>
        @endforelse
    </div>

    {{ $notifications->links() }}
    @endif
</div>
