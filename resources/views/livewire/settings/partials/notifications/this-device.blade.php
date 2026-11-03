{{-- a. This device: driven by pushDevice (resources/js/push-notifications.js). --}}
<section class="max-w-4xl rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6" aria-labelledby="ns-device-title"
         x-data="{
             get deviceState() {
                 {{-- The same order as pushDevice.blocker(). --}}
                 if (! secure) return 'insecure';
                 if (iosNeedsInstall) return 'ios_install';
                 if (! supported || permission === 'unsupported') return 'unsupported';
                 if (! configured) return 'not_configured';
                 if (permission === 'denied') return 'denied';
                 {{-- Subscribed in the browser but unknown to the server (removed below): not delivering. --}}
                 if (subscribed && known) return 'on';
                 if (permission === 'default') return 'default';
                 return 'off';
             },
             deviceErrors: @js($deviceErrors),
             {{-- @js() is not compiled inside a component tag's attribute, so x-button reads these. --}}
             localText: @js([__('notification-settings.device.local-title'), __('notification-settings.device.local-body')]),
         }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            <x-ic name="bell" class="mt-1 size-5 text-ink-2" />
            <div class="min-w-0">
                <h2 id="ns-device-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.device.title') }}</h2>
                <p class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.device.lead') }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2" data-testid="device-state">
            <x-status tone="done" icon="check-circle" :label="__('notification-settings.device.state.on')" x-show="deviceState === 'on'" x-cloak />
            <x-status tone="wait" icon="bell-off" :label="__('notification-settings.device.state.off')" x-show="deviceState === 'off'" x-cloak />
            <x-status tone="attn" icon="question" :label="__('notification-settings.device.state.default')" x-show="deviceState === 'default'" x-cloak />
            <x-status tone="stop" icon="ban" :label="__('notification-settings.device.state.denied')" x-show="deviceState === 'denied'" x-cloak />
            <x-status tone="stop" icon="ban" :label="__('notification-settings.device.state.unsupported')" x-show="deviceState === 'unsupported'" x-cloak />
            <x-status tone="stop" icon="shield" :label="__('notification-settings.device.state.insecure')" x-show="deviceState === 'insecure'" x-cloak />
            <x-status tone="attn" icon="phone" :label="__('notification-settings.device.state.ios_install')" x-show="deviceState === 'ios_install'" x-cloak />
            <x-status tone="stop" icon="info" :label="__('notification-settings.device.state.not_configured')" x-show="deviceState === 'not_configured'" x-cloak />
            <x-tag variant="soft" icon="monitor" :label="__('notification-settings.device.installed')" x-show="standalone" x-cloak />
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        {{-- Permission is asked by this click only. --}}
        <x-button icon="bell" :loading="false" data-testid="push-enable"
                  x-show="! subscribed && ['default', 'off'].includes(deviceState)" x-cloak
                  x-on:click="enable()" x-bind:disabled="busy" x-bind:aria-busy="String(!! busy)">
            {{ __('notification-settings.device.enable') }}
        </x-button>
        <x-button variant="outline" icon="bell-off" :loading="false" data-testid="push-disable"
                  x-show="subscribed" x-cloak
                  x-on:click="disable()" x-bind:disabled="busy" x-bind:aria-busy="String(!! busy)">
            {{ __('notification-settings.device.disable') }}
        </x-button>
        <x-button variant="quiet" icon="send" :loading="false" data-testid="push-local-test"
                  x-show="permission === 'granted'" x-cloak
                  x-on:click="localTest(...localText)" x-bind:disabled="busy">
            {{ __('notification-settings.device.local-test') }}
        </x-button>
        <span class="text-small text-ink-2" x-show="busy" x-cloak>{{ __('notification-settings.device.working') }}</span>
    </div>

    <p class="hc-err mt-3" role="alert" x-show="error" x-cloak x-text="deviceErrors[error] ?? deviceErrors.failed"></p>
    <p class="mt-3 text-small font-semibold text-ink" role="status" aria-live="polite">
        @foreach (['shown', 'denied', 'unsupported', 'failed'] as $outcome)
            <span x-show="testResult === '{{ $outcome }}'" x-cloak>{{ __('notification-settings.device.local-result.'.$outcome) }}</span>
        @endforeach
    </p>

    <div class="mt-4 space-y-2 rounded-control bg-soft p-4 text-small text-ink-2" x-show="deviceState === 'ios_install'" x-cloak>
        <p>{{ __('notification-settings.device.ios-install') }}</p>
        <a href="{{ route('settings.app') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 font-semibold text-ink underline underline-offset-2">
            {{ __('notification-settings.device.ios-install-link') }}
            <x-ic name="chevron" class="rtl:rotate-180" />
        </a>
    </div>

    <div class="mt-4 rounded-control border border-line-soft p-4" x-show="deviceState === 'denied'" x-cloak data-testid="push-blocked-help">
        <h3 class="font-bold text-ink">{{ __('notification-settings.device.blocked.title') }}</h3>
        <ul class="mt-2 list-disc space-y-1.5 ps-5 text-small text-ink-2">
            @foreach (['chromium', 'safari', 'firefox', 'android', 'ios'] as $platform)
                <li>{{ __('notification-settings.device.blocked.'.$platform) }}</li>
            @endforeach
        </ul>
    </div>

    <p class="mt-4 flex items-start gap-2 text-small text-ink-2">
        <x-ic name="info" class="mt-0.5 text-ink" />
        <span>{{ __('notification-settings.device.os-note') }}</span>
    </p>

    <details class="group mt-2">
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 font-semibold text-ink">
            <x-ic name="chevron" class="transition-transform group-open:rotate-90 rtl:rotate-180 rtl:group-open:rotate-90" />
            {{ __('notification-settings.device.os.title') }}
        </summary>
        <ul class="list-disc space-y-1.5 ps-10 text-small text-ink-2">
            @foreach (['windows', 'macos', 'android', 'ios'] as $os)
                <li>{{ __('notification-settings.device.os.'.$os) }}</li>
            @endforeach
        </ul>
    </details>
</section>
