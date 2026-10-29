@props([
    'id',
    'placeholder' => null,
    // Alpine expressions, evaluated in the calling scope.
    'options',              // () => array of { value, label, ... }
    'model',                // assignable path, e.g. selected.employee
    'disabled' => 'false',
    'error' => 'false',     // truthy Alpine expression → error styling
    'variant' => 'plain',   // plain | employee | position
    'clearable' => false,
    'emptyText' => null,
    'required' => false,
])

@php
    $placeholder ??= __('general.select');
    $emptyText ??= __('general.composer.no-results');

    /*
     * Interpolated into an Alpine expression as a JavaScript string literal, so
     * it has to be a single line — a wrapped class list would put a raw newline
     * inside the quotes and break the whole binding.
     */
    $control = preg_replace('/\s+/', ' ', trim(
        'hc-field pe-9 outline-none'
    ));
@endphp

<div
    x-data="hcCombobox({
        readOptions: () => ({{ $options }}),
        readValue: () => ({{ $model }}),
        writeValue: (next) => { {{ $model }} = next },
        isDisabled: () => ({{ $disabled }}),
        readError: () => ({{ $error }}),
        clearable: @js((bool) $clearable),
        placeholder: @js($placeholder),
    })"
    x-on:keydown.escape.prevent.stop="close()"
    x-on:click.outside="close()"
    class="relative"
>
    <input
        id="{{ $id }}"
        type="text"
        role="combobox"
        autocomplete="off"
        spellcheck="false"
        aria-autocomplete="list"
        aria-controls="{{ $id }}-listbox"
        x-ref="input"
        x-bind:class="[
            '{{ $control }}',
            hasError ? '!border-2 !border-primary' : '',
        ]"
        x-bind:value="open ? query : selectedLabel"
        x-bind:placeholder="isDisabled() ? '' : placeholder"
        x-bind:disabled="isDisabled()"
        x-bind:aria-expanded="open"
        x-bind:aria-activedescendant="open && active >= 0 ? '{{ $id }}-option-' + active : null"
        @if($required) aria-required="true" @endif
        x-on:input="query = $event.target.value; open = true; active = 0"
        x-on:focus="openList()"
        x-on:mousedown="openList()"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.home.prevent="open && (active = firstSelectable())"
        x-on:keydown.end.prevent="open && (active = lastSelectable())"
        x-on:keydown.enter.prevent="choose(active)"
        x-on:keydown.tab="commitAndClose()"
    />

    {{-- Trailing affordance: clear when there is something to clear, chevron otherwise. --}}
    <button
        type="button"
        tabindex="-1"
        class="absolute inset-y-0 end-0 flex items-center pe-3 text-secondary-medium transition
               hover:text-primary disabled:opacity-40 disabled:hover:text-secondary-medium"
        x-bind:disabled="isDisabled()"
        x-bind:aria-label="canClear ? @js(__('general.composer.clear')) : @js($placeholder)"
        x-on:click="canClear ? clear() : toggle()"
    >
        @if($clearable)
            <template x-if="canClear">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round"/>
                </svg>
            </template>
        @endif
        <template x-if="!canClear">
            <svg class="size-4 transition-transform" x-bind:class="open && 'rotate-180'"
                 viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path d="M5.5 8l4.5 4.5L14.5 8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </template>
    </button>

    <ul
        x-cloak
        x-show="open"
        x-transition.opacity.duration.120ms
        id="{{ $id }}-listbox"
        role="listbox"
        class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto overscroll-contain rounded-surface
               border border-neutral-200 bg-white py-1 shadow-e2"
    >
        <template x-if="filtered.length === 0">
            <li class="px-3 py-2 text-small text-secondary-light">{{ $emptyText }}</li>
        </template>

        <template x-for="(option, index) in filtered" :key="option.value">
            <li
                x-bind:id="'{{ $id }}-option-' + index"
                role="option"
                x-bind:aria-selected="isSelected(option)"
                x-bind:aria-disabled="isFull(option)"
                x-on:mouseenter="active = index"
                x-on:mousedown.prevent="choose(index)"
                x-bind:class="[
                    'flex items-center gap-2.5 px-3 py-2 text-body',
                    isFull(option) ? 'cursor-not-allowed text-secondary-light' : 'cursor-pointer text-neutral-800',
                    active === index && !isFull(option) ? 'bg-neutral-100' : '',
                    isSelected(option) ? 'bg-neutral-50' : '',
                    isSelected(option) ? 'font-semibold text-primary' : '',
                ]"
            >
                @if($variant === 'employee')
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-round bg-neutral-100
                               text-caption font-semibold text-secondary-medium"
                        x-text="initials(option.label)"
                        aria-hidden="true"
                    ></span>
                    <span class="min-w-0 flex-1 truncate" x-text="option.label"></span>
                    <template x-if="option.activeContractEndsAt">
                        <span class="shrink-0 rounded-round border border-primary px-2 py-0.5 text-caption font-semibold text-primary">
                            @lang('general.composer.duplicate-short')
                        </span>
                    </template>
                @elseif($variant === 'position')
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate" x-text="option.label"></span>
                        <template x-if="option.centerLabel">
                            <span class="truncate text-caption text-secondary-light" x-text="option.centerLabel"></span>
                        </template>
                    </span>
                    <span class="flex shrink-0 items-center gap-1.5">
                        <span class="flex gap-0.5" aria-hidden="true">
                            <template x-for="seat in seatBar(option)" :key="seat.i">
                                <span class="h-1 w-2.5 rounded-full"
                                      x-bind:class="seat.taken ? 'bg-primary' : 'bg-neutral-200'"></span>
                            </template>
                        </span>
                        <span class="text-caption tabular-nums"
                              x-bind:class="isFull(option) ? 'text-secondary-light' : 'text-secondary-medium'"
                              x-text="isFull(option)
                                  ? @js(__('general.composer.position-full'))
                                  : option.existEmp + ' / ' + option.maxEmp"></span>
                    </span>
                @else
                    <span class="min-w-0 flex-1 truncate" x-text="option.label"></span>
                @endif
            </li>
        </template>
    </ul>
</div>
