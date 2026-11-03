{{-- g. A real test notification through NotificationTester. --}}
<section class="max-w-4xl rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6" aria-labelledby="ns-test-title" data-testid="test-notification">
    <h2 id="ns-test-title" class="text-h4 font-extrabold text-ink">{{ __('notification-settings.test.title') }}</h2>
    <p class="mt-0.5 text-small text-ink-2">{{ __('notification-settings.test.lead') }}</p>

    <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="min-w-56">
            <label for="ns-test-target" class="mb-1.5 block font-bold text-ink">{{ __('notification-settings.test.target') }}</label>
            <select id="ns-test-target" class="hc-field" wire:model="testTarget">
                @foreach ($testTargets as $target)
                    <option value="{{ $target }}" @selected($testTarget === $target)>{{ __('notification-settings.test.targets.'.$target) }}</option>
                @endforeach
            </select>
        </div>
        <x-button icon="send" target="sendTest" data-testid="send-test"
                  x-on:click="$wire.sendTest($wire.testTarget === 'device' && subscriptionId != null ? Number(subscriptionId) : null, $wire.testTarget)">
            {{ __('notification-settings.test.send') }}
        </x-button>
    </div>

    <div class="mt-4" role="status" aria-live="polite" data-testid="test-result">
        @if ($testResult)
            <p class="flex flex-wrap items-center gap-3" wire:key="test-result-{{ $saveTick }}-{{ md5($testResult['message']) }}">
                <x-status :tone="$testResult['ok'] ? 'done' : 'stop'" :icon="$testResult['ok'] ? 'check-circle' : 'ban'"
                          :label="$testResult['ok'] ? __('notification-settings.test.sent') : __('notification-settings.test.not-sent')" />
                <span class="text-body text-ink">{{ $testResult['message'] }}</span>
            </p>
        @endif
    </div>
</section>
