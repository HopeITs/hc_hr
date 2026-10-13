<x-slot:title>
    {{ __('general.profile.settings') }}
</x-slot:title>

<div class="w-full px-12 py-8 space-y-8">

    <h1 class="text-h2 font-bold text-secondary-dark">
        {{ __('general.my-account') }}
    </h1>

    <div class="w-full bg-white rounded-surface shadow-[0px_0px_25px_-14px_rgba(0,_0,_0,_0.30)] border border-neutral-200 p-9 max-w-3xl">

        <form wire:submit.prevent="updatePassword" class="space-y-8">

            <div class="w-full">
                <label class="text-body font-semibold text-secondary-dark mb-1 block">
                    {{ __('general.profile.current-password') }}
                </label>

                <x-forms.password-input
                    id="current_password"
                    model="current_password"
                    autocomplete="current-password"
                    class="rounded-surface border border-neutral-300 focus:border-primary focus:ring-primary w-full"
                />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div>
                    <label class="text-body font-semibold text-secondary-dark mb-1 block">
                        {{ __('general.profile.new-password') }}
                    </label>

                    <x-forms.password-input
                        id="password"
                        model="password"
                        autocomplete="new-password"
                        class="rounded-surface border border-neutral-300 focus:border-primary focus:ring-primary w-full"
                    />
                </div>

                <div>
                    <label class="text-body font-semibold text-secondary-dark mb-1 block">
                        {{ __('auth.confirm-password') }}
                    </label>

                    <x-forms.password-input
                        id="password_confirmation"
                        model="password_confirmation"
                        autocomplete="new-password"
                        class="rounded-surface border border-neutral-300 focus:border-primary focus:ring-primary w-full"
                    />
                </div>

            </div>

            <div class="flex justify-end">
                <x-primary-button
                    class="text-body font-semibold rounded-surface bg-primary hover:bg-primary transition shadow-e1">
                    {{ __('general.profile.save') }}
                </x-primary-button>

                <x-action-message
                    on="password-updated"
                    class="text-body text-green-600 ms-4 self-center">
                    {{ __('general.profile.got-saved') }}
                </x-action-message>
            </div>

        </form>

    </div>
</div>
