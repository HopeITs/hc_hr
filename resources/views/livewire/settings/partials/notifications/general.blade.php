{{-- c. General: the master switch, channel switches, sound, previews, frequency. --}}
<section class="max-w-4xl rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6" aria-labelledby="ns-general-title">
    <h2 id="ns-general-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.general.title') }}</h2>

    {{-- The master switch: pauses, never rewrites the rest. --}}
    <div class="mt-3 rounded-control border-[1.5px] px-4 {{ $paused ? 'border-dashed border-line bg-soft' : 'border-ink' }}" data-testid="master-switch">
        @include('livewire.settings.partials.notification-switch', [
            'id' => 'ns-enabled',
            'model' => 'prefs.enabled',
            'icon' => $paused ? 'bell-off' : 'bell',
            'label' => __('notification-settings.general.master.label'),
            'description' => __('notification-settings.general.master.description'),
        ])
        @if ($paused)
            <p class="flex items-start gap-2 pb-3 text-small font-semibold text-ink" data-testid="paused-note">
                <x-ic name="pause" class="mt-0.5" />{{ __('notification-settings.general.master.paused') }}
            </p>
        @endif
    </div>

    <h3 class="mt-6 font-bold text-ink-2">{{ __('notification-settings.general.channels') }}</h3>
    <div class="divide-y divide-line-soft">
        @foreach ($channels as $channel)
            @include('livewire.settings.partials.notification-switch', [
                'id' => 'ns-channel-'.$channel->value,
                'model' => 'prefs.'.$channel->value,
                'icon' => ['in_app' => 'bell', 'desktop' => 'monitor', 'mobile' => 'phone', 'email' => 'mail'][$channel->value],
                'label' => $channel->label(),
                'description' => __('notification-settings.general.channel-hint.'.$channel->value),
            ])
        @endforeach
    </div>

    <div class="mt-4 divide-y divide-line-soft border-t border-line-soft">
        @include('livewire.settings.partials.notification-switch', [
            'id' => 'ns-sound', 'model' => 'prefs.sound', 'icon' => 'volume',
            'label' => __('notification-settings.general.sound.label'),
            'description' => __('notification-settings.general.sound.description'),
        ])
        @include('livewire.settings.partials.notification-switch', [
            'id' => 'ns-previews', 'model' => 'prefs.previews', 'icon' => 'eye',
            'label' => __('notification-settings.general.previews.label'),
            'description' => __('notification-settings.general.previews.description'),
        ])
    </div>

    <fieldset class="mt-4 border-t border-line-soft pt-4" aria-describedby="ns-frequency-desc">
        <legend class="sr-only">{{ __('notification-settings.general.frequency.label') }}</legend>
        <p class="font-bold text-ink" aria-hidden="true">{{ __('notification-settings.general.frequency.label') }}</p>
        <p id="ns-frequency-desc" class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.general.frequency.description') }}</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-3">
            @foreach ($frequencies as $frequency)
                <label class="relative block cursor-pointer">
                    <input type="radio" name="ns-frequency" value="{{ $frequency }}" class="peer sr-only" wire:model.live="prefs.frequency" @checked($prefs['frequency'] === $frequency)>
                    <span class="hc-choice flex min-h-11 items-center gap-2 px-3 py-2 font-semibold text-ink">
                        <x-ic :name="$prefs['frequency'] === $frequency ? 'check-circle' : 'dot'" class="{{ $prefs['frequency'] === $frequency ? 'text-ink' : 'text-ink-3' }}" />
                        {{ __('notification-settings.general.frequency.'.$frequency) }}
                    </span>
                </label>
            @endforeach
        </div>
        @error('prefs.frequency')<p class="hc-err mt-2" data-inline-error>{{ $message }}</p>@enderror

        @if ($prefs['frequency'] === 'daily')
            <div class="mt-4 max-w-48">
                <label for="ns-digest-time" class="mb-1.5 block font-bold text-ink">{{ __('notification-settings.general.frequency.time') }}</label>
                <input id="ns-digest-time" type="time" class="hc-field num" wire:model.live.debounce.700ms="prefs.digest_time"
                       @error('prefs.digest_time') aria-invalid="true" aria-describedby="ns-digest-time-error" @enderror>
                @error('prefs.digest_time')<p id="ns-digest-time-error" class="hc-err mt-1.5" data-inline-error>{{ $message }}</p>@enderror
            </div>
        @endif
    </fieldset>
</section>
