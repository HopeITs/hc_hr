@php($actions = $this->resolveActions()->filter(fn ($action): bool => ! $action->isStandalone()))

<div class="px-2 py-1 text-black">
    <x-livewire-table::dropdown size="sm" label="{{ __('general.actions') }}">
        <x-slot name="icon">
            <!-- Icon "play" (outline) from https://heroicons.com -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
            </svg>
        </x-slot>
        <div class="flex flex-col">
            <div class="flex flex-col border-b border-neutral-200 last:border-b-0">
                <span
                    class="px-3 py-2 font-bold text-small uppercase border-b border-neutral-200 bg-neutral-50 text-neutral-500">
                    @lang('Actions')
                </span>
                @foreach($actions as $action)
                    @if($action->callback() === null)
                        <button
                            class="px-3 py-1 text-left truncate hover:bg-neutral-100 disabled:hover:bg-white disabled:text-neutral-500"
                            x-data="{ selected: [item] }"
                            x-on:click="
                                {{ $action->code() }}
                                show = false
                            "
                            type="button">
                            {{ $action->label() }}
                        </button>
                    @else
                        <button
                            class="px-3 py-1 text-left truncate hover:bg-neutral-100 disabled:hover:bg-white disabled:text-neutral-500"
                            wire:key="{{ $action->code() }}"
                            wire:click="executeItemAction('{{ $action->code() }}', item)"
                            x-on:click="show = false"
                            type="button">
                            {{ $action->label() }}
                        </button>
                    @endif
                @endforeach
            </div>
        </div>
    </x-livewire-table::dropdown>
</div>
