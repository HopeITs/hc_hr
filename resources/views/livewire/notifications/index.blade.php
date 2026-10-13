<x-slot:title>
    {{ __('general.notifications') }}
</x-slot:title>

<div class="w-8/12 mx-auto space-y-4">
    {{-- Page header --}}
    <div class="flex items-center justify-between">
        <h2 class="text-h4 font-semibold text-neutral-800">@lang('general.notifications')</h2>

        @if($notifications->getCollection()->contains(fn ($n) => is_null($n->read_at)))
            <button
                type="button"
                wire:click="markAllAsRead"
                class="text-body font-medium text-secondary-medium transition hover:text-neutral-900 hover:underline">
                @lang('general.mark-all-read')
            </button>
        @endif
    </div>

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
                        {{ $notification->data['body'][app()->getLocale()] ?? '' }}
                    </p>

                    <p class="mt-1 text-caption text-secondary-light">
                        {{ $notification->created_at?->diffForHumans() }}
                    </p>
                </div>

                {{-- Unread dot (fades + shrinks out on read) --}}
                <span class="mt-2 size-2 shrink-0 rounded-full bg-primary transition-all duration-300 {{ $isUnread ? 'opacity-100 scale-100' : 'opacity-0 scale-0' }}"
                      x-bind:style="clicked ? 'opacity: 0; transform: scale(0)' : ''"></span>
            </a>
        @empty
            <div class="flex flex-col items-center justify-center gap-2 px-4 py-16 text-center">
                <span class="inline-flex size-12 items-center justify-center rounded-full bg-neutral-100 text-secondary-light">
                    <x-icons.notification class="size-5" />
                </span>
                <span class="text-body text-secondary-light">@lang('general.no-notifications')</span>
            </div>
        @endforelse
    </div>

    {{ $notifications->links() }}
</div>
