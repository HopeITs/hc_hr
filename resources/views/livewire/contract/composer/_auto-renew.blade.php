{{--
    The auto-renew switch, for the roles that may set it ($canManageAutoRenew).
    Editing shows it in the termination section; creating shows it under the
    dates, already on (G-B-10, answer 2), so HR sees the default and can turn
    it off before saving. Still editable afterwards from either screen.
--}}
        {{-- A trial never auto-renews (ContractObserver::saving()), so there is nothing to toggle. --}}
        <div class="md:col-span-2" x-show="selectedType?.category !== 'trial'">
            <div class="flex min-h-[2.625rem] items-center justify-between gap-4 rounded-control border border-line-soft bg-soft px-3 py-2">
                <div class="min-w-0">
                    <div class="flex items-start gap-1">
                        <x-forms.input-label for="composer_auto_renew">
                            @lang('general.auto-renew')
                        </x-forms.input-label>
                        <x-help.trigger topic="auto-renew" :label="__('general.auto-renew')"/>
                    </div>
                    <p class="mt-0.5 text-caption text-ink-2">
                        @lang('general.auto-renew-hint')
                    </p>
                    {{-- What switching it on actually does
                         (ContractAutoRenewalService). --}}
                    <p class="mt-1 text-caption text-ink-2">
                        @lang('contracts.composer-help.auto-renew')
                    </p>
                </div>

                <x-forms.toggle-input
                    id="composer_auto_renew"
                    x-model="autoRenew"
                    x-bind:checked="autoRenew"
                    class="shrink-0 p-2 text-xl"
                />
            </div>
        </div>
