{{-- e. Quiet hours, time zone, working days, urgent bypass. --}}
<section class="max-w-4xl rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6" aria-labelledby="ns-schedule-title"
         x-data="{ tzUnknown: false }">
    <h2 id="ns-schedule-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.schedule.title') }}</h2>
    <p class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.schedule.lead') }}</p>

    <div class="mt-2 divide-y divide-line-soft">
        @include('livewire.settings.partials.notification-switch', [
            'id' => 'ns-quiet', 'model' => 'prefs.quiet_hours', 'icon' => 'moon',
            'label' => __('notification-settings.schedule.quiet.label'),
            'description' => __('notification-settings.schedule.quiet.description'),
        ])
    </div>

    <div class="mt-2 grid gap-4 sm:grid-cols-2 {{ $prefs['quiet_hours'] ? '' : 'opacity-70' }}">
        @foreach (['quiet_start' => 'start', 'quiet_end' => 'end'] as $field => $word)
            <div>
                <label for="ns-{{ $field }}" class="mb-1.5 block font-bold text-ink">{{ __('notification-settings.schedule.'.$word) }}</label>
                <input id="ns-{{ $field }}" type="time" class="hc-field num" wire:model.live.debounce.700ms="prefs.{{ $field }}"
                       @error('prefs.'.$field) aria-invalid="true" aria-describedby="ns-{{ $field }}-error" @enderror>
                @error('prefs.'.$field)<p id="ns-{{ $field }}-error" class="hc-err mt-1.5" data-inline-error>{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>

    <div class="mt-5">
        <label for="ns-timezone" class="mb-1.5 block font-bold text-ink">{{ __('notification-settings.schedule.timezone') }}</label>
        <div class="flex flex-wrap items-center gap-3">
            <select id="ns-timezone" x-ref="tz" class="hc-field max-w-sm" wire:model.live="prefs.timezone"
                    @error('prefs.timezone') aria-invalid="true" aria-describedby="ns-timezone-error" @enderror>
                <option value="">{{ __('notification-settings.schedule.timezone-default', ['zone' => $appTimezone]) }}</option>
                @foreach ($timezones as $zone)
                    <option value="{{ $zone }}" @selected($prefs['timezone'] === $zone)>{{ str_replace('_', ' ', $zone) }}</option>
                @endforeach
            </select>
            <x-button variant="outline" icon="pin" :loading="false" data-testid="use-device-timezone"
                      x-on:click="
                          const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                          tzUnknown = ! [...$refs.tz.options].some(o => o.value === tz);
                          if (! tzUnknown) $wire.set('prefs.timezone', tz);
                      ">
                {{ __('notification-settings.schedule.use-device') }}
            </x-button>
        </div>
        <p class="mt-1.5 text-small text-ink-2" x-show="tzUnknown" x-cloak role="status">{{ __('notification-settings.schedule.device-unknown') }}</p>
        @error('prefs.timezone')<p id="ns-timezone-error" class="hc-err mt-1.5" data-inline-error>{{ $message }}</p>@enderror
    </div>

    <fieldset class="mt-5" aria-describedby="ns-days-desc">
        <legend class="font-bold text-ink">{{ __('notification-settings.schedule.days') }}</legend>
        <p id="ns-days-desc" class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.schedule.days-description') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($weekOrder as $day)
                @php $on = in_array($day, $workingDays, true); @endphp
                <label class="relative cursor-pointer" wire:key="ns-day-{{ $day }}">
                    <input type="checkbox" value="{{ $day }}" class="peer sr-only" wire:model.live="workingDays" @checked($on)>
                    <span class="hc-choice inline-flex min-h-11 min-w-16 items-center justify-center gap-1.5 px-3 font-semibold {{ $on ? 'text-ink' : 'text-ink-2' }}">
                        @if ($on)<x-ic name="check" />@endif
                        <span aria-hidden="true">{{ __('notification-settings.days.'.$day) }}</span>
                        <span class="sr-only">{{ __('notification-settings.days-long.'.$day) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('workingDays')<p class="hc-err mt-2" data-inline-error>{{ $message }}</p>@enderror
    </fieldset>

    <div class="mt-4 divide-y divide-line-soft border-t border-line-soft">
        @include('livewire.settings.partials.notification-switch', [
            'id' => 'ns-urgent-bypass', 'model' => 'prefs.urgent_bypass', 'icon' => 'alert',
            'label' => __('notification-settings.schedule.urgent.label'),
            'description' => __('notification-settings.schedule.urgent.description'),
        ])
    </div>
</section>
