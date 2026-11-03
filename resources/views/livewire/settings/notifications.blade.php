<x-slot:title>
    {{ __('notification-settings.title') }}
</x-slot:title>

{{--
    Notification settings (App\Livewire\Settings\NotificationSettings,
    docs/notifications/architecture.md). Every control saves itself; the live
    region under the header says so. What only the browser can know — the
    permission, this browser's subscription — is the Alpine component
    `pushDevice` (resources/js/push-notifications.js), which also tells the
    page when this device was added or removed (push-device-changed).
--}}
@php
    $paused = ! $prefs['enabled'];
    $offChannels = collect($channels)->reject(fn ($c) => $prefs[$c->value])->map->value->all();
    $deviceErrors = collect(['denied', 'unsupported', 'not_configured', 'insecure', 'network', 'failed', 'ios_install'])
        ->mapWithKeys(fn ($code) => [$code => __('notification-settings.device.errors.'.$code)]);
@endphp

<div class="w-full space-y-6 py-8 lg:px-12" x-data="pushDevice" x-on:push-device-changed.window="$wire.$refresh()" data-testid="notification-settings">

    <x-page-header class="!mb-0" :title="__('notification-settings.title')" :lead="__('notification-settings.lead')">
        <x-slot:crumb>
            <a href="{{ route('settings.password') }}" wire:navigate class="inline-flex items-center gap-1.5">
                <x-ic name="chevron" class="rotate-180 rtl:rotate-0" />
                {{ __('notification-settings.back') }}
            </a>
        </x-slot:crumb>
    </x-page-header>

    {{-- One polite line for every autosave; the key makes a repeated "Saved" a new announcement. --}}
    <div class="sticky top-2 z-10 max-w-4xl" role="status" aria-live="polite" data-testid="save-status">
        @if ($saveState === 'saved')
            <span wire:key="saved-{{ $saveTick }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition.opacity
                  class="inline-flex items-center gap-2 rounded-round border border-line-soft bg-card px-3 py-1.5 text-small font-semibold text-ink shadow-e1">
                <x-ic name="check-circle" />{{ __('notification-settings.status.saved') }}
            </span>
        @elseif ($saveState === 'error')
            <span wire:key="error-{{ $saveTick }}" class="hc-err rounded-round border border-primary bg-card px-3 py-1.5">{{ __('notification-settings.status.error') }}</span>
        @endif
    </div>

    @include('livewire.settings.partials.notifications.this-device')

    {{-- b. How it works ----------------------------------------------------------------- --}}
    <x-card :title="__('notification-settings.explainer.title')" class="max-w-4xl">
        <dl class="grid gap-4 sm:grid-cols-3">
            <div>
                <dt class="flex items-center gap-2 font-bold text-ink"><x-ic name="bell" />{{ __('notification-settings.explainer.in_app.title') }}</dt>
                <dd class="mt-1 text-small">{{ __('notification-settings.explainer.in_app.body') }}</dd>
            </div>
            <div>
                <dt class="flex items-center gap-2 font-bold text-ink"><x-ic name="monitor" /><x-ic name="phone" />{{ __('notification-settings.explainer.push.title') }}</dt>
                <dd class="mt-1 text-small">{{ __('notification-settings.explainer.push.body') }}</dd>
            </div>
            <div>
                <dt class="flex items-center gap-2 font-bold text-ink"><x-ic name="mail" />{{ __('notification-settings.explainer.email.title') }}</dt>
                <dd class="mt-1 text-small">{{ __('notification-settings.explainer.email.body') }}</dd>
            </div>
        </dl>
        <p class="mt-4 text-small">{{ __('notification-settings.explainer.split') }}</p>
    </x-card>

    @include('livewire.settings.partials.notifications.general')

    @include('livewire.settings.partials.notifications.matrix')

    @include('livewire.settings.partials.notifications.schedule')

    @include('livewire.settings.partials.notifications.devices', ['devices' => $this->devices])

    @include('livewire.settings.partials.notifications.test')
</div>
