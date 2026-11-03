{{-- f. The signed-in user's devices ($devices = auth()->user()->pushSubscriptions()). --}}
<section class="max-w-4xl space-y-3" aria-labelledby="ns-devices-title" data-testid="device-list">
    <div>
        <h2 id="ns-devices-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.devices.title') }}</h2>
        <p class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.devices.lead') }}</p>
    </div>

    @forelse ($devices as $device)
        @php
            $type = in_array($device->device_type, ['desktop', 'mobile', 'tablet'], true) ? $device->device_type : 'desktop';
            $fallbackName = __('notification-settings.devices.unnamed', [
                'browser' => $device->browser ?: __('notification-settings.devices.unknown'),
                'platform' => $device->platform ?: __('notification-settings.devices.unknown'),
            ]);
            $name = $device->device_name ?: $fallbackName;
            $state = $device->isExpired() ? 'expired' : (! $device->enabled ? 'off' : ($device->failure_count > 0 ? 'failing' : 'active'));
            [$tone, $stateIcon] = ['active' => ['done', 'check-circle'], 'off' => ['wait', 'pause'], 'failing' => ['attn', 'alert'], 'expired' => ['stop', 'ban']][$state];
            $channelOff = ! $prefs[$device->channel()->value];
            $rowId = 'ns-device-'.$device->id;
        @endphp

        <article wire:key="device-{{ $device->id }}" class="rounded-surface border border-line-soft bg-card p-4 shadow-e1 sm:p-5"
                 aria-labelledby="{{ $rowId }}-name" data-device-id="{{ $device->id }}"
                 x-data="{
                 editing: false,
                 name: @js($device->device_name ?? ''),
                 original: @js($device->device_name ?? ''),
                 {{-- @js() is not compiled inside a component tag's attribute, so x-button reads this. --}}
                 removeQuestion: @js([
                     'title' => __('notification-settings.devices.remove-title'),
                     'message' => __('notification-settings.devices.remove-message', ['name' => $name]),
                     'confirmLabel' => __('notification-settings.devices.remove-confirm'),
                 ]),
             }">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-round bg-soft text-ink" title="{{ __('notification-settings.devices.type.'.$type) }}">
                        <x-ic :name="$type === 'desktop' ? 'monitor' : $type" class="size-5" />
                        <span class="sr-only">{{ __('notification-settings.devices.type.'.$type) }}</span>
                    </span>

                    <div class="min-w-0 flex-1">
                        <div x-show="! editing" class="flex flex-wrap items-center gap-2">
                            <h3 id="{{ $rowId }}-name" class="truncate font-bold text-ink">{{ $name }}</h3>
                            <button type="button" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink-2 hover:bg-hover hover:text-ink"
                                    x-on:click="editing = true; $nextTick(() => $refs.input.focus())"
                                    aria-label="{{ __('notification-settings.devices.rename-label', ['name' => $name]) }}">
                                <x-ic name="pen" />
                            </button>
                            <x-tag variant="soft" icon="pin" :label="__('notification-settings.devices.this-device')"
                                   x-show="subscriptionId != null && String(subscriptionId) === '{{ $device->id }}'" x-cloak data-this-device />
                            @if ($device->standalone)
                                <x-tag variant="soft" :label="__('notification-settings.devices.installed')" />
                            @endif
                        </div>

                        <form x-show="editing" x-cloak class="flex flex-wrap items-center gap-2"
                              x-on:submit.prevent="$wire.renameDevice({{ $device->id }}, name).then(saved => { if (saved) { editing = false; original = name } })"
                              x-on:keydown.escape="editing = false; name = original">
                            <label for="{{ $rowId }}-input" class="sr-only">{{ __('notification-settings.devices.name') }}</label>
                            <input id="{{ $rowId }}-input" x-ref="input" type="text" maxlength="80" x-model="name" class="hc-field max-w-xs"
                                   placeholder="{{ $fallbackName }}"
                                   @error('device.'.$device->id) aria-invalid="true" aria-describedby="{{ $rowId }}-error" @enderror>
                            <x-button type="submit" size="sm" :loading="false">{{ __('notification-settings.devices.save') }}</x-button>
                            <x-button variant="quiet" size="sm" :loading="false" x-on:click="editing = false; name = original">{{ __('notification-settings.devices.cancel') }}</x-button>
                        </form>
                        @error('device.'.$device->id)<p id="{{ $rowId }}-error" class="hc-err mt-1" data-inline-error>{{ $message }}</p>@enderror

                        <p class="mt-1 text-small text-ink-2">
                            {{ $device->browser ?: __('notification-settings.devices.unknown') }} · {{ $device->platform ?: __('notification-settings.devices.unknown') }}
                            · {{ $device->last_seen_at ? __('notification-settings.devices.last-active', ['time' => $device->last_seen_at->diffForHumans()]) : __('notification-settings.devices.never') }}
                        </p>
                        @if ($state === 'failing' && $device->last_error)
                            <p class="mt-1 text-small text-ink-2">{{ __('notification-settings.devices.last-error', ['error' => $device->last_error]) }}</p>
                        @endif
                        @if ($channelOff)
                            <p class="mt-1 flex items-center gap-1.5 text-small text-ink-2"><x-ic name="pause" class="text-ink" />{{ __('notification-settings.devices.channel-off', ['channel' => $device->channel()->label()]) }}</p>
                        @endif
                    </div>
                </div>

                <x-status :tone="$tone" :icon="$stateIcon" :label="__('notification-settings.devices.state.'.$state)" data-device-state="{{ $state }}" />
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-line-soft pt-3">
                <div class="flex flex-wrap items-center gap-x-6">
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 font-semibold text-ink">
                        <input type="checkbox" role="switch" class="hc-switch" @checked($device->enabled)
                               wire:change="setDeviceEnabled({{ $device->id }}, $event.target.checked)">
                        {{ __('notification-settings.devices.enabled') }}
                    </label>
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 font-semibold text-ink">
                        <input type="checkbox" role="switch" class="hc-switch" @checked($device->urgent_only)
                               wire:change="setDeviceUrgentOnly({{ $device->id }}, $event.target.checked)"
                               aria-describedby="{{ $rowId }}-urgent-hint">
                        {{ __('notification-settings.devices.urgent-only') }}
                    </label>
                    <span id="{{ $rowId }}-urgent-hint" class="sr-only">{{ __('notification-settings.devices.urgent-only-hint') }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-button variant="outline" size="sm" icon="send" wire:click="testDevice({{ $device->id }})">
                        {{ __('notification-settings.devices.test') }}
                    </x-button>
                    <x-button variant="quiet" size="sm" icon="trash" :loading="false" data-testid="remove-device"
                              x-on:click="if (await $confirm(removeQuestion)) $wire.removeDevice({{ $device->id }}).then(() => {
                                  {{-- This browser's own row: ask the server again, so the card above stops saying 'on'. --}}
                                  if (subscriptionId != null && String(subscriptionId) === '{{ $device->id }}') refresh(true);
                              })">
                        {{ __('notification-settings.devices.remove') }}
                    </x-button>
                </div>
            </div>
        </article>
    @empty
        <x-center-empty-state :title="__('notification-settings.devices.empty-title')" :description="__('notification-settings.devices.empty-help')" class="!min-h-48">
            <x-slot:icon><x-ic name="monitor" class="size-9" /></x-slot:icon>
        </x-center-empty-state>
    @endforelse
</section>
