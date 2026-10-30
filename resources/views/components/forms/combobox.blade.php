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
        class="absolute inset-y-0 end-0 flex items-center pe-3 text-ink-2 transition
               hover:text-ink disabled:opacity-40 disabled:hover:text-ink-2"
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
               border border-line-soft bg-card py-1 shadow-e2"
    >
        <template x-if="filtered.length === 0">
            <li class="px-3 py-2 text-small text-ink-3">{{ $emptyText }}</li>
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
                    isFull(option) ? 'cursor-not-allowed text-ink-3' : 'cursor-pointer text-ink',
                    active === index && !isFull(option) ? 'bg-surface' : '',
                    isSelected(option) ? 'bg-soft' : '',
                    isSelected(option) ? 'font-semibold text-ink' : '',
                ]"
            >
                @if($variant === 'employee')
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-round bg-surface
                               text-caption font-semibold text-ink-2"
                        x-text="initials(option.label)"
                        aria-hidden="true"
                    ></span>
                    <span class="min-w-0 flex-1 truncate" x-text="option.label"></span>
                    <template x-if="option.activeContractEndsAt">
                        <x-tag variant="rush" icon="alert" class="shrink-0">
                            @lang('general.composer.duplicate-short')
                        </x-tag>
                    </template>
                @elseif($variant === 'position')
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate" x-text="option.label"></span>
                        <template x-if="option.centerLabel">
                            <span class="truncate text-caption text-ink-3" x-text="option.centerLabel"></span>
                        </template>
                    </span>
                    <span class="flex shrink-0 items-center gap-1.5">
                        <span class="flex gap-0.5" aria-hidden="true">
                            <template x-for="seat in seatBar(option)" :key="seat.i">
                                <span class="h-1 w-2.5 rounded-full"
                                      x-bind:class="seat.taken ? 'bg-ink' : 'bg-surface'"></span>
                            </template>
                        </span>
                        <span class="text-caption tabular-nums"
                              x-bind:class="isFull(option) ? 'text-ink-3' : 'text-ink-2'"
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
