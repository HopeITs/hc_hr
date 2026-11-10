{{--
    The phone bar's shortcuts (prompt 10), for the dashboard's customise mode on a phone.
    One select per place (46px, native, so the phone's own picker opens); each change
    is saved at once and the bar below the page follows (bottom-bar-changed). The first
    BottomBar::BEFORE_NEW places sit before the raised + and the rest after it; + and More
    are always there. Behaviour: App\Livewire\Layout\BottomBarPicker.
--}}
@php
    $field = 'mt-1 block h-[46px] w-full rounded-control border-[1.5px] border-line bg-field px-3 text-body text-ink hover:border-ink-2';
@endphp
<section aria-labelledby="bottom-bar-picker-title" aria-describedby="bottom-bar-picker-lead" data-testid="bottom-bar-picker"
         class="rounded-surface border border-line-soft bg-card p-4 shadow-sm">
    <h2 id="bottom-bar-picker-title" class="text-h4 font-extrabold text-ink">{{ __('navigation.bottom-bar.title') }}</h2>
    <p id="bottom-bar-picker-lead" class="mt-1 text-small text-ink-2">
        {{ __('mobile.bar.lead') }}
    </p>

    <ol class="mt-4 space-y-3">
        @foreach ($places as $index => $place)
            <li wire:key="bottom-bar-place-{{ $index }}">
                <label for="bottom-bar-place-{{ $index }}" class="block text-small font-bold text-ink">
                    {{ __($index < \App\Support\BottomBar::BEFORE_NEW ? 'mobile.bar.place-before' : 'mobile.bar.place-after', ['number' => $index + 1]) }}
                </label>
                <select id="bottom-bar-place-{{ $index }}" data-bottom-bar-place="{{ $index }}"
                        wire:change="setSlot({{ $index }}, $event.target.value)"
                        class="{{ $field }}">
                    <optgroup label="{{ __('navigation.bottom-bar.pages') }}">
                        @foreach ($pages as $key => $option)
                            <option value="{{ $key }}" @selected($key === $place['key'])>{{ $option['label'] }}</option>
                        @endforeach
                    </optgroup>
                    @if ($actions !== [])
                        <optgroup label="{{ __('navigation.bottom-bar.new') }}">
                            @foreach ($actions as $key => $option)
                                <option value="{{ $key }}" @selected($key === $place['key'])>{{ $option['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </li>
        @endforeach

        <li class="flex min-h-11 items-center gap-2 rounded-control border border-dashed border-line px-3 text-small font-bold text-ink-2" data-bottom-bar-more>
            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            {{ __('mobile.bar.more-place') }}
        </li>
    </ol>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-small text-ink-2">{{ $custom ? __('navigation.bottom-bar.custom') : __('navigation.bottom-bar.default') }}</p>
        @if ($custom)
            <x-button variant="outline" wire:click="resetBar" data-testid="bottom-bar-reset">
                {{ __('navigation.bottom-bar.reset') }}
            </x-button>
        @endif
    </div>

    {{-- Spoken after each change; the region is always there so the first message is heard. --}}
    <p class="mt-2 text-small text-ink-2" role="status" aria-live="polite">
        @if ($status === 'saved')
            {{ __('navigation.bottom-bar.saved') }}
        @elseif ($status === 'reset')
            {{ __('navigation.bottom-bar.reset-done') }}
        @endif
    </p>
</section>
