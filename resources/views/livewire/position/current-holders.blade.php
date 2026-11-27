{{-- Browser Back can restore a Livewire snapshot captured while the dialog was open.
     Start with it closed on each page entry, without sending a request. --}}
<div x-data
     x-init="$wire.$set('showCurrentHolders', false, false)"
     x-on:open-position-holders.window="$wire.openCurrentHolders($event.detail.positionId)">
    @teleport('body')
    <x-modal.dialog id="position-current-holders" :title="__('general.running-seat-holders')" wire:model.live="showCurrentHolders">
        @if($holdersPosition)
            <p class="mb-3 font-semibold"><bdi>{{ $holdersPosition->title }}</bdi></p>
            <ul class="divide-y divide-line-soft">
                @forelse($holdersPosition->runningSeatContracts->pluck('employee')->filter()->unique('id')->sortBy('name') as $holder)
                    <li class="flex items-center gap-3 py-3">
                        @can('viewAvatar', $holder)
                            <img src="{{ $holder->avatar_url }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-full object-cover">
                        @else
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface font-semibold" aria-hidden="true">{{ mb_substr($holder->name, 0, 1) }}</span>
                        @endcan
                        @can('viewDirectory', $holder)
                            <button type="button" x-on:click="close(); $dispatch('open-profile', { employeeId: {{ $holder->id }} })"
                                    class="min-w-0 cursor-pointer break-words text-start font-semibold hover:underline"><bdi>{{ $holder->name }}</bdi></button>
                        @else
                            <bdi class="min-w-0 break-words font-semibold">{{ $holder->name }}</bdi>
                        @endcan
                    </li>
                @empty
                    <li class="py-2 text-ink-2">{{ __('general.no-running-seat-holders') }}</li>
                @endforelse
            </ul>
        @endif
    </x-modal.dialog>
    @endteleport
</div>
