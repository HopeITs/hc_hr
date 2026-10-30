<x-slot:title>
    {{ __('general.profile.settings') }}
</x-slot:title>

<div class="w-full px-12 py-8 space-y-8">

    <x-page-header class="!mb-0" :title="__('general.my-account')" />

    <div class="w-full bg-card rounded-surface shadow-e1 border border-line-soft p-9 max-w-3xl">

        <form wire:submit.prevent="updatePassword" class="space-y-8">

            <div class="w-full">
                <label class="text-body font-semibold text-ink mb-1 block">
                    {{ __('general.profile.current-password') }}
                </label>

                <x-forms.password-input
                    id="current_password"
                    model="current_password"
                    autocomplete="current-password"
                    class="rounded-surface border border-line w-full"
                />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div>
                    <label class="text-body font-semibold text-ink mb-1 block">
                        {{ __('general.profile.new-password') }}
                    </label>

                    <x-forms.password-input
                        id="password"
                        model="password"
                        autocomplete="new-password"
                        class="rounded-surface border border-line w-full"
                    />
                </div>

                <div>
                    <label class="text-body font-semibold text-ink mb-1 block">
                        {{ __('auth.confirm-password') }}
                    </label>

                    <x-forms.password-input
                        id="password_confirmation"
                        model="password_confirmation"
                        autocomplete="new-password"
                        class="rounded-surface border border-line w-full"
                    />
                </div>

            </div>

            <div class="flex justify-end">
                <x-button type="submit" target="updatePassword">
                    {{ __('general.profile.save') }}
                </x-button>

                <x-action-message
                    on="password-updated"
                    class="text-body font-semibold text-ink ms-4 self-center">
                    {{ __('general.profile.got-saved') }}
                </x-action-message>
            </div>

        </form>

    </div>
</div>
