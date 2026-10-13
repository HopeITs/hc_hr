<div>
    {{-- Header --}}
    <div class="sticky top-0 z-10 flex items-center justify-between border-b border-neutral-100 bg-neutral-50/90 px-4 py-3 backdrop-blur">
        <span class="text-body font-semibold text-neutral-800">
            @lang('general.notifications')
        </span>

        @if($hasUnread)
            <button
                type="button"
                wire:click="markAllAsRead"
                class="text-small font-medium text-secondary-medium transition hover:text-neutral-900 hover:underline">
                @lang('general.mark-all-read')
            </button>
        @endif
    </div>

    {{-- List --}}
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
            class="notif-row group relative flex items-start gap-3 border-b border-neutral-100 px-4 py-3 transition-colors duration-300 last:border-b-0
                   {{ $isUnread
                        ? 'bg-neutral-100 hover:bg-neutral-200 cursor-pointer'
                        : 'bg-white hover:bg-neutral-50' }}">

            {{-- Unread accent bar --}}
            <span class="absolute inset-y-0 start-0 w-[3px] transition-all duration-300 {{ $isUnread ? 'bg-primary' : 'bg-transparent' }}"
                  x-bind:style="clicked ? 'background-color: transparent' : ''"></span>

            {{-- Text --}}
            <div class="min-w-0 flex-1">
                <p class="truncate text-body font-semibold transition-colors duration-300
                          {{ $isUnread ? 'text-neutral-900' : 'text-neutral-800' }}">
                    {{ $note->data['title'][app()->getLocale()] ?? 'Notification' }}
                </p>

                <p class="mt-1 line-clamp-2 text-small transition-colors duration-300
                          {{ $isUnread ? 'text-secondary-medium' : 'text-secondary-medium' }}">
                    {{ $note->data['body'][app()->getLocale()] ?? '' }}
                </p>

                <p class="mt-1 text-caption text-secondary-light">
                    {{ $note->created_at?->diffForHumans() }}
                </p>
            </div>

            {{-- Unread dot (fades + shrinks out on read) --}}
            <span class="mt-2 size-2 shrink-0 rounded-full bg-primary transition-all duration-300 {{ $isUnread ? 'opacity-100 scale-100' : 'opacity-0 scale-0' }}"
                  x-bind:style="clicked ? 'opacity: 0; transform: scale(0)' : ''"></span>
        </a>
    @empty
        <div class="flex flex-col items-center justify-center gap-2 px-4 py-12 text-center">
            <span class="inline-flex size-11 items-center justify-center rounded-full bg-neutral-100 text-secondary-light">
                <x-icons.notification class="size-5" />
            </span>
            <span class="text-body text-secondary-light">@lang('general.no-notifications')</span>
        </div>
    @endforelse
</div>
