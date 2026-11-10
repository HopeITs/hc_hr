<div class="flex flex-col gap-3">
    <div class="flex flex-col lg:flex-row gap-3">
        @includeWhen($this->canSearch(), 'livewire-table::bar.search')
        <div class="justify-center items-center w-full border-y border-transparent" wire:loading.flex>
            <span class="inline-block border border-4 border-primary border-r-transparent motion-safe:animate-spin rounded-full my-2 p-2"></span>
        </div>
        @include('livewire-table::bar.selection')
        <div class="flex gap-3 ml-auto">
    @if(method_exists($this, 'savedViews'))
        {{-- HasSavedViews: personal views (table state only), owner + active role only. --}}
        @php($savedViews = $this->savedViews())
        <div x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false" class="relative">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="saved-views-panel-{{ $this->getId() }}"
                class="inline-flex min-h-11 items-center rounded-control border border-neutral-300 bg-white px-4 text-sm font-semibold text-neutral-800 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('general.views-menu') }} ({{ $savedViews->count() }})
            </button>
            <div id="saved-views-panel-{{ $this->getId() }}" x-show="open" x-cloak class="absolute end-0 top-full z-30 mt-2 w-[min(24rem,calc(100vw-2rem))] rounded-surface border border-[var(--line-hairline)] bg-white p-3 shadow-e1">
                <p class="mb-2 text-small text-neutral-600">{{ __('general.views-help') }}</p>
                @error('savedViewName')
                    <p role="alert" class="mb-2 text-sm font-medium text-red-800">{{ $message }}</p>
                @enderror
                <ul class="flex flex-col gap-2" aria-label="{{ __('general.views-saved-list') }}">
                    @forelse($savedViews as $saved)
                        <li wire:key="saved-view-{{ $saved->id }}" x-data="{ renaming: false, name: @js($saved->name) }" class="flex flex-wrap items-center gap-2">
                            <button type="button" x-show="! renaming" wire:click="applyView({{ $saved->id }})" aria-label="{{ __('general.views-apply', ['name' => $saved->name]) }}"
                                class="inline-flex min-h-11 min-w-0 flex-1 items-center rounded-lg px-3 text-start text-sm font-medium hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"><span class="truncate" dir="auto">{{ $saved->name }}</span></button>
                            <button type="button" x-show="! renaming" x-on:click="renaming = true; $nextTick(() => $refs.rename.focus())" aria-label="{{ __('general.views-rename', ['name' => $saved->name]) }}"
                                class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.edit') }}</button>
                            <button type="button" x-show="! renaming" wire:click="deleteView({{ $saved->id }})" wire:confirm="{{ __('general.views-delete-confirm') }}" aria-label="{{ __('general.views-delete', ['name' => $saved->name]) }}"
                                class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-red-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.delete') }}</button>
                            <form x-show="renaming" x-cloak class="flex w-full flex-wrap items-center gap-2"
                                x-on:submit.prevent="$wire.renameView({{ $saved->id }}, name).then(ok => { renaming = ! ok })"
                                x-on:keydown.escape.stop="renaming = false">
                                <input x-ref="rename" x-model="name" type="text" maxlength="60" required dir="auto" aria-label="{{ __('general.views-name') }}"
                                    class="min-h-11 min-w-0 flex-1 rounded-control border border-neutral-300 px-3 text-sm">
                                <button type="submit" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.save') }}</button>
                                <button type="button" x-on:click="renaming = false" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.cancel') }}</button>
                            </form>
                        </li>
                    @empty
                        <li class="text-sm text-neutral-600">{{ __('general.views-none') }}</li>
                    @endforelse
                </ul>
                <form class="mt-3 flex flex-wrap items-center gap-2" wire:submit="saveView(savedViewName)">
                    <label for="saved-view-name-{{ $this->getId() }}" class="sr-only">{{ __('general.views-name') }}</label>
                    <input id="saved-view-name-{{ $this->getId() }}" type="text" wire:model="savedViewName" maxlength="60" required dir="auto" placeholder="{{ __('general.views-name') }}"
                        class="min-h-11 min-w-0 flex-1 rounded-control border border-neutral-300 px-3 text-sm">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-control border border-primary bg-primary px-4 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.views-save-current') }}</button>
                </form>
            </div>
        </div>
    @endif
            @includeWhen($this->useReordering, 'livewire-table::bar.buttons.reordering')
            @include('livewire-table::bar.buttons.refresh')
            @include('livewire-table::bar.dropdowns.polling')
            @include('livewire-table::bar.dropdowns.columns')
            @include('livewire-table::bar.dropdowns.filters')
            @include('livewire-table::bar.dropdowns.actions')
            @include('livewire-table::bar.dropdowns.trashed')
            <div class="perpage-select-box w-24" data-tour="table.per-page">
                <x-forms.select2-input wire:model.live="perPage" :hideSearch="true">
                    @foreach($perPageOptions as $perPage)
                        <option wire:key="{{ $perPage }}" value="{{ $perPage }}">{{ $perPage }}</option>
                    @endforeach
                </x-forms.select2-input>
            </div>
        </div>
    </div>
</div>
