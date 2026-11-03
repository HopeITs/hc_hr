<x-slot:title>
    {{ __('pwa.title') }}
</x-slot:title>

{{--
    "Install the app" (App\Livewire\Settings\InstallApp). Everything that
    changes here is the browser's to know (pwa-install.js): installed or not,
    whether Chromium offers its prompt, and which device this is. The Install
    button exists only while a real prompt is waiting; everywhere else the
    steps for the browser's own menu are the way in.
--}}
<div class="w-full py-8 space-y-6 lg:px-12" x-data="pwaInstall" data-testid="install-app">

    <x-page-header class="!mb-0" :title="__('pwa.title')" :lead="__('pwa.lead')">
        <x-slot:crumb>
            <a href="{{ route('settings.password') }}" wire:navigate class="inline-flex items-center gap-1.5">
                <x-ic name="chevron" class="rotate-180 rtl:rotate-0" />
                {{ __('pwa.back') }}
            </a>
        </x-slot:crumb>
    </x-page-header>

    {{-- The app, where it stands on this device, and the one real action. --}}
    <section class="max-w-3xl rounded-surface border border-line-soft bg-card p-6 shadow-e1 sm:p-8" aria-labelledby="install-app-name">
        <div class="flex flex-wrap items-start gap-5">
            <img src="{{ asset('favicons/android-chrome-192x192.png') }}" width="64" height="64" alt=""
                 class="size-16 shrink-0 rounded-surface border border-line-soft">

            <div class="min-w-0 flex-1 space-y-2">
                <h2 id="install-app-name" class="text-h4 font-extrabold text-ink">{{ __('pwa.app-name') }}</h2>
                <p class="text-body text-ink-2">{{ __('pwa.app-description') }}</p>

                <p class="flex flex-wrap items-center gap-2 text-small text-ink-2">
                    <span>{{ __('pwa.status.label') }}:</span>
                    <x-status tone="done" icon="check-circle" :label="__('pwa.status.installed')" x-show="status === 'installed'" x-cloak data-status="installed" />
                    <x-status tone="attn" icon="plus" :label="__('pwa.status.ready')" x-show="status === 'ready'" x-cloak data-status="ready" />
                    <x-status tone="wait" icon="info" :label="__('pwa.status.manual')" x-show="status === 'manual'" x-cloak data-status="manual" />
                    <x-status tone="stop" icon="ban" :label="__('pwa.status.unsupported')" x-show="status === 'unsupported'" x-cloak data-status="unsupported" />
                </p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-4">
            <x-button icon="plus" :loading="false" data-testid="install-app-button"
                      x-show="canPrompt" x-cloak
                      x-on:click="install()" x-bind:disabled="busy" x-bind:aria-busy="busy.toString()">
                {{ __('pwa.install') }}
            </x-button>

            <p class="text-small text-ink-2" x-show="installed" x-cloak>{{ __('pwa.installed-hint') }}</p>

            <p class="text-body font-semibold text-ink" role="status" aria-live="polite">
                <span x-show="busy" x-cloak>{{ __('pwa.installing') }}</span>
                @foreach (['accepted', 'dismissed', 'failed'] as $outcome)
                    <span x-show="!busy && outcome === '{{ $outcome }}'" x-cloak>{{ __('pwa.outcome.'.$outcome) }}</span>
                @endforeach
            </p>
        </div>
    </section>

    {{-- Said plainly, because "install" can sound like "works offline". --}}
    <x-card :title="__('pwa.about.title')" class="max-w-3xl">
        <ul class="space-y-2">
            @foreach (__('pwa.about.points') as $point)
                <li class="flex items-start gap-2.5">
                    <x-ic name="check" class="mt-1 text-ink" />
                    <span>{{ $point }}</span>
                </li>
            @endforeach
        </ul>
    </x-card>

    {{-- The steps for the detected platform; hidden once running installed. --}}
    <x-card :title="__('pwa.this-device')" class="max-w-3xl" x-show="!installed" x-cloak>
        @foreach ($platforms as $platform)
            <div x-show="platform === '{{ $platform }}'" x-cloak data-platform="{{ $platform }}">
                @include('livewire.settings.partials.install-steps', ['platform' => $platform])
            </div>
        @endforeach
    </x-card>

    <details class="group max-w-3xl rounded-surface border border-line-soft bg-card shadow-e1">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-h4 font-extrabold text-ink">
            {{ __('pwa.other-devices') }}
            <x-ic name="chevron" class="rotate-90 transition-transform group-open:-rotate-90" />
        </summary>
        <div class="space-y-6 border-t border-line-soft px-5 py-5 text-body text-ink-2">
            @foreach ($otherDevices as $platform)
                <div data-other-platform="{{ $platform }}">
                    @include('livewire.settings.partials.install-steps', ['platform' => $platform])
                </div>
            @endforeach
        </div>
    </details>
</div>
